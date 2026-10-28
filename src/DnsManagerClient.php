<?php

declare(strict_types=1);

namespace DnsMonitor;

use RuntimeException;

final class DnsManagerClient
{
	public function __construct(private readonly array $config, private readonly Logger $logger)
	{
	}

	/** Enable healthy backends before withdrawing unhealthy ones. */
	public function apply(array $currentState): void
	{
		$manager = $this->config['dns_manager'];
		if (!is_file($manager['script']) || !is_readable($manager['script'])) {
			throw new RuntimeException('Cannot read the dns-manager PHP script: ' . $manager['script']);
		}
		if (!is_file($manager['config']) || !is_readable($manager['config'])) {
			throw new RuntimeException('Cannot read the dns-manager configuration: ' . $manager['config']);
		}
		foreach ([true, false] as $available) {
			foreach ($currentState as $server) {
				if ($server['available'] !== $available) {
					continue;
				}
				$action = $available ? 'enable' : 'disable';
				$alias = $server['alias'];
				$this->logger->info(sprintf('Running dns-manager: zone=%s alias=%s action=%s', $manager['zone'], $alias, $action));
				$result = ProcessRunner::run([
					$manager['php_binary'], $manager['script'],
					'--config', $manager['config'], '--zone', $manager['zone'], $alias, $action,
				], '', $manager['timeout_sec']);
				$output = trim($result['stdout'] . "\n" . $result['stderr']);
				if (strlen($output) > 8192) {
					$output = substr($output, 0, 8192) . "\n[output truncated]";
				}
				if ($result['exit_code'] !== 0) {
					throw new RuntimeException(sprintf(
						'dns-manager failed for %s %s (exit %d): %s',
						$alias, $action, $result['exit_code'], $output,
					));
				}
				if ($output !== '') {
					$this->logger->info('dns-manager output: ' . $output);
				}
			}
		}
		$this->logger->info('All DNS commands completed and dns-manager reported success.');
	}
}
