<?php

namespace GuardLMS\Chamilo;

final class Connector
{
    public $adapter;
    public $store;

    public function __construct(?Adapter $adapter = null)
    {
        $probe = $adapter ?: new Adapter();
        $this->adapter = $adapter ?: ($probe->modern() ? new ModernAdapter() : new LegacyAdapter());
        $this->store = new State($this->adapter->site());
    }

    public function push($dueOnly = false)
    {
        $lock = fopen($this->store->path('.push.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['skipped' => 'busy'];
        }
        try {
            $state = $this->store->read();
            if (empty($state['token'])) {
                return ['skipped' => 'disconnected'];
            }
            if ($dueOnly && ($state['last_accepted'] ?? 0) > time() - 86400) {
                return ['skipped' => 'not_due'];
            }
            $payload = $this->adapter->inventory(!empty($state['config_enabled']));
            $result = Http::post('/api/externalpush/chamilo', $payload, $state['token']);
            $this->store->update(static function ($current) use ($state) {
                if (($current['token'] ?? null) === $state['token']) {
                    $current['last_accepted'] = time();
                    unset($current['last_error']);
                }
                return $current;
            });
            try {
                $this->refreshSdk();
            } catch (\Throwable $e) { /* Inventory is already accepted. Retry SDK configuration at the next cron. */
            }
            return $result;
        } catch (\Throwable $e) {
            $this->store->update(static function ($state) use ($e) {
                $state['last_error'] = $e->getMessage();
                return $state;
            });
            throw $e;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function refreshSdk($action = 'fetch')
    {
        $state = $this->store->read();
        if (empty($state['token'])) {
            return;
        }
        $result = Http::post('/api/integrations/sdk-key', ['siteurl' => $this->adapter->site(), 'platform' => 'chamilo', 'action' => $action], $state['token']);
        $sdk = $result['data'];
        if ($action === 'fetch' && empty($sdk['key']) && (empty($state['sdk']['key']) || ($state['sdk']['key_prefix'] ?? null) !== ($sdk['key_prefix'] ?? null)) && ($sdk['key_status'] ?? '') === 'exists') {
            $sdk = Http::post('/api/integrations/sdk-key', ['siteurl' => $this->adapter->site(), 'platform' => 'chamilo', 'action' => 'rotate'], $state['token'])['data'];
        }
        $this->store->update(static function ($current) use ($sdk, $state) {
            if (($current['token'] ?? null) !== $state['token']) {
                return $current;
            }
            if (empty($sdk['key']) && !empty($current['sdk']['key']) && ($sdk['key_status'] ?? '') !== 'revoked') {
                $sdk['key'] = $current['sdk']['key'];
            }
            $current['sdk'] = $sdk;
            return $current;
        });
    }

    public function begin()
    {
        $state = bin2hex(random_bytes(32));
        $_SESSION['guardlms_connect'] = ['state' => $state, 'site' => $this->adapter->site(), 'expires' => time() + 600, 'generation' => $this->store->read()['generation'] ?? 0];
        return Http::base().'/connect/chamilo?'.http_build_query(['siteurl' => $this->adapter->site(), 'callback' => $this->adapter->site().'/plugin/guardlms/callback.php', 'state' => $state]);
    }

    public function callback($code, $state)
    {
        $pending = $_SESSION['guardlms_connect'] ?? [];
        unset($_SESSION['guardlms_connect']);
        $generation = $this->store->read()['generation'] ?? 0;
        if (!is_string($state) || !is_string($code) || !preg_match('/^[A-Za-z0-9]{64}$/D', $code)
            || empty($pending['state']) || !hash_equals($pending['state'], $state) || $pending['expires'] < time()
            || $pending['site'] !== $this->adapter->site() || $pending['generation'] !== $generation) {
            throw new \RuntimeException('Connection expired or already used. Start Connect again.');
        }
        $result = Http::post('/api/integrations/exchange', ['code' => $code, 'state' => $state, 'siteurl' => $this->adapter->site()]);
        $data = $result['data'];
        if (($data['siteurl'] ?? '') !== $this->adapter->site() || ($data['pushpath'] ?? '') !== '/api/externalpush/chamilo' || empty($data['token'])) {
            throw new \RuntimeException('GuardLMS returned an unexpected site binding.');
        }
        $this->store->update(static function ($current) use ($data, $generation) {
            if (($current['generation'] ?? 0) !== $generation) {
                throw new \RuntimeException('Connection was cancelled.');
            }
            $current['token'] = $data['token'];
            $sdk = $data['sdk'] ?? [];
            if (empty($sdk['key']) && !empty($current['sdk']['key']) && ($current['sdk']['key_prefix'] ?? null) === ($sdk['key_prefix'] ?? null)) {
                $sdk['key'] = $current['sdk']['key'];
            }
            $current['sdk'] = $sdk;
            $current['verification_token'] = $data['verification_token'] ?? '';
            $current['website_id'] = $data['website_id'];
            unset($current['last_error']);
            return $current;
        });
        return $this->push();
    }

    public function disconnect()
    {
        // Credential revocation is attempted before forgetting local secrets. Never retain local capture after disconnect.
        try {
            $state = $this->store->read();
            if (!empty($state['token'])) {
                Http::post('/api/integrations/disconnect', ['siteurl' => $this->adapter->site(), 'platform' => 'chamilo'], $state['token']);
            }
        } catch (\Throwable $e) { /* Remote access can also be revoked in GuardLMS. */
        }
        $this->store->update(static function ($state) {
            return ['generation' => ($state['generation'] ?? 0) + 1, 'browser_enabled' => false, 'analytics_enabled' => false, 'server_enabled' => false, 'config_enabled' => false];
        });
        unset($_SESSION['guardlms_connect']);
        // Events are encrypted inside state and are discarded with it.
    }

    public function active(array $state)
    {
        $sdk = $state['sdk'] ?? [];
        return !empty($state['token']) && !empty($sdk['key']) && !empty($sdk['enabled']) && !empty($sdk['subscription_active']) && !empty($sdk['allowed_domains_match']);
    }

    public function markup($head = false)
    {
        $state = $this->store->read();
        $html = '';
        if (!empty($state['token']) && !empty($state['verification_token'])) {
            $html = '<meta name="guardlms-verification" content="'.htmlspecialchars($state['verification_token'], ENT_QUOTES, 'UTF-8').'">';
        }
        if (!$this->active($state) || (empty($state['browser_enabled']) && empty($state['analytics_enabled']))) {
            return $html;
        }
        $sdk = $state['sdk'];
        $config = ['apiKey' => $sdk['key'], 'endpoint' => $sdk['errors_endpoint'], 'autoDetectErrors' => !empty($state['browser_enabled']),
            'sampleRate' => $sdk['sample_rate'], 'maxBreadcrumbs' => $sdk['max_breadcrumbs'], 'maxErrorsPerMinute' => $sdk['max_errors_per_minute'], 'ignoreErrors' => $sdk['ignored_errors'],
            'analytics' => !empty($state['analytics_enabled']) && !empty($sdk['analytics_allowed']) ? ['enabled' => true, 'endpoint' => $sdk['analytics_endpoint'], 'sampleRate' => $sdk['analytics_sample_rate'], 'trackScrollDepth' => true, 'trackLayouts' => false] : false];
        $settings = json_encode(['src' => $sdk['sdk_url'], 'config' => $config], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return $html.'<script>(function(c){if(window.__guardlmsChamilo){return;}window.__guardlmsChamilo=true;var s=document.createElement("script");s.src=c.src;s.async=true;s.onload=function(){if(window.GuardLMS){window.GuardLMS.init(c.config);}};s.onerror=function(){window.__guardlmsChamilo=false;};document.head.appendChild(s);})('.$settings.');</script>';
    }
}
