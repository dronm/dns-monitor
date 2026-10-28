<?php

declare(strict_types=1);

// Local-only adapter tests. No production DNS or notification service is used.
require dirname(__DIR__) . '/src/ProcessRunner.php';
require dirname(__DIR__) . '/src/Logger.php';
require dirname(__DIR__) . '/src/HealthChecker.php';
require dirname(__DIR__) . '/src/DnsManagerClient.php';
require dirname(__DIR__) . '/src/Notifier.php';

use DnsMonitor\DnsManagerClient;
use DnsMonitor\HealthChecker;
use DnsMonitor\Logger;
use DnsMonitor\Notifier;
use DnsMonitor\ProcessRunner;

if (!extension_loaded('curl')) {
	fwrite(STDERR, "These tests require PHP CLI with the curl extension.\n");
	exit(1);
}

$passed = 0;
$failed = 0;
$skipped = 0;
$temporary = sys_get_temp_dir() . '/dns-monitor-adapters-' . bin2hex(random_bytes(8));
if (!mkdir($temporary, 0700)) {
	throw new RuntimeException('Cannot create the isolated test directory.');
}
$children = [];
$logger = new Logger(null, static function (string $line): void {});

function adapterTest(string $name, Closure $test): void
{
	global $passed, $failed;
	try {
		$test();
		$passed++;
		fwrite(STDOUT, "PASS: $name\n");
	} catch (Throwable $error) {
		$failed++;
		fwrite(STDERR, "FAIL: $name: {$error->getMessage()}\n");
	}
}

function adapterAssert(bool $condition, string $message = 'Assertion failed'): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function adapterThrows(Closure $operation, string $contains): void
{
	try {
		$operation();
	} catch (Throwable $error) {
		adapterAssert(str_contains($error->getMessage(), $contains), 'Unexpected error: ' . $error->getMessage());
		return;
	}
	throw new RuntimeException('Expected an exception containing: ' . $contains);
}

function adapterRecords(string $path): array
{
	if (!is_file($path)) {
		return [];
	}
	return array_map(
		static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
		file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
	);
}

function adapterConfig(string $temporary): array
{
	return [
		'dns_manager' => [
			'php_binary' => PHP_BINARY,
			'script' => $temporary . '/manager fixture.php',
			'config' => $temporary . '/manager config.json',
			'zone' => 'example.test',
			'timeout_sec' => 5,
		],
		'notify_script' => $temporary . '/notify fixture',
		'notify_timeout_sec' => 5,
		'check' => [
			'scheme' => 'http', 'port' => 80, 'path' => '/ok',
			'timeout_sec' => 2, 'connect_timeout_sec' => 1,
			'expected_status' => 200, 'expected_body' => 'OK', 'max_body_bytes' => 65536,
		],
		'internet_check' => [
			'enabled' => false, 'host' => 'example.invalid', 'timeout_sec' => 1,
			'ping_binary' => $temporary . '/ping fixture',
		],
	];
}

function adapterHttpServer(string $temporary, string $host): ?int
{
	global $children;
	$address = str_contains($host, ':') ? '[' . $host . ']' : $host;
	$socket = @stream_socket_server('tcp://' . $address . ':0', $code, $error);
	if ($socket === false) {
		return null;
	}
	$name = stream_socket_get_name($socket, false);
	$port = (int) substr($name, strrpos($name, ':') + 1);
	fclose($socket);
	$process = proc_open(
		[PHP_BINARY, '-n', '-S', $address . ':' . $port, $temporary . '/router.php'],
		[0 => ['file', '/dev/null', 'r'], 1 => ['file', $temporary . '/http-output.log', 'a'], 2 => ['file', $temporary . '/http-output.log', 'a']],
		$pipes,
	);
	if (!is_resource($process)) {
		throw new RuntimeException('Cannot start the isolated HTTP fixture.');
	}
	$children[] = $process;
	$deadline = microtime(true) + 3;
	do {
		$connection = @stream_socket_client('tcp://' . $address . ':' . $port, $code, $error, 0.1);
		if ($connection !== false) {
			fclose($connection);
			return $port;
		}
		usleep(20000);
	} while (microtime(true) < $deadline && proc_get_status($process)['running']);
	throw new RuntimeException('Local HTTP fixture did not start: ' . file_get_contents($temporary . '/http-output.log'));
}

function adapterRemove(string $path): void
{
	foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
		if ($entry->isDir() && !$entry->isLink()) {
			adapterRemove($entry->getPathname());
		} else {
			unlink($entry->getPathname());
		}
	}
	rmdir($path);
}

try {
	file_put_contents($temporary . '/manager config.json', '{}');
	file_put_contents($temporary . '/mode.json', '{}');
	file_put_contents($temporary . '/manager fixture.php', <<<'PHP'
<?php
$mode = json_decode(file_get_contents(__DIR__ . '/mode.json'), true);
file_put_contents(__DIR__ . '/manager-arguments.jsonl', json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
$alias = $argv[count($argv) - 2];
if (($mode['sleep_alias'] ?? null) === $alias) {
	sleep(3);
}
if (($mode['fail_alias'] ?? null) === $alias) {
	fwrite(STDERR, 'fixture failure');
	exit($mode['exit'] ?? 4);
}
fwrite(STDOUT, "Fixture manager success\n");
PHP);
	file_put_contents($temporary . '/notify-fixture.php', <<<'PHP'
<?php
$mode = json_decode(file_get_contents(__DIR__ . '/mode.json'), true);
file_put_contents(__DIR__ . '/notification-arguments.jsonl', json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
if ($mode['notify_sleep'] ?? false) {
	sleep(3);
}
exit($mode['notify_exit'] ?? 0);
PHP);
	file_put_contents($temporary . '/ping-fixture.php', <<<'PHP'
<?php
$mode = json_decode(file_get_contents(__DIR__ . '/mode.json'), true);
file_put_contents(__DIR__ . '/ping-arguments.jsonl', json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
exit($mode['ping_exit'] ?? 0);
PHP);
	foreach (['notify', 'ping'] as $name) {
		$path = $temporary . '/' . $name . ' fixture';
		file_put_contents($path, "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($temporary . '/' . $name . '-fixture.php') . ' "$@"' . "\n");
		chmod($path, 0700);
	}
	file_put_contents($temporary . '/router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents(__DIR__ . '/http-requests.jsonl', json_encode([$path]) . "\n", FILE_APPEND);
if ($path === '/redirect') {
	header('Location: /ok', true, 302);
	echo 'OK';
} elseif ($path === '/status') {
	http_response_code(503);
	echo 'OK';
} elseif ($path === '/body') {
	echo 'NOT OK';
} elseif ($path === '/large') {
	echo str_repeat('x', 70000);
} elseif ($path === '/slow') {
	sleep(3);
	echo 'OK';
} else {
	echo " \nOK\r\n";
}
PHP);

	adapterTest('process arguments remain literal, including empty arguments and shell metacharacters', function () use ($temporary): void {
		$payload = '$(touch ' . $temporary . '/injected); `touch ' . $temporary . '/injected2` " quoted';
		$result = ProcessRunner::run([PHP_BINARY, '-n', '-r', 'echo json_encode(array_slice($argv, 1));', '--', $payload, ''], '', 5);
		adapterAssert($result['exit_code'] === 0);
		adapterAssert(json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR) === [$payload, '']);
		adapterAssert(!file_exists($temporary . '/injected') && !file_exists($temporary . '/injected2'));
	});
	adapterTest('process input and stdout/stderr stay separate', function (): void {
		$result = ProcessRunner::run([PHP_BINARY, '-n', '-r', 'fwrite(STDOUT, stream_get_contents(STDIN)); fwrite(STDERR, "diagnostic");'], "test\0input\n", 5);
		adapterAssert($result === ['exit_code' => 0, 'stdout' => "test\0input\n", 'stderr' => 'diagnostic']);
	});
	adapterTest('nonzero process exit codes are retained', function (): void {
		$result = ProcessRunner::run([PHP_BINARY, '-n', '-r', 'exit(4);'], '', 5);
		adapterAssert($result['exit_code'] === 4);
	});
	adapterTest('missing executable is an error', function () use ($temporary): void {
		adapterThrows(fn () => ProcessRunner::run([$temporary . '/absent']), 'Executable not found');
	});
	adapterTest('process timeout bounds a stalled child', function (): void {
		$start = microtime(true);
		adapterThrows(fn () => ProcessRunner::run([PHP_BINARY, '-n', '-r', 'sleep(5);'], '', 1), 'timed out');
		adapterAssert(microtime(true) - $start < 3);
	});
	adapterTest('child output is bounded', function (): void {
		adapterThrows(fn () => ProcessRunner::run([PHP_BINARY, '-n', '-r', 'echo str_repeat("x", 2200000);'], '', 5), 'exceeded 2 MiB');
	});

	$state = [
		'down1' => ['alias' => 'down1', 'available' => false],
		'up1' => ['alias' => 'up1', 'available' => true],
		'down2' => ['alias' => 'down2', 'available' => false],
		'up2' => ['alias' => 'up2', 'available' => true],
	];
	adapterTest('manager enables healthy aliases before disabling unhealthy aliases with exact argv', function () use ($temporary, $logger, $state): void {
		(new DnsManagerClient(adapterConfig($temporary), $logger))->apply($state);
		$calls = adapterRecords($temporary . '/manager-arguments.jsonl');
		$expected = [];
		foreach ([['up1', 'enable'], ['up2', 'enable'], ['down1', 'disable'], ['down2', 'disable']] as [$alias, $action]) {
			$expected[] = ['--config', $temporary . '/manager config.json', '--zone', 'example.test', $alias, $action];
		}
		adapterAssert($calls === $expected, 'Wrong manager argument order or argument boundaries');
	});
	foreach ([4, 2] as $exitCode) {
		adapterTest('manager exit ' . $exitCode . ' aborts before disabling any unhealthy alias', function () use ($temporary, $logger, $state, $exitCode): void {
			file_put_contents($temporary . '/manager-arguments.jsonl', '');
			file_put_contents($temporary . '/mode.json', json_encode(['fail_alias' => 'up1', 'exit' => $exitCode]));
			adapterThrows(fn () => (new DnsManagerClient(adapterConfig($temporary), $logger))->apply($state), 'exit ' . $exitCode);
			adapterAssert(count(adapterRecords($temporary . '/manager-arguments.jsonl')) === 1);
		});
	}
	adapterTest('manager timeout leaves later aliases untouched', function () use ($temporary, $logger, $state): void {
		file_put_contents($temporary . '/manager-arguments.jsonl', '');
		file_put_contents($temporary . '/mode.json', json_encode(['sleep_alias' => 'up1']));
		$config = adapterConfig($temporary);
		$config['dns_manager']['timeout_sec'] = 1;
		adapterThrows(fn () => (new DnsManagerClient($config, $logger))->apply($state), 'timed out');
		adapterAssert(count(adapterRecords($temporary . '/manager-arguments.jsonl')) === 1);
	});
	file_put_contents($temporary . '/mode.json', '{}');
	adapterTest('notification receives exactly one complete literal argument', function () use ($temporary, $logger): void {
		$message = "server web1 enabled\nLiteral \"quotes\" ; \$(touch never) `false`";
		(new Notifier(adapterConfig($temporary), $logger))->send($message);
		adapterAssert(adapterRecords($temporary . '/notification-arguments.jsonl') === [[$message]]);
	});
	adapterTest('disabled notifications do not invoke a script', function () use ($temporary, $logger): void {
		$config = adapterConfig($temporary);
		$config['notify_script'] = null;
		(new Notifier($config, $logger))->send('not sent');
		adapterAssert(count(adapterRecords($temporary . '/notification-arguments.jsonl')) === 1);
	});
	adapterTest('notification failure is reported', function () use ($temporary, $logger): void {
		file_put_contents($temporary . '/mode.json', json_encode(['notify_exit' => 7]));
		adapterThrows(fn () => (new Notifier(adapterConfig($temporary), $logger))->send('failure'), 'exit 7');
	});
	adapterTest('notification timeout is bounded', function () use ($temporary, $logger): void {
		file_put_contents($temporary . '/mode.json', json_encode(['notify_sleep' => true]));
		$config = adapterConfig($temporary);
		$config['notify_timeout_sec'] = 1;
		adapterThrows(fn () => (new Notifier($config, $logger))->send('timeout'), 'timed out');
	});
	file_put_contents($temporary . '/mode.json', '{}');
	adapterTest('disabled internet guard never invokes ping', function () use ($temporary): void {
		adapterAssert((new HealthChecker(adapterConfig($temporary)))->hasInternetConnection());
		adapterAssert(!file_exists($temporary . '/ping-arguments.jsonl'));
	});
	adapterTest('internet guard invokes bounded ping arguments', function () use ($temporary): void {
		$config = adapterConfig($temporary);
		$config['internet_check']['enabled'] = true;
		adapterAssert((new HealthChecker($config))->hasInternetConnection());
		adapterAssert(adapterRecords($temporary . '/ping-arguments.jsonl') === [['-n', '-c', '1', '-W', '1', '-w', '1', 'example.invalid']]);
	});
	adapterTest('unsuccessful ping fails the internet guard', function () use ($temporary): void {
		file_put_contents($temporary . '/mode.json', json_encode(['ping_exit' => 1]));
		$config = adapterConfig($temporary);
		$config['internet_check']['enabled'] = true;
		adapterAssert(!(new HealthChecker($config))->hasInternetConnection());
	});
	adapterTest('missing ping binary is a configuration error', function () use ($temporary): void {
		$config = adapterConfig($temporary);
		$config['internet_check']['enabled'] = true;
		$config['internet_check']['ping_binary'] = $temporary . '/absent';
		adapterThrows(fn () => (new HealthChecker($config))->hasInternetConnection(), 'Executable not found');
	});

	$port = adapterHttpServer($temporary, '127.0.0.1');
	if ($port === null) {
		throw new RuntimeException('Cannot bind an IPv4 localhost HTTP test server.');
	}
	$httpConfig = adapterConfig($temporary);
	$httpConfig['check']['port'] = $port;
	$server = ['ip' => '127.0.0.1', 'alias' => 'local'];
	adapterTest('healthy HTTP requires matching status and trimmed body', function () use ($httpConfig, $server): void {
		adapterAssert((new HealthChecker($httpConfig))->check($server) === ['available' => true, 'status' => 200, 'error' => null]);
	});
	adapterTest('unexpected HTTP status fails even with an OK body', function () use ($httpConfig, $server): void {
		$httpConfig['check']['path'] = '/status';
		$result = (new HealthChecker($httpConfig))->check($server);
		adapterAssert(!$result['available'] && $result['status'] === 503);
	});
	adapterTest('unexpected body fails despite HTTP 200', function () use ($httpConfig, $server): void {
		$httpConfig['check']['path'] = '/body';
		$result = (new HealthChecker($httpConfig))->check($server);
		adapterAssert(!$result['available'] && $result['status'] === 200);
	});
	adapterTest('redirects are not followed to another healthy endpoint', function () use ($temporary, $httpConfig, $server): void {
		$httpConfig['check']['path'] = '/redirect';
		$before = count(adapterRecords($temporary . '/http-requests.jsonl'));
		$result = (new HealthChecker($httpConfig))->check($server);
		adapterAssert(!$result['available'] && $result['status'] === 302);
		adapterAssert(count(adapterRecords($temporary . '/http-requests.jsonl')) === $before + 1);
	});
	adapterTest('oversized HTTP response is rejected with a bounded diagnostic', function () use ($httpConfig, $server): void {
		$httpConfig['check']['path'] = '/large';
		$result = (new HealthChecker($httpConfig))->check($server);
		adapterAssert(!$result['available'] && str_contains($result['error'], 'exceeded 65536'));
		adapterAssert(strlen($result['error']) < 512);
	});
	adapterTest('proxy environment cannot redirect a direct backend health check', function () use ($httpConfig, $server): void {
		$keys = ['http_proxy', 'HTTP_PROXY', 'ALL_PROXY', 'all_proxy', 'NO_PROXY', 'no_proxy'];
		$previous = [];
		try {
			foreach ($keys as $key) {
				$previous[$key] = getenv($key);
				putenv($key . '=' . (strtolower($key) === 'no_proxy' ? '' : 'http://127.0.0.1:1'));
			}
			adapterAssert((new HealthChecker($httpConfig))->check($server)['available']);
		} finally {
			foreach ($previous as $key => $value) {
				putenv($value === false ? $key : $key . '=' . $value);
			}
		}
	});
	adapterTest('connection failure is an unhealthy observation', function () use ($httpConfig, $server): void {
		$socket = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
		$address = stream_socket_get_name($socket, false);
		$httpConfig['check']['port'] = (int) substr($address, strrpos($address, ':') + 1);
		fclose($socket);
		$result = (new HealthChecker($httpConfig))->check($server);
		adapterAssert(!$result['available'] && $result['status'] === 0 && $result['error'] !== '');
	});
	adapterTest('HTTP deadline fails a stalled endpoint', function () use ($httpConfig, $server): void {
		$httpConfig['check']['path'] = '/slow';
		$httpConfig['check']['timeout_sec'] = 1;
		$start = microtime(true);
		$result = (new HealthChecker($httpConfig))->check($server);
		adapterAssert(!$result['available'] && microtime(true) - $start < 2.5);
	});
	$ipv6Port = adapterHttpServer($temporary, '::1');
	if ($ipv6Port === null) {
		$skipped++;
		fwrite(STDOUT, "SKIP: local IPv6 is unavailable\n");
	} else {
		adapterTest('IPv6 backend URL is bracketed and checked directly', function () use ($httpConfig, $ipv6Port): void {
			$httpConfig['check']['port'] = $ipv6Port;
			adapterAssert((new HealthChecker($httpConfig))->check(['alias' => 'local-v6', 'ip' => '::1'])['available']);
		});
	}
} finally {
	foreach ($children as $process) {
		if (is_resource($process)) {
			@proc_terminate($process, 15);
			proc_close($process);
		}
	}
	adapterRemove($temporary);
}

fwrite(STDOUT, "Adapter tests: $passed passed, $failed failed, $skipped skipped.\n");
exit($failed > 0 ? 1 : 0);
