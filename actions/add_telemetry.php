<?php
declare(strict_types=1);

/**
 * Backward compatibility wrapper for actions/telemetry/add_telemetry.php
 */
if (!defined('FS_ROOT')) {
    define('FS_ROOT', dirname(__DIR__));
}
require_once __DIR__ . '/telemetry/add_telemetry.php';
