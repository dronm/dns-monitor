<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
	$prefix = 'DnsMonitor\\';
	if (str_starts_with($class, $prefix)) {
		$name = substr($class, strlen($prefix));
		if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $name) === 1) {
			$file = __DIR__ . '/' . $name . '.php';
			if (is_file($file)) {
				require $file;
			}
		}
	}
});
