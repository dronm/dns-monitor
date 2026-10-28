<?php

declare(strict_types=1);

namespace DnsMonitor;

use Closure;
use RuntimeException;
use Throwable;

final class Monitor
{
	private Closure $internet;
	private Closure $probe;
	private Closure $publish;
	private Closure $notify;

	public function __construct(
		private array $config,
		private StateStore $store,
		private Logger $logger,
		callable $internet,
		callable $probe,
		callable $publish,
		callable $notify,
	) {
		$this->internet = Closure::fromCallable($internet);
		$this->probe = Closure::fromCallable($probe);
		$this->publish = Closure::fromCallable($publish);
		$this->notify = Closure::fromCallable($notify);
	}

	public function run(bool $dryRun = false, bool $reconcile = false): int
	{
		if (!(($this->internet)())) {
			$this->logger->info('Internet check failed. Server checks skipped. State was not changed.');
			return 0;
		}
		$state = $this->store->load();
		$current = $this->observe($state['servers']);
		$hasBaseline = $state['baseline'] !== null;
		$changes = $this->changes($state['baseline'] ?? [], $current);
		$initialBaselineOnly = !$hasBaseline && !$state['pending_dns'] && !$this->config['apply_on_first_run'] && !$reconcile;
		$needsPublish = !$initialBaselineOnly && ($reconcile || $state['pending_dns'] || !$hasBaseline || $changes !== []);
		$hasAvailable = count(array_filter($current, static fn (array $server): bool => $server['available'])) > 0;
		$blocked = $needsPublish && !$hasAvailable && !$this->config['allow_all_down_publish'];

		if ($dryRun) {
			foreach ($current as $server) {
				$this->logger->info(sprintf('DRY RUN: alias=%s observed=%s effective=%s failures=%d successes=%d', $server['alias'], $server['observed_available'] ? 'up' : 'down', $server['available'] ? 'up' : 'down', $server['failure_count'], $server['success_count']));
			}
			$this->logger->info($blocked ? 'DRY RUN: all-down guard would block publication.' : ($needsPublish ? 'DRY RUN: would publish all configured aliases through dns-manager.' : 'DRY RUN: no DNS publication would be needed.'));
			$this->logger->info('DRY RUN: state unchanged; no DNS commands or notifications sent.');
			return 0;
		}

		$state['servers'] = $current;
		if (!$needsPublish) {
			$state['baseline'] = $current;
			$this->store->save($state);
			if ($initialBaselineOnly) {
				$this->logger->info('Initial state saved. DNS commands skipped because apply_on_first_run=false. This baseline does not verify DNS.');
			}
			return 0;
		}

		// Persist intent before any external command. A failed/ambiguous request may
		// already have changed DNS, including when health later returns to baseline.
		$state['pending_dns'] = true;
		$this->store->save($state);
		if ($blocked) {
			$this->logger->info('All servers are effectively unavailable. DNS update skipped because allow_all_down_publish=false. Observations saved; DNS publication remains pending.');
			return 0;
		}
		foreach ($changes as $change) {
			$this->logger->info(sprintf('Effective state changed: alias=%s ip=%s %s -> %s', $change['alias'], $change['ip'], $change['old_available'] === null ? 'unknown' : ($change['old_available'] ? 'available' : 'unavailable'), $change['new_available'] ? 'available' : 'unavailable'));
		}
		try {
			($this->publish)($current);
		} catch (Throwable $error) {
			$this->logger->error('DNS commands failed; publication remains pending: ' . $error->getMessage());
			if ($hasBaseline) {
				$this->sendNotification('DNS failover command failed: ' . $error->getMessage());
			}
			throw $error;
		}

		$state['baseline'] = $current;
		$state['pending_dns'] = false;
		$this->store->save($state);
		$this->logger->info('DNS publication completed and verified by dns-manager.');
		if ($hasBaseline) {
			foreach ($changes as $change) {
				$this->sendNotification('server ' . $change['alias'] . ' ' . ($change['new_available'] ? 'enabled' : 'disabled'));
			}
		}
		return 0;
	}

	private function observe(array $previous): array
	{
		$current = [];
		foreach ($this->config['servers'] as $server) {
			$alias = $server['alias'];
			$result = ($this->probe)($server);
			if (!is_array($result) || !is_bool($result['available'] ?? null) || !is_int($result['status'] ?? null) || !array_key_exists('error', $result) || ($result['error'] !== null && !is_string($result['error']))) {
				throw new RuntimeException('Invalid health-check result for ' . $alias);
			}
			$observed = $result['available'];
			$old = $previous[$alias] ?? null;
			$effective = $old === null ? $observed : $old['available'];
			$failures = $observed ? 0 : min(($old['failure_count'] ?? 0), PHP_INT_MAX - 1) + 1;
			$successes = $observed ? min(($old['success_count'] ?? 0), PHP_INT_MAX - 1) + 1 : 0;
			if ($observed && !$effective && $successes >= $this->config['retry']['up_after_successes']) {
				$effective = true;
			} elseif (!$observed && $effective && $failures >= $this->config['retry']['down_after_failures']) {
				$effective = false;
			}
			$current[$alias] = [
				'ip' => $server['ip'],
				'alias' => $alias,
				'available' => $effective,
				'observed_available' => $observed,
				'failure_count' => $failures,
				'success_count' => $successes,
				'status' => $result['status'],
				'error' => $result['error'],
				'checked_at' => date(DATE_ATOM),
			];
		}
		return $current;
	}

	private function changes(array $baseline, array $current): array
	{
		$changes = [];
		foreach ($current as $alias => $server) {
			$old = $baseline[$alias] ?? null;
			if ($old === null || $old['available'] !== $server['available'] || $old['ip'] !== $server['ip']) {
				$changes[] = ['alias' => $server['alias'], 'ip' => $server['ip'], 'old_available' => $old['available'] ?? null, 'new_available' => $server['available']];
			}
		}
		return $changes;
	}

	private function sendNotification(string $message): void
	{
		try {
			($this->notify)($message);
		} catch (Throwable $error) {
			$this->logger->error('Notification failed: ' . $error->getMessage());
		}
	}
}
