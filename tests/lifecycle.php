<?php

namespace GuardLMS\Chamilo {
    // Isolate the HTTP boundary; never open a network connection in these checks.
    function curl_init($url)
    {
        $GLOBALS['fixture_requests'][] = $url;
        return new \stdClass();
    }
    function curl_setopt_array($handle, $options)
    {
        $GLOBALS['fixture_options'] = $options;
        return true;
    }
    function curl_exec($handle)
    {
        $body = json_encode($GLOBALS['fixture_response']);
        $GLOBALS['fixture_options'][CURLOPT_WRITEFUNCTION]($handle, $body);
        return true;
    }
    function curl_getinfo($handle, $option)
    {
        return $GLOBALS['fixture_status'];
    }
    function curl_close($handle)
    {
    }
}

namespace {
    require dirname(__DIR__).'/autoload.php';
    function verify($condition, $message)
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
        echo 'PASS '.$message."\n";
    }
    $dir = sys_get_temp_dir().'/guardlms-lifecycle-'.bin2hex(random_bytes(8));
    putenv('CHAMILO_GUARDLMS_DATA='.$dir);
    $adapter = new class () extends GuardLMS\Chamilo\Adapter {
        public function site()
        {
            return 'https://lifecycle.example';
        }
        public function inventory($includeConfig)
        {
            return ['siteurl' => $this->site()];
        }
    };
    $c = new GuardLMS\Chamilo\Connector($adapter);
    $c->store->update(static function ($state) {
        return ['token' => 'inventory-fixture', 'last_accepted' => 100];
    });
    $GLOBALS['fixture_status'] = 200;
    $GLOBALS['fixture_response'] = ['data' => ['stale' => true]];
    try {
        $c->push();
        throw new RuntimeException('Stale snapshot treated as accepted');
    } catch (RuntimeException $error) {
        verify(strpos($error->getMessage(), 'system clock') !== false, 'ignored stale snapshot gives actionable failure');
    }
    $state = $c->store->read();
    verify($state['last_accepted'] === 100 && isset($state['last_error']), 'ignored snapshot preserves the successful reporting time');
    verify(count($GLOBALS['fixture_requests']) === 1, 'ignored snapshot does not refresh SDK as a successful report');
    $GLOBALS['fixture_status'] = 401;
    $GLOBALS['fixture_response'] = ['message' => 'Rejected'];
    try {
        $c->push();
        throw new RuntimeException('Invalid key accepted');
    } catch (RuntimeException $error) {
        verify($error->getCode() === 401 && $c->store->read()['last_accepted'] === 100, 'credential refusal preserves successful reporting time');
    }
    $c->store->update(static function ($state) {
        $state['server_enabled'] = true;
        $state['events'] = [['event' => ['message' => 'old queued event']]];
        $state['sdk'] = ['key' => 'public-fixture', 'enabled' => true, 'subscription_active' => true, 'allowed_domains_match' => true];
        return $state;
    });
    $log = $c->store->path('.php-errors.log');
    file_put_contents($log, "PHP Warning: earlier consent period\n");
    $c->saveOptions(['server_enabled' => false]);
    verify(empty($c->store->read()['events']), 'server opt-out discards queued events');
    file_put_contents($log, "PHP Warning: after opting out\n", FILE_APPEND);
    $c->saveOptions(['server_enabled' => true]);
    GuardLMS\Chamilo\Monitor::importNativeLog($c);
    verify(empty($c->store->read()['events']), 're-enabling capture does not replay diagnostics from the disabled period');
    file_put_contents($log, "PHP Warning: new consented diagnostic\n", FILE_APPEND);
    GuardLMS\Chamilo\Monitor::importNativeLog($c);
    verify(count($c->store->read()['events']) === 1, 'new diagnostics after re-enabling capture are recorded');
    foreach (glob($dir.'/*') as $file) {
        unlink($file);
    }
    rmdir($dir);
}
