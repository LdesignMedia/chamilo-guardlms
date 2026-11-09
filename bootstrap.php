<?php

// Optional php.ini auto_prepend_file. No Chamilo core edits or recursive runtime bootstrap.
require_once __DIR__.'/autoload.php';
try {
    if (function_exists('api_get_path')) {
        $connector = new \GuardLMS\Chamilo\Connector();
    } else {
        // Trusted per-vhost canonical URL; never infer the site from an incoming Host header.
        $site = rtrim(getenv('CHAMILO_GUARDLMS_SITE') ?: '', '/');
        if (!preg_match('~^https://[^?#]+$~D', $site)) {
            return;
        }
        if (PHP_SAPI !== 'cli') {
            $host = parse_url($site, PHP_URL_HOST);
            $port = parse_url($site, PHP_URL_PORT);
            if (strtolower($_SERVER['HTTP_HOST'] ?? '') !== strtolower($host.($port ? ':'.$port : ''))) {
                return;
            }
            $basepath = rtrim(parse_url($site, PHP_URL_PATH) ?: '', '/');
            if ($basepath && strpos(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH).'/', $basepath.'/') !== 0) {
                return;
            }
        }
        $adapter = new class ($site) extends \GuardLMS\Chamilo\Adapter {
            private $siteurl;
            public function __construct($site)
            {
                $this->siteurl = $site;
            }
            public function site()
            {
                return $this->siteurl;
            }
        };
        $connector = new \GuardLMS\Chamilo\Connector($adapter);
    }
    $state = $connector->store->read();
    if (!empty($state['server_enabled']) && $connector->active($state)) {
        // Native Chamilo 1.11 Database::handleError logs handled SQL errors before exiting.
        // Keep that log private and import only generic, redacted error observations.
        $log = $connector->store->path('.php-errors.log');
        if (!is_file($log)) {
            touch($log);
            chmod($log, 0600);
        }
        ini_set('log_errors', '1');
        ini_set('error_log', $log);
        \GuardLMS\Chamilo\Monitor::register($connector);
    }
} catch (\Throwable $e) { /* Monitoring never changes application availability. */
}
