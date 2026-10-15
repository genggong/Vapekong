<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin', 'owner']);
$db = Database::connect();

$backupDir = __DIR__ . '/../../../storage/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0775, true);
}

function sql_quote_value(PDO $db, $value): string {
    if ($value === null) return 'NULL';
    return $db->quote((string)$value);
}

try {
    $database = $db->query("SELECT DATABASE()")->fetchColumn();
    $tables = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

    $filename = 'backup_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$database) . '_' . date('Ymd_His') . '.sql';
    $path = $backupDir . '/' . $filename;

    $out = "-- Backup Database KasirPro UMKM\n";
    $out .= "-- Database: {$database}\n";
    $out .= "-- Created: " . date('Y-m-d H:i:s') . "\n\n";
    $out .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    $out .= "START TRANSACTION;\n";
    $out .= "SET time_zone = \"+00:00\";\n\n";

    foreach ($tables as $row) {
        $table = $row[0];

        $createStmt = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        $createSql = $createStmt['Create Table'] ?? array_values($createStmt)[1] ?? '';

        $out .= "\n-- --------------------------------------------------------\n";
        $out .= "-- Table structure for table `{$table}`\n";
        $out .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $out .= $createSql . ";\n\n";

        $stmt = $db->query("SELECT * FROM `{$table}`");
        $columns = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $meta = $stmt->getColumnMeta($i);
            $columns[] = '`' . str_replace('`', '``', $meta['name']) . '`';
        }

        $batch = [];
        while ($data = $stmt->fetch(PDO::FETCH_NUM)) {
            $values = array_map(fn($v) => sql_quote_value($db, $v), $data);
            $batch[] = '(' . implode(',', $values) . ')';

            if (count($batch) >= 100) {
                $out .= "INSERT INTO `{$table}` (" . implode(',', $columns) . ") VALUES\n" . implode(",\n", $batch) . ";\n";
                $batch = [];
            }
        }

        if ($batch) {
            $out .= "INSERT INTO `{$table}` (" . implode(',', $columns) . ") VALUES\n" . implode(",\n", $batch) . ";\n";
        }

        $out .= "\n";
    }

    $out .= "COMMIT;\n";
    file_put_contents($path, $out);

    Auth::log('backup_database', 'Membuat backup database ' . $filename);
    flash('success', 'Backup database berhasil dibuat.');
} catch (Throwable $e) {
    flash('error', 'Backup database gagal: ' . $e->getMessage());
}

legacy_redirect('admin/backup/index.php');
