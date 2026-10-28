<?php

declare(strict_types=1);

namespace DnsMonitor;

use RuntimeException;

final class HealthChecker
{
	public function __construct(private readonly array $config)
	{
	}

	/** @return array{available: bool, status: int, error: ?string} */
	public function check(array $server): array
	{
		if (!extension_loaded('curl')) {
			throw new RuntimeException('The PHP curl extension is required for HTTP health checks.');
		}
		$check = $this->config['check'];
		$ip = $server['ip'];
		$host = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
		$url = sprintf('%s://%s:%d%s', $check['scheme'], $host, $check['port'], $check['path']);
		$handle = curl_init($url);
		if ($handle === false) {
			throw new RuntimeException('Could not initialize the HTTP health check.');
		}
		$body = '';
		$tooLarge = false;
		$limit = $check['max_body_bytes'];
		try {
			$optionsSet = curl_setopt_array($handle, [
				CURLOPT_HEADER => false,
				CURLOPT_TIMEOUT => $check['timeout_sec'],
				CURLOPT_CONNECTTIMEOUT => $check['connect_timeout_sec'],
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_FAILONERROR => false,
				CURLOPT_PROXY => '',
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
				CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $limit): int {
					if (strlen($body) + strlen($chunk) > $limit) {
						$tooLarge = true;
						return 0;
					}
					$body .= $chunk;
					return strlen($chunk);
				},
			]);
			if (!$optionsSet) {
				throw new RuntimeException('Could not configure the HTTP health check.');
			}
			$result = curl_exec($handle);
			$status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
			$error = curl_error($handle);
			$available = $result !== false
				&& !$tooLarge
				&& $status === $check['expected_status']
				&& trim($body) === $check['expected_body'];
			if ($available) {
				return ['available' => true, 'status' => $status, 'error' => null];
			}
			if ($tooLarge) {
				$error = 'Health response exceeded ' . $limit . ' bytes.';
			} elseif ($error === '') {
				$preview = substr($body, 0, 512);
				$error = 'Unexpected response (HTTP ' . $status . '): ' . $preview . (strlen($body) > 512 ? '...' : '');
			}
			return ['available' => false, 'status' => $status, 'error' => $error];
		} finally {
			curl_close($handle);
		}
	}

	public function hasInternetConnection(): bool
	{
		$check = $this->config['internet_check'];
		if (!$check['enabled']) {
			return true;
		}
		$timeout = $check['timeout_sec'];
		$result = ProcessRunner::run([
			$check['ping_binary'], '-n', '-c', '1', '-W', (string) $timeout,
			'-w', (string) $timeout, $check['host'],
		], '', $timeout + 2);
		return $result['exit_code'] === 0;
	}
}
