<?php
require_once __DIR__.'/includes/bootstrap.php';
if(current_user())log_activity('Logout','Pengguna keluar dari workspace');
$_SESSION=[];session_destroy();header('Location: login.php');exit;
