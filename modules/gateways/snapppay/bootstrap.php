<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'SnappPay\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $name = substr($class, strlen($prefix));
    if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $name)) {
        $file = __DIR__ . '/src/' . $name . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
