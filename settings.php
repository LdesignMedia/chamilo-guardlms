<?php
require_once __DIR__.'/common.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$nativeLanguage = function_exists('api_get_interface_language') ? (string) api_get_interface_language() : (string) \Chamilo\CoreBundle\Framework\Container::getRequest()->getLocale();
$lang = strpos($nativeLanguage, 'dutch') !== false || strpos($nativeLanguage, 'nl') === 0 ? 'nl' : 'en';
$labels = require __DIR__.'/lang/'.$lang.'.php';
$notice = $_SESSION['guardlms_notice'] ?? '';
unset($_SESSION['guardlms_notice']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['guardlms_csrf'], $_POST['csrf'])) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
    try {
        switch ($_POST['action'] ?? '') {
            case 'connect': header('Location: '.$connector->begin());
                exit;
            case 'disconnect': $connector->disconnect();
                $notice = $labels['disconnected'];
                break;
            case 'push': $connector->push();
                $notice = $labels['accepted'];
                break;
            case 'save':
                $connector->store->update(static function ($state) {
                    foreach (['browser_enabled', 'analytics_enabled', 'server_enabled', 'config_enabled'] as $key) {
                        $state[$key] = isset($_POST[$key]);
                    }
                    if (empty($state['server_enabled'])) {
                        unset($state['events']);
                    }
                    return $state;
                });
                $notice = $labels['saved'];
                break;
        }
    } catch (Throwable $e) {
        $notice = $e->getMessage();
    }
}
$state = $connector->store->read();
?>
<!doctype html><html lang="<?= $lang ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GuardLMS — Chamilo</title><link rel="stylesheet" href="assets/settings.css"></head><body><main>
<h1>GuardLMS for Chamilo</h1><p><?= guardlms_escape($labels['intro']) ?></p>
<?php if ($notice): ?><p role="status"><?= guardlms_escape($notice) ?></p><?php endif ?>
<dl><dt><?= guardlms_escape($labels['site']) ?></dt><dd><?= guardlms_escape($connector->adapter->site()) ?></dd><dt><?= guardlms_escape($labels['status']) ?></dt><dd><?= guardlms_escape(empty($state['token']) ? $labels['disconnected'] : (!empty($state['last_error']) ? $state['last_error'] : $labels['connected'])) ?></dd><dt><?= guardlms_escape($labels['last']) ?></dt><dd><?= empty($state['last_accepted']) ? '—' : guardlms_escape(gmdate('Y-m-d H:i:s', $state['last_accepted']).' UTC') ?></dd></dl>
<form method="post"><input type="hidden" name="csrf" value="<?= guardlms_escape($_SESSION['guardlms_csrf']) ?>">
<button name="action" value="connect"><?= guardlms_escape(empty($state['token']) ? $labels['connect'] : $labels['reconnect']) ?></button>
<?php if (!empty($state['token'])): ?><button name="action" value="push"><?= guardlms_escape($labels['push']) ?></button><button name="action" value="disconnect"><?= guardlms_escape($labels['disconnect']) ?></button><a href="<?= guardlms_escape(\GuardLMS\Chamilo\Http::base().'/websites/'.(int) $state['website_id']) ?>"><?= guardlms_escape($labels['dashboard']) ?></a><?php endif ?>
<fieldset><legend><?= guardlms_escape($labels['optional']) ?></legend>
<?php foreach (['config_enabled','browser_enabled','analytics_enabled','server_enabled'] as $key): ?><label><input type="checkbox" name="<?= $key ?>" <?= !empty($state[$key]) ? 'checked' : '' ?>> <?= guardlms_escape($labels[$key]) ?></label><?php endforeach ?>
<p><?= guardlms_escape($labels['privacy']) ?></p><button name="action" value="save"><?= guardlms_escape($labels['save']) ?></button></fieldset></form>
<details><summary><?= guardlms_escape($labels['preview']) ?></summary><pre><?php try {
    echo guardlms_escape(json_encode($connector->adapter->inventory(!empty($state['config_enabled'])), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
} catch (Throwable $e) {
    echo guardlms_escape($e->getMessage());
} ?></pre></details>
<p><?= guardlms_escape($labels['cron']) ?></p><a href="https://github.com/LdesignMedia/chamilo-guardlms#installation"><?= guardlms_escape($labels['guide']) ?></a>
</main></body></html>
