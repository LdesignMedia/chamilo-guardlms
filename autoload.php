<?php

spl_autoload_register(static function ($class) {
    $prefix = 'GuardLMS\\Chamilo\\';
    if (strpos($class, $prefix) === 0) {
        $name = substr($class, strlen($prefix));
        if (preg_match('/^[A-Za-z]+$/D', $name) && is_file(__DIR__.'/src/'.$name.'.php')) {
            require_once __DIR__.'/src/'.$name.'.php';
        }
    }
});
