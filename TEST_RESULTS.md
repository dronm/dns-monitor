# Verification results

Verified on 2026-10-28 with PHP CLI 8.3.6, its matching cURL extension, and BIND 9.18.39. Runtime tools were extracted into temporary test directories; system packages and production services were not changed.

## Results

| Suite | Passed | Failed | Scope |
| --- | ---: | ---: | --- |
| `tests/run.php` | 28 | 0 | Monitoring decisions, persistent state, retries, migration, and notifications |
| `tests/config.php` | 12 | 0 | Configuration and inventory validation, path identity, and file isolation |
| `tests/adapters.php` | 27 | 0 | HTTP and process adapters using loopback servers and temporary executables |
| `tests/end_to_end.php` | 11 | 0 | Actual monitor CLI, actual dns-manager, isolated BIND, HTTP fixtures, and notification recorder |
| **Total** | **78** | **0** | |

The adapter suite reported zero skipped tests, including its IPv6 loopback check. All 16 PHP source/test/entry-point files passed syntax checks. Both delivered JSON configurations load through the actual configuration parser with two servers and the original three-failure/two-success thresholds.

## What was checked

### Original monitor behavior

- One health observation per server per cycle, with consecutive counters retained across runs.
- Exact status and trimmed response-body matching, including unsuccessful HTTP responses, connection failures, and timeouts.
- Three consecutive failures before withdrawal and two consecutive successes before restoration.
- Opposite observations reset failure or recovery streaks.
- First-run observation-only baseline, and optional initial publication without change notifications.
- Internet failure skips probes, DNS, notification delivery, and state changes.
- All-down protection, including release after recovery and an explicit opt-in to publishing all down.
- Nonblocking process lock prevents an overlapping CLI cycle from probing or changing state.
- Existing notification message text and complete-message single-argument delivery.
- Successful DNS state is committed before notifications; notification failures do not undo it.
- Original alias-keyed JSON state imports without losing availability or counters.

### DNS integration and recovery

- The manager receives explicit script, configuration, zone, alias, and enable/disable arguments.
- Healthy aliases are enabled before unhealthy aliases are disabled.
- An unsuccessful enable prevents subsequent disables.
- Manager nonzero exits, including exit 4, and uncertain timeouts leave work pending.
- Pending work is saved before the first external DNS operation.
- A partial real publication followed by recovery to the old health baseline is repaired on retry.
- An explicit reconciliation repairs manually introduced DNS drift without requiring a health transition.
- Real BIND checks confirmed tagged A and TXT withdrawal/restoration while preserving untagged infrastructure.

### Validation and runtime safeguards

- Dry runs probe health but leave state, authoritative DNS, and notifications unchanged.
- Setup validation checks the actual manager source and aliases without HTTP probes or DNS updates.
- Malformed state and mismatched state versions/zones are rejected rather than silently reset.
- Duplicate/invalid aliases, invalid IPs, empty inventories, and misspelled settings are rejected.
- Numeric string aliases survive state serialization and retain their independent identities.
- Runtime files cannot overlap configuration, inventory, executable inputs, or one another; tests include symlinked parents and hard links.
- Process arguments remain separate without a shell; deadlines and bounded process output are enforced.
- HTTP redirects and proxy environment variables do not redirect health probes; response bodies are bounded.

## Test isolation and limits

The complete integration test uses the unchanged dns-manager project with a newly generated test key, an `example.test` zone, random loopback ports, and temporary state. Notification delivery is recorded by a local fixture executable. No production health endpoint, BIND service, TSIG credential, or notification destination was used.

Reference hashes confirmed that the existing dns-manager project and extracted original monitor files remained unchanged. Historical production state is not included in the new project; copy the current legacy state during cutover if continuity is desired.

Tests establish the behavior of the code and its adapters in the local environment. Production filesystem permissions, BIND authorization, scheduled execution, and the user's notification provider still depend on the deployed configuration. Use the README's validation and cutover instructions on the intended server account.

## Repeat the tests

```bash
php tests/run.php
php tests/config.php
php tests/adapters.php
php tests/end_to_end.php
```

The adapter and complete integration suites require PHP cURL and permission to open loopback sockets. The complete integration suite also requires the separate dns-manager project and BIND tools; supported environment overrides are documented in README.md.
