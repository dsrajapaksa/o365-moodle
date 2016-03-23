<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package local_o365
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Microsoft Open Technologies, Inc. (http://msopentech.com/)
 */

namespace local_o365\page;

/**
 * Admin control panel page.
 */
class acp extends base {

    /**
     * Add base navbar for this page.
     */
    protected function add_navbar() {
        global $PAGE;
        $PAGE->navbar->add($this->title, new \moodle_url('/admin/settings.php?section=local_o365'));
    }

    /**
     * Set the system API user.
     */
    public function mode_setsystemuser() {
        global $SESSION;
        $SESSION->auth_oidc_justevent = true;
        redirect(new \moodle_url('/auth/oidc/index.php', ['promptaconsent' => 1]));
    }

    /**
     * Perform health checks.
     */
    public function mode_healthcheck() {
        $this->standard_header();

        echo \html_writer::tag('h2', get_string('acp_healthcheck', 'local_o365'));
        echo '<br />';

        $healthchecks = ['systemapiuser'];
        foreach ($healthchecks as $healthcheck) {
            $healthcheckclass = '\local_o365\healthcheck\\'.$healthcheck;
            $healthcheck = new $healthcheckclass();
            $result = $healthcheck->run();

            echo '<h5>'.$healthcheck->get_name().'</h5>';
            if ($result['result'] === true) {
                echo '<div class="alert alert-success">'.$result['message'].'</div>';
            } else {
                echo '<div class="alert alert-error">';
                echo $result['message'];
                if (isset($result['fixlink'])) {
                    echo '<br /><br />'.\html_writer::link($result['fixlink'], get_string('healthcheck_fixlink', 'local_o365'));
                }
                echo '</div>';
            }
        }

        $this->standard_footer();
    }

    /**
     * Clear items from the match queue.
     */
    public function mode_usermatchclear() {
        global $DB;
        $type = optional_param('type', null, PARAM_TEXT);
        $return = ['success' => false];
        switch ($type) {
            case 'success':
                $DB->delete_records_select('local_o365_matchqueue', 'completed = "1" AND errormessage = ""');
                $return = ['success' => true];
                break;

            case 'error':
                $DB->delete_records_select('local_o365_matchqueue', 'completed = "1" AND errormessage != ""');
                $return = ['success' => true];
                break;

            case 'queued':
                $DB->delete_records_select('local_o365_matchqueue', 'completed = "0"');
                $return = ['success' => true];
                break;

            case 'all':
                $DB->delete_records('local_o365_matchqueue');
                $return = ['success' => true];
                break;

            default:
                $return = ['success' => false];
        }
        echo json_encode($return);
        die();
    }

    /**
     * User match tool.
     */
    public function mode_usermatch() {
        global $DB, $OUTPUT, $PAGE, $SESSION;

        $errors = [];
        $mform = new \local_o365\form\usermatch('?mode=usermatch');
        if ($fromform = $mform->get_data()) {
            $datafile = $mform->save_temp_file('matchdatafile');
            if (!empty($datafile)) {
                $finfo = new \finfo();
                $type = $finfo->file($datafile, FILEINFO_MIME);
                $type = explode(';', $type);
                if (strtolower($type[0]) === 'text/plain') {
                    try {
                        $fh = fopen($datafile, 'r');
                        if (!empty($fh)) {
                            $row = 1;
                            while (($data = fgetcsv($fh)) !== false) {
                                if (!empty($data)) {
                                    if (isset($data[0]) && isset($data[1])) {
                                        $newrec = new \stdClass;
                                        $newrec->musername = trim($data[0]);
                                        $newrec->o365username = trim($data[1]);
                                        $newrec->completed = 0;
                                        $newrec->errormessage = '';
                                        $DB->insert_record('local_o365_matchqueue', $newrec);
                                    } else {
                                        $errors[] = get_string('acp_usermatch_upload_err_data', 'local_o365', $row);
                                    }
                                }
                                $row++;
                            }
                            fclose($fh);
                        } else {
                            $errors[] = get_string('acp_usermatch_upload_err_fileopen', 'local_o365');
                        }
                    } catch (\Exception $e) {
                        $errors[] = $e->getMessage();
                    }
                } else {
                    $errors[] = get_string('acp_usermatch_upload_err_badmime', 'local_o365', $type[0]);
                }
                @unlink($datafile);
                $mform->set_data([]);
            } else {
                $errors[] = get_string('acp_usermatch_upload_err_nofile', 'local_o365');
            }
            if (!empty($errors)) {
                $SESSION->o365matcherrors = $errors;
            }
            redirect(new \moodle_url('/local/o365/acp.php', ['mode' => 'usermatch']));
            die();
        }

        $PAGE->requires->jquery();
        $this->standard_header();
        echo \html_writer::tag('h2', get_string('acp_usermatch', 'local_o365'));
        echo \html_writer::div(get_string('acp_usermatch_desc', 'local_o365'));
        echo \html_writer::empty_tag('br');
        echo \html_writer::empty_tag('br');
        echo \html_writer::tag('h4', get_string('acp_usermatch_upload', 'local_o365'));
        echo \html_writer::div(get_string('acp_usermatch_upload_desc', 'local_o365'));
        echo \html_writer::empty_tag('br');
        if (!empty($SESSION->o365matcherrors)) {
            foreach ($SESSION->o365matcherrors as $error) {
                echo \html_writer::div($error, 'alert-error alert local_o365_statusmessage');
            }
            $SESSION->o365matcherrors = [];
        }
        $mform->display();

        echo \html_writer::empty_tag('br');
        echo \html_writer::tag('h4', get_string('acp_usermatch_matchqueue', 'local_o365'));
        echo \html_writer::div(get_string('acp_usermatch_matchqueue_desc', 'local_o365'));
        $matchqueuelength = $DB->count_records('local_o365_matchqueue');
        if ($matchqueuelength > 0) {

            echo \html_writer::start_tag('div', ['class' => 'matchqueuetoolbar']);

            $clearurl = new \moodle_url('/local/o365/acp.php', ['mode' => 'usermatchclear']);
            $clearurl = $clearurl->out();

            // Clear successful button.
            $checkicon = $OUTPUT->pix_icon('t/check', 'success', 'moodle');
            $clearcallback = '$(\'table.matchqueue\').find(\'tr.success\').fadeOut();';
            $attrs = ['onclick' => '$.post(\''.$clearurl.'\', {type:\'success\'}, function(data) { '.$clearcallback.' })'];
            $buttontext = get_string('acp_usermatch_matchqueue_clearsuccess', 'local_o365');
            echo \html_writer::tag('button', $checkicon.' '.$buttontext, $attrs);

            // Clear error button.
            $warningicon = $OUTPUT->pix_icon('i/warning', 'warning', 'moodle');
            $clearcallback = '$(\'table.matchqueue\').find(\'tr.error\').fadeOut();';
            $attrs = ['onclick' => '$.post(\''.$clearurl.'\', {type:\'error\'}, function(data) { '.$clearcallback.' })'];
            $buttontext = get_string('acp_usermatch_matchqueue_clearerrors', 'local_o365');
            echo \html_writer::tag('button', $warningicon.' '.$buttontext, $attrs);

            // Clear warning button.
            $queuedicon = $OUTPUT->pix_icon('i/scheduled', 'warning', 'moodle');
            $clearcallback = '$(\'table.matchqueue\').find(\'tr.queued\').fadeOut();';
            $attrs = ['onclick' => '$.post(\''.$clearurl.'\', {type:\'queued\'}, function(data) { '.$clearcallback.' })'];
            $buttontext = get_string('acp_usermatch_matchqueue_clearqueued', 'local_o365');
            echo \html_writer::tag('button', $queuedicon.' '.$buttontext, $attrs);

            // Clear all button.
            $removeicon = $OUTPUT->pix_icon('t/delete', 'warning', 'moodle');
            $clearcallback = '$(\'table.matchqueue\').find(\'tr:not(:first-child)\').fadeOut();';
            $attrs = ['onclick' => '$.post(\''.$clearurl.'\', {type:\'all\'}, function(data) { '.$clearcallback.' })'];
            $buttontext = get_string('acp_usermatch_matchqueue_clearall', 'local_o365');
            echo \html_writer::tag('button', $removeicon.' '.$buttontext, $attrs);

            echo \html_writer::end_tag('div');

            $matchqueue = $DB->get_recordset('local_o365_matchqueue', null, 'id ASC');
            // Constructing table manually instead of \html_table for memory reasons.
            echo \html_writer::start_tag('table', ['class' => 'matchqueue']);
            echo \html_writer::start_tag('tr');
            echo \html_writer::tag('th', '');
            echo \html_writer::tag('th', get_string('acp_usermatch_matchqueue_column_muser', 'local_o365'));
                echo \html_writer::tag('th', get_string('acp_usermatch_matchqueue_column_o365user', 'local_o365'));
                echo \html_writer::tag('th', get_string('acp_usermatch_matchqueue_column_status', 'local_o365'));
            echo \html_writer::end_tag('tr');
            foreach ($matchqueue as $queuerec) {
                $status = 'queued';
                $trclass = 'alert-info queued';
                if (!empty($queuerec->completed) && empty($queuerec->errormessage)) {
                    $status = 'success';
                    $trclass = 'alert-success success';
                } else if (!empty($queuerec->errormessage)) {
                    $status = 'error';
                    $trclass = 'alert-error error';
                }

                echo \html_writer::start_tag('tr', ['class' => $trclass]);

                switch ($status) {
                    case 'success':
                        echo \html_writer::tag('td', $checkicon);
                        break;

                    case 'error':
                        echo \html_writer::tag('td', $warningicon);
                        break;

                    default:
                        echo \html_writer::tag('td', $queuedicon);
                }

                echo \html_writer::tag('td', $queuerec->musername);
                echo \html_writer::tag('td', $queuerec->o365username);

                switch ($status) {
                    case 'success':
                        echo \html_writer::tag('td', get_string('acp_usermatch_matchqueue_status_success', 'local_o365'));
                        break;

                    case 'error':
                        $statusstr = get_string('acp_usermatch_matchqueue_status_error', 'local_o365', $queuerec->errormessage);
                        echo \html_writer::tag('td', $statusstr);
                        break;

                    default:
                        echo \html_writer::tag('td', get_string('acp_usermatch_matchqueue_status_queued', 'local_o365'));
                }
                echo \html_writer::end_tag('tr');
            }
            echo \html_writer::end_tag('table');
            $matchqueue->close();
        } else {
            $msgclasses = 'alert-info alert local_o365_statusmessage';
            echo \html_writer::div(get_string('acp_usermatch_matchqueue_empty', 'local_o365'), $msgclasses);
        }
        $this->standard_footer();
    }

    /**
     * Resync course usergroup membership.
     */
    public function mode_maintenance_coursegroupusers() {
        global $DB;
        $courseid = optional_param('courseid', 0, PARAM_INT);
        \core_php_time_limit::raise(0);
        raise_memory_limit(MEMORY_EXTRA);
        disable_output_buffering();

        $httpclient = new \local_o365\httpclient();
        $clientdata = \local_o365\oauth2\clientdata::instance_from_oidc();
        $graphresource = \local_o365\rest\unified::get_resource();
        $graphtoken = \local_o365\oauth2\systemtoken::instance(null, $graphresource, $clientdata, $httpclient);
        if (empty($graphtoken)) {
            mtrace('Could not get unified API token.');
            return true;
        }
        $graphclient = new \local_o365\rest\unified($graphtoken, $httpclient);
        $coursegroups = new \local_o365\feature\usergroups\coursegroups($graphclient, $DB, true);

        $sql = 'SELECT crs.id,
                       obj.objectid as groupobjectid
                  FROM {course} crs
                  JOIN {local_o365_objects} obj ON obj.type = ? AND obj.subtype = ? AND obj.moodleid = crs.id
                 WHERE crs.id != ?';
        $params = ['group', 'course', SITEID];
        if (!empty($courseid)) {
            $sql .= ' AND crs.id = ?';
            $params[] = $courseid;
        }
        $courses = $DB->get_recordset_sql($sql, $params);
        foreach ($courses as $course) {
            try {
                echo '<pre>';
                $coursegroups->resync_group_membership($course->id, $course->groupobjectid);
                echo '</pre>';
                mtrace(PHP_EOL);
            } catch (\Exception $e) {
                mtrace('Could not sync course '.$course->id.'. Reason: '.$e->getMessage());
            }
        }
        $courses->close();

        die();
    }

    public function mode_maintenance_debugdata() {
        global $CFG;

        $pluginmanager = \core_plugin_manager::instance();

        $plugins = [
            'auth_oidc' => [
                'authendpoint',
                'tokenendpoint',
                'oidcresource',
                'autoappend',
                'domainhint',
                'loginflow',
                'debugmode',
            ],
            'block_microsoft' => [
                'showo365download',
                'settings_showonenotenotebook',
                'settings_showoutlooksync',
                'settings_showpreferences',
                'settings_showo365connect',
                'settings_showmanageo365conection',
                'settings_showcoursespsite',
            ],
            'filter_oembed' => [
                'o365video',
                'officemix',
                'sway',
                'provider_docsdotcom_enabled',
            ],
            'local_microsoftservices' => [],
            'local_msaccount' => [],
            'local_o365' => [
                'aadsync',
                'aadtenant',
                'azuresetupresult',
                'chineseapi',
                'creategroups',
                'debugmode',
                'enableunifiedapi',
                'fieldmap',
                'odburl',
                'photoexpire',
                'usersynccreationrestriction',
                'sharepoint_initialized',
                'task_usersync_lastskiptoken',
                'unifiedapiactive',
            ],
            'local_office365' => [],
            'local_onenote' => [],
            'assignsubmission_onenote' => [],
            'assignfeedback_onenote' => [],
            'repository_office365' => [],
            'repository_onenote' => [],
        ];

        $configdata = [];

        $configdata['moodlecfg'] = [
            'dbtype' => $CFG->dbtype,
            'debug' => $CFG->debug,
            'debugdisplay' => $CFG->debugdisplay,
            'debugdeveloper' => $CFG->debugdeveloper,
            'auth' => $CFG->auth,
            'timezone' => $CFG->timezone,
            'forcetimezone' => $CFG->forcetimezone,
            'authpreventaccountcreation' => $CFG->authpreventaccountcreation,
            'alternateloginurl' => $CFG->alternateloginurl,
            'release' => $CFG->release,
            'version' => $CFG->version,
        ];

        $configdata['plugin_data'] = [];
        foreach ($plugins as $plugin => $settings) {
            $plugintype = substr($plugin, 0, strpos($plugin, '_'));
            $pluginsubtype = substr($plugin, strpos($plugin, '_') + 1);

            $plugindata = [];
            $plugincfg = get_config($plugin);

            $plugindata['version'] = (isset($plugincfg->version)) ? $plugincfg->version : 'null';

            $enabled = $pluginmanager->get_enabled_plugins($plugintype);
            $plugindata['enabled'] = (isset($enabled[$pluginsubtype])) ? 1 : 0;

            foreach ($settings as $setting) {
                $plugindata[$setting] = (isset($plugincfg->$setting)) ? $plugincfg->$setting : null;
            }

            $configdata['plugin_data'][$plugin] = $plugindata;
        }

        $azuredata = [];
        $httpclient = new \local_o365\httpclient();
        $aadresource = \local_o365\rest\azuread::get_resource();
        $clientdata = \local_o365\oauth2\clientdata::instance_from_oidc();
        $token = \local_o365\oauth2\systemtoken::instance(null, $aadresource, $clientdata, $httpclient);
        $aadapiclient = new \local_o365\rest\azuread($token, $httpclient);

        echo '<pre>';
        print_r($aadapiclient->get_application_info());
        die();
        list($missingperms, $haswrite) = $aadapiclient->check_permissions();
        $azuredata['legacy_setup'] = ['missingperms' => $missingperms];

        // Graph setup check.
        $graphresource = \local_o365\rest\unified::get_resource();
        $token = \local_o365\oauth2\systemtoken::instance(null, $graphresource, $clientdata, $httpclient);
        if (empty($token)) {
            throw new \moodle_exception('errorchecksystemapiuser', 'local_o365');
        }
        $graphapiclient = new \local_o365\rest\unified($token, $httpclient);
        $graphsetupresult = $graphapiclient->check_permissions();
        $azuredata['graph_setup'] = ($graphsetupresult !== null)
            ? ['active' => 1, 'missingperms' => $graphsetupresult] : ['active' => 0];

        $configdata['azure_data'] = $azuredata;

        echo '<pre>';
        print_r($configdata);
    }

    /**
     * Maintenance tools.
     */
    public function mode_maintenance() {
        global $DB, $OUTPUT, $PAGE, $SESSION;
        $PAGE->navbar->add(get_string('acp_maintenance', 'local_o365'), new \moodle_url($this->url, ['mode' => 'maintenance']));
        $PAGE->requires->jquery();
        $this->standard_header();

        echo \html_writer::tag('h2', get_string('acp_maintenance', 'local_o365'));
        echo \html_writer::div(get_string('acp_maintenance_desc', 'local_o365'));
        echo \html_writer::empty_tag('br');
        echo \html_writer::div(get_string('acp_maintenance_warning', 'local_o365'), 'alert alert-info');

        $toolurl = new \moodle_url($this->url, ['mode' => 'maintenance_coursegroupusers']);
        $toolname = get_string('acp_maintenance_coursegroupusers', 'local_o365');
        echo \html_writer::link($toolurl, $toolname, ['target' => '_blank']);
        echo \html_writer::div(get_string('acp_maintenance_coursegroupusers_desc', 'local_o365'));
        echo \html_writer::empty_tag('br');
        echo \html_writer::empty_tag('br');
        $toolurl = new \moodle_url($this->url, ['mode' => 'maintenance_debugdata']);
        $toolname = get_string('acp_maintenance_debugdata', 'local_o365');
        echo \html_writer::link($toolurl, $toolname);
        echo \html_writer::div(get_string('acp_maintenance_debugdata_desc', 'local_o365'));

        $this->standard_footer();
    }
}
