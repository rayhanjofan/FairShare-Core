<?php
/**
 * FairShare – Database introspection script.
 * Periksa struktur aktual tabel group_members di Supabase.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = require_db();

echo "=== Constraints pada public.group_members ===\n";
$rows = $pdo->query("
    SELECT conname, contype, pg_get_constraintdef(oid) AS def
    FROM pg_constraint
    WHERE conrelid = 'public.group_members'::regclass
    ORDER BY contype, conname
")->fetchAll();
foreach ($rows as $r) {
    $type = match($r['contype']) {
        'p' => 'PRIMARY KEY', 'u' => 'UNIQUE', 'f' => 'FK', 'c' => 'CHECK', default => $r['contype']
    };
    echo "  [{$type}] {$r['conname']}: {$r['def']}\n";
}

echo "\n=== Indexes pada public.group_members ===\n";
$rows = $pdo->query("
    SELECT indexname, indexdef
    FROM pg_indexes
    WHERE schemaname = 'public' AND tablename = 'group_members'
    ORDER BY indexname
")->fetchAll();
foreach ($rows as $r) {
    echo "  {$r['indexname']}: {$r['indexdef']}\n";
}

echo "\n=== Kolom public.group_members ===\n";
$rows = $pdo->query("
    SELECT column_name, data_type, is_nullable, column_default
    FROM information_schema.columns
    WHERE table_schema = 'public' AND table_name = 'group_members'
    ORDER BY ordinal_position
")->fetchAll();
foreach ($rows as $r) {
    echo "  {$r['column_name']}: {$r['data_type']} " .
         ($r['is_nullable'] === 'NO' ? 'NOT NULL' : 'NULL') .
         ($r['column_default'] ? " DEFAULT {$r['column_default']}" : '') . "\n";
}

echo "\n=== Constraints pada public.groups ===\n";
$rows = $pdo->query("
    SELECT conname, contype, pg_get_constraintdef(oid) AS def
    FROM pg_constraint
    WHERE conrelid = 'public.groups'::regclass
    ORDER BY contype, conname
")->fetchAll();
foreach ($rows as $r) {
    $type = match($r['contype']) {
        'p' => 'PRIMARY KEY', 'u' => 'UNIQUE', 'f' => 'FK', 'c' => 'CHECK', default => $r['contype']
    };
    echo "  [{$type}] {$r['conname']}: {$r['def']}\n";
}
