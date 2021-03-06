<?php
App::uses('Folder', 'Utility');
App::uses('File', 'Utility');
require_once 'AppShell.php';
class EventShell extends AppShell
{
    public $uses = array('Event', 'Post', 'Attribute', 'Job', 'User', 'Task', 'Whitelist', 'Server', 'Organisation');
    public $tasks = array('ConfigLoad');

    public function doPublish()
    {
        $this->ConfigLoad->execute();
        $id = $this->args[0];
        $this->Event->id = $id;
        if (!$this->Event->exists()) {
            throw new NotFoundException(__('Invalid event'));
        }
        $this->Job->create();
        $data = array(
            'worker' => 'default',
            'job_type' => 'doPublish',
            'job_input' => $id,
            'status' => 0,
            'retries' => 0,
            //'org' => $jobOrg,
            'message' => 'Job created.',
        );
        $this->Job->save($data);
        // update the event and set the from field to the current instance's organisation from the bootstrap. We also need to save id and info for the logs.
        $this->Event->recursive = -1;
        $event = $this->Event->read(null, $id);
        $event['Event']['published'] = 1;
        $fieldList = array('published', 'id', 'info');
        $this->Event->save($event, array('fieldList' => $fieldList));
        // only allow form submit CSRF protection.
        $this->Job->saveField('status', 1);
        $this->Job->saveField('message', 'Job done.');
    }

    public function cache()
    {
        $this->ConfigLoad->execute();
        $timeStart = time();
        $userId = $this->args[0];
        $id = $this->args[1];
        $user = $this->User->getAuthUser($userId);
        $this->Job->id = $id;
        $export_type = $this->args[2];
        file_put_contents('/tmp/test', $export_type);
        $typeData = $this->Event->export_types[$export_type];
        if (!in_array($export_type, array_keys($this->Event->export_types))) {
            $this->Job->saveField('progress', 100);
            $timeDelta = (time()-$timeStart);
            $this->Job->saveField('message', 'Job Failed due to invalid export format. (in '.$timeDelta.'s)');
            $this->Job->saveField('date_modified', date("Y-m-d H:i:s"));
            return false;
        }
        if ($export_type == 'text') {
            $types = array_keys($this->Attribute->typeDefinitions);
            $typeCount = count($types);
            foreach ($types as $k => $type) {
                $typeData['params']['type'] = $type;
                $this->__runCaching($user, $typeData, false, $export_type, '_' . $type);
                $this->Job->saveField('message', 'Processing all attributes of type '. $type . '.');
                $this->Job->saveField('progress', intval($k / $typeCount));
            }
        } else {
            $this->__runCaching($user, $typeData, $id, $export_type);
        }
        $this->Job->saveField('progress', 100);
        $timeDelta = (time()-$timeStart);
        $this->Job->saveField('message', 'Job done. (in '.$timeDelta.'s)');
        $this->Job->saveField('date_modified', date("Y-m-d H:i:s"));
    }

    private function __runCaching($user, $typeData, $id, $export_type, $subType = '')
    {
        $this->ConfigLoad->execute();
        $export_type = strtolower($typeData['type']);
        $final = $this->{$typeData['scope']}->restSearch($user, $typeData['params']['returnFormat'], $typeData['params'], false, $id);
        $dir = new Folder(APP . 'tmp/cached_exports/' . $export_type, true, 0750);
        //echo PHP_EOL . $dir->pwd() . DS . 'misp.' . $export_type . $subType . '.ADMIN' . $typeData['extension'] . PHP_EOL;
        if ($user['Role']['perm_site_admin']) {
            $file = new File($dir->pwd() . DS . 'misp.' . $export_type . $subType . '.ADMIN' . $typeData['extension']);
        } else {
            $file = new File($dir->pwd() . DS . 'misp.' . $export_type . $subType . '.' . $user['Organisation']['name'] .  $typeData['extension']);
        }
        $file->write($final);
        $file->close();
        return true;
    }

    public function cachebro()
    {
        $this->ConfigLoad->execute();
        $timeStart = time();
        $userId = $this->args[0];
        $user = $this->User->getAuthUser($userId);
        $id = $this->args[1];
        $this->Job->id = $id;
        $this->Job->saveField('progress', 1);
        App::uses('BroExport', 'Export');
        $export = new BroExport();
        $types = array_keys($export->mispTypes);
        $typeCount = count($types);
        $dir = new Folder(APP . DS . '/tmp/cached_exports/bro', true, 0750);
        if ($user['Role']['perm_site_admin']) {
            $file = new File($dir->pwd() . DS . 'misp.bro.ADMIN.intel');
        } else {
            $file = new File($dir->pwd() . DS . 'misp.bro.' . $user['Organisation']['name'] . '.intel');
        }

        $file->write('');
        $skipHeader = false;
        foreach ($types as $k => $type) {
            $final = $this->Attribute->bro($user, $type, false, false, false, false, false, false, $skipHeader);
            $skipHeader = true;
            foreach ($final as $attribute) {
                $file->append($attribute . PHP_EOL);
            }
            $this->Job->saveField('progress', $k / $typeCount * 100);
        }
        $file->close();
        $timeDelta = (time()-$timeStart);
        $this->Job->saveField('progress', 100);
        $this->Job->saveField('message', 'Job done. (in '.$timeDelta.'s)');
        $this->Job->saveField('date_modified', date("Y-m-d H:i:s"));
    }

    public function alertemail()
    {
        $this->ConfigLoad->execute();
        $userId = $this->args[0];
        $processId = $this->args[1];
        $job = $this->Job->read(null, $processId);
        $eventId = $this->args[2];
        $oldpublish = $this->args[3];
        $user = $this->User->getAuthUser($userId);
        $result = $this->Event->sendAlertEmail($eventId, $user, $oldpublish, $processId);
        $job['Job']['progress'] = 100;
        $job['Job']['message'] = 'Emails sent.';
        //$job['Job']['date_modified'] = date("Y-m-d H:i:s");
        $this->Job->save($job);
    }

    public function contactemail()
    {
        $this->ConfigLoad->execute();
        $id = $this->args[0];
        $message = $this->args[1];
        $all = $this->args[2];
        $userId = $this->args[3];
        $isSiteAdmin = $this->args[4];
        $processId = $this->args[5];
        $this->Job->id = $processId;
        $user = $this->User->getAuthUser($userId);
        $result = $this->Event->sendContactEmail($id, $message, $all, array('User' => $user), $isSiteAdmin);
        $this->Job->saveField('progress', '100');
        $this->Job->saveField('date_modified', date("Y-m-d H:i:s"));
        if ($result != true) $this->Job->saveField('message', 'Job done.');
    }

    public function postsemail()
    {
        $this->ConfigLoad->execute();
        $userId = $this->args[0];
        $postId = $this->args[1];
        $eventId = $this->args[2];
        $title = $this->args[3];
        $message = $this->args[4];
        $processId = $this->args[5];
        $this->Job->id = $processId;
        $result = $this->Post->sendPostsEmail($userId, $postId, $eventId, $title, $message);
        $job['Job']['progress'] = 100;
        $job['Job']['message'] = 'Emails sent.';
        $job['Job']['date_modified'] = date("Y-m-d H:i:s");
        $this->Job->save($job);
    }

    public function enqueueCaching()
    {
        $this->ConfigLoad->execute();
        $timestamp = $this->args[0];
        $task = $this->Task->findByType('cache_exports');

        // If the next execution time and the timestamp don't match, it means that this task is no longer valid as the time for the execution has since being scheduled
        // been updated.
        if ($task['Task']['next_execution_time'] != $timestamp) return;

        $users = $this->User->find('all', array(
                'recursive' => -1,
                'conditions' => array(
                        'Role.perm_site_admin' => 0,
                        'User.disabled' => 0,
                ),
                'contain' => array(
                        'Organisation' => array('fields' => array('name')),
                        'Role' => array('fields' => array('perm_site_admin'))
                ),
                'fields' => array('User.org_id', 'User.id'),
                'group' => array('User.org_id')
        ));
        $site_admin = $this->User->find('first', array(
                'recursive' => -1,
                'conditions' => array(
                        'Role.perm_site_admin' => 1,
                        'User.disabled' => 0
                ),
                'contain' => array(
                        'Organisation' => array('fields' => array('name')),
                        'Role' => array('fields' => array('perm_site_admin'))
                ),
                'fields' => array('User.org_id', 'User.id')
        ));
        $users[] = $site_admin;

        if ($task['Task']['timer'] > 0)    $this->Task->reQueue($task, 'cache', 'EventShell', 'enqueueCaching', false, false);

        // Queue a set of exports for admins. This "ADMIN" organisation. The organisation of the admin users doesn't actually matter, it is only used to indentify
        // the special cache files containing all events
        $i = 0;
        foreach ($users as $user) {
            foreach ($this->Event->export_types as $k => $type) {
                if ($k == 'stix') continue;
                $this->Job->cache($k, $user['User']);
                $i++;
            }
        }
        $this->Task->id = $task['Task']['id'];
        $this->Task->saveField('message', $i . ' job(s) started at ' . date('d/m/Y - H:i:s') . '.');
    }

    public function publish()
    {
        $this->ConfigLoad->execute();
        $id = $this->args[0];
        $passAlong = $this->args[1];
        $jobId = $this->args[2];
        $userId = $this->args[3];
        $user = $this->User->getAuthUser($userId);
        $job = $this->Job->read(null, $jobId);
        $this->Event->Behaviors->unload('SysLogLogable.SysLogLogable');
        $result = $this->Event->publish($id, $passAlong);
        $job['Job']['progress'] = 100;
        $job['Job']['date_modified'] = date("Y-m-d H:i:s");
        if ($result) {
            $job['Job']['message'] = 'Event published.';
        } else {
            $job['Job']['message'] = 'Event published, but the upload to other instances may have failed.';
        }
        $this->Job->save($job);
        $log = ClassRegistry::init('Log');
        $log->create();
        $log->createLogEntry($user, 'publish', 'Event', $id, 'Event (' . $id . '): published.', 'published () => (1)');
    }

    public function publish_sightings()
    {
        $this->ConfigLoad->execute();
        $id = $this->args[0];
        $passAlong = $this->args[1];
        $jobId = $this->args[2];
        $userId = $this->args[3];
        $user = $this->User->getAuthUser($userId);
        $job = $this->Job->read(null, $jobId);
        $this->Event->Behaviors->unload('SysLogLogable.SysLogLogable');
        $result = $this->Event->publish_sightings($id, $passAlong);
        $job['Job']['progress'] = 100;
        $job['Job']['date_modified'] = date("Y-m-d H:i:s");
        if ($result) {
            $job['Job']['message'] = 'Sightings published.';
        } else {
            $job['Job']['message'] = 'Sightings published, but the upload to other instances may have failed.';
        }
        $this->Job->save($job);
        $log = ClassRegistry::init('Log');
        $log->create();
        $log->createLogEntry($user, 'publish_sightings', 'Event', $id, 'Sightings for event (' . $id . '): published.', 'publish_sightings updated');
    }

    public function enrichment()
    {
        $this->ConfigLoad->execute();
        if (empty($this->args[0]) || empty($this->args[1]) || empty($this->args[2])) {
            die('Usage: ' . $this->Server->command_line_functions['enrichment'] . PHP_EOL);
        }
        $userId = $this->args[0];
        $user = $this->User->getAuthUser($userId);
        if (empty($user)) die('Invalid user.');
        $eventId = $this->args[1];
        $modulesRaw = $this->args[2];
        try {
            $modules = json_decode($modulesRaw, true);
        } catch (Exception $e) {
            die('Invalid module JSON');
        }
        if (!empty($this->args[3])) {
            $jobId = $this->args[3];
        } else {
            $this->Job->create();
            $data = array(
                    'worker' => 'default',
                    'job_type' => 'enrichment',
                    'job_input' => 'Event: ' . $eventId . ' modules: ' . $modulesRaw,
                    'status' => 0,
                    'retries' => 0,
                    'org' => $user['Organisation']['name'],
                    'message' => 'Enriching event.',
            );
            $this->Job->save($data);
            $jobId = $this->Job->id;
        }
        $job = $this->Job->read(null, $jobId);
        $options = array(
            'user' => $user,
            'event_id' => $eventId,
            'modules' => $modules
        );
        $result = $this->Event->enrichment($options);
        $job['Job']['progress'] = 100;
        $job['Job']['date_modified'] = date("Y-m-d H:i:s");
        if ($result) {
            $job['Job']['message'] = 'Added ' . $result . ' attribute' . ($result > 1 ? 's.' : '.');
        } else {
            $job['Job']['message'] = 'Enrichment finished, but no attributes added.';
        }
        echo $job['Job']['message'] . PHP_EOL;
        $this->Job->save($job);
        $log = ClassRegistry::init('Log');
        $log->create();
        $log->createLogEntry($user, 'enrichment', 'Event', $eventId, 'Event (' . $eventId . '): enriched.', 'enriched () => (1)');
    }

    public function processfreetext()
    {
        $this->ConfigLoad->execute();
        $inputFile = $this->args[0];
        $tempdir = new Folder(APP . 'tmp/cache/ingest', true, 0750);
        $tempFile = new File(APP . 'tmp/cache/ingest' . DS . $inputFile);
        $inputData = $tempFile->read();
        $inputData = json_decode($inputData, true);
        $tempFile->delete();
        $this->Event->processFreeTextData(
            $inputData['user'],
            $inputData['attributes'],
            $inputData['id'],
            $inputData['default_comment'],
            $inputData['force'],
            $inputData['adhereToWarninglists'],
            $inputData['jobId']
        );
        return true;
    }

    public function processmoduleresult()
    {
        $this->ConfigLoad->execute();
        $inputFile = $this->args[0];
        $tempDir = new Folder(APP . 'tmp/cache/ingest', true, 0750);
        $tempFile = new File(APP . 'tmp/cache/ingest' . DS . $inputFile);
        $inputData = json_decode($tempFile->read(), true);
        $tempFile->delete();
        $this->Event->processModuleResultsData(
            $inputData['user'],
            $inputData['misp_format'],
            $inputData['id'],
            $inputData['default_comment'],
            $inputData['jobId']
        );
        return true;
    }
}
