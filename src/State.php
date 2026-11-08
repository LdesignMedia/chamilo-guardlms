<?php

namespace GuardLMS\Chamilo;

/** Per-access-URL authenticated storage, outside the document root. */
final class State
{
    private $dir;
    private $scope;
    private $key;

    public function __construct($site)
    {
        $this->dir = rtrim(getenv('CHAMILO_GUARDLMS_DATA') ?: '/var/lib/chamilo-guardlms', '/');
        if ($this->dir[0] !== '/' || (isset($_SERVER['DOCUMENT_ROOT']) && realpath($_SERVER['DOCUMENT_ROOT']) && strpos($this->dir.'/', realpath($_SERVER['DOCUMENT_ROOT']).'/') === 0)) {
            throw new \RuntimeException('GuardLMS storage must be outside the public directory.');
        }
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Create writable private GuardLMS storage first.');
        }
        $this->scope = hash('sha256', rtrim($site, '/'));
        $keyfile = $this->dir.'/installation.key';
        $lock = fopen($this->dir.'/key.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock GuardLMS storage.');
        }
        try {
            if (!is_file($keyfile)) {
                $this->writeFile($keyfile, random_bytes(32));
            }
            $this->key = file_get_contents($keyfile);
            if (strlen($this->key) !== 32) {
                throw new \RuntimeException('Invalid GuardLMS installation key. Restore the original key.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function path($suffix)
    {
        return $this->dir.'/'.$this->scope.$suffix;
    }

    public function read()
    {
        $file = $this->path('.json');
        if (!is_file($file)) {
            return [];
        }
        $raw = base64_decode(file_get_contents($file), true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Invalid encrypted GuardLMS settings.');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $this->scope);
        if ($plain === false) {
            throw new \RuntimeException('Cannot decrypt GuardLMS settings. Restore the original installation key.');
        }
        return json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
    }

    public function update(callable $change)
    {
        $lock = fopen($this->path('.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock GuardLMS settings.');
        }
        try {
            $state = $change($this->read());
            $iv = random_bytes(12);
            $cipher = openssl_encrypt(json_encode($state, JSON_THROW_ON_ERROR), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, $this->scope);
            if ($cipher === false) {
                throw new \RuntimeException('Unable to encrypt GuardLMS settings.');
            }
            $this->writeFile($this->path('.json'), base64_encode($iv.$tag.$cipher));
            return $state;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function writeFile($path, $content)
    {
        $temp = tempnam($this->dir, '.write-');
        try {
            if (!chmod($temp, 0600) || file_put_contents($temp, $content) !== strlen($content) || !rename($temp, $path)) {
                throw new \RuntimeException('Unable to save private GuardLMS settings.');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }
}
