<?php
// Local Docker development configuration, loaded by the generated root config.php.
unset($CFG);
global $CFG;
$CFG = new stdClass();
$CFG->dbtype = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost = getenv('MOODLE_DB_HOST');
$CFG->dbname = getenv('MOODLE_DB_NAME');
$CFG->dbuser = getenv('MOODLE_DB_USER');
$CFG->dbpass = getenv('MOODLE_DB_PASSWORD');
$CFG->prefix = 'mdl_';
$CFG->dboptions = ['dbpersist' => false, 'dbport' => 5432, 'dbsocket' => false];
$CFG->wwwroot = getenv('MOODLE_WWWROOT');
$CFG->dataroot = '/var/www/moodledata';
$CFG->directorypermissions = 02770;
$CFG->admin = 'admin';
$CFG->routerconfigured = true;
// Cookies are not scoped by port; avoid colliding with other localhost Moodle sites.
$CFG->sessioncookie = 'MoodleDocker';
$CFG->cookiesecure = true;
require_once(__DIR__ . '/curl-security.php');
$CFG->curlsecurityblockedhosts = moodle_docker_blocked_hosts();
$CFG->curlsecurityallowedport = "80\n443\n" . getenv('MOODLE_HTTPS_PORT');
require_once(dirname(__DIR__) . '/lib/setup.php');
