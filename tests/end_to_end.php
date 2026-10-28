<?php

declare(strict_types=1);

// Starts only loopback fixtures with temporary keys, data, state, and messages.
require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/TestSupport.php';

use DnsMonitor\ProcessRunner;
use DnsMonitor\Tests\Suite;
use function DnsMonitor\Tests\expect;
use function DnsMonitor\Tests\removeTree;
use function DnsMonitor\Tests\same;
use function DnsMonitor\Tests\temporaryDirectory;

function integrationBinary(string $environment, string $fallback): string
{
	return ProcessRunner::executable(getenv($environment) ?: $fallback);
}

function integrationPort(): int
{
	$socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
	if ($socket === false) {
		throw new RuntimeException('Cannot reserve a loopback fixture port: ' . $message);
	}
	$address = stream_socket_get_name($socket, false);
	$port = (int) substr($address, strrpos($address, ':') + 1);
	return fclose($socket) ? $port : throw new RuntimeException('Cannot release fixture port.');
}

function integrationJson(string $path, array $data): void
{
	file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
}

function integrationOk(array $result): void
{
	same(0, $result['exit_code'], 'Command failed: ' . $result['stdout'] . $result['stderr']);
}

function integrationQuote(string $value): string
{
	return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

$directory = temporaryDirectory();
$children = [];
$suite = new Suite();
$exitCode = 1;
try {
	if (!extension_loaded('curl')) {
		throw new RuntimeException('The end-to-end tests require PHP cURL.');
	}
	$managerScript = realpath(getenv('DNS_MONITOR_MANAGER_SCRIPT') ?: dirname(__DIR__, 2) . '/dns-manager/dns-manager');
	if ($managerScript === false) {
		throw new RuntimeException('Place dns-manager beside dns-monitor or set DNS_MONITOR_MANAGER_SCRIPT.');
	}
	$named = integrationBinary('DNS_MONITOR_NAMED_BINARY', 'named');
	$dig = integrationBinary('DNS_MONITOR_DIG_BINARY', 'dig');
	$nsupdate = integrationBinary('DNS_MONITOR_NSUPDATE_BINARY', 'nsupdate');
	$checkzone = integrationBinary('DNS_MONITOR_CHECKZONE_BINARY', 'named-checkzone');
	$dnsPort = integrationPort();
	$httpPort = integrationPort();
	$source = $directory . '/source.zone';
	$live = $directory . '/live.zone';
	$key = $directory . '/test.key';
	$sourceText = <<<'ZONE'
$ORIGIN example.test.
$TTL 60
@ 60 IN SOA ns1.example.test. hostmaster.example.test. (1 3600 600 604800 60)
@ 60 IN NS ns1.example.test.
ns1 60 IN A 127.0.0.1
www 60 IN A 192.0.2.10 ; dm=web1
www 60 IN A 192.0.2.20 ; dm=web2
meta 60 IN TXT "web1 metadata" ; dm=web1
infra 60 IN A 192.0.2.10

ZONE;
	file_put_contents($source, $sourceText);
	file_put_contents($live, $sourceText);
	file_put_contents($key, 'key "monitor-test" { algorithm hmac-sha256; secret "' . base64_encode(random_bytes(32)) . '"; };' . "\n");
	chmod($key, 0600);
	$namedConfig = 'include ' . integrationQuote($key) . ";\n";
	$namedConfig .= 'options { directory ' . integrationQuote($directory) . '; listen-on port ' . $dnsPort . ' { 127.0.0.1; }; listen-on-v6 { none; }; recursion no; dnssec-validation no; trust-anchor-telemetry no; empty-zones-enable no; pid-file ' . integrationQuote($directory . '/named.pid') . '; session-keyfile ' . integrationQuote($directory . '/session.key') . "; };\n";
	$namedConfig .= "controls { };\n";
	$namedConfig .= 'zone "example.test" { type primary; file ' . integrationQuote($live) . '; update-policy { grant monitor-test name www.example.test. A; grant monitor-test name meta.example.test. TXT; }; };' . "\n";
	file_put_contents($directory . '/named.conf', $namedConfig);
	$bindProcess = proc_open([$named, '-g', '-c', $directory . '/named.conf', '-n', '1', '-U', '1'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/named.log', 'a'], 2 => ['file', $directory . '/named.log', 'a']], $pipes);
	if (!is_resource($bindProcess)) {
		throw new RuntimeException('Cannot start isolated BIND.');
	}
	$children[] = $bindProcess;
	$query = static function (string $owner = 'www', string $type = 'A') use ($dig, $dnsPort): string {
		$result = ProcessRunner::run([$dig, '-r', '@127.0.0.1', '-p', (string) $dnsPort, $owner . '.example.test.', $type, '+tcp', '+norecurse', '+short', '+time=1', '+tries=1'], '', 3);
		integrationOk($result);
		$lines = array_filter(explode("\n", trim($result['stdout'])), static fn (string $value): bool => $value !== '');
		sort($lines);
		return implode("\n", $lines);
	};
	$ready = false;
	for ($attempt = 0; $attempt < 30; $attempt++) {
		try {
			if ($query() === "192.0.2.10\n192.0.2.20") {
				$ready = true;
				break;
			}
		} catch (Throwable) {
		}
		if (!proc_get_status($bindProcess)['running']) {
			break;
		}
		usleep(100000);
	}
	if (!$ready) {
		throw new RuntimeException('BIND fixture did not start: ' . file_get_contents($directory . '/named.log'));
	}

	foreach (['web1' => '127.0.0.1', 'web2' => '127.0.0.2'] as $alias => $ip) {
		file_put_contents($directory . '/' . $alias . '.health', 'up');
		$router = '<?php $base = __DIR__; file_put_contents($base . "/hits", "1", FILE_APPEND); $up = trim(file_get_contents($base . ' . var_export('/' . $alias . '.health', true) . ')) === "up"; http_response_code($up ? 200 : 503); echo $up ? "OK\n" : "DOWN";';
		file_put_contents($directory . '/' . $alias . '-router.php', $router);
		$child = proc_open([PHP_BINARY, '-n', '-S', $ip . ':' . $httpPort, $directory . '/' . $alias . '-router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/http.log', 'a'], 2 => ['file', $directory . '/http.log', 'a']], $pipes);
		if (!is_resource($child)) {
			throw new RuntimeException('Cannot start loopback HTTP fixture.');
		}
		$children[] = $child;
		$connected = false;
		for ($attempt = 0; $attempt < 30; $attempt++) {
			$socket = @stream_socket_client('tcp://' . $ip . ':' . $httpPort, $code, $error, 0.1);
			if (is_resource($socket)) {
				fclose($socket);
				$connected = true;
				break;
			}
			usleep(100000);
		}
		if (!$connected) {
			throw new RuntimeException('HTTP fixture did not start: ' . file_get_contents($directory . '/http.log'));
		}
	}
	$notify = $directory . '/notify';
	file_put_contents($notify, '#!' . PHP_BINARY . "\n<?php\nfile_put_contents(__DIR__ . '/messages.jsonl', json_encode(array_slice(\$argv, 1), JSON_THROW_ON_ERROR) . \"\\n\", FILE_APPEND);\n");
	chmod($notify, 0700);
	$messages = static function () use ($directory): array {
		if (!is_file($directory . '/messages.jsonl')) {
			return [];
		}
		return array_map(static fn (string $line): array => json_decode($line, true, 64, JSON_THROW_ON_ERROR), file($directory . '/messages.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
	};
	$manager = [
		'binaries' => ['nsupdate' => $nsupdate, 'named_checkzone' => $checkzone, 'dig' => $dig],
		'timeout_sec' => 3, 'lock_timeout_sec' => 1,
		'zones' => ['example.test' => ['source_file' => $source, 'server' => '127.0.0.1', 'port' => $dnsPort, 'key_file' => $key, 'check_names' => 'fail']],
	];
	integrationJson($directory . '/manager.json', $manager);
	$inventory = [['alias' => 'web1', 'ip' => '127.0.0.1'], ['alias' => 'web2', 'ip' => '127.0.0.2']];
	integrationJson($directory . '/servers.json', $inventory);
	$config = [
		'dns_manager' => ['php_binary' => PHP_BINARY, 'script' => $managerScript, 'config' => 'manager.json', 'zone' => 'example.test', 'timeout_sec' => 30],
		'servers_file' => 'servers.json', 'state_file' => 'state.json', 'lock_file' => 'monitor.lock', 'log_file' => 'monitor.log',
		'check' => ['scheme' => 'http', 'port' => $httpPort, 'path' => '/replica_status.php', 'timeout_sec' => 2, 'connect_timeout_sec' => 1, 'expected_status' => 200, 'expected_body' => 'OK'],
		'apply_on_first_run' => false, 'allow_all_down_publish' => false,
		'internet_check' => ['enabled' => false], 'notify_script' => $notify, 'notify_timeout_sec' => 3,
		'retry' => ['down_after_failures' => 3, 'up_after_successes' => 2],
	];
	integrationJson($directory . '/monitor.json', $config);
	$php = [PHP_BINARY];
	if (getenv('DNS_MONITOR_CURL_EXTENSION')) {
		$php = [PHP_BINARY, '-n', '-d', 'extension=' . getenv('DNS_MONITOR_CURL_EXTENSION')];
	}
	$run = static fn (array $arguments = []): array => ProcessRunner::run([...$php, dirname(__DIR__) . '/dns-monitor', '--config', $directory . '/monitor.json', ...$arguments], '', 45);
	$state = static fn (): array => json_decode(file_get_contents($directory . '/state.json'), true, 64, JSON_THROW_ON_ERROR);
	$hits = static fn (): string => is_file($directory . '/hits') ? file_get_contents($directory . '/hits') : '';

	$suite->test('validate checks the real manager and aliases without probes or updates', static function () use ($run, $directory, $hits, $messages, $query, $source, $sourceText): void {
		integrationOk($run(['validate']));
		same('', $hits(), 'Validation contacted a health endpoint.');
		expect(!is_file($directory . '/state.json'), 'Validation created state.');
		same([], $messages(), 'Validation notified.');
		same($sourceText, file_get_contents($source), 'Validation changed source.');
		same("192.0.2.10\n192.0.2.20", $query(), 'Validation changed DNS.');
	});
	$suite->test('CLI dry run makes HTTP checks without changing state, DNS, or notifications', static function () use ($run, $directory, $hits, $messages, $query): void {
		integrationOk($run(['run', '--dry-run', '--reconcile']));
		same('11', $hits(), 'Dry run did not probe each backend.');
		expect(!is_file($directory . '/state.json'), 'Dry run created state.');
		same([], $messages(), 'Dry run notified.');
		same("192.0.2.10\n192.0.2.20", $query(), 'Dry run changed DNS.');
	});
	$suite->test('first normal run establishes the original observation-only baseline', static function () use ($run, $state, $messages): void {
		integrationOk($run());
		same(false, $state()['pending_dns'], 'First observation left pending work.');
		same(true, $state()['servers']['web1']['available'], 'HTTP health was not recorded.');
		same([], $messages(), 'First observation notified.');
	});
	$suite->test('overlapping CLI run respects the monitor lock', static function () use ($run, $directory, $hits): void {
		$lock = fopen($directory . '/monitor.lock', 'c');
		expect(flock($lock, LOCK_EX | LOCK_NB), 'Cannot acquire fixture lock.');
		$before = file_get_contents($directory . '/state.json');
		$beforeHits = $hits();
		try {
			$result = $run();
			integrationOk($result);
			expect(str_contains($result['stdout'], 'already running'), 'Lock skip was not reported.');
			same($before, file_get_contents($directory . '/state.json'), 'Locked run changed state.');
			same($beforeHits, $hits(), 'Locked run probed servers.');
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	});
	$suite->test('three failed HTTP checks withdraw tagged A and TXT while preserving infrastructure', static function () use ($run, $directory, $query, $messages, $state): void {
		file_put_contents($directory . '/web1.health', 'down');
		integrationOk($run());
		integrationOk($run());
		same("192.0.2.10\n192.0.2.20", $query(), 'Two failures prematurely changed DNS.');
		integrationOk($run());
		same('192.0.2.20', $query(), 'Failed alias was not withdrawn.');
		same('', $query('meta', 'TXT'), 'Tagged TXT was not withdrawn.');
		same('192.0.2.10', $query('infra'), 'Untagged infrastructure was removed.');
		same([['server web1 disabled']], $messages(), 'Original notification contract changed.');
		same(false, $state()['pending_dns'], 'Verified publication remains pending.');
	});
	$suite->test('two successful HTTP checks restore the alias and notify once', static function () use ($run, $directory, $query, $messages): void {
		file_put_contents($directory . '/web1.health', 'up');
		integrationOk($run());
		same('192.0.2.20', $query(), 'One success prematurely restored DNS.');
		integrationOk($run());
		same("192.0.2.10\n192.0.2.20", $query(), 'Recovery failed to restore DNS.');
		same('"web1 metadata"', $query('meta', 'TXT'), 'Recovery failed to restore tagged TXT.');
		same([['server web1 disabled'], ['server web1 enabled']], $messages(), 'Recovery notification differs.');
	});
	$suite->test('all-down guard leaves the last DNS publication intact', static function () use ($run, $directory, $query, $state, $messages): void {
		file_put_contents($directory . '/web1.health', 'down');
		file_put_contents($directory . '/web2.health', 'down');
		for ($i = 0; $i < 3; $i++) {
			integrationOk($run());
		}
		same("192.0.2.10\n192.0.2.20", $query(), 'All-down guard removed DNS records.');
		same(true, $state()['pending_dns'], 'Blocked publication was forgotten.');
		same(false, $state()['servers']['web2']['available'], 'Blocked observations were lost.');
		same(2, count($messages()), 'Blocked publication emitted success notifications.');
	});
	$suite->test('recovery of one backend releases all-down protection and publishes current state', static function () use ($run, $directory, $query, $state, $messages): void {
		file_put_contents($directory . '/web2.health', 'up');
		integrationOk($run());
		same(true, $state()['pending_dns'], 'One success bypassed recovery threshold.');
		integrationOk($run());
		same('192.0.2.20', $query(), 'Recovered backend did not become the sole published backend.');
		same(false, $state()['pending_dns'], 'Recovery did not finish pending publication.');
		same(['server web1 disabled'], $messages()[2], 'Incorrect change after all-down recovery.');
	});
	$suite->test('real partial publication is repaired when health returns to the previous baseline', static function () use ($run, $directory, $query, $state, $messages, &$manager, &$config, $nsupdate): void {
		$config['retry'] = ['down_after_failures' => 1, 'up_after_successes' => 1];
		integrationJson($directory . '/monitor.json', $config);
		$wrapper = '#!' . PHP_BINARY . "\n<?php\nrequire " . var_export(dirname(__DIR__) . '/src/bootstrap.php', true) . ";\n";
		$wrapper .= '$input = stream_get_contents(STDIN); if (str_contains($input, "update delete") && str_contains($input, "192.0.2.20")) { fwrite(STDERR, "Injected update rejection\\n"); exit(17); }' . "\n";
		$wrapper .= '$result = DnsMonitor\\ProcessRunner::run([' . var_export($nsupdate, true) . ', ...array_slice($argv, 1)], $input, 5); fwrite(STDOUT, $result["stdout"]); fwrite(STDERR, $result["stderr"]); exit($result["exit_code"]);' . "\n";
		file_put_contents($directory . '/conditional-nsupdate', $wrapper);
		chmod($directory . '/conditional-nsupdate', 0700);
		$manager['binaries']['nsupdate'] = $directory . '/conditional-nsupdate';
		integrationJson($directory . '/manager.json', $manager);
		file_put_contents($directory . '/web1.health', 'up');
		file_put_contents($directory . '/web2.health', 'down');
		$result = $run();
		same(1, $result['exit_code'], 'Partial manager failure was reported as success.');
		expect(str_contains($result['stderr'], 'exit 4'), 'Manager uncertainty exit was not propagated.');
		same("192.0.2.10\n192.0.2.20", $query(), 'First alias did not really change before the injected failure.');
		same(true, $state()['pending_dns'], 'Partial publication was not retained.');
		expect(str_starts_with($messages()[3][0], 'DNS failover command failed: '), 'Original failure notification was lost.');
		$manager['binaries']['nsupdate'] = $nsupdate;
		integrationJson($directory . '/manager.json', $manager);
		file_put_contents($directory . '/web1.health', 'down');
		file_put_contents($directory . '/web2.health', 'up');
		integrationOk($run());
		same('192.0.2.20', $query(), 'Return-to-baseline repair left a wrongly enabled alias.');
		same(false, $state()['pending_dns'], 'Repair did not finish pending work.');
		same(4, count($messages()), 'Repair invented a health transition notification.');
	});
	$suite->test('explicit reconcile repairs live DNS drift without needing a health transition', static function () use ($run, $query, $nsupdate, $key, $dnsPort, $messages): void {
		integrationOk(ProcessRunner::run([$nsupdate, '-v', '-k', $key], "server 127.0.0.1 {$dnsPort}\nzone example.test.\nupdate delete www.example.test. IN A 192.0.2.20\nsend\n", 5));
		same('', $query(), 'Drift fixture did not remove the record.');
		integrationOk($run(['run', '--reconcile']));
		same('192.0.2.20', $query(), 'Reconcile did not restore the desired alias.');
		same(4, count($messages()), 'Reconcile invented health notifications.');
	});
	$suite->test('invalid inventory and mistyped configuration fail before any health or DNS operation', static function () use ($run, $directory, &$config, $inventory, $hits, $query): void {
		$beforeHits = $hits();
		$beforeState = file_get_contents($directory . '/state.json');
		foreach ([[], [$inventory[0], $inventory[0]], [['alias' => '-bad', 'ip' => '127.0.0.1']]] as $invalid) {
			integrationJson($directory . '/servers.json', $invalid);
			same(2, $run()['exit_code'], 'Invalid inventory was accepted.');
		}
		integrationJson($directory . '/servers.json', $inventory);
		$invalidConfig = $config;
		$invalidConfig['allow_all_down_publsih'] = true;
		integrationJson($directory . '/monitor.json', $invalidConfig);
		same(2, $run()['exit_code'], 'Misspelled safety setting was ignored.');
		integrationJson($directory . '/monitor.json', $config);
		same($beforeHits, $hits(), 'Invalid inputs performed HTTP checks.');
		same($beforeState, file_get_contents($directory . '/state.json'), 'Invalid inputs altered state.');
		same('192.0.2.20', $query(), 'Invalid inputs altered DNS.');
	});
	$exitCode = $suite->finish();
} catch (Throwable $error) {
	fwrite(STDERR, 'Fixture error: ' . $error->getMessage() . "\n");
} finally {
	foreach (array_reverse($children) as $child) {
		if (is_resource($child)) {
			proc_terminate($child);
			$deadline = microtime(true) + 2;
			while (proc_get_status($child)['running'] && microtime(true) < $deadline) {
				usleep(10000);
			}
			if (proc_get_status($child)['running']) {
				proc_terminate($child, 9);
			}
			proc_close($child);
		}
	}
	removeTree($directory);
}

exit($exitCode);
