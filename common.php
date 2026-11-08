<?php

require_once __DIR__.'/../../main/inc/global.inc.php';
api_protect_admin_script();
require_once __DIR__.'/autoload.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$connector = new \GuardLMS\Chamilo\Connector();
if (empty($_SESSION['guardlms_csrf'])) {
    $_SESSION['guardlms_csrf'] = bin2hex(random_bytes(32));
}
function guardlms_escape($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
