<?php

require dirname(__DIR__).'/autoload.php';
use GuardLMS\Chamilo\State;
use GuardLMS\Chamilo\Monitor;

function verify($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    } echo "PASS $message\n";
}
$dir = sys_get_temp_dir().'/chamilo-guardlms-'.bin2hex(random_bytes(8));
putenv('CHAMILO_GUARDLMS_DATA='.$dir);
$a = new State('https://one.example');
$b = new State('https://two.example');
$a->update(static function ($state) {
    return ['token' => 'private-api-key', 'sdk' => ['key' => 'private-sdk-key']];
});
verify($a->read()['token'] === 'private-api-key', 'encrypted round trip');
verify(strpos(file_get_contents($a->path('.json')), 'private-api-key') === false, 'plaintext key absent');
verify($b->read() === [], 'access URL isolation');
$a->update(static function ($state) {
    $state['last_accepted'] = 123;
    return $state;
});
verify($a->read()['token'] === 'private-api-key', 'updates preserve existing credential');
file_put_contents($a->path('.json'), base64_encode(random_bytes(80)));
try {
    $a->read();
    throw new RuntimeException('Accepted tampered ciphertext');
} catch (RuntimeException $e) {
    verify(strpos($e->getMessage(), 'decrypt') !== false, 'tamper rejection');
}
verify(strpos(Monitor::redact('password=secret email=learner@example.com token=abcdef1234567890'), 'secret') === false, 'credential redaction');
verify(strpos(Monitor::redact('learner@example.com'), '@') === false, 'email redaction');
$adapter = new GuardLMS\Chamilo\Adapter();
verify($adapter->boolean('false') === false && $adapter->boolean('true') === true && $adapter->boolean('unreadable') === null, 'unknown settings remain unknown');
$fake = new class () extends GuardLMS\Chamilo\Adapter {
    public function site()
    {
        return 'https://expiry.example/subdirectory';
    }
};
$connector = new GuardLMS\Chamilo\Connector($fake);
$connector->store->update(static function ($state) {
    return ['token' => 'preserved-credential'];
});
$redirect = $connector->begin();
parse_str(parse_url($redirect, PHP_URL_QUERY), $query);
verify($query['callback'] === 'https://expiry.example/subdirectory/plugin/guardlms/callback.php', 'callback preserves the canonical subdirectory');
$_SESSION['guardlms_connect']['expires'] = time() - 1;
try {
    $connector->callback(str_repeat('a', 64), $query['state']);
    throw new RuntimeException('Expired callback accepted');
} catch (RuntimeException $e) {
    verify(strpos($e->getMessage(), 'expired') !== false, 'expired callback refused before network exchange');
}
verify(!isset($_SESSION['guardlms_connect']) && $connector->store->read()['token'] === 'preserved-credential', 'expired state consumed without replacing a working credential');
$lockfileAdapter = new class ($dir) extends GuardLMS\Chamilo\Adapter {
    private $root;
    public function __construct($root)
    {
        $this->root = $root;
    }
    public function codeRoot()
    {
        return $this->root;
    }
};
verify($lockfileAdapter->dependencies() === null, 'missing dependency metadata stays unknown');
file_put_contents($dir.'/composer.lock', json_encode(['packages' => [['name' => 'vendor/package', 'version' => '1.2.3']], 'packages-dev' => [['name' => 'vendor/tool', 'version' => '2.0']]]));
file_put_contents($dir.'/package-lock.json', json_encode(['packages' => ['' => ['name' => 'site'], 'node_modules/tool' => ['version' => '1.0'], 'node_modules/a/node_modules/tool' => ['version' => '2.0', 'dev' => true], 'node_modules/unknown' => []]]));
$dependencies = $lockfileAdapter->dependencies();
verify(count($dependencies) === 4 && $dependencies[1]['dev'] === true, 'Composer dev metadata and nested npm versions retained without invented versions');
file_put_contents($dir.'/package-lock.json', json_encode(['dependencies' => ['tool' => ['version' => '1.0', 'dependencies' => ['child' => ['version' => '2.0']]]]]));
verify(count($lockfileAdapter->dependencies()) === 4, 'npm v1 nested dependency metadata');
$lock = fopen($connector->store->path('.push.lock'), 'c');
flock($lock, LOCK_EX);
verify($connector->push() === ['skipped' => 'busy'], 'overlapping reporting is skipped before transport');
flock($lock, LOCK_UN);
fclose($lock);
foreach (glob($dir.'/*') as $file) {
    unlink($file);
} rmdir($dir);
