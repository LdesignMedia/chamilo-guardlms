<?php

require_once __DIR__.'/common.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
try {
    $connector->callback($_GET['code'] ?? null, $_GET['state'] ?? null);
    $_SESSION['guardlms_notice'] = 'Connected. Inventory accepted.';
} catch (Throwable $e) {
    $_SESSION['guardlms_notice'] = $e->getMessage();
}
header('Location: '.$connector->adapter->site().'/plugin/guardlms/settings.php');
exit;
