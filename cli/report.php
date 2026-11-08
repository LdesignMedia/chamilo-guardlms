<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__.'/../../../main/inc/global.inc.php';
require_once dirname(__DIR__).'/autoload.php';
try {
    $connector = new \GuardLMS\Chamilo\Connector();
    if (in_array('--preview', $argv, true)) {
        $result = $connector->adapter->inventory(!empty($connector->store->read()['config_enabled']));
    } else {
        $result = $connector->push(in_array('--due', $argv, true));
        \GuardLMS\Chamilo\Monitor::flush($connector);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}
