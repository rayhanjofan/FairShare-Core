<?php

if (!defined('FS_ROOT')) {
    define('FS_ROOT', dirname(__DIR__, 2));
}
require_once FS_ROOT . '/includes/bootstrap.php';
if(current_user())log_activity('Logout','Pengguna keluar dari workspace');
$_SESSION=[];session_destroy();header('Location: login.php');exit;

