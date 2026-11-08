<?php

namespace GuardLMS\Chamilo;

/** Native runtime APIs; never substitute a directory scan for installed inventory. */
class Adapter
{
    public function site()
    {
        if (($this->modern() || (function_exists('api_is_multiple_url_enabled') && api_is_multiple_url_enabled())) && function_exists('api_get_access_url') && function_exists('api_get_current_access_url_id')) {
            $url = api_get_access_url(api_get_current_access_url_id());
            if (is_array($url) && !empty($url['url'])) {
                return rtrim($url['url'], '/');
            }
        }
        return rtrim(api_get_path(WEB_PATH), '/');
    }

    public function root()
    {
        return rtrim(api_get_path(SYS_PATH), '/');
    }
    public function modern()
    {
        return is_file(dirname($this->root()).'/version.php') && is_dir(dirname($this->root()).'/src/CoreBundle');
    }
    public function codeRoot()
    {
        return $this->modern() ? dirname($this->root()) : $this->root();
    }

    public function version()
    {
        $file = $this->codeRoot().'/version.php';
        if (is_file($file)) {
            $version = require $file;
            if (is_array($version) && isset($version['new_version'])) {
                return $version['new_version'];
            }
        }
        $new_version = null;
        if (is_file($this->root().'/main/install/version.php')) {
            require $this->root().'/main/install/version.php';
        }
        if (is_string($new_version) && $new_version !== '') {
            return $new_version;
        }
        $version = api_get_setting('chamilo_database_version');
        if (!is_string($version) || $version === '') {
            throw new \RuntimeException('Cannot determine the installed Chamilo version.');
        }
        return $version;
    }

    public function plugins()
    {
        $manager = new \AppPlugin();
        $names = $manager->getInstalledPlugins();
        if (!is_array($names)) {
            throw new \RuntimeException('Cannot read the complete native plugin registry.');
        }
        $rows = [];
        foreach ($names as $name) {
            $info = $manager->getPluginInfo($name);
            $enabled = null;
            if ($this->modern()) {
                $entity = \Chamilo\CoreBundle\Framework\Container::getPluginRepository()->findOneByTitle($name);
                if ($entity) {
                    $rel = $entity->getConfigurationsByAccessUrl(\Chamilo\CoreBundle\Framework\Container::getAccessUrlUtil()->getCurrent());
                    $enabled = $rel ? (bool) $rel->isActive() : false;
                }
            } elseif (method_exists($manager, 'getInstalledPluginsInCurrentUrl')) {
                $enabled = in_array($name, $manager->getInstalledPluginsInCurrentUrl(), true);
            } elseif (isset($info['settings']['enabled'])) {
                $enabled = $this->boolean($info['settings']['enabled']);
            }
            $rows[] = ['id' => (string) $name, 'version' => isset($info['version']) ? (string) $info['version'] : null, 'enabled' => $enabled];
        }
        return $rows;
    }

    public function boolean($value)
    {
        if (in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true)) {
            return true;
        }
        if (in_array($value, [false, 0, '0', 'false', 'off', 'no', ''], true)) {
            return false;
        }
        return null;
    }

    public function settings()
    {
        $result = [];
        $map = $this->modern() ? ['mfa_enabled' => 'security.2fa_enable', 'login_max_attempts' => 'security.login_max_attempt_before_blocking_account', 'reset_token_lifetime' => 'security.user_reset_password_token_limit']
            : ['login_max_attempts' => 'login_max_attempt_before_blocking_account', 'reset_token_lifetime' => 'user_reset_password_token_limit'];
        foreach ($map as $key => $native) {
            $value = api_get_setting($native);
            if ($value === null || $value === '') {
                continue;
            }
            if (in_array($key, ['password_policy', 'mfa_enabled'], true)) {
                $value = $this->boolean($value);
                if ($value !== null) {
                    if ($key === 'mfa_enabled') {
                        $result['mfa_available'] = true;
                    }
                    $result[$key] = $value;
                }
            } elseif (is_numeric($value) && $value >= 0) {
                $result[$key] = (int) $value;
            }
        }
        // A UI strength meter does not establish server-side policy enforcement.
        // Leave password_policy unknown until an adapter can prove enforcement.
        if (!$this->modern() && function_exists('api_get_configuration_value')) {
            $limit = api_get_configuration_value('login_max_attempt_before_blocking_account');
            if (is_numeric($limit) && $limit >= 0) {
                $result['login_max_attempts'] = (int) $limit;
            }
        }
        foreach (['debug_display' => 'display_errors', 'cookie_secure' => 'session.cookie_secure', 'cookie_httponly' => 'session.cookie_httponly'] as $key => $ini) {
            if ($key === 'debug_display' && PHP_SAPI === 'cli') {
                continue;
            }
            $value = ini_get($ini);
            if ($value !== false) {
                $result[$key] = $this->boolean($value);
            }
        }
        $same = ucfirst(strtolower((string) ini_get('session.cookie_samesite')));
        if (in_array($same, ['Lax', 'Strict', 'None'], true)) {
            $result['cookie_samesite'] = $same;
        }
        return $result;
    }

    public function inventory($includeConfig)
    {
        $result = ['siteurl' => $this->site(), 'generatedtime' => time(), 'chamilo' => ['version' => $this->version(), 'plugins' => $this->plugins()], 'php' => ['version' => PHP_VERSION, 'extensions' => get_loaded_extensions()], 'server' => ['os_family' => PHP_OS_FAMILY, 'arch' => php_uname('m')]];
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $result['server']['webserver'] = substr($_SERVER['SERVER_SOFTWARE'], 0, 255);
        }
        if (is_readable('/etc/os-release')) {
            $os = parse_ini_file('/etc/os-release');
            foreach (['ID' => 'os_id', 'NAME' => 'os_name', 'VERSION_ID' => 'os_version', 'PRETTY_NAME' => 'os_pretty'] as $source => $key) {
                if (isset($os[$source])) {
                    $result['server'][$key] = substr($os[$source], 0, $key === 'os_version' ? 50 : 100);
                }
            }
        }
        $query = \Database::query('SELECT VERSION() AS version');
        $row = \Database::fetch_array($query);
        if (!empty($row['version'])) {
            $result['database'] = ['type' => stripos($row['version'], 'MariaDB') !== false ? 'MariaDB' : 'MySQL', 'version' => (string) $row['version']];
        }
        $dependencies = $this->dependencies();
        if ($dependencies !== null) {
            $result['dependencies'] = $dependencies;
        }
        if ($includeConfig) {
            $result['security_config'] = $this->settings();
        }
        return $result;
    }

    public function dependencies()
    {
        $rows = [];
        $observed = false;
        foreach (['composer.lock', 'package-lock.json'] as $file) {
            $path = $this->codeRoot().'/'.$file;
            if (!is_readable($path) || filesize($path) > 16777216) {
                continue;
            }
            $data = json_decode(file_get_contents($path), true);
            if (!is_array($data)) {
                continue;
            }
            $observed = true;
            if ($file === 'composer.lock') {
                foreach (['packages' => false, 'packages-dev' => true] as $group => $dev) {
                    foreach ($data[$group] ?? [] as $p) {
                        if (!empty($p['name']) && !empty($p['version'])) {
                            $rows['composer:'.$p['name']] = ['ecosystem' => 'composer', 'name' => $p['name'], 'version' => $p['version'], 'dev' => $dev];
                        }
                    }
                }
            } else {
                if (!isset($data['packages']) && isset($data['dependencies'])) {
                    $this->npmDependencies($data['dependencies'], $rows);
                }
                foreach ($data['packages'] ?? [] as $path => $p) {
                    if ($path === '' || empty($p['version'])) {
                        continue;
                    }
                    $name = $p['name'] ?? preg_replace('~^.*node_modules/~', '', $path);
                    $rows['npm:'.$name.':'.$p['version']] = ['ecosystem' => 'npm', 'name' => $name, 'version' => $p['version'], 'dev' => !empty($p['dev'])];
                }
            }
        }
        return $observed ? array_slice(array_values($rows), 0, 2000) : null;
    }
    private function npmDependencies(array $dependencies, array &$rows, $depth = 0)
    {
        if ($depth > 30 || count($rows) >= 2000) {
            return;
        }
        foreach ($dependencies as $name => $package) {
            if (!is_array($package)) {
                continue;
            }
            if (!empty($package['version'])) {
                $rows['npm:'.$name.':'.$package['version']] = ['ecosystem' => 'npm', 'name' => $name, 'version' => $package['version'], 'dev' => !empty($package['dev'])];
            }
            if (!empty($package['dependencies']) && is_array($package['dependencies'])) {
                $this->npmDependencies($package['dependencies'], $rows, $depth + 1);
            }
            if (count($rows) >= 2000) {
                break;
            }
        }
    }

}
