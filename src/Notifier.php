<?php

declare(strict_types=1);

namespace DnsMonitor;

use RuntimeException;

final class Notifier
{
	public function __construct(private readonly array $config, private readonly Logger $logger)
	{
	}

	public function send(string $message): void
	{
		$script = $this->config['notify_script'];
		if ($script === null || $script === '') {
			return;
		}
		if (!is_file($script) || !is_executable($script)) {
			throw new RuntimeException('Notification script is not executable: ' . $script);
		}
		$this->logger->info('Running notification script: ' . $script);
		$result = ProcessRunner::run([$script, $message], '', $this->config['notify_timeout_sec']);
		if ($result['exit_code'] !== 0) {
			$output = trim($result['stdout'] . "\n" . $result['stderr']);
			if (strlen($output) > 4096) {
				$output = substr($output, 0, 4096) . ' [output truncated]';
			}
			throw new RuntimeException(sprintf('Notification script failed (exit %d): %s', $result['exit_code'], $output));
		}
		$this->logger->info('Notification sent: ' . $message);
	}
}
