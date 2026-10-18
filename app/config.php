<?php
define('APP_NAME', 'FairShare');
define('DATA_PATH', dirname(__DIR__) . '/storage');
if (!is_dir(DATA_PATH))
    mkdir(DATA_PATH, 0775, true);
