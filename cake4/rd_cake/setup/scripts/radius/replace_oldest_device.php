<?php
// Called by FreeRADIUS post-auth only, after password verification.
// Request passwords are never passed to this helper.
function env($key, $default = null) { $v = getenv($key); return $v === false ? $default : $v; }
require_once __DIR__ . '/DeviceSessionReplacement.php';
try {
    $config = require dirname(__DIR__, 3) . '/config/app_local.php';
    $c = $config['Datasources']['default'];
    $db = new PDO('mysql:host='.$c['host'].';port='.($c['port'] ?? 3306).';dbname='.$c['database'].';charset=utf8mb4',
        $c['username'], $c['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>3]);
    $service = new DeviceSessionReplacement($db);
    $service->replace((string)getenv('Tmp-String-4'), (string)getenv('Tmp-String-5'), (string)getenv('Tmp-String-6'));
    exit(0);
} catch (Throwable $e) {
    // Generic client-facing failure; never expose DB settings, secrets or packet data.
    fwrite(STDERR, 'device-replacement failed: '.($e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : get_class($e))."\n");
    echo 'Reply-Message := "Unable to disconnect the previous device. Please try again."'."\n";
    exit(1);
}
