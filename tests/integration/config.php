<?php
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}
// Test-only database settings (throwaway container, see docker-compose.yml)
return array(
    'components' => array(
        'db' => array(
            'connectionString' => 'mysql:host=db;port=3306;dbname=limesurvey;',
            'emulatePrepare' => true,
            'username' => 'limesurvey',
            'password' => 'limesurvey',
            'charset' => 'utf8mb4',
            'tablePrefix' => 'lime_',
        ),
        'urlManager' => array(
            'urlFormat' => 'path',
            'rules' => array(),
            'showScriptName' => true,
        ),
    ),
    'config' => array(
        'debug' => 1,
        'debugsql' => 0,
    ),
);
