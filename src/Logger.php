<?php

declare(strict_types=1);

namespace DnsMonitor;

use Closure;
use RuntimeException;

final class Logger
{
	private ?Closure $sink;

	public function __construct(private ?string $file = null, ?callable $sink = null)
	{
		$this->sink = $sink === null ? null : Closure::fromCallable($sink);
	}

	public function info(string $message): void
	{
		$this->write('INFO', $message);
	}

	public function error(string $message): void
	{
		$this->write('ERROR', $message);
	}

	private function write(string $level, string $message): void
	{
		$line = sprintf("[%s] %s %s\n", date(DATE_ATOM), $level, str_replace(["\r", "\n", "\0"], ['\\r', '\\n', '\\0'], $message));
		if ($this->sink !== null) {
			($this->sink)($line);
		}
		if ($this->file === null) {
			return;
		}
		if (is_link($this->file)) {
			throw new RuntimeException('Log file must not be a symbolic link.');
		}
		$handle = @fopen($this->file, 'ab');
		if ($handle === false) {
			throw new RuntimeException('Cannot open log file: ' . $this->file);
		}
		try {
			if (!flock($handle, LOCK_EX) || fwrite($handle, $line) !== strlen($line) || !fflush($handle)) {
				throw new RuntimeException('Cannot write log file: ' . $this->file);
			}
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}
}
