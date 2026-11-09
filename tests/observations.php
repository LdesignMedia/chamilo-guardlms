<?php

require dirname(__DIR__).'/autoload.php';

function api_get_setting($name)
{
    return $name === 'login_max_attempt_before_blocking_account' ? '5' : '300';
}

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}

$cli = new class () extends GuardLMS\Chamilo\Adapter {
    public function modern()
    {
        return false;
    }
};
// Simulate the web branch without bootstrapping Chamilo or contacting a server.
$web = new class () extends GuardLMS\Chamilo\Adapter {
    public function modern()
    {
        return false;
    }
    protected function webRuntime()
    {
        return true;
    }
};

ini_set('display_errors', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_httponly', '0');
ini_set('session.cookie_samesite', 'Strict');
$cliSettings = $cli->settings();
$webSettings = $web->settings();
foreach (['debug_display', 'cookie_secure', 'cookie_httponly', 'cookie_samesite'] as $key) {
    check(!array_key_exists($key, $cliSettings), 'CLI omits '.$key.' web-runtime observation');
}
check($cliSettings['login_max_attempts'] === 5 && $cliSettings['reset_token_lifetime'] === 300, 'CLI preserves native platform configuration observations');
check($webSettings['debug_display'] === true, 'web branch observes display_errors');
check($webSettings['cookie_secure'] === true && $webSettings['cookie_httponly'] === false && $webSettings['cookie_samesite'] === 'Strict', 'web branch observes actual cookie ini settings');
check($webSettings['login_max_attempts'] === 5 && $webSettings['reset_token_lifetime'] === 300, 'web branch preserves native platform configuration observations');
