<?php
/**
 * Pure-PHP database export (no shell_exec/mysqldump dependency, since this
 * runs from a web-triggered admin action and we don't want to grant a
 * PHP process shell access just to make a backup). Structure + data for
 * every table, written as a single timestamped .sql file under
 * storage/db_backups (outside public access).
 */
class BackupService
{
    private const NAME_PATTERN = '/^backup_\d{8}_\d{6}\.sql$/';

    private PDO $pdo;
    private string $dir;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->dir = APP_ROOT . '/storage/db_backups';
    }

    public function directory(): string
    {
        return $this->dir;
    }

    /** Full path of an existing backup, or null if the name isn't a backup filename or the file is gone. Blocks path traversal. */
    public function pathFor(string $name): ?string
    {
        $name = basename($name);
        if (!$this->isValidName($name)) {
            return null;
        }
        $path = $this->dir . '/' . $name;
        return is_file($path) ? $path : null;
    }

    public function isValidName(string $name): bool
    {
        return (bool) preg_match(self::NAME_PATTERN, $name);
    }

    /** Writes a new backup and returns its filename. */
    public function create(): string
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Could not create the backup storage folder.');
        }

        $filename = 'backup_' . date('Ymd_His') . '.sql';
        $fh = fopen($this->dir . '/' . $filename, 'w');
        if (!$fh) {
            throw new RuntimeException('Could not open the backup file for writing.');
        }

        fwrite($fh, "-- Virac Solo Parent ID -- database backup\n-- Generated " . date('Y-m-d H:i:s') . " by admin panel\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $this->writeTable($fh, $table);
        }
        $this->writeTriggers($fh);
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);

        return $filename;
    }

    private function writeTable($fh, string $table): void
    {
        $createSql = $this->pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch()['Create Table'] ?? '';
        fwrite($fh, "-- ----------------------------\n-- Table: $table\n-- ----------------------------\n");
        fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n$createSql;\n\n");

        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        if ($total === 0) {
            return;
        }

        $columns = array_column($this->pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(), 'Field');
        $columnList = '`' . implode('`, `', $columns) . '`';
        $batchSize = 500;
        for ($offset = 0; $offset < $total; $offset += $batchSize) {
            $rows = $this->pdo->query('SELECT * FROM `' . $table . '` LIMIT ' . $batchSize . ' OFFSET ' . $offset)->fetchAll(PDO::FETCH_NUM);
            if (!$rows) {
                break;
            }
            $valueLines = array_map(
                fn($row) => '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $this->pdo->quote((string) $v), $row)) . ')',
                $rows
            );
            fwrite($fh, "INSERT INTO `$table` ($columnList) VALUES\n" . implode(",\n", $valueLines) . ";\n");
        }
        fwrite($fh, "\n");
    }

    /**
     * Recreates the triggers (e.g. the audit log's append-only guard) after
     * the data is loaded, in mysqldump's DELIMITER form.
     */
    private function writeTriggers($fh): void
    {
        $triggers = $this->pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_COLUMN);
        if (!$triggers) {
            return;
        }
        fwrite($fh, "-- ----------------------------\n-- Triggers\n-- ----------------------------\nDELIMITER $$\n");
        foreach ($triggers as $name) {
            $create = $this->pdo->query('SHOW CREATE TRIGGER `' . str_replace('`', '``', $name) . '`')->fetch()['SQL Original Statement'] ?? '';
            // Drop the DEFINER clause so the backup restores under whichever account imports it.
            $create = preg_replace('/^CREATE\s+DEFINER=\S+\s+TRIGGER/i', 'CREATE TRIGGER', $create);
            fwrite($fh, "DROP TRIGGER IF EXISTS `$name`$$\n$create$$\n");
        }
        fwrite($fh, "DELIMITER ;\n\n");
    }

    /** Existing backups (name, size, created timestamp), newest first. */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->dir . '/backup_*.sql') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => filesize($f), 'created' => filemtime($f)];
        }
        usort($out, fn($a, $b) => $b['created'] <=> $a['created']);
        return $out;
    }

    /** Deletes a backup by name; returns false if there's no such backup. */
    public function delete(string $name): bool
    {
        $path = $this->pathFor($name);
        if (!$path) {
            return false;
        }
        unlink($path);
        return true;
    }

    /** Total bytes of every file under $dir (0 if it doesn't exist). */
    public static function folderSize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $size = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $item) {
            $size += $item->getSize();
        }
        return $size;
    }
}
