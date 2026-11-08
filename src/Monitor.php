<?php

namespace GuardLMS\Chamilo;

/** Bounded, redacted, best-effort capture. Does not enable diagnostic output. */
final class Monitor
{
    private static $registered = false;
    private static $busy = false;
    private static $count = 0;

    public static function register(Connector $connector)
    {
        if (self::$registered) {
            return;
        }
        $state = $connector->store->read();
        if (empty($state['server_enabled']) || !$connector->active($state)) {
            return;
        }
        self::$registered = true;
        $previous = null;
        $previous = set_error_handler(static function ($severity, $message, $file, $line) use ($connector, &$previous) {
            if (error_reporting() & $severity) {
                self::capture($connector, $message, $file, $line, 'php', 'PHP warning');
            }
            return $previous ? $previous($severity, $message, $file, $line) : false;
        });
        $exceptionHandler = null;
        $exceptionHandler = set_exception_handler(static function ($exception) use ($connector, &$exceptionHandler) {
            self::exception($connector, $exception);
            self::flush($connector);
            if ($exceptionHandler) {
                $exceptionHandler($exception);
            } else {
                restore_exception_handler();
                throw $exception;
            }
        });
        register_shutdown_function(static function () use ($connector) {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::capture($connector, $error['message'], $error['file'], $error['line'], 'php', 'PHP fatal error');
            }
            self::flush($connector);
        });
    }

    public static function exception(Connector $connector, \Throwable $exception)
    {
        $sql = $exception instanceof \PDOException || stripos(get_class($exception), 'DBAL') !== false || stripos(get_class($exception), 'SQL') !== false;
        $frames = [];
        foreach (array_slice($exception->getTrace(), 0, 20) as $frame) {
            $frames[] = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '').' at '.basename($frame['file'] ?? 'internal').':'.(int) ($frame['line'] ?? 0);
        }
        self::capture($connector, $exception->getMessage(), $exception->getFile(), $exception->getLine(), $sql ? 'sql' : 'php', get_class($exception), implode("\n", $frames));
    }

    public static function redact($text)
    {
        $text = substr((string) $text, 0, 8000);
        $text = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[email]', $text);
        $text = preg_replace('/\b(?:password|passwd|token|secret|api.?key|authorization|cookie|session|csrf|state|code)\b\s*[=:]\s*\S+/i', '[credential]', $text);
        $text = preg_replace('/\b(?:Bearer\s+)?[A-Za-z0-9_+\/=-]{32,}\b/', '[token]', $text);
        $text = preg_replace('/\b[a-f0-9]{32,}\b/i', '[hash]', $text);
        return $text;
    }

    public static function capture(Connector $connector, $message, $file, $line, $type, $class, $stack = null)
    {
        if (self::$busy || self::$count >= 20) {
            return;
        }
        self::$busy = true;
        try {
            $state = $connector->store->read();
            if (empty($state['server_enabled']) || !$connector->active($state)) {
                return;
            }
            self::$count++;
            // SQL diagnostics may contain arbitrary literals, credentials and records. Never send raw messages or binds.
            $message = $type === 'sql' ? 'Database operation failed; SQL statement and parameters withheld.' : 'PHP runtime error; diagnostic message withheld to protect application data.';
            $event = ['type' => $type, 'message' => $message, 'errorClass' => $class, 'handled' => false,
                'sourceFile' => basename($file), 'lineNumber' => (int) $line, 'pageUrl' => $connector->adapter->site().'/',
                'timestamp' => gmdate('c'), 'severity' => 'high', 'appVersion' => 'chamilo/guardlms-0.1.0'];
            if (is_string($stack) && $stack !== '') {
                $event['stackTrace'] = substr($stack, 0, 4000);
            }
            $connector->store->update(static function ($current) use ($event) {
                if (empty($current['server_enabled']) || empty($current['token'])) {
                    return $current;
                }
                $current['events'] = array_slice(array_merge($current['events'] ?? [], [['id' => bin2hex(random_bytes(12)), 'event' => $event]]), -100);
                return $current;
            });
        } catch (\Throwable $e) { /* No recursive capture or application failures. */
        } finally {
            self::$busy = false;
        }
    }

    public static function importNativeLog(Connector $connector)
    {
        $file = $connector->store->path('.php-errors.log');
        if (!is_readable($file)) {
            return;
        }
        $state = $connector->store->read();
        if (empty($state['server_enabled']) || !$connector->active($state)) {
            return;
        }
        $handle = fopen($file, 'r');
        if (!$handle) {
            return;
        }
        $size = filesize($file);
        $cursor = $state['log_cursor'] ?? 0;
        $inode = fileinode($file);
        if ($cursor > $size || ($state['log_inode'] ?? null) !== $inode) {
            $cursor = 0;
        }
        fseek($handle, $cursor);
        $read = 0;
        while (!feof($handle) && $read < 1048576) {
            $line = fgets($handle, 16384);
            if ($line === false) {
                break;
            }
            $read += strlen($line);
            if (stripos($line, 'SQLSTATE[') !== false) {
                self::capture($connector, '', 'database', 0, 'sql', 'Native database error');
            } elseif (preg_match('/PHP (Warning|Fatal error|Parse error|Notice):/i', $line, $match)) {
                self::capture($connector, '', 'php-runtime', 0, 'php', 'PHP '.$match[1]);
            }
        }
        $cursor = ftell($handle);
        fclose($handle);
        $connector->store->update(static function ($current) use ($cursor, $inode) {
            $current['log_cursor'] = $cursor;
            $current['log_inode'] = $inode;
            return $current;
        });
    }

    public static function flush(Connector $connector)
    {
        if (self::$busy) {
            return;
        }
        try {
            self::importNativeLog($connector);
        } catch (\Throwable $e) {
        }
        $lock = fopen($connector->store->path('.events.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return;
        }
        self::$busy = true;
        try {
            $state = $connector->store->read();
            if (empty($state['server_enabled']) || !$connector->active($state) || empty($state['events'])) {
                return;
            }
            $batch = array_slice($state['events'], 0, 20);
            Http::post('/api/sdk/errors/collect', ['errors' => array_column($batch, 'event'), '_origin' => $connector->adapter->site()], $state['sdk']['key'], PHP_SAPI === 'cli' ? 15 : 2);
            $ids = array_column($batch, 'id');
            $connector->store->update(static function ($current) use ($ids) {
                $current['events'] = array_values(array_filter($current['events'] ?? [], static function ($item) use ($ids) {
                    return !in_array($item['id'], $ids, true);
                }));
                return $current;
            });
        } catch (\Throwable $e) { /* Retry on cron; bounded local queue. */
        } finally {
            self::$busy = false;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
