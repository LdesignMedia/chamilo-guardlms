<?php

if (!class_exists('Plugin', false)) {
    require_once __DIR__.'/../../main/inc/global.inc.php';
}
require_once __DIR__.'/src/GuardlmsPlugin.php';
$region = $plugin_info['current_region'] ?? '';
if ($region) {
    echo GuardlmsPlugin::create()->renderRegion($region);
} else {
    header('Location: settings.php');
    exit;
}
