<?php

declare(strict_types=1);

namespace DnsMonitor\Tests;

use DnsMonitor\Logger;
use DnsMonitor\Monitor;
use DnsMonitor\StateStore;
use RuntimeException;
use Throwable;

final class Suite
{
	private int $passed = 0;
	private int $failed = 0;

	public function test(string $name, callable $test): void
	{
		try {
			$test();
			$this->passed++;
			fwrite(STDOUT, "PASS {$name}\n");
		} catch (Throwable $error) {
			$this->failed++;
			fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
		}
	}

	public function finish(): int
	{
		fwrite(STDOUT, "\n{$this->passed} passed; {$this->failed} failed.\n");
		return $this->failed === 0 ? 0 : 1;
	}
}

function expect(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function same(mixed $expected, mixed $actual, string $message): void
{
	if ($expected !== $actual) {
		throw new RuntimeException($message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

function throws(callable $operation, string $message, ?string $contains = null): Throwable
{
	try {
		$operation();
	} catch (Throwable $error) {
		if ($contains !== null && !str_contains($error->getMessage(), $contains)) {
			throw new RuntimeException($message . '; unexpected exception: ' . $error->getMessage());
		}
		return $error;
	}
	throw new RuntimeException($message);
}

function temporaryDirectory(): string
{
	$path = sys_get_temp_dir() . '/dns-monitor-test-' . bin2hex(random_bytes(8));
	if (!mkdir($path, 0700)) {
		throw new RuntimeException('Could not create fixture directory.');
	}
	return $path;
}

function removeTree(string $directory): void
{
	if (!is_dir($directory) || is_link($directory)) {
		return;
	}
	foreach (new \FilesystemIterator($directory) as $entry) {
		if ($entry->isDir() && !$entry->isLink()) {
			removeTree($entry->getPathname());
		} else {
			unlink($entry->getPathname());
		}
	}
	rmdir($directory);
}

/** In-memory external services with a real temporary persistent state file. */
final class Harness
{
	public readonly string $directory;
	public readonly string $statePath;
	public readonly StateStore $store;
	public array $config;
	public array $observations = ['web1' => true, 'web2' => true];
	public array $probes = [];
	public array $publications = [];
	public array $notifications = [];
	public array $logs = [];
	public bool $internetAvailable = true;
	public $publishHook = null;
	public $notifyHook = null;

	public function __construct()
	{
		$this->directory = temporaryDirectory();
		$this->statePath = $this->directory . '/state.json';
		$this->store = new StateStore($this->statePath, 'example.test');
		$this->config = [
			'zone' => 'example.test',
			'state_file' => $this->statePath,
			'lock_file' => $this->directory . '/monitor.lock',
			'log_file' => $this->directory . '/monitor.log',
			'servers' => [
				['alias' => 'web1', 'ip' => '192.0.2.10'],
				['alias' => 'web2', 'ip' => '192.0.2.20'],
			],
			'apply_on_first_run' => false,
			'allow_all_down_publish' => false,
			'retry' => ['down_after_failures' => 3, 'up_after_successes' => 2],
			'dns_manager' => ['zone' => 'example.test'],
		];
	}

	public function run(bool $dryRun = false, bool $reconcile = false): int
	{
		$logger = new Logger(null, function (string $line): void {
			$this->logs[] = $line;
		});
		$monitor = new Monitor(
			$this->config,
			$this->store,
			$logger,
			fn (): bool => $this->internetAvailable,
			function (array $server): array {
				$this->probes[] = $server['alias'];
				$observation = $this->observations[$server['alias']];
				if (is_array($observation)) {
					return $observation;
				}
				return [
					'available' => $observation,
					'status' => $observation ? 200 : 503,
					'error' => $observation ? null : 'Unhealthy fixture response',
				];
			},
			function (array $state): void {
				$this->publications[] = $state;
				if ($this->publishHook !== null) {
					($this->publishHook)($state);
				}
			},
			function (string $message): void {
				$this->notifications[] = $message;
				if ($this->notifyHook !== null) {
					($this->notifyHook)($message);
				}
			},
		);
		return $monitor->run($dryRun, $reconcile);
	}

	public function immediateTransitions(): void
	{
		$this->config['retry'] = ['down_after_failures' => 1, 'up_after_successes' => 1];
	}

	public function close(): void
	{
		removeTree($this->directory);
	}
}

function withHarness(callable $test): void
{
	$harness = new Harness();
	try {
		$test($harness);
	} finally {
		$harness->close();
	}
}
