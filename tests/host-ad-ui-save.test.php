<?php
/** Japanese renderer and real AD form POST against a disposable local HTTP/DB fixture. */
if (!getenv('MJ1_DB_DSN')) { echo "SKIP UI save: no scratch DB\n"; exit(0); }
if (getenv('MJ1_DB_ALLOW_RESET') !== '1') { throw new \RuntimeException('scratch reset opt-in required'); }
$pdo = new \PDO(getenv('MJ1_DB_DSN'), getenv('MJ1_DB_USER'), getenv('MJ1_DB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
if (strpos($pdo->query('SELECT DATABASE()')->fetchColumn(), 'fog_mj1') !== 0) { throw new \RuntimeException('scratch DB required'); }
$pdo->exec('CREATE DATABASE IF NOT EXISTS fog_mj1_ui');
$pdo->exec('USE fog_mj1_ui');
$pdo->exec('DROP TABLE IF EXISTS hostDirectory');
$pdo->exec('DROP TABLE IF EXISTS hosts');
putenv('MJ1_DB_DSN=mysql:host=mj1-db;dbname=fog_mj1_ui;charset=utf8mb4');
$root = dirname(__DIR__);
putenv('LANG=ja_JP.UTF-8');
putenv('LANGUAGE=ja_JP.UTF-8');
$port = random_int(19000, 25000);
$log = tempnam(sys_get_temp_dir(), 'fog-ui-');
$cookie = tempnam(sys_get_temp_dir(), 'fog-ui-cookie-');
$process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
if (!is_resource($process)) { throw new \RuntimeException('UI server did not start'); }
try {
    $url = 'http://127.0.0.1:' . $port . '/tests/fixtures/directory-operation-ui.php';
    $request = static function ($post = null) use ($url, $cookie) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie]);
        if ($post !== null) { curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = curl_exec($c); $status = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
        return [$status, $body];
    };
    for ($i = 0; $i < 20; $i++) {
        list($status, $html) = $request();
        if ($status === 200) { break; }
        usleep(100000);
    }
    if ($status !== 200 || strpos($html, 'ドメイン参加状態') === false) {
        throw new \RuntimeException('Japanese AD form did not render: ' . $status . '; ' . file_get_contents($log));
    }
    if (!preg_match('/<input\b[^>]*\bname="rejoin_allow_create"[^>]*>/', $html, $checkbox)
        || strpos($checkbox[0], 'form-control') !== false || strpos($checkbox[0], 'form-check-input') === false) {
        throw new \RuntimeException('checkbox does not use the existing FOG structure');
    }
    foreach ([false, true] as $allow) {
        if (!preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $token)) { throw new \RuntimeException('CSRF token missing'); }
        $post = ['_csrf' => html_entity_decode($token[1], ENT_QUOTES, 'UTF-8'), 'domain' => '1',
            'domainname' => 'corp.example', 'domainuser' => 'fixture-user', 'rejoin_settings_present' => '1'];
        if ($allow) { $post['rejoin_allow_create'] = '1'; }
        list($status, $html) = $request($post);
        if ($status !== 200) { throw new \RuntimeException('AD form POST failed: ' . $status); }
        $saved = json_decode($pdo->query('SELECT hdRejoinConfig FROM hostDirectory WHERE hdHostID=701')->fetchColumn(), true);
        if (!is_array($saved) || ($saved['allow_create'] ?? null) !== $allow) { throw new \RuntimeException('AD form permission save did not persist'); }
    }
    echo "PASS Japanese AD form render, checkbox structure, CSRF-backed OFF/ON saves\n";
} finally {
    proc_terminate($process); proc_close($process); unlink($log); unlink($cookie);
}
