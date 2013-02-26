<?php

namespace Binovo\ElkarBackupBundle\Controller;

use \DateTime;
use \Exception;
use \PDOException;
use \RuntimeException;
use Binovo\ElkarBackupBundle\Entity\Client;
use Binovo\ElkarBackupBundle\Entity\Job;
use Binovo\ElkarBackupBundle\Entity\Message;
use Binovo\ElkarBackupBundle\Entity\Policy;
use Binovo\ElkarBackupBundle\Entity\Script;
use Binovo\ElkarBackupBundle\Entity\User;
use Binovo\ElkarBackupBundle\Form\Type\AuthorizedKeyType;
use Binovo\ElkarBackupBundle\Form\Type\ClientType;
use Binovo\ElkarBackupBundle\Form\Type\JobType;
use Binovo\ElkarBackupBundle\Form\Type\JobForSortType;
use Binovo\ElkarBackupBundle\Form\Type\PolicyType;
use Binovo\ElkarBackupBundle\Form\Type\ScriptType;
use Binovo\ElkarBackupBundle\Form\Type\UserType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Security\Core\SecurityContext;

class DefaultController extends Controller
{
    protected function info($msg, $translatorParams = array(), $context = array())
    {
        $logger = $this->get('BnvWebLogger');
        $context = array_merge(array('source' => 'DefaultController'), $context);
        $logger->info($this->trans($msg, $translatorParams, 'BinovoElkarBackup'), $context);
    }

    protected function generateClientRoute($id)
    {
        return $this->generateUrl('editClient',
                                  array('id' => $id));
    }

    protected function generateJobRoute($idJob, $idClient)
    {
        return $this->generateUrl('editJob',
                                  array('idClient' => $idClient,
                                        'idJob'    => $idJob));
    }

    protected function generatePolicyRoute($id)
    {
        return $this->generateUrl('editPolicy',
                                  array('id' => $id));
    }

    protected function generateScriptRoute($id)
    {
        return $this->generateUrl('editScript',
                                  array('id' => $id));
    }

    protected function generateUserRoute($id)
    {
        return $this->generateUrl('editUser',
                                  array('id' => $id));
    }

    /*
     * Checks if autofs is installed and the builtin -hosts map activated
     */
    protected function isAutoFsAvailable()
    {
        $result = false;
        $file = fopen('/etc/auto.master', 'r');
        if (!$file) {
            return false;
        }
        while ($line = fgets($file)) {
            if (preg_match('/^\s*\/net\s*-hosts/', $line)) {
                $result = true;
                break;
            }
        }
        fclose($file);
        return $result;
    }

    /**
     * Should be called after making changes to any of the parameters to make the changes effective.
     */
    protected function clearCache()
    {
        $realCacheDir = $this->container->getParameter('kernel.cache_dir');
        $oldCacheDir  = $realCacheDir.'_old';
        $this->container->get('cache_clearer')->clear($realCacheDir);
        rename($realCacheDir, $oldCacheDir);
        $this->container->get('filesystem')->remove($oldCacheDir);
    }

    /**
     * @Route("/about", name="about")
     * @Template()
     */
    public function aboutAction(Request $request)
    {
        return $this->render('BinovoElkarBackupBundle:Default:about.html.twig');
    }

    /**
     * @Route("/config/publickey/get", name="downloadPublicKey")
     * @Template()
     */
    public function downloadPublicKeyAction(Request $request)
    {
        if (!file_exists($this->container->getParameter('public_key'))) {
            throw $this->createNotFoundException($this->trans('Unable to find public key:'));
        }
        $headers = array('Content-Type'        => 'text/plain',
                         'Content-Disposition' => sprintf('attachment; filename="Publickey.pub"'));

        return new Response(file_get_contents($this->container->getParameter('public_key')), 200, $headers);
    }

    /**
     * @Route("/config/publickey/generate", name="generatePublicKey")
     * @Method("POST")
     * @Template()
     */
    public function generatePublicKeyAction(Request $request)
    {
        $t = $this->get('translator');
        $db = $this->getDoctrine();
        $manager = $db->getManager();
        $msg = new Message('DefaultController', 'TickCommand',
                           json_encode(array('command' => "elkarbackup:generate_keypair")));
        $manager->persist($msg);
        $this->info('Public key generation requested');
        $manager->flush();
        $this->get('session')->getFlashBag()->add('manageParameters',
                                                  $t->trans('Wait for key generation. It should be available in less than 2 minutes. Check logs if otherwise',
                                                            array(),
                                                            'BinovoElkarBackup'));

        return $this->redirect($this->generateUrl('manageParameters'));
    }

    public function trans($msg, $params = array(), $domain = 'BinovoElkarBackup')
    {
        return $this->get('translator')->trans($msg, $params, $domain);
    }

    /**
     * @Route("/client/{id}/delete", name="deleteClient")
     * @Method("POST")
     * @Template()
     */
    public function deleteClientAction(Request $request, $id)
    {
        $t = $this->get('translator');
        $db = $this->getDoctrine();
        $repository = $db->getRepository('BinovoElkarBackupBundle:Client');
        $manager = $db->getManager();
        $client = $repository->find($id);
        try {
            $manager->remove($client);
            $msg = new Message('DefaultController', 'TickCommand',
                               json_encode(array('command' => "elkarbackup:delete_job_backups",
                                                 'client'  => (int)$id)));
            $manager->persist($msg);
            $this->info('Client "%clientid%" deleted', array('%clientid%' => $id), array('link' => $this->generateClientRoute($id)));
            $manager->flush();
        } catch (Exception $e) {
            $this->get('session')->getFlashBag()->add('clients',
                                                      $t->trans('Unable to delete client: %extrainfo%',
                                                                array('%extrainfo%' => $e->getMessage()), 'BinovoElkarBackup'));
        }

        return $this->redirect($this->generateUrl('showClients'));
    }

    /**
     * @Route("/login", name="login")
     * @Method("GET")
     * @Template()
     */
    public function loginAction(Request $request)
    {
        $request = $this->getRequest();
        $session = $request->getSession();
        $t = $this->get('translator');

        // get the login error if there is one
        if ($request->attributes->has(SecurityContext::AUTHENTICATION_ERROR)) {
            $error = $request->attributes->get(SecurityContext::AUTHENTICATION_ERROR);
        } else {
            $error = $session->get(SecurityContext::AUTHENTICATION_ERROR);
            $session->remove(SecurityContext::AUTHENTICATION_ERROR);
        }
        $this->info('Log in attempt with user: %username%', array('%username%' => $session->get(SecurityContext::LAST_USERNAME)));
        $this->getDoctrine()->getManager()->flush();
        $locales = $this->container->getParameter('supported_locales');
        $localesWithNames = array();
        foreach ($locales as $locale) {
            $localesWithNames[] = array($locale, $t->trans("language_$locale", array(), 'BinovoElkarBackup'));
        }

        return $this->render('BinovoElkarBackupBundle:Default:login.html.twig', array(
                                 'last_username' => $session->get(SecurityContext::LAST_USERNAME),
                                 'error'         => $error,
                                 'supportedLocales' => $localesWithNames));
    }

    /**
     * @Route("/client/{id}", name="editClient")
     * @Method("GET")
     * @Template()
     */
    public function editClientAction(Request $request, $id)
    {
        if ('new' === $id) {
            $client = new Client();
        } else {
            $repository = $this->getDoctrine()
                ->getRepository('BinovoElkarBackupBundle:Client');
            $client = $repository->find($id);
        }
        foreach ($client->getJobs() as $job) {
            $job->setLogEntry($this->getLastLogForLink(sprintf('%%/client/%d/job/%d', $client->getId(), $job->getId())));
        }
        $form = $this->createForm(new ClientType(), $client, array('translator' => $this->get('translator')));
        $this->info('View client %clientid%',
                    array('%clientid%' => $id),
                    array('link' => $this->generateClientRoute($id)));
        $this->getDoctrine()->getManager()->flush();

        return $this->render('BinovoElkarBackupBundle:Default:client.html.twig',
                             array('form' => $form->createView()));
    }

    /**
     * @Route("/client/{id}", requirements={"id" = "\d+"}, defaults={"id" = "-1"}, name="saveClient")
     * @Method("POST")
     * @Template()
     */
    public function saveClientAction(Request $request, $id)
    {
        $t = $this->get('translator');
        if ("-1" === $id) {
            $client = new Client();
        } else {
            $repository = $this->getDoctrine()
                ->getRepository('BinovoElkarBackupBundle:Client');
            $client = $repository->find($id);
        }
        $jobsToDelete = array(); // we store here the jobs that are missing in the form
        foreach ($client->getJobs() as $job) {
            $jobsToDelete[$job->getId()] = $job;
        }
        $form = $this->createForm(new ClientType(), $client, array('translator' => $t));
        $form->bind($request);
        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            foreach ($client->getJobs() as $job) {
                if (isset($jobsToDelete[$job->getid()])) {
                    unset($jobsToDelete[$job->getid()]);
                }
            }
            try {
                foreach ($jobsToDelete as $idJob => $job) {
                    $client->getJobs()->removeElement($job);
                    $em->remove($job);
                    $msg = new Message('DefaultController', 'TickCommand',
                                       json_encode(array('command' => "elkarbackup:delete_job_backups",
                                                         'client'  => (int)$id,
                                                         'job'     => $idJob)));
                    $em->persist($msg);
                    $this->info('Delete client %clientid%, job %jobid%',
                                array('%clientid%' => $client->getId(),
                                      '%jobid%' => $job->getId()),
                                array('link' => $this->generateJobRoute($job->getId(), $client->getId())));
                }
                $em->persist($client);
                $this->info('Save client %clientid%',
                        array('%clientid%' => $client->getId()),
                            array('link' => $this->generateClientRoute($client->getId()))
                    );
                $em->flush();

                return $this->redirect($this->generateUrl('editClient', array('id' => $client->getId())));
            } catch (Exception $e) {
                $this->get('session')->getFlashBag()->add('client',
                                                          $t->trans('Unable to save your changes: %extrainfo%',
                                                                    array('%extrainfo%' => $e->getMessage()),
                                                                    'BinovoElkarBackup'));

                return $this->redirect($this->generateUrl('editClient', array('id' => $client->getId())));
            }
        } else {

            return $this->render('BinovoElkarBackupBundle:Default:client.html.twig',
                                 array('form' => $form->createView()));
        }
    }

    /**
     * @Route("/job/{id}/delete", name="deleteJob")
     * @Route("/client/{idClient}/job/{idJob}/delete", requirements={"idClient" = "\d+", "idJob" = "\d+"}, defaults={"idJob" = "-1"}, name="deleteJob")
     * @Method("POST")
     * @Template()
     */
    public function deleteJobAction(Request $request, $idClient, $idJob)
    {
        $t = $this->get('translator');
        $db = $this->getDoctrine();
        $repository = $db->getRepository('BinovoElkarBackupBundle:Job');
        $manager = $db->getManager();
        $job = $repository->find($idJob);
        try {
            $manager->remove($job);
            $msg = new Message('DefaultController', 'TickCommand',
                               json_encode(array('command' => "elkarbackup:delete_job_backups",
                                                 'client'  => (int)$idClient,
                                                 'job'     => (int)$idJob)));
            $manager->persist($msg);
            $this->info('Delete client %clientid%, job "%jobid%"', array('%clientid%' => $idClient, '%jobid%' => $idJob), array('link' => $this->generateJobRoute($idJob, $idClient)));
            $manager->flush();
        } catch (Exception $e) {
            $this->get('session')->getFlashBag()->add('client',
                                                      $t->trans('Unable to delete job: %extrainfo%',
                                                                array('%extrainfo%' => $e->getMessage()), 'BinovoElkarBackup'));
        }

        return $this->redirect($this->generateUrl('editClient', array('id' => $idClient)));
    }

    /**
     * @Route("/client/{idClient}/job/{idJob}", name="editJob")
     * @Method("GET")
     * @Template()
     */
    public function editJobAction(Request $request, $idClient, $idJob)
    {
        if ('new' === $idJob) {
            $job = new Job();
            $client = $this->getDoctrine()
                ->getRepository('BinovoElkarBackupBundle:Client')->find($idClient);
            if (null == $client) {
                throw $this->createNotFoundException($this->trans('Unable to find Client entity:') . $idClient);
            }
            $job->setClient($client);
            $job->setOwner($this->get('security.context')->getToken()->getUser());
        } else {
            $job = $this->getDoctrine()
                ->getRepository('BinovoElkarBackupBundle:Job')->find($idJob);
        }
        $form = $this->createForm(new JobType(), $job, array('translator' => $this->get('translator')));
        $this->info('View client %clientid%, job %jobid%',
                    array('%clientid%' => $idClient, '%jobid%' => $idJob),
                    array('link' => $this->generateJobRoute($idJob, $idClient)));
        $this->getDoctrine()->getManager()->flush();

        return $this->render('BinovoElkarBackupBundle:Default:job.html.twig',
                             array('form' => $form->createView()));
    }

    /**
     * @Route("/client/{idClient}/job/{idJob}/run", requirements={"idClient" = "\d+", "idJob" = "\d+"}, name="runJob")
     * @Method("POST")
     * @Template()
     */
    public function runJobAction(Request $request, $idClient, $idJob)
    {
        $em = $this->getDoctrine()->getManager();
        $msg = new Message('DefaultController', 'TickCommand',
                           json_encode(array('command' => 'elkarbackup:run_job',
                                             'client'  => $idClient,
                                             'job'     => $idJob)));
        $em->persist($msg);
        $em->flush();
        $response = new Response("Job execution requested successfully");
        $response->headers->set('Content-Type', 'text/plain');

        return $response;
    }

    /**
     * @Route("/client/{idClient}/job/{idJob}/config", requirements={"idClient" = "\d+", "idJob" = "\d+"}, name="showJobConfig")
     * @Method("GET")
     * @Template()
     */
    public function showJobConfigAction(Request $request, $idClient, $idJob)
    {
        $t = $this->get('translator');
        $backupDir  = $this->container->getParameter('backup_dir');
        $repository = $this->getDoctrine()->getRepository('BinovoElkarBackupBundle:Job');
        $job = $repository->find($idJob);
        if (null == $job || $job->getClient()->getId() != $idClient) {
            throw $this->createNotFoundException($t->trans('Unable to find Job entity: ', array(), 'BinovoElkarBackup') . $idClient . " " . $idJob);
        }
        $tmpDir = $this->container->getParameter('tmp_dir');
        $url = $job->getUrl();
        $idJob = $job->getId();
        $policy = $job->getPolicy();
        $retains = $policy->getRetains();
        $includes = array();
        $include = $job->getInclude();
        if ($include) {
            $includes = explode("\n", $include);
        }
        $excludes = array();
        $exclude = $job->getExclude();
        if ($exclude) {
            $excludes = explode("\n", $exclude);
        }
        $syncFirst = $policy->getSyncFirst();
        $response = new Response();
        $response->headers->set('Content-Type', 'text/plain');
        $this->info('Show job config %clientid%, job %jobid%',
                    array('%clientid%' => $idClient, '%jobid%' => $idJob),
                    array('link' => $this->generateJobRoute($idJob, $idClient)));
        $this->getDoctrine()->getManager()->flush();
        $preCommand  = '';
        $postCommand = '';
        if ($job->getPreScript() != null) {
            $preCommand = sprintf('/usr/bin/env ELKARBACKUP_LEVEL="JOB" ELKARBACKUP_EVENT="PRE" ELKARBACKUP_URL="%s" ELKARBACKUP_ID="%s" ELKARBACKUP_PATH="%s" ELKARBACKUP_STATUS="%s" sudo "%s" 2>&1',
                                  $url,
                                  $idJob,
                                  $job->getSnapshotRoot(),
                                  0,
                                  $job->getPreScript()->getScriptPath('pre'));
        }
        if ($job->getPostScript() != null) {
            $postCommand = sprintf('/usr/bin/env ELKARBACKUP_LEVEL="JOB" ELKARBACKUP_EVENT="POST" ELKARBACKUP_URL="%s" ELKARBACKUP_ID="%s" ELKARBACKUP_PATH="%s" ELKARBACKUP_STATUS="%s" sudo "%s" 2>&1',
                                  $url,
                                  $idJob,
                                  $job->getSnapshotRoot(),
                                  0,
                                  $job->getPostScript()->getScriptPath('post'));
        }

        return $this->render('BinovoElkarBackupBundle:Default:rsnapshotconfig.txt.twig',
                             array('cmdPreExec'          => $preCommand,
                                   'cmdPostExec'         => $postCommand,
                                   'excludes'            => $excludes,
                                   'idClient'            => sprintf('%04d', $idClient),
                                   'idJob'               => sprintf('%04d', $idJob),
                                   'includes'            => $includes,
                                   'backupDir'           => $backupDir,
                                   'retains'             => $retains,
                                   'tmp'                 => $tmpDir,
                                   'snapshotRoot'        => $job->getSnapshotRoot(),
                                   'syncFirst'           => $syncFirst,
                                   'url'                 => $url,
                                   'useLocalPermissions' => $job->getUseLocalPermissions()),
                             $response);
    }

    /**
     * @Route("/client/{idClient}/job/{idJob}", requirements={"idClient" = "\d+", "idJob" = "\d+"}, defaults={"idJob" = "-1"}, name="saveJob")
     * @Method("POST")
     * @Template()
     */
    public function saveJobAction(Request $request, $idClient, $idJob)
    {
        $t = $this->get('translator');
        if ("-1" === $idJob) {
            $job = new Job();
            $client = $this->getDoctrine()
                ->getRepository('BinovoElkarBackupBundle:Client')->find($idClient);
            if (null == $client) {
                throw $this->createNotFoundException($t->trans('Unable to find Client entity:', array(), 'BinovoElkarBackup') . $idClient);
            }
            $job->setClient($client);
        } else {
            $repository = $this->getDoctrine()
                ->getRepository('BinovoElkarBackupBundle:Job');
            $job = $repository->find($idJob);
        }
        $storedOwner = $job->getOwner();
        $form = $this->createForm(new JobType(), $job, array('translator' => $t));
        $form->bind($request);
        if ($form->isValid()) {
            $job = $form->getData();
            if (!$this->get('security.context')->isGranted('ROLE_ADMIN')) { // only allow chown to admin
                $job->setOwner($storedOwner);
            }
            if ($job->getOwner() == null) {
                $job->setOwner($this->get('security.context')->getToken()->getUser());
            }
            try {
                $em = $this->getDoctrine()->getManager();
                $em->persist($job);
                $this->info('Save client %clientid%, job %jobid%',
                            array('%clientid%' => $job->getClient()->getId(),
                                  '%jobid%'    => $job->getId()),
                            array('link' => $this->generateJobRoute($job->getId(), $job->getClient()->getId())));
                $em->flush();
            } catch (Exception $e) {
                $this->get('session')->getFlashBag()->add('job',
                                                          $t->trans('Unable to save your changes: %extrainfo%',
                                                                    array('%extrainfo%' => $e->getMessage()),
                                                                    'BinovoElkarBackup'));
            }

            return $this->redirect($this->generateJobRoute($job->getId(), $job->getClient()->getId()));
        } else {

            return $this->render('BinovoElkarBackupBundle:Default:job.html.twig',
                                 array('form' => $form->createView()));
        }
    }

    /**
     * @Route("/client/{idClient}/job/{idJob}/backup/{action}/{path}", requirements={"idClient" = "\d+", "idJob" = "\d+", "path" = ".*", "action" = "view|download"}, defaults={"path" = "/"}, name="showJobBackup")
     * @Method("GET")
     */
    public function showJobBackupAction(Request $request, $idClient, $idJob, $action, $path)
    {
        $t = $this->get('translator');
        $repository = $this->getDoctrine()
            ->getRepository('BinovoElkarBackupBundle:Job');
        $job = $repository->find($idJob);
        if ($job->getClient()->getId() != $idClient) {
            throw $this->createNotFoundException($t->trans('Unable to find Job entity: ', array(), 'BinovoElkarBackup') . $idClient . " " . $idJob);
        }

        $snapshotRoot = realpath($job->getSnapshotRoot());
        $realPath = realpath($snapshotRoot . '/' . $path);
        if (false == $realPath) {
            throw $this->createNotFoundException($t->trans('Path not found:', array(), 'BinovoElkarBackup') . $path);
        }
        if (0 !== strpos($realPath, $snapshotRoot)) {
            throw $this->createNotFoundException($t->trans('Path not found:', array(), 'BinovoElkarBackup') . $path);
        }
        if (is_dir($realPath)) {
            if ('download' == $action) {
                $headers = array('Content-Type'        => 'application/x-gzip',
                                 'Content-Disposition' => sprintf('attachment; filename="%s.tar.gz"', basename($realPath)));
                $f = function() use ($realPath){
                    $command = sprintf('cd %s; tar zc %s', dirname($realPath), basename($realPath));
                    passthru($command);
                };
                $this->info('Download backup directory %clientid%, %jobid% %path%',
                            array('%clientid%' => $idClient,
                                  '%jobid%'    => $idJob,
                                  '%path%'     => $path),
                            array('link' => $this->generateUrl('showJobBackup',
                                                               array('action'   => $action,
                                                                     'idClient' => $idClient,
                                                                     'idJob'    => $idJob,
                                                                     'path'     => $path))));
                $this->getDoctrine()->getManager()->flush();

                return new StreamedResponse($f, 200, $headers);
            } else {
                $content = scandir($realPath);
                if (false === $content) {
                    $content = array();
                }
                foreach ($content as &$aFile) {
                    $date = new \DateTime();
                    $date->setTimestamp(filemtime($realPath . '/' . $aFile));
                    $aFile = array($aFile, $date, is_dir($realPath . '/' . $aFile));
                }
                $this->info('View backup directory %clientid%, %jobid% %path%',
                            array('%clientid%' => $idClient,
                                  '%jobid%'    => $idJob,
                                  '%path%'     => $path),
                            array('link' => $this->generateUrl('showJobBackup',
                                                               array('action'   => $action,
                                                                     'idClient' => $idClient,
                                                                     'idJob'    => $idJob,
                                                                     'path'     => $path))));
                $this->getDoctrine()->getManager()->flush();

                return $this->render('BinovoElkarBackupBundle:Default:directory.html.twig',
                                     array('content'  => $content,
                                           'job'      => $job,
                                           'path'     => $path,
                                           'realPath' => $realPath));
            }
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE); // return mime type ala mimetype extension
            $mimeType = finfo_file($finfo, $realPath);
            finfo_close($finfo);
            $headers = array('Content-Type' => $mimeType,
                             'Content-Disposition' => sprintf('attachment; filename="%s"', basename($realPath)));
            $this->info('Download backup file %clientid%, %jobid% %path%',
                        array('%clientid%' => $idClient,
                              '%jobid%'    => $idJob,
                              '%path%'     => $path),
                        array('link' => $this->generateUrl('showJobBackup',
                                                           array('action'   => $action,
                                                                 'idClient' => $idClient,
                                                                 'idJob'    => $idJob,
                                                                 'path'     => $path))));
            $this->getDoctrine()->getManager()->flush();

            return new Response(file_get_contents($realPath), 200, $headers);
        }
    }

    /**
     * @Route("/", name="home")
     * @Template()
     */
    public function homeAction(Request $request)
    {
        return $this->redirect($this->generateUrl('showClients'));
    }

    /**
     * @Route("/hello/{name}")
     * @Template()
     */
    public function indexAction($name)
    {
        return array('name' => $name);
    }

    /**
     * @Route("/policy/{id}", name="editPolicy")
     * @Method("GET")
     * @Template()
     */
    public function editPolicyAction(Request $request, $id)
    {
        $t = $this->get('translator');
        if ('new' === $id) {
            $policy = new Policy();
        } else {
            $policy = $this->getDoctrine()
                ->getRepository('BinovoElkarBackupBundle:Policy')->find($id);
        }
        $form = $this->createForm(new PolicyType(), $policy, array('translator' => $t));
        $this->info('View policy %policyname%',
                    array('%policyname%' => $policy->getName()),
                    array('link' => $this->generatePolicyRoute($policy->getId())));
        $this->getDoctrine()->getManager()->flush();

        return $this->render('BinovoElkarBackupBundle:Default:policy.html.twig',
                             array('form' => $form->createView()));
    }

    /**
     * @Route("/policy/{id}/delete", name="deletePolicy")
     * @Method("POST")
     * @Template()
     */
    public function deletePolicyAction(Request $request, $id)
    {
        $db = $this->getDoctrine();
        $repository = $db->getRepository('BinovoElkarBackupBundle:Policy');
        $manager = $db->getManager();
        $policy = $repository->find($id);
        try{
            $manager->remove($policy);
            $this->info('Delete policy %policyname%',
                        array('%policyname%' => $policy->getName()),
                        array('link' => $this->generatePolicyRoute($id)));
            $manager->flush();
        } catch (PDOException $e) {
            $this->get('session')->getFlashBag()->add('showPolicies',
                                                      $t->trans('Removing the policy %name% failed. Check that it is not in use.', array('%name' => $policy->getName()), 'BinovoElkarBackup'));
        }

        return $this->redirect($this->generateUrl('showPolicies'));
    }

    /**
     * @Route("/policy/{id}", requirements={"id" = "\d+"}, defaults={"id" = "-1"}, name="savePolicy")
     * @Method("POST")
     * @Template()
     */
    public function savePolicyAction(Request $request, $id)
    {
        $t = $this->get('translator');
        if ("-1" === $id) {
            $policy = new Policy();
        } else {
            $repository = $this->getDoctrine()
                ->getRepository('BinovoElkarBackupBundle:Policy');
            $policy = $repository->find($id);
        }
        $form = $this->createForm(new PolicyType(), $policy, array('translator' => $t));
        $form->bind($request);
        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($policy);
            $this->info('Save policy %policyname%',
                        array('%policyname%' => $policy->getName()),
                        array('link' => $this->generatePolicyRoute($id)));
            $em->flush();

            return $this->redirect($this->generatePolicyRoute($policy->getId()));
        } else {

            return $this->render('BinovoElkarBackupBundle:Default:policy.html.twig',
                                 array('form' => $form->createView()));
        }
    }

    /**
     * @Route("/jobs/sort", name="sortJobs")
     * @Template()
     */
    public function sortJobsAction(Request $request)
    {
        $t = $this->get('translator');
        $repository = $this->getDoctrine()
            ->getRepository('BinovoElkarBackupBundle:Job');
        $jobs = $repository->createQueryBuilder('j')
                            ->innerJoin('j.client', 'c')
                            ->where('j.isActive <> 0 AND c.isActive <> 0')
                            ->orderBy('j.priority', 'ASC')
                            ->getQuery()->getResult();
        $formBuilder = $this->createFormBuilder(array('jobs' => $jobs));
        $formBuilder->add('jobs', 'collection',
                          array('type' => new JobForSortType()));
        $form = $formBuilder->getForm();
        if ($request->isMethod('POST')) {
            $i = 1;
            foreach ($_POST['form']['jobs'] as $jobId) {
                $jobId = $jobId['id'];
                $job = $repository->findOneById($jobId);
                $job->setPriority($i);
                ++$i;
            }
            $this->info('Jobs reordered',
                        array(),
                        array('link' => $this->generateUrl('showClients')));
            $this->getDoctrine()->getManager()->flush();
            $this->get('session')->getFlashBag()->add('sortJobs',
                                                      $t->trans('Jobs prioritized',
                                                                array(),
                                                                'BinovoElkarBackup'));
            $result = $this->redirect($this->generateUrl('sortJobs'));
        } else {
            $result = $this->render('BinovoElkarBackupBundle:Default:sortjobs.html.twig',
                                    array('form' => $form->createView()));
        }

        return $result;
    }

    /**
     * @Route("/clients", name="showClients")
     * @Template()
     */
    public function showClientsAction(Request $request)
    {
        $repository = $this->getDoctrine()
            ->getRepository('BinovoElkarBackupBundle:Client');
        $query = $repository->createQueryBuilder('c')
            ->getQuery();

        $paginator = $this->get('knp_paginator');
        $pagination = $paginator->paginate(
            $query,
            $request->query->get('page', 1)/*page number*/,
            $request->get('lines', $this->container->getParameter('pagination_lines_per_page'))
            );
        foreach ($pagination as $i => $client) {
            $client->setLogEntry($this->getLastLogForLink('%/client/' . $client->getId()));
            foreach ($client->getJobs() as $job) {
                $job->setLogEntry($this->getLastLogForLink('%/client/' . $client->getId() . '/job/' . $job->getId()));
            }
        }
        $this->info('View clients',
                    array(),
                    array('link' => $this->generateUrl('showClients')));
        $this->getDoctrine()->getManager()->flush();

        return $this->render('BinovoElkarBackupBundle:Default:clients.html.twig',
                             array('pagination' => $pagination));
    }

    /**
     * @Route("/scripts", name="showScripts")
     * @Template()
     */
    public function showScriptsAction(Request $request)
    {
        $repository = $this->getDoctrine()
            ->getRepository('BinovoElkarBackupBundle:Script');
        $query = $repository->createQueryBuilder('c')
            ->getQuery();

        $paginator = $this->get('knp_paginator');
        $pagination = $paginator->paginate(
            $query,
            $request->query->get('page', 1)/*page number*/,
            $request->get('lines', $this->container->getParameter('pagination_lines_per_page'))
            );
        $this->info('View scripts',
                    array(),
                    array('link' => $this->generateUrl('showScripts')));
        $this->getDoctrine()->getManager()->flush();

        return $this->render('BinovoElkarBackupBundle:Default:scripts.html.twig',
                             array('pagination' => $pagination));
    }

    public function getLastLogForLink($link)
    {
        $lastLog = null;
        $em = $this->getDoctrine()->getManager();
        // :WARNING: this call might end up slowing things too much.
        $dql =<<<EOF
SELECT l
FROM  BinovoElkarBackupBundle:LogRecord l
WHERE l.source = 'TickCommand' AND l.link LIKE :link
ORDER BY l.id DESC
EOF;
        $query = $em->createQuery($dql)->setParameter('link', $link);
        $logs = $query->getResult();
        if (count($logs) > 0) {
            $lastLog = $logs[0];
        }

        return $lastLog;
    }

    /**
     * @Route("/logs", name="showLogs")
     * @Template()
     */
    public function showLogsAction(Request $request)
    {
        $formValues = array();
        $t = $this->get('translator');
        $repository = $this->getDoctrine()
            ->getRepository('BinovoElkarBackupBundle:LogRecord');
        $queryBuilder = $repository->createQueryBuilder('l')
            ->addOrderBy('l.id', 'DESC');
        $queryParamCounter = 1;
        if ($request->get('filter')) {
            $queryBuilder->where("1 = 1");
            foreach ($request->get('filter') as $op => $filterValues) {
                if (!in_array($op, array('gte', 'eq', 'like'))) {
                    $op = 'eq';
                }
                foreach ($filterValues as $columnName => $value) {
                    if ($value) {
                        $queryBuilder->andWhere($queryBuilder->expr()->$op($columnName, "?$queryParamCounter"));
                        if ('like' == $op) {
                            $queryBuilder->setParameter($queryParamCounter, '%' . $value . '%');
                        } else {
                            $queryBuilder->setParameter($queryParamCounter, $value);
                        }
                        ++$queryParamCounter;
                        $formValues["filter[$op][$columnName]"] = $value;
                    }
                }
            }
        }
        $query = $queryBuilder->getQuery();

        $paginator = $this->get('knp_paginator');
        $pagination = $paginator->paginate(
            $query,
            $request->query->get('page', 1)/*page number*/,
            $request->get('lines', $this->container->getParameter('pagination_lines_per_page'))
            );
        $this->info('View logs',
                    array(),
                    array('link' => $this->generateUrl('showLogs')));
        $this->getDoctrine()->getManager()->flush();

        return $this->render('BinovoElkarBackupBundle:Default:logs.html.twig',
                             array('pagination' => $pagination,
                                   'levels' => array('options' => array(Job::NOTIFICATION_LEVEL_ALL     => $t->trans('All messages'   , array(), 'BinovoElkarBackup'),
                                                                        Job::NOTIFICATION_LEVEL_INFO    => $t->trans('Notices and up' , array(), 'BinovoElkarBackup'),
                                                                        Job::NOTIFICATION_LEVEL_WARNING => $t->trans('Warnings and up', array(), 'BinovoElkarBackup'),
                                                                        Job::NOTIFICATION_LEVEL_ERROR   => $t->trans('Errors and up'  , array(), 'BinovoElkarBackup'),
                                                                        Job::NOTIFICATION_LEVEL_NONE    => $t->trans('None'           , array(), 'BinovoElkarBackup')),
                                                     'value'   => isset($formValues['filter[gte][l.level]']) ? $formValues['filter[gte][l.level]'] : null,
                                                     'name'    => 'filter[gte][l.level]'),
                                   'object' => array('value'   => isset($formValues['filter[like][l.link]']) ? $formValues['filter[like][l.link]'] : null,
                                                     'name'    => 'filter[like][l.link]'),
                                   'source' => array('options' => array(''                            => $t->trans('All', array(), 'BinovoElkarBackup'),
                                                                        'DefaultController'           => 'DefaultController',
                                                                        'GenerateKeyPairCommand'      => 'GenerateKeyPairCommand',
                                                                        'RunJobCommand'               => 'RunJobCommand',
                                                                        'TickCommand'                 => 'TickCommand',
                                                                        'UpdateAuthorizedKeysCommand' => 'UpdateAuthorizedKeysCommand'),
                                                     'value'   => isset($formValues['filter[eq][l.source]']) ? $formValues['filter[eq][l.source]'] : null,
                                                     'name'    => 'filter[eq][l.source]')));
    }

    /**
     * @Route("/policies", name="showPolicies")
     * @Template()
     */
    public function showPoliciesAction(Request $request)
    {
        $repository = $this->getDoctrine()
            ->getRepository('BinovoElkarBackupBundle:Policy');
        $query = $repository->createQueryBuilder('c')
            ->getQuery();

        $paginator = $this->get('knp_paginator');
        $pagination = $paginator->paginate(
            $query,
            $request->query->get('page', 1)/*page number*/,
            $request->get('lines', $this->container->getParameter('pagination_lines_per_page'))
            );
        $this->info('View policies',
                    array(),
                    array('link' => $this->generateUrl('showPolicies')));
        $this->getDoctrine()->getManager()->flush();

        return $this->render('BinovoElkarBackupBundle:Default:policies.html.twig',
                             array('pagination' => $pagination));
    }

    /**
     * @Route("/config/repositorybackupscript/download", name="getRepositoryBackupScript")
     * @Template()
     */
    public function getRepositoryBackupScriptAction(Request $request)
    {
        $response = $this->render('BinovoElkarBackupBundle:Default:copyrepository.sh.twig',
                                  array('backupsroot'   => $this->container->getParameter('backup_dir'),
                                        'backupsuser'   => 'elkarbackup',
                                        'mysqldb'       => $this->container->getParameter('database_name'),
                                        'mysqlhost'     => $this->container->getParameter('database_host'),
                                        'mysqlpassword' => $this->container->getParameter('database_password'),
                                        'mysqluser'     => $this->container->getParameter('database_user'),
                                        'server'        => $request->getHttpHost(),
                                        'uploads'       => $this->container->getParameter('upload_dir')));
        $response->headers->set('Content-Type'       , 'text/plain');
        $response->headers->set('Content-Disposition', 'attachment; filename="copyrepository.sh"');

        return $response;
    }

    public function readKeyFileAsCommentAndRest($filename)
    {
        $keys = array();
        foreach (explode("\n", file_get_contents($filename)) as $keyLine) {
            $matches = array();
            // the format of eacn non empty non comment line is "options keytype base64-encoded key comment" where key is one of ecdsa-sha2-nistp256, ecdsa-sha2-nistp384, ecdsa-sha2-nistp521, ssh-dss, ssh-rsa
            if (preg_match('/(.*(?:ecdsa-sha2-nistp256|ecdsa-sha2-nistp384|ecdsa-sha2-nistp521|ssh-dss|ssh-rsa) *[^ ]*) *(.*)/', $keyLine, $matches)) {
                $keys[] = array('publicKey' => $matches[1], 'comment' => $matches[2]);
            }
        }

        return $keys;
    }

    /**
     * @Route("/config/repositorybackupscript/manage", name="configureRepositoryBackupScript")
     * @Template()
     */
    public function configureRepositoryBackupScriptAction(Request $request)
    {
        $t = $this->get('translator');
        $authorizedKeysFile = dirname($this->container->getParameter('public_key')) . '/authorized_keys';
        $keys = $this->readKeyFileAsCommentAndRest($authorizedKeysFile);
        $formBuilder = $this->createFormBuilder(array('publicKeys' => $keys));
        $formBuilder->add('publicKeys', 'collection',
                          array('type'         => new AuthorizedKeyType($t),
                                'allow_add'    => true,
                                'allow_delete' => true,
                                'options'      => array('required' => false,
                                                        'attr'     => array('class' => 'span10'))));
        $form = $formBuilder->getForm();
        if ($request->isMethod('POST')) {
            $form->bind($request);
            $data = $form->getData();
            $serializedKeys = '';
            foreach ($data['publicKeys'] as $key) {
                $serializedKeys .= sprintf("%s %s\n", $key['publicKey'], $key['comment']);
            }
            $manager = $this->getDoctrine()->getManager();
            $msg = new Message('DefaultController', 'TickCommand',
                               json_encode(array('command' => "elkarbackup:update_authorized_keys",
                                                 'content'  => $serializedKeys)));
            $manager->persist($msg);
            $this->info('Updating key file %keys%',
                        array('%keys%' => $serializedKeys));
            $manager->flush();
            $this->get('session')->getFlashBag()->add('backupScriptConfig',
                                                      $t->trans('Key file updated. The update should be effective in less than 2 minutes.',
                                                                array(),
                                                                'BinovoElkarBackup'));
            $result = $this->redirect($this->generateUrl('configureRepositoryBackupScript'));
        } else {
            $result = $this->render('BinovoElkarBackupBundle:Default:backupscriptconfig.html.twig',
                                    array('form'            => $form->createView()));
        }

        return $result;
    }

    /**
     * @Route("/config/backupslocation", name="manageBackupsLocation")
     * @Template()
     */
    public function manageBackupsLocationAction(Request $request)
    {
        $t = $this->get('translator');
        $backupDir = $this->container->getParameter('backup_dir');
        $hostAndDir = array();
        if (preg_match('/^\/net\/([^\/]+)(\/.*)$/', $backupDir, $hostAndDir)) {
            $data = array('host'      => $hostAndDir[1],
                          'directory' => $hostAndDir[2]);
        } else {
            $data = array('host'      => '',
                          'directory' => $backupDir);
        }
        $formBuilder = $this->createFormBuilder($data);
        $formBuilder->add('host'      , 'text'  , array('required' => false,
                                                        'label'    => $t->trans('Host', array(), 'BinovoElkarBackup'),
                                                        'attr'     => array('class'    => 'span12'),
                                                        'disabled' => !$this->isAutoFsAvailable()));
        $formBuilder->add('directory' , 'text'  , array('required' => false,
                                                        'label'    => $t->trans('Directory', array(), 'BinovoElkarBackup'),
                                                        'attr'     => array('class' => 'span12')));
        $result = null;
        $form = $formBuilder->getForm();
        if ($request->isMethod('POST')) {
            $form->bind($request);
            $data = $form->getData();
            if ('' != $data['host']) {
                $backupDir = sprintf('/net/%s%s', $data['host'], $data['directory']);
            } else {
                $backupDir = $data['directory'];
            }
            $ok = true;
            if ($this->container->getParameter('backup_dir') != $backupDir) {
                if (!$this->setParameter('backup_dir', $backupDir)) {
                    $this->get('session')->getFlashBag()->add('manageParameters',
                                                              $t->trans('Parameters updated',
                                                                        array(),
                                                                        'BinovoElkarBackup'));
                }
            }
            $result = $this->redirect($this->generateUrl('manageBackupsLocation'));
        } else {
            $result = $this->render('BinovoElkarBackupBundle:Default:backupslocation.html.twig',
                                    array('form' => $form->createView()));
        }
        $this->getDoctrine()->getManager()->flush();
        $this->clearCache();

        return $result;
    }
    /**
     * @Route("/config/params", name="manageParameters")
     * @Template()
     */
    public function manageParametersAction(Request $request)
    {
        $t = $this->get('translator');
        $params = array('database_host'             => array('type' => 'text'    , 'required' => false, 'attr' => array('class' => 'span12'), 'label' => $t->trans('MySQL host'            , array(), 'BinovoElkarBackup')),
                        'database_port'             => array('type' => 'text'    , 'required' => false, 'attr' => array('class' => 'span12'), 'label' => $t->trans('MySQL port'            , array(), 'BinovoElkarBackup')),
                        'database_name'             => array('type' => 'text'    , 'required' => false, 'attr' => array('class' => 'span12'), 'label' => $t->trans('MySQL DB name'         , array(), 'BinovoElkarBackup')),
                        'database_user'             => array('type' => 'text'    , 'required' => false, 'attr' => array('class' => 'span12'), 'label' => $t->trans('MySQL user'            , array(), 'BinovoElkarBackup')),
                        'database_password'         => array('type' => 'password', 'required' => false, 'attr' => array('class' => 'span12'), 'label' => $t->trans('MySQL password'        , array(), 'BinovoElkarBackup')),
                        'mailer_transport'          => array('type' => 'choice'  , 'required' => false, 'attr' => array('class' => 'span12'), 'choices' => array('gmail'    => 'gmail',
                                                                                                                                                                 'mail'     => 'mail',
                                                                                                                                                                 'sendmail' => 'sendmail',
                                                                                                                                                                 'smtp'     => 'smtp'),
                                                             'label' => $t->trans('Mailer transpor'       , array(), 'BinovoElkarBackup')),
                        'mailer_host'               => array('type' => 'text'    , 'required' => false, 'attr' => array('class' => 'span12'), 'label' => $t->trans('Mailer host'           , array(), 'BinovoElkarBackup')),
                        'mailer_user'               => array('type' => 'text'    , 'required' => false, 'attr' => array('class' => 'span12'), 'label' => $t->trans('Mailer user'           , array(), 'BinovoElkarBackup')),
                        'mailer_password'           => array('type' => 'text'    , 'required' => false, 'attr' => array('class' => 'span12'), 'label' => $t->trans('Mailer password'       , array(), 'BinovoElkarBackup')),
                        'backup_dir'                => array('type' => 'text'    , 'required' => false, 'attr' => array('class' => 'span12'), 'label' => $t->trans('Backups dir'           , array(), 'BinovoElkarBackup')),
                        'max_log_age'               => array('type' => 'choice'  , 'required' => false, 'attr' => array('class' => 'span12'), 'choices' => array('P1D' => $t->trans('One day'    , array(), 'BinovoElkarBackup'),
                                                                                                                                                                 'P1W' => $t->trans('One week'   , array(), 'BinovoElkarBackup'),
                                                                                                                                                                 'P2W' => $t->trans('Two weeks'  , array(), 'BinovoElkarBackup'),
                                                                                                                                                                 'P3W' => $t->trans('Three weeks', array(), 'BinovoElkarBackup'),
                                                                                                                                                                 'P1M' => $t->trans('A month'    , array(), 'BinovoElkarBackup'),
                                                                                                                                                                 'P6M' => $t->trans('Six months' , array(), 'BinovoElkarBackup'),
                                                                                                                                                                 'P1Y' => $t->trans('A year'     , array(), 'BinovoElkarBackup'),
                                                                                                                                                                 'P2Y' => $t->trans('Two years'  , array(), 'BinovoElkarBackup'),
                                                                                                                                                                 'P3Y' => $t->trans('Three years', array(), 'BinovoElkarBackup'),
                                                                                                                                                                 'P4Y' => $t->trans('Four years' , array(), 'BinovoElkarBackup'),
                                                                                                                                                                 'P5Y' => $t->trans('Five years' , array(), 'BinovoElkarBackup'),
                                                                                                                                                                 ''    => $t->trans('Never'      , array(), 'BinovoElkarBackup')),
                                                             'label' => $t->trans('Remove logs older than', array(), 'BinovoElkarBackup')),
                        'warning_load_level'        => array('type' => 'percent' , 'required' => false, 'attr' => array('class' => 'span11'), 'label' => $t->trans('Quota warning level'   , array(), 'BinovoElkarBackup')),
                        'pagination_lines_per_page' => array('type' => 'integer' , 'required' => false, 'attr' => array('class' => 'span12'), 'label' => $t->trans('Records per page'   , array(), 'BinovoElkarBackup')),
            );
        $defaultData = array();
        foreach ($params as $paramName => $formField) {
            if ('password' != $formField['type']) {
                $defaultData[$paramName] = $this->container->getParameter($paramName);
            }
        }
        $formBuilder = $this->createFormBuilder($defaultData);
        foreach ($params as $paramName => $formField) {
            $formBuilder->add($paramName, $formField['type'], array_diff_key($formField, array('type' => true)));
        }
        $result = null;
        $form = $formBuilder->getForm();
        if ($request->isMethod('POST')) {
            $form->bind($request);
            $data = $form->getData();
            $allOk = true;
            foreach ($data as $paramName => $paramValue) {
                $ok = true;
                if ('password' == $params[$paramName]['type']) {
                    if (!empty($paramValue)) {
                        $ok = $this->setParameter($paramName, $paramValue);
                    }
                } else {
                    if ($paramValue != $this->container->getParameter($paramName)) {
                        $ok = $this->setParameter($paramName, $paramValue);
                    }
                }
                if (!$ok) {
                    $this->get('session')->getFlashBag()->add('manageParameters',
                                                              $t->trans('Error saving parameter "%param%"',
                                                                        array('%param%' => $params[$paramName]['label']),
                                                                        'BinovoElkarBackup'));
                    $allOk = false;
                }
            }
            if ($allOk) {
                $this->get('session')->getFlashBag()->add('manageParameters',
                                                          $t->trans('Parameters updated',
                                                                    array(),
                                                                    'BinovoElkarBackup'));
            }
            $result = $this->redirect($this->generateUrl('manageParameters'));
        } else {
            $result = $this->render('BinovoElkarBackupBundle:Default:params.html.twig',
                                    array('form'            => $form->createView(),
                                          'showKeyDownload' => file_exists($this->container->getParameter('public_key'))));
        }
        $this->getDoctrine()->getManager()->flush();
        $this->clearCache();

        return $result;
    }

    /**
     * Sets the value of a filed in the parameters.yml file to the given value
     */
    public function setParameter($name, $value)
    {
        $paramsFilename = dirname(__FILE__) . '/../../../../../app/config/parameters.yml';
        $paramsFile = file_get_contents($paramsFilename);
        if (false == $paramsFile) {
            return false;
        }
        $updated = preg_replace("/$name:.*/", "$name: $value", $paramsFile);
        $ok = file_put_contents($paramsFilename, $updated);
        if ($ok) {
            $this->info('Set Parameter %paramname%',
                        array('%paramname%' => $name),
                        array('link' => $this->generateUrl('showPolicies')));
        } else {
            $this->info('Set Parameter %paramname%',
                        array('%paramname%' => $name),
                        array('link' => $this->generateUrl('showPolicies')));
        }

        return $ok;
    }

    /**
     * @Route("/password", name="changePassword")
     * @Template()
     */
    public function changePasswordAction(Request $request)
    {
        $t = $this->get('translator');
        $defaultData = array();
        $form = $this->createFormBuilder($defaultData)
            ->add('oldPassword' , 'password', array('label' => $t->trans('Old password'        , array(), 'BinovoElkarBackup')))
            ->add('newPassword' , 'password', array('label' => $t->trans('New password'        , array(), 'BinovoElkarBackup')))
            ->add('newPassword2', 'password', array('label' => $t->trans('Confirm new password', array(), 'BinovoElkarBackup')))
            ->getForm();
        if ($request->isMethod('POST')) {
            $form->bind($request);
            $data = $form->getData();
            $user = $this->get('security.context')->getToken()->getUser();
            $encoder = $this->get('security.encoder_factory')->getEncoder($user);
            $ok = true;
            if (empty($data['newPassword']) || $data['newPassword'] !== $data['newPassword2']) {
                $ok = false;
                $this->get('session')->getFlashBag()->add('changePassword',
                                                          $t->trans("Passwords do not match", array(), 'BinovoElkarBackup'));
                $this->info('Change password for user %username% failed. Passwords do not match.',
                            array('%username%' => $user->getUsername()),
                            array('link' => $this->generateUserRoute($user->getId())));
            }
            if ($encoder->encodePassword($data['oldPassword'], $user->getSalt()) !== $user->getPassword()) {
                $ok = false;
                $this->get('session')->getFlashBag()->add('changePassword',
                                                          $t->trans("Wrong old password", array(), 'BinovoElkarBackup'));
                $this->info('Change password for user %username% failed. Wrong old password.',
                            array('%username%' => $user->getUsername()),
                            array('link' => $this->generateUserRoute($user->getId())));
            }
            if ($ok) {
                $user->setPassword($encoder->encodePassword($data['newPassword'], $user->getSalt()));
                $manager = $this->getDoctrine()->getManager();
                $manager->persist($user);
                $this->get('session')->getFlashBag()->add('changePassword',
                                                          $t->trans("Password changed", array(), 'BinovoElkarBackup'));
                $this->info('Change password for user %username%.',
                            array('%username%' => $user->getUsername()),
                            array('link' => $this->generateUserRoute($user->getId())));
            }
            $manager->flush();

            return $this->redirect($this->generateUrl('changePassword'));
        } else {

            return $this->render('BinovoElkarBackupBundle:Default:password.html.twig',
                                 array('form'    => $form->createView()));
        }
    }

    /**
     * @Route("/script/{id}/delete", name="deleteScript")
     * @Method("POST")
     * @Template()
     */
    public function deleteScriptAction(Request $request, $id)
    {
        $t = $this->get('translator');
        $db = $this->getDoctrine();
        $repository = $db->getRepository('BinovoElkarBackupBundle:Script');
        $manager = $db->getManager();
        $script = $repository->find($id);
        try{
            $manager->remove($script);
            $this->info('Delete script %scriptname%',
                        array('%scriptname%' => $script->getName()),
                        array('link' => $this->generateScriptRoute($id)));
            $manager->flush();
        } catch (PDOException $e) {
            $this->get('session')->getFlashBag()->add('showScripts',
                                                      $t->trans('Removing the script %name% failed. Check that it is not in use.', array('%name%' => $script->getName()), 'BinovoElkarBackup'));
        }

        return $this->redirect($this->generateUrl('showScripts'));
    }

    /**
     * @Route("/script/{id}", name="editScript")
     * @Method("GET")
     * @Template()
     */
    public function editScriptAction(Request $request, $id)
    {
        $t = $this->get('translator');
        if ('new' === $id) {
            $script = new Script();
        } else {
            $repository = $this->getDoctrine()
                ->getRepository('BinovoElkarBackupBundle:Script');
            $script = $repository->find($id);
        }
        $form = $this->createForm(new ScriptType(), $script, array('scriptFileRequired' => !$script->getScriptFileExists(),
                                                                   'translator' => $t));
        $this->info('View script %scriptname%.',
                    array('%scriptname%' => $script->getName()),
                    array('link' => $this->generateScriptRoute($id)));
        $this->getDoctrine()->getManager()->flush();

        return $this->render('BinovoElkarBackupBundle:Default:script.html.twig',
                             array('form' => $form->createView()));
    }

    /**
     * @Route("/script/{id}", requirements={"id" = "\d+"}, defaults={"id" = "-1"}, name="saveScript")
     * @Method("POST")
     * @Template()
     */
    public function saveScriptAction(Request $request, $id)
    {
        $t = $this->get('translator');
        if ("-1" === $id) {
            $script = new Script();
        } else {
            $repository = $this->getDoctrine()
                ->getRepository('BinovoElkarBackupBundle:Script');
            $script = $repository->find($id);
        }
        $form = $this->createForm(new ScriptType(), $script, array('scriptFileRequired' => !$script->getScriptFileExists(),
                                                                   'translator' => $t));
        $form->bind($request);
        $result = null;
        if ($form->isValid()) {
            if ("-1" == $id && null == $script->getScriptFile()) { // it is a new script but no file was uploaded
                $this->get('session')->getFlashBag()->add('editScript',
                                                          $t->trans('Uploading a script is mandatory for script creation.',
                                                                    array(),
                                                                    'BinovoElkarBackup'));
            } else {
                $em = $this->getDoctrine()->getManager();
                $script->setLastUpdated(new DateTime()); // we to this to force the PostPersist script to run.
                $em->persist($script);
                $this->info('Save script %scriptname%.',
                            array('%scriptname%' => $script->getScriptname()),
                            array('link' => $this->generateScriptRoute($id)));
                $em->flush();
                $result = $this->redirect($this->generateScriptRoute($script->getId()));
            }
        }
        if (!$result) {
            $result = $this->render('BinovoElkarBackupBundle:Default:script.html.twig',
                                    array('form' => $form->createView()));
        }

        return $result;
    }

    /**
     * @Route("/user/{id}/delete", name="deleteUser")
     * @Method("POST")
     * @Template()
     */
    public function deleteUserAction(Request $request, $id)
    {
        if (User::SUPERUSER_ID != $id) {
            $db = $this->getDoctrine();
            $repository = $db->getRepository('BinovoElkarBackupBundle:User');
            $manager = $db->getManager();
            $user = $repository->find($id);
            $manager->remove($user);
            $this->info('Delete user %username%.',
                        array('%username%' => $user->getUsername()),
                        array('link' => $this->generateUserRoute($id)));
            $manager->flush();
        }

        return $this->redirect($this->generateUrl('showUsers'));
    }

    /**
     * @Route("/user/{id}", name="editUser")
     * @Method("GET")
     * @Template()
     */
    public function editUserAction(Request $request, $id)
    {
        $t = $this->get('translator');
        if ('new' === $id) {
            $user = new User();
        } else {
            $repository = $this->getDoctrine()
                ->getRepository('BinovoElkarBackupBundle:User');
            $user = $repository->find($id);
        }
        $form = $this->createForm(new UserType(), $user, array('translator' => $t));
        $this->info('View user %username%.',
                    array('%username%' => $user->getUsername()),
                    array('link' => $this->generateUserRoute($id)));
        $this->getDoctrine()->getManager()->flush();

        return $this->render('BinovoElkarBackupBundle:Default:user.html.twig',
                             array('form' => $form->createView()));
    }

    /**
     * @Route("/user/{id}", requirements={"id" = "\d+"}, defaults={"id" = "-1"}, name="saveUser")
     * @Method("POST")
     * @Template()
     */
    public function saveUserAction(Request $request, $id)
    {
        $t = $this->get('translator');
        if ("-1" === $id) {
            $user = new User();
        } else {
            $repository = $this->getDoctrine()
                ->getRepository('BinovoElkarBackupBundle:User');
            $user = $repository->find($id);
        }
        $form = $this->createForm(new UserType(), $user, array('translator' => $t));
        $form->bind($request);
        if ($form->isValid()) {
            if ($user->newPassword) {
                $factory = $this->get('security.encoder_factory');
                $encoder = $factory->getEncoder($user);
                $password = $encoder->encodePassword($user->newPassword, $user->getSalt());
                $user->setPassword($password);
            }
            $em = $this->getDoctrine()->getManager();
            $em->persist($user);
            $this->info('Save user %username%.',
                        array('%username%' => $user->getUsername()),
                        array('link' => $this->generateUserRoute($id)));
            $em->flush();

            return $this->redirect($this->generateUserRoute($user->getId()));
        } else {

            return $this->render('BinovoElkarBackupBundle:Default:user.html.twig',
                                 array('form' => $form->createView()));
        }
    }

    /**
     * @Route("/setlocale/{locale}", name="setLocale")
     */
    public function setLanguage(Request $request, $locale)
    {
        $this->get('session')->set('_locale', $locale);
        $referer = $request->headers->get('referer');

        return $this->redirect($referer);
    }

    /**
     * @Route("/users", name="showUsers")
     * @Template()
     */
    public function showUsersAction(Request $request)
    {
        $repository = $this->getDoctrine()
            ->getRepository('BinovoElkarBackupBundle:User');
        $query = $repository->createQueryBuilder('c')
            ->getQuery();

        $paginator = $this->get('knp_paginator');
        $pagination = $paginator->paginate(
            $query,
            $request->query->get('page', 1)/*page number*/,
            $request->get('lines', $this->container->getParameter('pagination_lines_per_page'))
            );
        $this->info('View users',
                    array(),
                    array('link' => $this->generateUrl('showUsers')));
        $this->getDoctrine()->getManager()->flush();

        return $this->render('BinovoElkarBackupBundle:Default:users.html.twig',
                             array('pagination' => $pagination));
    }
}