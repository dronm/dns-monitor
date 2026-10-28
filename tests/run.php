<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/TestSupport.php';

use DnsMonitor\StateStore;
use DnsMonitor\Tests\Harness;
use DnsMonitor\Tests\Suite;
use function DnsMonitor\Tests\expect;
use function DnsMonitor\Tests\same;
use function DnsMonitor\Tests\throws;
use function DnsMonitor\Tests\withHarness;

$suite = new Suite();

$suite->test('first run observes and saves a baseline without publishing or notifying', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->observations['web1'] = false;
		same(0, $h->run(), 'Initial observation should succeed.');
		$state = $h->store->load();
		same(false, $state['servers']['web1']['available'], 'A new alias should use its first observation immediately.');
		same(1, $state['servers']['web1']['failure_count'], 'Initial failure count should be one.');
		same(false, $state['baseline']['web1']['available'], 'The initial baseline should match observations.');
		same(false, $state['pending_dns'], 'Observation-only startup should not leave pending publication.');
		same([], $h->publications, 'First-run DNS publication must be opt-in.');
		same([], $h->notifications, 'Initial observations must not emit change notifications.');
	});
});

$suite->test('apply_on_first_run writes pending intent before publication and sends no change notification', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->config['apply_on_first_run'] = true;
		$h->publishHook = static function () use ($h): void {
			$pending = $h->store->load();
			same(true, $pending['pending_dns'], 'Intent must be persistent before an external change starts.');
			same(null, $pending['baseline'], 'An initial publication has no completed baseline yet.');
		};
		same(0, $h->run(), 'First publication should succeed.');
		same(1, count($h->publications), 'Initial publication should run once.');
		same(false, $h->store->load()['pending_dns'], 'Successful publication should clear pending state.');
		same([], $h->notifications, 'Initial publication should not notify changes.');
	});
});

$suite->test('unchanged effective state updates observations without publishing DNS', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->run();
		$before = $h->store->load()['servers']['web1']['success_count'];
		$h->run();
		$after = $h->store->load()['servers']['web1']['success_count'];
		expect($after >= $before, 'Continued successful observations should preserve the consecutive-success count.');
		same([], $h->publications, 'No effective change means no publication.');
		same([], $h->notifications, 'No effective change means no notification.');
	});
});

$suite->test('three failures disable and two successes enable across separate runs', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->run();
		$h->observations['web1'] = false;
		$h->run();
		$h->run();
		same([], $h->publications, 'Two failures should not disable a server.');
		same(true, $h->store->load()['servers']['web1']['available'], 'Effective availability should remain true before the threshold.');
		$h->run();
		same(1, count($h->publications), 'The third failure should publish once.');
		same(false, $h->publications[0]['web1']['available'], 'The failed server should be disabled.');
		same(true, $h->publications[0]['web2']['available'], 'Publication should include the unchanged healthy server.');
		$h->observations['web1'] = true;
		$h->run();
		same(1, count($h->publications), 'One recovery success should not enable a server.');
		$h->run();
		same(2, count($h->publications), 'The second recovery success should publish once.');
		same(true, $h->publications[1]['web1']['available'], 'Recovered server should be enabled.');
		same(['server web1 disabled', 'server web1 enabled'], $h->notifications, 'Legacy notification messages should be retained.');
	});
});

$suite->test('a successful observation resets the failure streak', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->run();
		$h->observations['web1'] = false;
		$h->run();
		$h->run();
		$h->observations['web1'] = true;
		$h->run();
		same(0, $h->store->load()['servers']['web1']['failure_count'], 'Success should reset the failure counter.');
		$h->observations['web1'] = false;
		$h->run();
		same([], $h->publications, 'A fresh failure after success must start a new streak.');
		same(1, $h->store->load()['servers']['web1']['failure_count'], 'The new failure streak should contain one observation.');
	});
});

$suite->test('a failed observation resets the recovery streak', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->observations['web1'] = false;
		$h->run();
		$h->observations['web1'] = true;
		$h->run();
		$h->observations['web1'] = false;
		$h->run();
		same(0, $h->store->load()['servers']['web1']['success_count'], 'Failure should reset the recovery counter.');
		$h->observations['web1'] = true;
		$h->run();
		same([], $h->publications, 'Recovery requires consecutive successful checks.');
	});
});

$suite->test('Internet gate runs before state loading and leaves state unchanged', static function (): void {
	withHarness(static function (Harness $h): void {
		$content = "{ deliberately malformed state\n";
		file_put_contents($h->statePath, $content);
		$h->internetAvailable = false;
		same(0, $h->run(), 'Internet loss should skip the cycle even with unreadable state content.');
		same($content, file_get_contents($h->statePath), 'Skipped cycle must not change state bytes.');
		same([], $h->probes, 'No HTTP probes should run without connectivity.');
		same([], $h->publications, 'No publication should run without connectivity.');
		same([], $h->notifications, 'No notifications should run without connectivity.');
	});
});

$suite->test('dry run probes but cannot mutate state, DNS, or notifications', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->immediateTransitions();
		$h->run();
		$before = file_get_contents($h->statePath);
		$h->probes = [];
		$h->observations['web1'] = false;
		same(0, $h->run(true), 'Dry run should succeed.');
		same(['web1', 'web2'], $h->probes, 'Dry run should perform the configured checks.');
		same($before, file_get_contents($h->statePath), 'Dry run must preserve state bytes.');
		same([], $h->publications, 'Dry run must not publish.');
		same([], $h->notifications, 'Dry run must not notify.');
	});
});

$suite->test('dry run on a fresh installation does not create runtime state', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->config['apply_on_first_run'] = true;
		same(0, $h->run(true, true), 'Dry run should override a requested reconciliation.');
		expect(!file_exists($h->statePath), 'Dry run must not create a state file.');
		same([], $h->publications, 'Dry run must suppress initial publication.');
		same([], $h->notifications, 'Dry run must suppress notifications.');
	});
});

$suite->test('failed publication retains pending work and retries unchanged desired state', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->immediateTransitions();
		$h->run();
		$h->observations['web1'] = false;
		$h->publishHook = static function () use ($h): void {
			$state = $h->store->load();
			same(true, $state['pending_dns'], 'Pending publication should be written before calling the manager.');
			same(false, $state['servers']['web1']['available'], 'The new health state should be persistent.');
			same(true, $state['baseline']['web1']['available'], 'Failed publication must keep the previous baseline.');
			throw new RuntimeException('manager exit 4: outcome unverified');
		};
		throws(static fn (): int => $h->run(), 'DNS failure should propagate.', 'manager exit 4');
		same(true, $h->store->load()['pending_dns'], 'Failure must remain pending.');
		expect(str_starts_with($h->notifications[0], 'DNS failover command failed: '), 'The legacy failure-notification prefix should be retained.');
		$h->publishHook = null;
		same(0, $h->run(), 'A later cycle should retry pending work.');
		same(2, count($h->publications), 'Unchanged observations must not suppress the pending retry.');
		same(false, $h->store->load()['pending_dns'], 'Successful replay should clear pending work.');
		same(false, $h->store->load()['baseline']['web1']['available'], 'Successful replay should advance the baseline.');
		same('server web1 disabled', $h->notifications[1], 'Successful replay should notify the completed transition.');
	});
});

$suite->test('return to the old baseline still repairs an uncertain partial publication', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->immediateTransitions();
		$h->run();
		$h->observations['web1'] = false;
		$live = ['web1' => true, 'web2' => true];
		$h->publishHook = static function (array $state) use (&$live): void {
			$live['web1'] = $state['web1']['available'];
			throw new RuntimeException('second operation failed after web1 changed');
		};
		throws(static fn (): int => $h->run(), 'A partial failure should propagate.');
		same(false, $live['web1'], 'Fixture should simulate a successful early remote mutation.');
		$h->observations['web1'] = true;
		$h->publishHook = static function (array $state) use (&$live): void {
			foreach ($state as $alias => $server) {
				$live[$alias] = $server['available'];
			}
		};
		$h->run();
		same(2, count($h->publications), 'Pending state must force replay even when health matches the old baseline again.');
		same(true, $live['web1'], 'Replay must restore the recovered server.');
		same(false, $h->store->load()['pending_dns'], 'Successful repair should clear pending work.');
	});
});

$suite->test('initial publication failure stays pending without sending a change notification', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->config['apply_on_first_run'] = true;
		$h->publishHook = static function (): void {
			throw new RuntimeException('initial DNS rejection');
		};
		throws(static fn (): int => $h->run(), 'Initial DNS error should propagate.');
		$state = $h->store->load();
		same(null, $state['baseline'], 'A failed first publication must not invent a successful baseline.');
		same(true, $state['pending_dns'], 'A failed first publication must be retried.');
		same([], $h->notifications, 'Original startup behavior suppresses failure notifications before a baseline exists.');
	});
});

$suite->test('all-down guard prevents publication while retaining observations and pending work', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->immediateTransitions();
		$h->run();
		$h->observations = ['web1' => false, 'web2' => false];
		same(0, $h->run(), 'Guarded all-down cycle should skip publication.');
		$state = $h->store->load();
		same([], $h->publications, 'Guard must prevent every manager operation.');
		same([], $h->notifications, 'Guard must not report unpublished disables as complete.');
		same(true, $state['pending_dns'], 'Unpublished all-down state should remain pending.');
		same(true, $state['baseline']['web1']['available'], 'Guard should preserve the previous baseline.');
		same(false, $state['servers']['web1']['available'], 'Observed effective health should still be saved.');
		$h->run();
		expect($h->store->load()['servers']['web1']['failure_count'] >= 1, 'All-down cycles should preserve failure history.');
		$h->observations['web2'] = true;
		$h->run();
		same(1, count($h->publications), 'One recovered backend should unblock pending publication.');
		same(false, $h->publications[0]['web1']['available'], 'The remaining unavailable backend should be withdrawn.');
		same(true, $h->publications[0]['web2']['available'], 'The recovered backend should be enabled.');
	});
});

$suite->test('allow_all_down_publish explicitly permits disabling every backend', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->immediateTransitions();
		$h->config['allow_all_down_publish'] = true;
		$h->run();
		$h->observations = ['web1' => false, 'web2' => false];
		$h->run();
		same(1, count($h->publications), 'Explicit permission should allow an all-down publication.');
		same(false, $h->publications[0]['web1']['available'], 'First backend should be disabled.');
		same(false, $h->publications[0]['web2']['available'], 'Second backend should be disabled.');
		same(['server web1 disabled', 'server web2 disabled'], $h->notifications, 'Each successfully published transition should be notified.');
	});
});

$suite->test('first-run all-down protection keeps publication pending without a baseline', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->config['apply_on_first_run'] = true;
		$h->observations = ['web1' => false, 'web2' => false];
		$h->run();
		$state = $h->store->load();
		same([], $h->publications, 'First-run publishing must still obey the all-down guard.');
		same(null, $state['baseline'], 'A blocked first publication has no baseline.');
		same(true, $state['pending_dns'], 'First-run all-down publication should remain pending.');
	});
});

$suite->test('reconcile forces unchanged state publication without inventing change notifications', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->run();
		$h->run(false, true);
		same(1, count($h->publications), 'Reconciliation should force a full publication.');
		same([], $h->notifications, 'No health transition means no change notification.');
		same(false, $h->store->load()['pending_dns'], 'Successful reconciliation should finish pending work.');
	});
});

$suite->test('reconcile overrides observation-only startup but still obeys all-down protection', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->run(false, true);
		same(1, count($h->publications), 'Explicit reconciliation should publish on the first run.');
		same([], $h->notifications, 'Initial reconciliation should not notify changes.');
	});
	withHarness(static function (Harness $h): void {
		$h->observations = ['web1' => false, 'web2' => false];
		$h->run(false, true);
		same([], $h->publications, 'Reconciliation must not bypass all-down protection.');
	});
});

$suite->test('state is committed before notifications and one failed notifier does not suppress the rest', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->immediateTransitions();
		$h->config['allow_all_down_publish'] = true;
		$h->run();
		$h->observations = ['web1' => false, 'web2' => false];
		$h->notifyHook = static function (string $message) use ($h): void {
			$state = $h->store->load();
			same(false, $state['pending_dns'], 'Notifications must occur after the successful state commit.');
			same(false, $state['baseline']['web1']['available'], 'The completed baseline must be saved before notifying.');
			if ($message === 'server web1 disabled') {
				throw new RuntimeException('notification provider unavailable');
			}
		};
		same(0, $h->run(), 'A notification failure must not invalidate completed DNS work.');
		same(['server web1 disabled', 'server web2 disabled'], $h->notifications, 'A failed notification must not suppress subsequent messages.');
		$h->run();
		same(1, count($h->publications), 'Notification failure must not force unnecessary DNS publication.');
		same(2, count($h->notifications), 'Failed notifications are logged rather than automatically retried.');
	});
});

$suite->test('failure-notification errors do not replace the original DNS failure', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->immediateTransitions();
		$h->run();
		$h->observations['web1'] = false;
		$h->publishHook = static function (): void {
			throw new RuntimeException('original manager failure');
		};
		$h->notifyHook = static function (): void {
			throw new RuntimeException('secondary notification failure');
		};
		throws(static fn (): int => $h->run(), 'DNS failure should remain the reported error.', 'original manager failure');
		same(true, $h->store->load()['pending_dns'], 'Notification failure must not discard pending DNS work.');
	});
});

$suite->test('new aliases trigger full publication using their first observed availability', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->run();
		$h->config['servers'][] = ['alias' => 'web3', 'ip' => '192.0.2.30'];
		$h->observations['web3'] = false;
		$h->run();
		same(1, count($h->publications), 'Adding an alias should trigger publication.');
		same(3, count($h->publications[0]), 'Publication should include the complete current inventory.');
		same(false, $h->publications[0]['web3']['available'], 'A new unhealthy alias should use its first observation.');
	});
});

$suite->test('removing an alias stops probing it and never schedules its DNS withdrawal', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->run();
		$h->config['servers'] = [$h->config['servers'][0]];
		$h->probes = [];
		$h->run(false, true);
		same(['web1'], $h->probes, 'Removed aliases should no longer be monitored.');
		same(['web1'], array_keys($h->publications[0]), 'Removal from inventory must not implicitly delete DNS records.');
	});
});

$suite->test('transport errors retain diagnostic details without bypassing hysteresis', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->run();
		$h->observations['web1'] = ['available' => false, 'status' => 0, 'error' => 'connection timed out'];
		$h->run();
		$server = $h->store->load()['servers']['web1'];
		same(true, $server['available'], 'One transport error should not bypass the failure threshold.');
		same(false, $server['observed_available'], 'The most recent probe should be recorded separately.');
		same(0, $server['status'], 'Transport error status should be retained.');
		same('connection timed out', $server['error'], 'Probe diagnostic should be retained.');
		same([], $h->publications, 'One transport error must not trigger failover prematurely.');
	});
});

$suite->test('legacy state migration preserves availability and consecutive-check counts', static function (): void {
	withHarness(static function (Harness $h): void {
		$legacy = [
			'web1' => [
				'ip' => '192.0.2.10', 'alias' => 'web1', 'available' => true,
				'observed_available' => false, 'failure_count' => 2, 'success_count' => 0,
				'status' => 503, 'error' => 'unhealthy', 'checked_at' => '2026-01-01T00:00:00+00:00',
			],
			'web2' => [
				'ip' => '192.0.2.20', 'alias' => 'web2', 'available' => true,
				'observed_available' => true, 'failure_count' => 0, 'success_count' => 25,
				'status' => 200, 'error' => null, 'checked_at' => '2026-01-01T00:00:00+00:00',
			],
		];
		file_put_contents($h->statePath, json_encode($legacy, JSON_THROW_ON_ERROR));
		$loaded = $h->store->load();
		same(2, $loaded['servers']['web1']['failure_count'], 'Migration must preserve in-progress failure streaks.');
		same(true, $loaded['baseline']['web1']['available'], 'Migration must preserve the effective baseline.');
		same(false, $loaded['pending_dns'], 'Legacy state has no known pending operation.');
		$h->observations['web1'] = false;
		$h->run();
		same(1, count($h->publications), 'The next failure should cross the old threshold without starting over.');
		same(false, $h->publications[0]['web1']['available'], 'Migrated consecutive failures should disable the backend.');
		same(1, json_decode((string) file_get_contents($h->statePath), true, 512, JSON_THROW_ON_ERROR)['version'], 'Successful write should upgrade the on-disk format.');
	});
});

$suite->test('legacy state with missing optional counters gets compatible defaults', static function (): void {
	withHarness(static function (Harness $h): void {
		$legacy = ['web1' => ['ip' => '192.0.2.10', 'alias' => 'web1', 'available' => false]];
		file_put_contents($h->statePath, json_encode($legacy, JSON_THROW_ON_ERROR));
		$server = $h->store->load()['servers']['web1'];
		same(false, $server['available'], 'Migration should preserve effective availability.');
		same(false, $server['observed_available'], 'Missing observation should default to effective availability.');
		same(0, $server['failure_count'], 'Missing failure count should default to zero.');
		same(0, $server['success_count'], 'Missing success count should default to zero.');
	});
});

$suite->test('malformed state fails closed and remains untouched', static function (): void {
	withHarness(static function (Harness $h): void {
		$content = "{malformed state}\n";
		file_put_contents($h->statePath, $content);
		throws(static fn (): int => $h->run(), 'Corrupt state must not be treated as a first run.');
		same($content, file_get_contents($h->statePath), 'Corrupt state must be preserved for diagnosis.');
		same([], $h->probes, 'State corruption should stop probes.');
		same([], $h->publications, 'State corruption must not trigger publication.');
		same([], $h->notifications, 'State corruption must not fabricate notifications.');
	});
});

$suite->test('state for another zone cannot be silently reused', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->run();
		$otherZone = new StateStore($h->statePath, 'other.example.test');
		throws(static fn (): array => $otherZone->load(), 'A different zone must not inherit this publication baseline.');
	});
});

$suite->test('unknown state format versions are rejected', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->run();
		$state = json_decode((string) file_get_contents($h->statePath), true, 512, JSON_THROW_ON_ERROR);
		$state['version'] = 999;
		file_put_contents($h->statePath, json_encode($state, JSON_THROW_ON_ERROR));
		throws(static fn (): array => $h->store->load(), 'Unknown state versions must be rejected.');
	});
});

$suite->test('numeric aliases survive JSON state round trips and preserve their command identity', static function (): void {
	withHarness(static function (Harness $h): void {
		$h->immediateTransitions();
		$h->config['servers'] = [
			['alias' => '0', 'ip' => '192.0.2.10'],
			['alias' => '1', 'ip' => '192.0.2.20'],
			['alias' => '01', 'ip' => '192.0.2.30'],
		];
		$h->observations = ['0' => true, '1' => true, '01' => true];
		$h->run();
		$h->observations['0'] = false;
		$h->run();
		same('0', $h->publications[0]['0']['alias'], 'PHP numeric array keys must not change the string alias sent to the manager.');
		same(false, $h->publications[0]['0']['available'], 'Numeric alias zero should be independently disabled.');
		same(true, $h->publications[0]['01']['available'], 'A leading-zero alias must remain a distinct identity.');
		same(['server 0 disabled'], $h->notifications, 'Numeric alias should be retained in the notification.');
		same('0', $h->store->load()['baseline']['0']['alias'], 'State reload must preserve the original alias string.');
	});
});

exit($suite->finish());
