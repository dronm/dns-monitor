<?php

declare(strict_types=1);

namespace DnsMonitor;

use JsonException;
use RuntimeException;

final class StateStore
{
	public function __construct(private string $path, private string $zone)
	{
	}

	public function load(): array
	{
		$empty = ['version' => 1, 'zone' => $this->zone, 'servers' => [], 'baseline' => null, 'pending_dns' => false];
		if (is_link($this->path)) {
			throw new RuntimeException('State file must not be a symbolic link.');
		}
		if (!file_exists($this->path)) {
			return $empty;
		}
		if (!is_file($this->path) || !is_readable($this->path) || filesize($this->path) > 2097152) {
			throw new RuntimeException('Cannot read state file (maximum 2 MiB): ' . $this->path);
		}
		$text = file_get_contents($this->path);
		if ($text === false || trim($text) === '') {
			throw new RuntimeException('State file is empty or unreadable; restore a valid state or deliberately choose a new state_file.');
		}
		try {
			$data = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException $error) {
			throw new RuntimeException('Invalid state JSON; state was not reset: ' . $error->getMessage(), 0, $error);
		}
		if (!is_array($data)) {
			throw new RuntimeException('State must be a JSON object.');
		}
		if ($data === []) {
			return $empty;
		}
		if (isset($data['version']) && is_int($data['version'])) {
			return $this->validate($data);
		}
		// The original monitor stored an alias-keyed map without an envelope.
		$legacy = $this->serverMap($data);
		return ['version' => 1, 'zone' => $this->zone, 'servers' => $legacy, 'baseline' => $legacy, 'pending_dns' => false];
	}

	public function save(array $state): void
	{
		$state = $this->validate($state);
		if (is_link($this->path) || (file_exists($this->path) && !is_file($this->path))) {
			throw new RuntimeException('State must be a regular file: ' . $this->path);
		}
		$json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
		if (strlen($json) > 2097152) {
			throw new RuntimeException('State exceeds 2 MiB.');
		}
		$directory = dirname($this->path);
		if (!is_dir($directory) || !is_writable($directory)) {
			throw new RuntimeException('State directory is not writable: ' . $directory);
		}
		$temp = tempnam($directory, '.dns-monitor-');
		if ($temp === false || dirname($temp) !== $directory) {
			if ($temp !== false) {
				@unlink($temp);
			}
			throw new RuntimeException('Cannot create temporary state file.');
		}
		$handle = null;
		try {
			$mode = is_file($this->path) ? (fileperms($this->path) & 0777) : 0600;
			if (!chmod($temp, $mode)) {
				throw new RuntimeException('Cannot set state file permissions.');
			}
			$handle = fopen($temp, 'wb');
			if ($handle === false) {
				throw new RuntimeException('Cannot open temporary state file.');
			}
			$offset = 0;
			while ($offset < strlen($json)) {
				$written = fwrite($handle, substr($json, $offset));
				if ($written === false || $written === 0) {
					throw new RuntimeException('Cannot write complete state file.');
				}
				$offset += $written;
			}
			if (!fflush($handle) || !fsync($handle)) {
				throw new RuntimeException('Cannot flush state file.');
			}
			fclose($handle);
			$handle = null;
			if (!rename($temp, $this->path)) {
				throw new RuntimeException('Cannot replace state file.');
			}
			clearstatcache(true, $this->path);
		} finally {
			if (is_resource($handle)) {
				fclose($handle);
			}
			if (is_file($temp)) {
				unlink($temp);
			}
		}
	}

	private function validate(array $state): array
	{
		if (($state['version'] ?? null) !== 1 || ($state['zone'] ?? null) !== $this->zone || !array_key_exists('baseline', $state) || !is_bool($state['pending_dns'] ?? null)) {
			throw new RuntimeException('Invalid state version, zone, baseline, or pending marker. Use a separate state_file for each zone.');
		}
		$state['servers'] = $this->serverMap($state['servers'] ?? null);
		$state['baseline'] = $state['baseline'] === null ? null : $this->serverMap($state['baseline']);
		return $state;
	}

	private function serverMap(mixed $servers): array
	{
		if (!is_array($servers)) {
			throw new RuntimeException('Invalid server map in state.');
		}
		foreach ($servers as $alias => &$server) {
			if (!Config::validAlias((string) $alias) || !is_array($server) || ($server['alias'] ?? null) !== (string) $alias || !is_string($server['ip'] ?? null) || filter_var($server['ip'], FILTER_VALIDATE_IP) === false || !is_bool($server['available'] ?? null)) {
				throw new RuntimeException('Invalid server state for alias: ' . $alias);
			}
			$server += ['observed_available' => $server['available'], 'failure_count' => 0, 'success_count' => 0, 'status' => 0, 'error' => null, 'checked_at' => ''];
			foreach (['failure_count', 'success_count', 'status'] as $field) {
				if (!is_int($server[$field]) || $server[$field] < 0) {
					throw new RuntimeException('Invalid ' . $field . ' in state for ' . $alias);
				}
			}
			if (!is_bool($server['observed_available']) || (!is_string($server['error']) && $server['error'] !== null) || !is_string($server['checked_at'])) {
				throw new RuntimeException('Invalid observation in state for ' . $alias);
			}
		}
		unset($server);
		return $servers;
	}
}
