<?php

declare(strict_types=1);

// Local filesystem fixtures only: no probes, manager commands, or notifications.
require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/TestSupport.php';

use DnsMonitor\Config;
use DnsMonitor\Tests\Suite;
use function DnsMonitor\Tests\removeTree;
use function DnsMonitor\Tests\same;
use function DnsMonitor\Tests\temporaryDirectory;
use function DnsMonitor\Tests\throws;

function withConfigFixture(callable $test): void
{
	$directory = temporaryDirectory();
	try {
		foreach (['var', 'manager', 'bin'] as $name) {
			mkdir($directory . '/' . $name, 0700);
		}
		file_put_contents($directory . '/servers.json', json_encode([
			['alias' => 'web1', 'ip' => '192.0.2.10'],
			['alias' => 'web2', 'ip' => '192.0.2.20'],
		], JSON_THROW_ON_ERROR));
		file_put_contents($directory . '/manager/dns-manager', "<?php\n// Unexecuted test fixture.\n");
		file_put_contents($directory . '/manager/config.json', '{}');
		foreach (['php', 'ping', 'notify'] as $name) {
			file_put_contents($directory . '/bin/' . $name, "#!/bin/sh\nexit 0\n");
			chmod($directory . '/bin/' . $name, 0700);
		}
		$config = [
			'dns_manager' => [
				'php_binary' => 'bin/php',
				'script' => 'manager/dns-manager',
				'config' => 'manager/config.json',
				'zone' => 'example.test',
			],
			'servers_file' => 'servers.json',
			'state_file' => 'var/state.json',
			'lock_file' => 'var/monitor.lock',
			'log_file' => 'var/monitor.log',
			'notify_script' => 'bin/notify',
			'internet_check' => ['enabled' => true, 'ping_binary' => 'bin/ping'],
		];
		$test($directory, $config);
	} finally {
		removeTree($directory);
	}
}

function loadConfigFixture(string $directory, array $config): array
{
	$file = $directory . '/dns-monitor.json';
	file_put_contents($file, json_encode($config, JSON_THROW_ON_ERROR));
	return Config::load($file);
}

$suite = new Suite();

$suite->test('relative configuration paths resolve against the configuration directory', static function (): void {
	withConfigFixture(static function (string $directory, array $config): void {
		$config['state_file'] = 'var/../var/./state.json';
		$loaded = loadConfigFixture($directory, $config);
		same($directory . '/servers.json', $loaded['servers_file'], 'Inventory path should resolve against configuration directory.');
		same($directory . '/var/state.json', $loaded['state_file'], 'State path should normalize dot segments.');
		same($directory . '/var/monitor.lock', $loaded['lock_file'], 'Lock path should resolve against configuration directory.');
		same($directory . '/var/monitor.log', $loaded['log_file'], 'Log path should resolve against configuration directory.');
		same($directory . '/manager/dns-manager', $loaded['dns_manager']['script'], 'Manager script should resolve against configuration directory.');
		same($directory . '/manager/config.json', $loaded['dns_manager']['config'], 'Manager configuration should resolve against configuration directory.');
		same($directory . '/bin/php', $loaded['dns_manager']['php_binary'], 'PHP path should resolve against configuration directory.');
		same($directory . '/bin/ping', $loaded['internet_check']['ping_binary'], 'Ping path should resolve against configuration directory.');
		same($directory . '/bin/notify', $loaded['notify_script'], 'Notifier path should resolve against configuration directory.');
	});
});

$suite->test('runtime state, lock, and log targets must be distinct', static function (): void {
	foreach ([['state_file', 'lock_file'], ['state_file', 'log_file'], ['lock_file', 'log_file']] as [$first, $second]) {
		withConfigFixture(static function (string $directory, array $config) use ($first, $second): void {
			$config[$second] = $config[$first];
			throws(static fn (): array => loadConfigFixture($directory, $config), "Overlapping {$first} and {$second} must be rejected.");
		});
	}
});

$suite->test('runtime targets cannot overwrite configuration, inventory, manager, or executable inputs', static function (): void {
	$inputs = [
		'dns-monitor.json', 'servers.json', 'manager/dns-manager', 'manager/config.json',
		'bin/php', 'bin/notify', 'bin/ping',
	];
	foreach (['state_file', 'lock_file', 'log_file'] as $target) {
		foreach ($inputs as $input) {
			withConfigFixture(static function (string $directory, array $config) use ($target, $input): void {
				$config[$target] = $input;
				throws(static fn (): array => loadConfigFixture($directory, $config), "Runtime {$target} must not overlap input {$input}.");
			});
		}
	}
});

$suite->test('symlinked parent directories cannot disguise a state/log collision', static function (): void {
	withConfigFixture(static function (string $directory, array $config): void {
		symlink($directory . '/var', $directory . '/runtime-alias');
		$config['log_file'] = 'runtime-alias/state.json';
		throws(static fn (): array => loadConfigFixture($directory, $config), 'State and log resolving to the same target through parent symlinks must be rejected.');
	});
});

$suite->test('path identity uses the deepest existing parent when runtime directories do not exist yet', static function (): void {
	withConfigFixture(static function (string $directory, array $config): void {
		symlink($directory . '/var', $directory . '/runtime-alias');
		$config['state_file'] = 'var/future/nested/state.json';
		$config['log_file'] = 'runtime-alias/future/nested/state.json';
		throws(static fn (): array => loadConfigFixture($directory, $config), 'Future targets with equivalent existing parents must be rejected.');
	});
});

$suite->test('a symlinked parent cannot disguise a log/input collision', static function (): void {
	withConfigFixture(static function (string $directory, array $config): void {
		symlink($directory . '/manager', $directory . '/manager-alias');
		$config['log_file'] = 'manager-alias/config.json';
		throws(static fn (): array => loadConfigFixture($directory, $config), 'Log target must not alias the manager configuration through a symlinked parent.');
	});
});

$suite->test('an existing symbolic link cannot disguise an input as a runtime file', static function (): void {
	withConfigFixture(static function (string $directory, array $config): void {
		symlink($directory . '/manager/config.json', $directory . '/var/monitor.log');
		throws(static fn (): array => loadConfigFixture($directory, $config), 'Runtime symlink to a manager input must be rejected.');
	});
});

$suite->test('hard-linked runtime files are recognized as the same file', static function (): void {
	withConfigFixture(static function (string $directory, array $config): void {
		file_put_contents($directory . '/var/state.json', '{}');
		link($directory . '/var/state.json', $directory . '/var/monitor.log');
		throws(static fn (): array => loadConfigFixture($directory, $config), 'Distinct filenames sharing a runtime inode must be rejected.');
	});
});

$suite->test('a hard link cannot turn a manager input into a writable log target', static function (): void {
	withConfigFixture(static function (string $directory, array $config): void {
		link($directory . '/manager/config.json', $directory . '/var/monitor.log');
		throws(static fn (): array => loadConfigFixture($directory, $config), 'Runtime file sharing an input inode must be rejected.');
	});
});

$suite->test('unknown top-level and nested settings are rejected', static function (): void {
	withConfigFixture(static function (string $directory, array $config): void {
		$config['allow_all_dwon_publish'] = true;
		throws(static fn (): array => loadConfigFixture($directory, $config), 'Misspelled top-level settings must not silently change protection behavior.', 'Unknown setting');
	});
	withConfigFixture(static function (string $directory, array $config): void {
		$config['dns_manager']['timout_sec'] = 1;
		throws(static fn (): array => loadConfigFixture($directory, $config), 'Misspelled nested settings must not silently use defaults.', 'Unknown setting');
	});
});

$suite->test('duplicate aliases are rejected before monitoring begins', static function (): void {
	withConfigFixture(static function (string $directory, array $config): void {
		file_put_contents($directory . '/servers.json', json_encode([
			['alias' => 'web1', 'ip' => '192.0.2.10'],
			['alias' => 'web1', 'ip' => '192.0.2.20'],
		], JSON_THROW_ON_ERROR));
		throws(static fn (): array => loadConfigFixture($directory, $config), 'Duplicate aliases must not silently overwrite one server with another.', 'duplicate');
	});
});

$suite->test('invalid inventory structures and invalid server identities are rejected', static function (): void {
	$invalidInventories = [
		'[]',
		'{"web1":{"alias":"web1","ip":"192.0.2.10"}}',
		'{malformed JSON}',
		'[{"alias":"web1"}]',
		'[{"alias":"web1","ip":"not-an-ip"}]',
		'[{"alias":"-web1","ip":"192.0.2.10"}]',
		'[{"alias":1,"ip":"192.0.2.10"}]',
		json_encode([['alias' => str_repeat('a', 65), 'ip' => '192.0.2.10']], JSON_THROW_ON_ERROR),
	];
	foreach ($invalidInventories as $inventory) {
		withConfigFixture(static function (string $directory, array $config) use ($inventory): void {
			file_put_contents($directory . '/servers.json', $inventory);
			throws(static fn (): array => loadConfigFixture($directory, $config), 'Invalid inventory must not reach health checks or DNS publication.');
		});
	}
});

exit($suite->finish());
