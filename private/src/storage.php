<?php
declare(strict_types=1);
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/guest.php';

// Parent tables precede children in both migration and recovery dumps.
const CARD_TABLES = [
    'accounts' => 'id,auth_id,profile,state,revision',
    'tickets' => 'hash,owner_id,expires',
    'exchange_requests' => 'id,ticket_hash,owner_id,requester_id,status,created',
    'connections' => 'id,left_id,right_id,met_at,left_name,right_name',
    'identities' => 'subject,auth_id,provider',
    'devices' => 'hash,auth_id,expires',
];

function initializeStorage(array $config): void
{
    $db = database($config);
    $lock = 'card-init-' . substr(hash('sha256', $config['mysql']['database']), 0, 48);
    if ((int)query($db, 'SELECT GET_LOCK(?, 10)', [$lock])->fetchColumn() !== 1) {
        throw new RuntimeException('Another database initialization is running.');
    }
    try {
        $schema = file_get_contents(dirname(__DIR__) . '/schema.sql');
        foreach (explode(';', $schema) as $statement) {
            if (trim($statement) !== '') {
                $db->exec($statement);
            }
        }
        if ($db->query('SELECT version FROM card_storage_version WHERE version=1')->fetchColumn()) {
            return;
        }
        $db->beginTransaction();
        foreach (CARD_TABLES as $table => $columns) {
            if ($db->query("SELECT 1 FROM $table LIMIT 1")->fetchColumn()) {
                throw new RuntimeException("Migration requires empty target tables: $table");
            }
        }
        $path = $config['data_dir'] . '/app.sqlite';
        if (is_file($path)) {
            $source = new PDO('sqlite:' . $path);
            $source->exec('PRAGMA query_only=ON');
            $source->beginTransaction();
            if ($source->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('SQLite integrity check failed.');
            }
            foreach (CARD_TABLES as $table => $columns) {
                // OAuth-only installations predate the device and identity tables.
                if (in_array($table, ['devices', 'identities'], true) &&
                    !query($source, "SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", [$table])->fetchColumn()) {
                    continue;
                }
                $rows = $source->query("SELECT $columns FROM $table");
                $marks = implode(',', array_fill(0, $rows->columnCount(), '?'));
                $insert = $db->prepare("INSERT INTO $table ($columns) VALUES ($marks)");
                $count = 0;
                while ($row = $rows->fetch(PDO::FETCH_NUM)) {
                    $insert->execute($row);
                    ++$count;
                }
                if ((int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn() !== $count) {
                    throw new RuntimeException("Migration count mismatch: $table");
                }
                echo "$table: $count rows\n";
            }
            $source->commit();
        }
        migrateIdentity($db);
        $db->exec('INSERT INTO card_storage_version VALUES (1)');
        $db->commit();
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        query($db, 'SELECT RELEASE_LOCK(?)', [$lock]);
    }
}
