<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
$pdo = require_db();
$stmt = $pdo->query("SELECT data_type FROM information_schema.columns WHERE table_name='telemetry_logs' AND column_name='activity_timestamp';");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) {
    echo $row['data_type'];
} else {
    echo "Column not found";
}
?>