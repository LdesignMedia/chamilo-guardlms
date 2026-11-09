<?php

require dirname(__DIR__).'/autoload.php';

use GuardLMS\Chamilo\Adapter;
use GuardLMS\Chamilo\Connector;
use GuardLMS\Chamilo\Monitor;

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}

$adapter = new class () extends Adapter {
    public function site()
    {
        return 'https://monitor.example';
    }
};

if (($argv[1] ?? '') === '--import') {
    $connector = new Connector($adapter);
    for ($i = 0; $i < (int) ($argv[2] ?? 0); $i++) {
        Monitor::capture($connector, '', 'fixture.php', $i, 'php', 'PHP warning');
    }
    Monitor::importNativeLog($connector);
    exit;
}

$dir = sys_get_temp_dir().'/chamilo-monitor-'.bin2hex(random_bytes(8));
putenv('CHAMILO_GUARDLMS_DATA='.$dir);
$connector = new Connector($adapter);
$initial = ['token' => 'fixture-token', 'server_enabled' => true, 'sdk' => ['key' => 'public-fixture', 'enabled' => true, 'subscription_active' => true, 'allowed_domains_match' => true]];
$reset = static function ($log) use ($connector, $initial) {
    $connector->store->update(static function () use ($initial) {
        return $initial;
    });
    file_put_contents($connector->store->path('.php-errors.log'), $log);
};
$import = static function ($prior = 0) {
    $process = proc_open([PHP_BINARY, __FILE__, '--import', (string) $prior], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start isolated import process.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Import failed: '.$output);
    }
};

try {
    $line = "PHP Warning: private fixture diagnostic\n";
    $reset(str_repeat($line, 20)."SQLSTATE[HY000] private SQL fixture\n");
    $import();
    $state = $connector->store->read();
    check(count($state['events']) === 20 && $state['log_cursor'] === 20 * strlen($line), 'full budget retains the first unread diagnostic');
    $import();
    $state = $connector->store->read();
    check(count($state['events']) === 21 && $state['events'][20]['event']['type'] === 'sql', 'next PHP process captures the remaining SQL diagnostic');
    $import();
    check(count($connector->store->read()['events']) === 21, 'completed import does not replay existing diagnostics');

    $reset($line.$line);
    $import(19);
    $state = $connector->store->read();
    check(count($state['events']) === 20 && $state['log_cursor'] === strlen($line), 'earlier handler captures reduce import capacity without losing unread input');
    $import();
    check(count($connector->store->read()['events']) === 21, 'next request imports the diagnostic deferred by handler captures');

    $reset(str_repeat('x', 1200000)."\n".$line);
    $import();
    $state = $connector->store->read();
    check(empty($state['events']) && $state['log_cursor'] <= 1048576 + 16383, 'malformed log input remains bounded per import');
    $import();
    check(count($connector->store->read()['events']) === 1, 'import resumes after bounded malformed input');
} finally {
    foreach (glob($dir.'/*') as $file) {
        unlink($file);
    }
    rmdir($dir);
}
