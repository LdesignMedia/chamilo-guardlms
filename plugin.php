<?php

require_once __DIR__.'/src/GuardlmsPlugin.php';
$plugin_info = GuardlmsPlugin::create()->get_info();
$plugin_info['title'] = 'GuardLMS';
$plugin_info['comment'] = 'Software inventory, security checks and optional monitoring. Open plugin/guardlms/settings.php to connect.';
$plugin_info['source'] = 'community';
