<?php

// Lifecycle scripts run only when included by Chamilo's native plugin manager.
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Use the native plugin manager.');
}

require_once __DIR__.'/../../main/inc/global.inc.php';
api_protect_admin_script();
require_once __DIR__.'/src/GuardlmsPlugin.php';
GuardlmsPlugin::create()->install();
