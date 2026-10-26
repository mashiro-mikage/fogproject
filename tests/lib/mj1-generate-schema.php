<?php
// Creates a disposable reference database for the upstream schema generator.
if (getenv('MJ1_DB_ALLOW_RESET') !== '1') { throw new \RuntimeException('scratch DB opt-in required'); }
$pdo = new \PDO(getenv('MJ1_DB_DSN'), getenv('MJ1_DB_USER'), getenv('MJ1_DB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
if (strpos($pdo->query('SELECT DATABASE()')->fetchColumn(), 'fog_mj1') !== 0) { throw new \RuntimeException('scratch DB required'); }
$manifest = require dirname(__DIR__, 2) . '/packages/web/commons/schema-expected.php';
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($manifest['tables'] as $table => $definition) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    $create = $definition['create'];
    if ('hostDirectory' === $table) {
        // Build the official 443 shape from the current manifest so the
        // collected 444 migration can add these fields exactly once.
        $create = preg_replace(
            '/,\s*`hdRejoin(?:Config|State)`\s+text\s+(?:NULL\s+)?DEFAULT NULL/i',
            '',
            $create,
            -1,
            $removed
        );
        if (2 !== $removed
            || false !== strpos($create, '`hdRejoinConfig`')
            || false !== strpos($create, '`hdRejoinState`')) {
            throw new \RuntimeException('current manifest hostDirectory must contain exactly the two schema 444 fields');
        }
    }
    $pdo->exec($create);
}
$sql = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/mj1-schema-sql.php'));
$ddl444 = is_string($sql) ? json_decode($sql, true) : null;
if (!is_string($ddl444)
    || strpos($ddl444, 'ALTER TABLE `hostDirectory` ADD COLUMN `hdRejoinConfig`') !== 0
    || false === strpos($ddl444, '`hdRejoinState` TEXT NULL DEFAULT NULL')) {
    throw new \RuntimeException('schema helper must return the actual collected 444 DDL as one string');
}
$pdo->exec($ddl444);
$config = "<?php\ndefine('DATABASE_HOST', 'mj1-db');\ndefine('DATABASE_NAME', 'fog_mj1');\ndefine('DATABASE_USERNAME', 'root');\ndefine('DATABASE_PASSWORD', '" . getenv('MJ1_DB_PASSWORD') . "');\n";
file_put_contents(dirname(__DIR__, 2) . '/packages/web/commons/config.class.php', $config);
echo "PASS: scratch reference schema prepared for official generator\n";
