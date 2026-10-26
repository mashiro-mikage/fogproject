<?php
/** Real MariaDB execution of the official 441-444 schema steps. */
if (!getenv('MJ1_DB_DSN')) {
    echo "SKIP MJ-1 migration DB: MJ1_DB_DSN not set\n";
    exit(0);
}

// The collector runs in isolation from the FOG bootstrap.
require __DIR__ . '/lib/fog-schema-collector.php';

$pdo = new \PDO(
    getenv('MJ1_DB_DSN'),
    getenv('MJ1_DB_USER') ?: 'root',
    getenv('MJ1_DB_PASSWORD'),
    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]
);
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (getenv('MJ1_DB_ALLOW_RESET') !== '1' || 0 !== strpos($database, 'fog_mj1')) {
    throw new \RuntimeException('MJ-1 migration test requires an explicitly resettable fog_mj1 scratch database');
}
$root = dirname(__DIR__);
$manifest = require $root . '/packages/web/commons/schema-expected.php';
$steps = fogCollectSchemaSteps($root . '/packages/web/commons/schema.php', $database, 444);
$step444 = end($steps);
$step443 = $steps[count($steps) - 2] ?? null;
$step442 = $steps[count($steps) - 3] ?? null;
if (!is_array($step444) || count($step444) !== 1 || !is_string($step444[0] ?? null)) {
    throw new \RuntimeException('last collected migration must contain the single 444 SQL statement');
}
if (!is_array($step442) || !is_string($step442[0] ?? null)
    || false === strpos($step442[0], 'msTransferStart')) {
    throw new \RuntimeException('collected 442 migration must be the actual msTransferStart DDL');
}
if (!is_array($step443) || !is_string($step443[0] ?? null)
    || false === strpos($step443[0], 'INSERT IGNORE INTO `moduleStatusByHost`')
    || false === strpos($step444[0], '`hdRejoinConfig` TEXT NULL DEFAULT NULL')
    || false === strpos($step444[0], '`hdRejoinState` TEXT NULL DEFAULT NULL')) {
    throw new \RuntimeException('collected 443/444 steps do not match the official module-preservation and directory-column migrations');
}
$ddl443 = $step443[0];
$ddl444 = $step444[0];

function mj1MigrationCreateTables(PDO $pdo, array $manifest, $has442)
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('DROP TABLE IF EXISTS hostDirectory');
    $pdo->exec('DROP TABLE IF EXISTS multicastSessions');
    $pdo->exec('DROP TABLE IF EXISTS moduleStatusByHost');
    $pdo->exec('DROP TABLE IF EXISTS groupModuleAssoc');
    $pdo->exec('DROP TABLE IF EXISTS groupMembers');
    $pdo->exec('DROP TABLE IF EXISTS modules');
    $pdo->exec('DROP TABLE IF EXISTS hosts');
    $pdo->exec($manifest['tables']['hosts']['create']);
    $pdo->exec("INSERT INTO hosts (hostID,hostName,hostADDomain,hostUseAD,hostAgentFingerprint) VALUES (7,'default-missing','corp.example',1,'" . str_repeat('f', 64) . "'),(8,'explicit-on','corp.example',1,'" . str_repeat('e', 64) . "'),(9,'explicit-off','corp.example',1,'" . str_repeat('d', 64) . "'),(10,'group-grant','corp.example',1,'" . str_repeat('c', 64) . "'),(11,'default-off','corp.example',1,'" . str_repeat('b', 64) . "')");

    $hostDirectory = preg_replace(
        '/`hdRejoin(?:Config|State)`\s+text\s+(?:DEFAULT NULL|NULL DEFAULT NULL),\s*/i',
        '',
        $manifest['tables']['hostDirectory']['create']
    );
    $pdo->exec($hostDirectory);
    $pdo->exec($manifest['tables']['multicastSessions']['create']);
    foreach (['modules', 'moduleStatusByHost', 'groupMembers', 'groupModuleAssoc'] as $table) {
        $pdo->exec($manifest['tables'][$table]['create']);
    }
    if (!$has442) {
        $pdo->exec('ALTER TABLE `multicastSessions` DROP COLUMN `msTransferStart`');
    }
    $pdo->exec("INSERT INTO hostDirectory (hdHostID,hdDomain,hdNetbios) VALUES (7,'old.example','OLD7'),(8,'old.example','OLD8'),(9,'old.example','OLD9'),(10,'old.example','OLD10'),(11,'old.example','OLD11')");
}

function mj1MigrationRows(PDO $pdo, $before = null)
{
    $rows = [];
    foreach (['hosts', 'hostDirectory', 'multicastSessions'] as $table) {
        $columns = isset($before[$table])
            ? $before[$table]['columns']
            : $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(\PDO::FETCH_COLUMN);
        $tableRows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(\PDO::FETCH_ASSOC);
        $projected = [];
        foreach ($tableRows as $row) {
            $projectedRow = [];
            foreach ($columns as $column) {
                $projectedRow[$column] = $row[$column];
            }
            $projected[] = json_encode($projectedRow);
        }
        sort($projected, SORT_STRING);
        $rows[$table] = ['columns' => $columns, 'rows' => $projected];
    }
    return $rows;
}

function mj1MigrationNormalizeColumnType($type)
{
    $type = strtolower(trim($type));
    return preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $type);
}

function mj1MigrationModuleStates(PDO $pdo)
{
    // PDO returns numeric columns as ints or numeric strings depending on PHP
    // and driver version; compare the same database values strictly everywhere.
    return array_map(
        function ($row) {
            return [(int)$row[0], (int)$row[1], (int)$row[2]];
        },
        $pdo->query('SELECT `msHostID`,`msModuleID`,`msState` FROM `moduleStatusByHost` ORDER BY `msHostID`,`msModuleID`')->fetchAll(\PDO::FETCH_NUM)
    );
}

function mj1MigrationAssertExpectedHostDirectory(PDO $pdo, array $manifest)
{
    $expected = $manifest['tables']['hostDirectory']['columns'];
    $actual = $pdo->query('SHOW COLUMNS FROM `hostDirectory`')->fetchAll(\PDO::FETCH_ASSOC);
    if (array_column($actual, 'Field') !== array_keys($expected)) {
        throw new \RuntimeException('schema 444 hostDirectory columns do not match schema-expected.php');
    }
    foreach ($actual as $column) {
        $definition = $expected[$column['Field']];
        $expectedType = preg_split('/\s+(?:NOT NULL|DEFAULT)\b/i', $definition, 2)[0];
        if (mj1MigrationNormalizeColumnType($column['Type']) !== mj1MigrationNormalizeColumnType($expectedType)
            || (false !== strpos($definition, 'NOT NULL')) !== ('NO' === $column['Null'])) {
            throw new \RuntimeException('schema 444 hostDirectory column structure differs from schema-expected.php: ' . $column['Field']);
        }
    }
    foreach (['hdRejoinConfig', 'hdRejoinState'] as $field) {
        $column = $actual[array_search($field, array_column($actual, 'Field'), true)];
        if (null !== $column['Default']) {
            throw new \RuntimeException('schema 444 nullable text column must default to NULL: ' . $field);
        }
    }
}

function mj1MigrationPrepare443Fixture(PDO $pdo, $ddl442, $ddl443, array $manifest, $apply442, $apply443)
{
    if (!$apply442) {
        $columns = $pdo->query('SHOW COLUMNS FROM `multicastSessions`')->fetchAll(\PDO::FETCH_COLUMN);
        if (!in_array('msTransferStart', $columns, true)) {
            throw new \RuntimeException('official 442 fixture must already contain msTransferStart');
        }
    }
    $before442 = $pdo->query('SHOW COLUMNS FROM `multicastSessions`')->fetchAll(\PDO::FETCH_COLUMN);
    if ($apply442 && in_array('msTransferStart', $before442, true)) {
        throw new \RuntimeException('official 441 fixture unexpectedly contains msTransferStart');
    }
    if (in_array('hdRejoinConfig', $pdo->query('SHOW COLUMNS FROM `hostDirectory`')->fetchAll(\PDO::FETCH_COLUMN), true)) {
        throw new \RuntimeException('MJ-1 columns must not exist before schema 444');
    }
    if ($apply442) {
        $pdo->exec($ddl442);
    }
    $after442 = $pdo->query('SHOW COLUMNS FROM `multicastSessions`')->fetchAll(\PDO::FETCH_COLUMN);
    if (!in_array('msTransferStart', $after442, true)) {
        throw new \RuntimeException('schema 442 did not add msTransferStart');
    }
    if (in_array('hdRejoinConfig', $pdo->query('SHOW COLUMNS FROM `hostDirectory`')->fetchAll(\PDO::FETCH_COLUMN), true)) {
        throw new \RuntimeException('MJ-1 columns appeared before schema 444');
    }
    $pdo->exec("INSERT INTO `modules` (`id`,`name`,`short_name`,`description`,`default`) VALUES (1,'Default on','on','',1),(2,'Default off','off','',0)");
    $pdo->exec("INSERT INTO `moduleStatusByHost` (`msHostID`,`msModuleID`,`msState`) VALUES (8,1,1),(9,1,0)");
    $pdo->exec("INSERT INTO `groupMembers` (`gmHostID`,`gmGroupID`) VALUES (10,5)");
    $pdo->exec("INSERT INTO `groupModuleAssoc` (`gmaGroupID`,`gmaModuleID`) VALUES (5,1)");
    $pdo->exec("INSERT INTO `multicastSessions` (`msName`,`msBasePort`,`msLogPath`,`msImage`,`msInterface`,`msNFSGroupID`) VALUES ('fixture',1,'','','',1)");
    $pdo->exec("UPDATE `multicastSessions` SET `msTransferStart`='2026-01-02 03:04:05'");
    $transferBefore = $pdo->query('SELECT `msTransferStart` FROM `multicastSessions`')->fetchColumn();
    if ('2026-01-02 03:04:05' !== $transferBefore) {
        throw new \RuntimeException('442 msTransferStart marker value was not stored');
    }
    if ($apply443) {
        $rowsBefore443 = mj1MigrationRows($pdo);
        $pdo->exec($ddl443);
        if ($rowsBefore443 !== mj1MigrationRows($pdo, $rowsBefore443)) {
            throw new \RuntimeException('schema 443 changed existing rows');
        }
    } else {
        // Model an already-upgraded official 443 database without replaying 443.
        $pdo->exec("INSERT INTO `moduleStatusByHost` (`msHostID`,`msModuleID`,`msState`) VALUES (7,1,0),(11,1,0)");
    }
    $hostDirectoryColumns = $pdo->query('SHOW COLUMNS FROM `hostDirectory`')->fetchAll(\PDO::FETCH_COLUMN);
    foreach (['hdRejoinConfig', 'hdRejoinState'] as $field) {
        if (in_array($field, $hostDirectoryColumns, true)) {
            throw new \RuntimeException('schema 443 fixture unexpectedly has schema 444 column: ' . $field);
        }
    }
    $states = mj1MigrationModuleStates($pdo);
    if ($states !== [[7, 1, 0], [8, 1, 1], [9, 1, 0], [11, 1, 0]]) {
        throw new \RuntimeException('schema 443 default ON/OFF, explicit ON/OFF, or group grant behavior is incorrect');
    }
    $moduleTwoRows = (int)$pdo->query('SELECT COUNT(*) FROM `moduleStatusByHost` WHERE `msModuleID`=2')->fetchColumn();
    if (0 !== $moduleTwoRows) {
        throw new \RuntimeException('schema 443 must leave default-OFF module rows missing');
    }
    return $transferBefore;
}

function mj1MigrationApply444AndAssert(PDO $pdo, $ddl444, array $manifest, $transferBefore, $states)
{
    $rowsBefore444 = mj1MigrationRows($pdo);
    $pdo->exec($ddl444);
    if ($rowsBefore444 !== mj1MigrationRows($pdo, $rowsBefore444)) {
        throw new \RuntimeException('schema 444 changed existing host, directory, or multicast values');
    }
    mj1MigrationAssertExpectedHostDirectory($pdo, $manifest);
    $after444 = $pdo->query('SELECT `hdRejoinConfig`, `hdRejoinState` FROM `hostDirectory`')->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($after444 as $row) {
        if (null !== $row['hdRejoinConfig'] || null !== $row['hdRejoinState']) {
            throw new \RuntimeException('schema 444 fields must be NULL for existing rows');
        }
    }
    if ($transferBefore !== $pdo->query('SELECT `msTransferStart` FROM `multicastSessions`')->fetchColumn()) {
        throw new \RuntimeException('schema 442 msTransferStart value was not preserved');
    }
    if ($states !== mj1MigrationModuleStates($pdo)) {
        throw new \RuntimeException('schema 444 changed schema 443 module state');
    }
}

// Official 441 -> 442 -> 443 -> 444.
mj1MigrationCreateTables($pdo, $manifest, false);
$transferBefore = mj1MigrationPrepare443Fixture($pdo, $step442[0], $ddl443, $manifest, true, true);
$states = mj1MigrationModuleStates($pdo);
mj1MigrationApply444AndAssert($pdo, $ddl444, $manifest, $transferBefore, $states);

// Official 442 -> 443 -> 444.
mj1MigrationCreateTables($pdo, $manifest, true);
$transferBefore = mj1MigrationPrepare443Fixture($pdo, $step442[0], $ddl443, $manifest, false, true);
$states = mj1MigrationModuleStates($pdo);
mj1MigrationApply444AndAssert($pdo, $ddl444, $manifest, $transferBefore, $states);

// Official 443 fixture: prepare its persisted state, then apply only 444.
mj1MigrationCreateTables($pdo, $manifest, true);
$transferBefore = mj1MigrationPrepare443Fixture($pdo, $step442[0], $ddl443, $manifest, false, false);
$states = mj1MigrationModuleStates($pdo);
mj1MigrationApply444AndAssert($pdo, $ddl444, $manifest, $transferBefore, $states);

echo "PASS schema 444: official 441->442->443->444, 442->443->444, and 443->444; module and row state preserved\n";
