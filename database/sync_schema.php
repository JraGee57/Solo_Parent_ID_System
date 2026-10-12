<?php
/**
 * Compare the configured database with schema.sql, or apply additive upgrades.
 * The reference schema is built in an isolated database and always removed.
 * Existing records, custom settings and reference data are never overwritten.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

if (count($argv) > 2 || (isset($argv[1]) && !in_array($argv[1], ['--check', '--apply'], true))) {
    fwrite(STDERR, "Usage: php database/sync_schema.php [--check|--apply]\n");
    exit(1);
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../services/BackupService.php';

function schema_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

/** Read the project's SQL format, including DELIMITER-wrapped triggers. */
function schema_statements(string $sql): array
{
    $statements = [];
    $delimiter = ';';
    $pending = '';
    foreach (preg_split('/\R/', $sql) as $line) {
        if (preg_match('/^DELIMITER\s+(\S+)/i', trim($line), $match)) {
            if (trim($pending) !== '') {
                throw new RuntimeException('Unterminated statement before DELIMITER.');
            }
            $delimiter = $match[1];
            continue;
        }
        if (trim($line) === '' || str_starts_with(ltrim($line), '--')) {
            continue;
        }
        $pending .= $line . "\n";
        if (str_ends_with(rtrim($pending), $delimiter)) {
            $statements[] = substr(rtrim($pending), 0, -strlen($delimiter));
            $pending = '';
        }
    }
    if (trim($pending) !== '') {
        throw new RuntimeException('Unterminated schema statement.');
    }
    return $statements;
}

/** Server-normalized definitions; index order and sequence counters do not matter. */
function schema_tables(PDO $pdo, string $database): array
{
    $tables = [];
    foreach ($pdo->query('SHOW FULL TABLES FROM ' . schema_identifier($database))->fetchAll(PDO::FETCH_NUM) as $row) {
        if ($row[1] !== 'BASE TABLE') {
            throw new RuntimeException('Unexpected view in database: ' . $row[0]);
        }
        $name = $row[0];
        $create = $pdo->query('SHOW CREATE TABLE ' . schema_identifier($database) . '.' . schema_identifier($name))->fetch()['Create Table'];
        $definitions = ['columns' => [], 'indexes' => [], 'constraints' => []];
        foreach (explode("\n", $create) as $line) {
            $line = rtrim(trim($line), ',');
            if (preg_match('/^`([^`]+)` /', $line, $match)) {
                $definitions['columns'][$match[1]] = $line;
            } elseif (str_starts_with($line, 'PRIMARY KEY ')) {
                $definitions['indexes']['PRIMARY'] = $line;
            } elseif (preg_match('/^(?:UNIQUE |FULLTEXT |SPATIAL )?KEY `([^`]+)` /', $line, $match)) {
                $definitions['indexes'][$match[1]] = $line;
            } elseif (preg_match('/^CONSTRAINT `([^`]+)` /', $line, $match)) {
                $definitions['constraints'][$match[1]] = $line;
            } elseif (str_starts_with($line, ') ENGINE=')) {
                $definitions['options'] = preg_replace('/ AUTO_INCREMENT=\d+/', '', $line);
            } elseif (!str_starts_with($line, 'CREATE TABLE ')) {
                throw new RuntimeException('Unsupported table definition: ' . $name . ': ' . $line);
            }
        }
        $tables[$name] = $definitions;
    }
    return $tables;
}

function schema_triggers(PDO $pdo, string $database): array
{
    $triggers = [];
    foreach ($pdo->query('SHOW TRIGGERS FROM ' . schema_identifier($database))->fetchAll() as $row) {
        $triggers[$row['Trigger']] = array_intersect_key($row, array_flip(['Event', 'Table', 'Statement', 'Timing']));
    }
    return $triggers;
}

/** Plan additions only. Existing incompatible definitions require a reviewed migration. */
function schema_plan(PDO $pdo, array $expected, array $actual, array $expectedTriggers, array $actualTriggers): array
{
    $tableSql = $columnSql = $indexSql = $constraintSql = $triggerSql = $issues = [];
    foreach ($expected as $table => $definition) {
        $quoted = schema_identifier($table);
        if (!isset($actual[$table])) {
            // Create all missing tables before adding their foreign keys.
            $lines = array_merge(array_values($definition['columns']), array_values($definition['indexes']));
            $tableSql[] = 'CREATE TABLE ' . $quoted . " (\n  " . implode(",\n  ", $lines) . "\n" . $definition['options'];
        } elseif ($definition['options'] !== $actual[$table]['options']) {
            $issues[] = $table . ': engine, charset or table options differ.';
        }
        $previous = null;
        foreach ($definition['columns'] as $column => $sql) {
            if (isset($actual[$table]) && !isset($actual[$table]['columns'][$column])) {
                if (str_contains($sql, 'NOT NULL') && !str_contains($sql, 'DEFAULT ') && !str_contains($sql, 'AUTO_INCREMENT')
                    && $pdo->query('SELECT 1 FROM ' . $quoted . ' LIMIT 1')->fetchColumn() !== false) {
                    $issues[] = $table . '.' . $column . ': requires a data backfill before adding a required column.';
                } else {
                    $columnSql[] = 'ALTER TABLE ' . $quoted . ' ADD COLUMN ' . $sql
                        . ($previous === null ? ' FIRST' : ' AFTER ' . schema_identifier($previous));
                }
            } elseif (isset($actual[$table]['columns'][$column]) && $sql !== $actual[$table]['columns'][$column]) {
                $issues[] = $table . '.' . $column . ': type, default, nullability or check differs.';
            }
            $previous = $column;
        }
        foreach (['indexes', 'constraints'] as $kind) {
            foreach ($definition[$kind] as $name => $sql) {
                if (!isset($actual[$table][$kind][$name])) {
                    if ($kind === 'constraints') {
                        $constraintSql[] = 'ALTER TABLE ' . $quoted . ' ADD ' . $sql;
                    } elseif (isset($actual[$table])) {
                        $indexSql[] = 'ALTER TABLE ' . $quoted . ' ADD ' . $sql;
                    }
                } elseif ($sql !== $actual[$table][$kind][$name]) {
                    $issues[] = $table . '.' . $name . ': ' . $kind . ' definition differs.';
                }
            }
        }
        // An old unique key can prohibit inserts allowed by today's schema.
        foreach ($actual[$table]['indexes'] ?? [] as $name => $sql) {
            if (!isset($definition['indexes'][$name]) && (str_starts_with($sql, 'UNIQUE KEY ') || $name === 'PRIMARY')) {
                $issues[] = $table . '.' . $name . ': legacy unique key requires a reviewed migration.';
            }
        }
        foreach ($actual[$table]['constraints'] ?? [] as $name => $sql) {
            if (!isset($definition['constraints'][$name])) {
                $issues[] = $table . '.' . $name . ': unexpected constraint requires review.';
            }
        }
    }
    foreach ($expectedTriggers as $name => $trigger) {
        if (!isset($actualTriggers[$name])) {
            $triggerSql[] = 'CREATE TRIGGER ' . schema_identifier($name) . ' ' . $trigger['Timing'] . ' ' . $trigger['Event']
                . ' ON ' . schema_identifier($trigger['Table']) . ' FOR EACH ROW ' . $trigger['Statement'];
        } elseif ($trigger !== $actualTriggers[$name]) {
            $issues[] = $name . ': audit trigger definition differs.';
        }
    }
    return [array_merge($tableSql, $columnSql, $indexSql, $constraintSql, $triggerSql), $issues];
}

$apply = ($argv[1] ?? '--check') === '--apply';
$reference = 'sp_schema_check_' . bin2hex(random_bytes(8));
$createdReference = false;
$exitCode = 0;
$lockName = 'solo_parent_schema_' . substr(hash('sha256', DB_NAME), 0, 40);
$locked = false;
try {
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Another schema check or upgrade is running.');
    }
    $locked = true;
    $pdo->exec('CREATE DATABASE ' . schema_identifier($reference) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $createdReference = true;
    $pdo->exec('USE ' . schema_identifier($reference));
    foreach (schema_statements(file_get_contents(__DIR__ . '/schema.sql')) as $statement) {
        // Never execute schema.sql's destructive fresh-install database commands.
        if (preg_match('/^(DROP DATABASE|CREATE DATABASE|USE)\b/i', $statement)) {
            continue;
        }
        if (!preg_match('/^(CREATE TABLE|ALTER TABLE|CREATE TRIGGER)\b/i', $statement)) {
            throw new RuntimeException('Unexpected statement in schema.sql.');
        }
        $pdo->exec($statement);
    }
    $expected = schema_tables($pdo, $reference);
    $expectedTriggers = schema_triggers($pdo, $reference);
    $pdo->exec('USE ' . schema_identifier(DB_NAME));
    $actual = schema_tables($pdo, DB_NAME);
    [$statements, $issues] = schema_plan($pdo, $expected, $actual, $expectedTriggers, schema_triggers($pdo, DB_NAME));
    echo 'Database: ' . DB_NAME . '; canonical tables: ' . count($expected) . PHP_EOL;
    foreach (array_diff(array_keys($actual), array_keys($expected)) as $table) {
        echo 'Extra table retained: ' . $table . PHP_EOL;
    }
    foreach ($issues as $issue) {
        echo 'Review required: ' . $issue . PHP_EOL;
    }
    if ($issues) {
        $exitCode = 1;
        echo "No changes applied. Resolve incompatible definitions with a data-preserving migration.\n";
    } elseif (!$statements) {
        echo "Schema matches: columns, defaults, indexes, foreign keys and audit triggers. No upgrade needed.\n";
    } elseif (!$apply) {
        foreach ($statements as $statement) {
            echo $statement . ";\n";
        }
        echo "Additive changes pending. Run with --apply to back up and upgrade.\n";
        $exitCode = 1;
    } else {
        $backup = new BackupService($pdo);
        $filename = $backup->create();
        echo 'Backup: ' . $backup->directory() . '/' . $filename . PHP_EOL;
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        [$remaining, $issues] = schema_plan($pdo, $expected, schema_tables($pdo, DB_NAME), $expectedTriggers, schema_triggers($pdo, DB_NAME));
        if ($remaining || $issues) {
            throw new RuntimeException('The upgraded database still differs from schema.sql. Run --check for details.');
        }
        echo 'Applied ' . count($statements) . " additive changes; schema matches.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Schema check/upgrade failed: ' . $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    // These operations only remove the randomly named database created by this run.
    $pdo->exec('USE ' . schema_identifier(DB_NAME));
    if ($createdReference) {
        $pdo->exec('DROP DATABASE ' . schema_identifier($reference));
    }
    if ($locked) {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}
exit($exitCode);
