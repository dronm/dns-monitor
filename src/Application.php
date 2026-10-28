<?php

declare(strict_types=1);

namespace DnsMonitor;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class Application
{
	public static function run(array $arguments, string $defaultConfig): int
	{
		$logger = null;
		$lock = null;
		$oldMask = umask(0077);
		try {
			$options = self::options(array_slice($arguments, 1), $defaultConfig);
			if ($options['help']) {
				self::help();
				return 0;
			}
			if ($options['version']) {
				fwrite(STDOUT, "dns-monitor 1.0.0\n");
				return 0;
			}
			$config = Config::load($options['config']);
			$store = new StateStore($config['state_file'], $config['dns_manager']['zone']);
			if ($options['action'] === 'status') {
				$state = $store->load();
				fwrite(STDOUT, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n");
				return 0;
			}
			Config::validateDependencies($config, $options['action'] === 'validate');
			$logger = new Logger($config['log_file'], static function (string $line): void { fwrite(STDOUT, $line); });
			if ($options['action'] === 'validate') {
				$store->load();
				self::validateManager($config);
				$logger->info('Configuration, dependencies, state, and dns-manager aliases are valid. No health probes, DNS updates, or notifications sent.');
				return 0;
			}
			if (is_link($config['lock_file'])) {
				throw new RuntimeException('Lock file must not be a symbolic link.');
			}
			$lock = @fopen($config['lock_file'], 'c');
			if ($lock === false) {
				throw new RuntimeException('Cannot open lock file: ' . $config['lock_file']);
			}
			if (!flock($lock, LOCK_EX | LOCK_NB)) {
				$logger->info('Another monitor process is already running. Exiting.');
				return 0;
			}
			$checker = new HealthChecker($config);
			$manager = new DnsManagerClient($config, $logger);
			$notifier = new Notifier($config, $logger);
			$monitor = new Monitor($config, $store, $logger, [$checker, 'hasInternetConnection'], [$checker, 'check'], [$manager, 'apply'], [$notifier, 'send']);
			return $monitor->run($options['dry_run'], $options['reconcile']);
		} catch (Throwable $error) {
			fwrite(STDERR, 'ERROR: ' . $error->getMessage() . "\n");
			if ($logger !== null) {
				try {
					$logger->error('Monitor failed: ' . $error->getMessage());
				} catch (Throwable $logError) {
					fwrite(STDERR, 'ERROR: ' . $logError->getMessage() . "\n");
				}
			}
			return $error instanceof InvalidArgumentException ? 2 : 1;
		} finally {
			if (is_resource($lock)) {
				flock($lock, LOCK_UN);
				fclose($lock);
			}
			umask($oldMask);
		}
	}

	private static function validateManager(array $config): void
	{
		$manager = $config['dns_manager'];
		foreach (array_merge([null], array_column($config['servers'], 'alias')) as $alias) {
			$arguments = [$manager['php_binary'], $manager['script'], '--config', $manager['config'], '--zone', $manager['zone']];
			$arguments[] = $alias === null ? 'validate' : 'status';
			if ($alias !== null) {
				$arguments[] = $alias;
			}
			$result = ProcessRunner::run($arguments, '', $manager['timeout_sec']);
			if ($result['exit_code'] !== 0) {
				throw new RuntimeException('dns-manager validation failed: ' . trim($result['stderr'] . "\n" . $result['stdout']));
			}
		}
	}

	private static function options(array $arguments, string $defaultConfig): array
	{
		$options = ['config' => $defaultConfig, 'action' => 'run', 'help' => false, 'version' => false, 'dry_run' => false, 'reconcile' => false];
		$actionSeen = false;
		$configSeen = false;
		for ($index = 0; $index < count($arguments); $index++) {
			$value = $arguments[$index];
			if (in_array($value, ['--help', '-h', '--version', '--dry-run', '--reconcile'], true)) {
				$name = $value === '-h' ? 'help' : str_replace('-', '_', substr($value, 2));
				$options[$name] = true;
			} elseif ($value === '--config' || str_starts_with($value, '--config=')) {
				if ($configSeen) {
					throw new InvalidArgumentException('--config may only be supplied once.');
				}
				$configSeen = true;
				$file = $value === '--config' ? ($arguments[++$index] ?? '') : substr($value, 9);
				if ($file === '' || str_starts_with($file, '--')) {
					throw new InvalidArgumentException('--config requires a file path.');
				}
				$options['config'] = $file;
			} elseif (in_array($value, ['run', 'validate', 'status'], true) && !$actionSeen) {
				$options['action'] = $value;
				$actionSeen = true;
			} else {
				throw new InvalidArgumentException('Unknown or extra argument: ' . $value . '. Use --help.');
			}
		}
		if ($options['action'] !== 'run' && ($options['dry_run'] || $options['reconcile'])) {
			throw new InvalidArgumentException('--dry-run and --reconcile apply only to run.');
		}
		return $options;
	}

	private static function help(): void
	{
		fwrite(STDOUT, <<<'HELP'
dns-monitor 1.0.0 - Check servers and manage failover through dns-manager.

Usage:
  dns-monitor [--config FILE] [run] [--dry-run] [--reconcile]
  dns-monitor [--config FILE] validate
  dns-monitor [--config FILE] status
  dns-monitor --help | --version

The default configuration is dns-monitor.json beside the executable.
One invocation performs one cycle. Schedule run with cron.
Dry run performs health probes but does not save state, update DNS, or notify.
Reconcile reapplies all aliases after checks, even without an effective change;
it still uses hysteresis and honors the all-down guard.
Validate checks local configuration, dependencies, state, zone source and aliases;
it does not probe health endpoints, update DNS, or send notifications.
Status prints saved state as JSON; it does not query DNS or probe servers.
Exit codes: 0 completed or deliberately skipped; 1 runtime/publication failure;
2 invalid arguments, configuration, or server inventory.

HELP);
	}
}
