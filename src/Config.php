<?php

declare(strict_types=1);

namespace DnsMonitor;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class Config
{
	public static function load(string $file): array
	{
		$resolved = realpath($file);
		if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
			throw new InvalidArgumentException('Cannot read configuration: ' . $file);
		}
		$data = self::readJson($resolved);
		if (!is_array($data) || array_is_list($data)) {
			throw new InvalidArgumentException('Configuration must be a JSON object.');
		}
		self::keys($data, ['dns_manager', 'servers_file', 'state_file', 'lock_file', 'log_file', 'check', 'apply_on_first_run', 'allow_all_down_publish', 'internet_check', 'notify_script', 'notify_timeout_sec', 'retry'], 'configuration');
		$base = dirname($resolved);
		$config = ['config_file' => $resolved];
		foreach (['servers_file', 'state_file', 'lock_file', 'log_file'] as $name) {
			$config[$name] = self::path(self::string($data[$name] ?? null, $name), $base);
		}
		if (count(array_unique([$config['state_file'], $config['lock_file'], $config['log_file'], $config['servers_file'], $resolved])) !== 5) {
			throw new InvalidArgumentException('Configuration, inventory, state, lock, and log paths must be different.');
		}
		foreach (['apply_on_first_run' => false, 'allow_all_down_publish' => false] as $name => $default) {
			$config[$name] = self::boolean($data[$name] ?? $default, $name);
		}
		$manager = self::object($data['dns_manager'] ?? null, 'dns_manager');
		self::keys($manager, ['php_binary', 'script', 'config', 'zone', 'timeout_sec'], 'dns_manager');
		$zone = strtolower(rtrim(self::string($manager['zone'] ?? null, 'dns_manager.zone'), '.'));
		if (strlen($zone) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D', $zone) !== 1) {
			throw new InvalidArgumentException('Invalid dns_manager.zone.');
		}
		$php = self::string($manager['php_binary'] ?? 'php', 'dns_manager.php_binary');
		$config['dns_manager'] = [
			'php_binary' => str_contains($php, '/') ? self::path($php, $base) : $php,
			'script' => self::path(self::string($manager['script'] ?? null, 'dns_manager.script'), $base),
			'config' => self::path(self::string($manager['config'] ?? null, 'dns_manager.config'), $base),
			'zone' => $zone,
			'timeout_sec' => self::integer($manager['timeout_sec'] ?? 600, 'dns_manager.timeout_sec', 1, 86400),
		];
		$check = self::object($data['check'] ?? [], 'check');
		self::keys($check, ['scheme', 'port', 'path', 'timeout_sec', 'connect_timeout_sec', 'expected_status', 'expected_body', 'max_body_bytes'], 'check');
		$scheme = $check['scheme'] ?? 'http';
		if (!in_array($scheme, ['http', 'https'], true)) {
			throw new InvalidArgumentException('check.scheme must be http or https.');
		}
		$path = self::string($check['path'] ?? '/replica_status.php', 'check.path');
		if (!str_starts_with($path, '/') || preg_match('/[\x00-\x20\x7f#]/', $path) === 1) {
			throw new InvalidArgumentException('check.path must start with / and contain no whitespace, control characters, or fragment.');
		}
		$config['check'] = [
			'scheme' => $scheme,
			'port' => self::integer($check['port'] ?? ($scheme === 'https' ? 443 : 80), 'check.port', 1, 65535),
			'path' => $path,
			'timeout_sec' => self::integer($check['timeout_sec'] ?? 3, 'check.timeout_sec', 1, 3600),
			'connect_timeout_sec' => self::integer($check['connect_timeout_sec'] ?? 2, 'check.connect_timeout_sec', 1, 3600),
			'expected_status' => self::integer($check['expected_status'] ?? 200, 'check.expected_status', 100, 599),
			'expected_body' => self::string($check['expected_body'] ?? 'OK', 'check.expected_body', true),
			'max_body_bytes' => self::integer($check['max_body_bytes'] ?? 65536, 'check.max_body_bytes', 1, 16777216),
		];
		$retry = self::object($data['retry'] ?? [], 'retry');
		self::keys($retry, ['down_after_failures', 'up_after_successes'], 'retry');
		$config['retry'] = [
			'down_after_failures' => self::integer($retry['down_after_failures'] ?? 3, 'retry.down_after_failures', 1, 1000000),
			'up_after_successes' => self::integer($retry['up_after_successes'] ?? 2, 'retry.up_after_successes', 1, 1000000),
		];
		$internet = self::object($data['internet_check'] ?? [], 'internet_check');
		self::keys($internet, ['enabled', 'host', 'timeout_sec', 'ping_binary'], 'internet_check');
		$host = self::string($internet['host'] ?? 'www.google.com', 'internet_check.host');
		if (strlen($host) > 253 || (filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/D', $host) !== 1)) {
			throw new InvalidArgumentException('Invalid internet_check.host.');
		}
		$ping = self::string($internet['ping_binary'] ?? 'ping', 'internet_check.ping_binary');
		$config['internet_check'] = [
			'enabled' => self::boolean($internet['enabled'] ?? true, 'internet_check.enabled'),
			'host' => $host,
			'timeout_sec' => self::integer($internet['timeout_sec'] ?? 3, 'internet_check.timeout_sec', 1, 300),
			'ping_binary' => str_contains($ping, '/') ? self::path($ping, $base) : $ping,
		];
		$notify = $data['notify_script'] ?? null;
		$config['notify_script'] = $notify === null || $notify === '' ? null : self::path(self::string($notify, 'notify_script'), $base);
		$config['notify_timeout_sec'] = self::integer($data['notify_timeout_sec'] ?? 30, 'notify_timeout_sec', 1, 3600);
		self::checkPathIsolation($config);
		$config['servers'] = self::servers($config['servers_file']);
		return $config;
	}

	public static function validateDependencies(array $config, bool $validateNotifier = true): void
	{
		if (PHP_VERSION_ID < 80100) {
			throw new RuntimeException('PHP 8.1 or newer is required.');
		}
		if (!extension_loaded('curl')) {
			throw new RuntimeException('PHP cURL extension is required (install php-curl for your CLI PHP version).');
		}
		if (!function_exists('proc_open')) {
			throw new RuntimeException('PHP proc_open() is required and must not be disabled.');
		}
		ProcessRunner::executable($config['dns_manager']['php_binary']);
		foreach (['script', 'config'] as $name) {
			if (!is_file($config['dns_manager'][$name]) || !is_readable($config['dns_manager'][$name])) {
				throw new RuntimeException('Cannot read dns_manager.' . $name . ': ' . $config['dns_manager'][$name]);
			}
		}
		if ($config['internet_check']['enabled']) {
			ProcessRunner::executable($config['internet_check']['ping_binary']);
		}
		if ($validateNotifier && $config['notify_script'] !== null) {
			ProcessRunner::executable($config['notify_script']);
		}
		foreach (['state_file', 'lock_file', 'log_file'] as $name) {
			$path = $config[$name];
			if (!is_dir(dirname($path)) || !is_writable(dirname($path)) || is_link($path) || (file_exists($path) && (!is_file($path) || !is_writable($path)))) {
				throw new RuntimeException('Need a writable regular file and parent directory for ' . $name . ': ' . $path);
			}
		}
	}

	public static function validAlias(string $alias): bool
	{
		return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $alias) === 1;
	}

	private static function servers(string $file): array
	{
		$servers = self::readJson($file);
		if (!is_array($servers) || !array_is_list($servers) || $servers === []) {
			throw new InvalidArgumentException('Servers inventory must be a nonempty JSON array.');
		}
		$seen = [];
		foreach ($servers as $index => $server) {
			if (!is_array($server)) {
				throw new InvalidArgumentException('Invalid server at index ' . $index);
			}
			self::keys($server, ['alias', 'ip'], 'server ' . $index);
			$alias = self::string($server['alias'] ?? null, 'server.alias');
			$ip = self::string($server['ip'] ?? null, 'server.ip');
			if (!self::validAlias($alias) || isset($seen[$alias])) {
				throw new InvalidArgumentException('Invalid or duplicate server alias: ' . $alias);
			}
			if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
				throw new InvalidArgumentException('Invalid server IP: ' . $ip);
			}
			$seen[$alias] = true;
		}
		return $servers;
	}

	private static function checkPathIsolation(array $config): void
	{
		$inputs = [
			'config_file' => $config['config_file'],
			'servers_file' => $config['servers_file'],
			'dns_manager.script' => $config['dns_manager']['script'],
			'dns_manager.config' => $config['dns_manager']['config'],
		];
		if ($config['notify_script'] !== null) {
			$inputs['notify_script'] = $config['notify_script'];
		}
		foreach (['dns_manager.php_binary' => $config['dns_manager']['php_binary'], 'internet_check.ping_binary' => $config['internet_check']['ping_binary']] as $name => $binary) {
			if (str_contains($binary, '/')) {
				$inputs[$name] = $binary;
			} else {
				try {
					$inputs[$name] = ProcessRunner::executable($binary);
				} catch (RuntimeException) {
					// Dependency validation reports missing executables when needed.
				}
			}
		}
		foreach (['state_file', 'lock_file', 'log_file'] as $name) {
			$identity = self::pathIdentity($config[$name]);
			foreach ($inputs as $other => $path) {
				$otherIdentity = self::pathIdentity($path);
				if ($identity['path'] === $otherIdentity['path'] || ($identity['inode'] !== null && $identity['inode'] === $otherIdentity['inode'])) {
					throw new InvalidArgumentException('File paths overlap: ' . $name . ' and ' . $other . '. Runtime files must be separate from inputs and from each other.');
				}
			}
			$inputs[$name] = $config[$name];
		}
	}

	private static function pathIdentity(string $path): array
	{
		$canonical = realpath($path);
		if ($canonical === false) {
			$parent = $path;
			$suffix = [];
			do {
				array_unshift($suffix, basename($parent));
				$parent = dirname($parent);
				$resolved = realpath($parent);
			} while ($resolved === false && $parent !== '/');
			$canonical = rtrim($resolved === false ? $parent : $resolved, '/') . '/' . implode('/', $suffix);
		}
		$stat = @stat($path);
		return ['path' => $canonical, 'inode' => $stat === false ? null : $stat['dev'] . ':' . $stat['ino']];
	}

	private static function readJson(string $file): mixed
	{
		if (!is_file($file) || !is_readable($file) || filesize($file) > 1048576) {
			throw new InvalidArgumentException('Cannot read JSON file (maximum 1 MiB): ' . $file);
		}
		$text = file_get_contents($file);
		if ($text === false) {
			throw new InvalidArgumentException('Cannot read JSON file: ' . $file);
		}
		try {
			return json_decode($text, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException $error) {
			throw new InvalidArgumentException('Invalid JSON in ' . $file . ': ' . $error->getMessage(), 0, $error);
		}
	}

	private static function keys(array $data, array $allowed, string $where): void
	{
		foreach (array_keys($data) as $key) {
			if (!in_array($key, $allowed, true)) {
				throw new InvalidArgumentException('Unknown setting in ' . $where . ': ' . $key);
			}
		}
	}

	private static function object(mixed $value, string $name): array
	{
		if (!is_array($value) || ($value !== [] && array_is_list($value))) {
			throw new InvalidArgumentException($name . ' must be an object.');
		}
		return $value;
	}

	private static function string(mixed $value, string $name, bool $emptyAllowed = false): string
	{
		if (!is_string($value) || (!$emptyAllowed && trim($value) === '') || str_contains($value, "\0")) {
			throw new InvalidArgumentException($name . ' must be a ' . ($emptyAllowed ? '' : 'nonempty ') . 'string without NUL bytes.');
		}
		return $value;
	}

	private static function boolean(mixed $value, string $name): bool
	{
		if (!is_bool($value)) {
			throw new InvalidArgumentException($name . ' must be true or false.');
		}
		return $value;
	}

	private static function integer(mixed $value, string $name, int $minimum, int $maximum): int
	{
		if (!is_int($value) || $value < $minimum || $value > $maximum) {
			throw new InvalidArgumentException($name . ' must be an integer from ' . $minimum . ' to ' . $maximum . '.');
		}
		return $value;
	}

	private static function path(string $value, string $base): string
	{
		$absolute = str_starts_with($value, '/') ? $value : $base . '/' . $value;
		$parts = [];
		foreach (explode('/', $absolute) as $part) {
			if ($part === '' || $part === '.') {
				continue;
			}
			if ($part === '..') {
				array_pop($parts);
			} else {
				$parts[] = $part;
			}
		}
		return '/' . implode('/', $parts);
	}
}
