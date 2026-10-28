# dns-monitor

A standalone PHP CLI that checks backend health, enables or disables their DNS records through [dns-manager](https://github.com/dronm/dns-manager), and calls a configured notification script when availability changes.

Each invocation performs one monitoring cycle. Run it from cron at your preferred interval. [dns-manager](https://github.com/dronm/dns-manager) manages the annotated zone source, TSIG authentication, BIND updates, and authoritative verification.

## Behavior

Each monitoring cycle follows these rules:

- It checks each configured server directly by IP using HTTP or HTTPS. The response must have the configured HTTP status and, after trimming surrounding whitespace, exactly match the configured body.
- It counts consecutive observations across runs. The supplied settings require three failed checks to disable an established server and two successful checks to enable it again. A success resets the failure count; a failure resets the success count.
- An optional internet check runs first. If its ping fails, server checks, DNS publication, notifications, and state changes are skipped.
- With `apply_on_first_run=false`, the first cycle saves an initial baseline without publishing DNS or notifying. A newly encountered server starts with its first observed availability; startup does not wait for the retry threshold.
- With `allow_all_down_publish=false`, it publishes nothing when every configured server is effectively unavailable. This uses availability after the consecutive-check thresholds, not just the latest HTTP response.
- A process lock prevents overlapping cycles using the same lock file.
- An effective change triggers publication of every configured alias. Unchanged cycles update observations without repeatedly invoking [dns-manager](https://github.com/dronm/dns-manager).
- Notifications use the messages `server ALIAS enabled` and `server ALIAS disabled`.

For example, a server that was available remains effectively available after its first and second failed observations. Its third consecutive failure requests `disable`. Two later consecutive successes request `enable`.

### Publication and retries

The monitor invokes the PHP [dns-manager](https://github.com/dronm/dns-manager) CLI for each configured alias:

```bash
/usr/bin/php /opt/dns-manager/dns-manager \
	--config /opt/dns-manager/dns-manager.json \
	--zone example.org web1 enable
```

It enables all desired healthy aliases before disabling unavailable aliases. An unsuccessful enable stops the batch before any disables. Every manager command must exit successfully before the monitor treats the complete publication as successful.

Updates across aliases are separate operations, so a batch can partially succeed. Before calling the manager, the monitor saves `pending_dns=true`. Any manager error, including exit code `4` or an uncertain timeout, leaves publication pending. A later cycle retries the complete current desired state, even if health has returned to the previous baseline. This prevents a partial update from being forgotten.

Observations and counters are saved during publication failures and all-down blocking, allowing recovery thresholds to progress while DNS work is pending. Failed internet checks leave the state file untouched.

## Requirements

- PHP CLI 8.1 or newer with the cURL extension and `proc_open()` enabled.
- Linux/Unix filesystem permissions and locking; the enabled internet check uses Linux `iputils`-style `ping` options.
- The separate PHP [dns-manager](https://github.com/dronm/dns-manager) project, already configured for the intended BIND zone.
- The dependencies required by [dns-manager](https://github.com/dronm/dns-manager), including `nsupdate`, `named-checkzone`, `dig`, and its update credential.
- An executable notification script if notifications are enabled.

No Composer packages or PHP framework are required. DNS operations are delegated to the separate [dns-manager](https://github.com/dronm/dns-manager) CLI.

Run it as the account already able to run [dns-manager](https://github.com/dronm/dns-manager) successfully. That account needs access to the manager configuration, its annotated zone source and source directory, its lock file, and its TSIG key. It also needs write access to the monitor's state, lock, and log files and their parent directories. Normal operation does not require running the monitor as root.

## Files and configuration

| File | Purpose |
| --- | --- |
| `dns-monitor` | CLI entry point |
| `dns-monitor.example.json` | Generic configuration example |
| `servers.example.json` | Generic server inventory |
| `dns-monitor.json` | Local operational configuration; default when `--config` is omitted |
| `servers.json` | Local operational server inventory |
| `src/` | Monitor, adapters, configuration, logging, and state handling |
| `var/` | Runtime state, lock, and logs |
| `tests/` | Isolated regression tests |

The local `dns-monitor.json` and `servers.json` files are excluded by `.gitignore`. The example files and this README use generic names. Configure the notification script separately and point `notify_script` to its executable path.

To create local configuration files from the examples:

```bash
cd /opt/dns-monitor
cp -i dns-monitor.example.json dns-monitor.json
cp -i servers.example.json servers.json
mkdir -p var
chmod 700 var
```

After copying the example, change these settings in `dns-monitor.json`:

```json
{
	"dns_manager": {
		"php_binary": "/usr/bin/php",
		"script": "/opt/dns-manager/dns-manager",
		"config": "/opt/dns-manager/dns-manager.json",
		"zone": "example.org",
		"timeout_sec": 600
	},
	"servers_file": "servers.json",
	"state_file": "var/state.json",
	"lock_file": "var/monitor.lock",
	"log_file": "var/monitor.log",
	"notify_script": "/opt/notifications/notify.sh"
}
```

This is a partial configuration showing the settings to change; retain the remaining sections from the complete example. Adapt the zone, paths, inventory, and health-check settings to the installation. The untouched example deliberately uses separate `var/*.example.*` runtime paths and `servers.example.json`.

All relative paths are resolved from the directory containing the configuration file, regardless of the working directory used by cron. `php_binary` and `ping_binary` may be executable names resolved through `PATH`; absolute paths are preferable in cron configurations.

Keep state, lock, and log paths separate from one another and from configuration files, inventory, and executable scripts. Configuration validation rejects overlapping paths, including existing hard links and paths through symlinked directories.

### Server inventory

The inventory is a JSON array of IP/alias pairs:

```json
[
	{
		"ip": "192.0.2.10",
		"alias": "web1"
	},
	{
		"ip": "192.0.2.20",
		"alias": "web2"
	}
]
```

Each alias must exactly match an alias in the manager's annotated zone source, such as `; dm=web1`. Aliases are case-sensitive, unique within the inventory, and must follow the manager's alias syntax: an alphanumeric first character, followed by alphanumerics, `.`, `_`, or `-`, with at most 64 characters total. An empty inventory is rejected.

The IP controls the health probe. DNS record definitions are stored in the manager's source file. Changing an inventory IP does not rewrite record RDATA; it causes a new publication using the configured alias definitions. Update the manager's source through its documented workflow when record values need to change.

Removing an alias from the inventory stops monitoring it. It does not disable its records. Use [dns-manager](https://github.com/dronm/dns-manager) explicitly to withdraw that alias before removing it if withdrawal is intended.

The monitor controls the enabled state of its configured aliases. A later publication can therefore replace a manual enable/disable decision for those aliases. Pause the monitor when you need to hold a different manual state during maintenance.

### Settings

| Setting | Meaning |
| --- | --- |
| `dns_manager.php_binary` | PHP executable used to launch the manager |
| `dns_manager.script` | Path to the PHP [dns-manager](https://github.com/dronm/dns-manager) entry point |
| `dns_manager.config` | Path to the manager's own JSON configuration |
| `dns_manager.zone` | Zone passed to every manager invocation |
| `dns_manager.timeout_sec` | Deadline for one entire manager invocation; default `600` seconds |
| `servers_file` | Server inventory JSON |
| `state_file` | Saved observations, baseline, and pending publication flag |
| `lock_file` | Nonblocking exclusive monitor lock |
| `log_file` | Timestamped application log; messages also go to standard output |
| `check.scheme` / `port` / `path` | HTTP endpoint shared by all monitored IPs |
| `check.timeout_sec` | Total HTTP request timeout; supplied value `3` |
| `check.connect_timeout_sec` | HTTP connection timeout; supplied value `2` |
| `check.expected_status` | Required HTTP status; supplied value `200` |
| `check.expected_body` | Exact required body after trimming the received body; supplied value `OK` |
| `check.max_body_bytes` | Maximum accepted response body; default `65536` |
| `retry.down_after_failures` | Consecutive failures before an established available server becomes unavailable; supplied value `3` |
| `retry.up_after_successes` | Consecutive successes before an established unavailable server becomes available; supplied value `2` |
| `apply_on_first_run` | Whether to publish the initial state; default `false` |
| `allow_all_down_publish` | Whether a batch may proceed when all configured servers are unavailable; default `false` |
| `internet_check.enabled` | Whether the preliminary ping check is required |
| `internet_check.host` | Hostname or IP used for the preliminary ping |
| `internet_check.timeout_sec` | Ping response/deadline timeout; supplied value `3` |
| `internet_check.ping_binary` | Ping executable |
| `notify_script` | Executable receiving notification text; `null` or an empty string disables notifications |
| `notify_timeout_sec` | Notification process timeout; default `30` seconds |

Health checks do not follow redirects or use proxy environment settings. HTTPS uses certificate verification; a direct-IP HTTPS endpoint must have a certificate valid for that IP. IPv6 addresses are supported and bracketed correctly in request URLs.

The manager timeout covers the whole alias operation, which can verify several records. It is separate from the manager's individual DNS-query timeout. Commands use argument arrays without a shell.

## Notifications

Set `notify_script` to the executable script that sends your notifications. The monitor calls it with the full message as one argument, equivalent to:

```bash
/opt/notifications/notify.sh 'server web1 disabled'
```

Messages are:

```text
server web1 disabled
server web1 enabled
DNS failover command failed: <error details>
```

Change notifications are attempted only after the whole DNS batch succeeds and the completed state is saved. Initial startup has no change notifications. DNS failure notifications are attempted when a previous baseline exists. A retry that returns to the previous baseline may repair DNS without producing an availability-change message.

If a notification script fails or times out, the error is logged. Successful DNS work remains committed, and subsequent change notifications in the same cycle are still attempted. There is no persistent notification queue or automatic redelivery of a failed change notification. Repeated DNS publication failures can each generate a failure notification.

## Commands

Run these as the account that will own the cron job.

### Validate setup

```bash
php /opt/dns-monitor/dns-monitor --config /opt/dns-monitor/dns-monitor.json validate
```

This checks configuration, local dependencies, runtime paths, saved state, the manager's zone source, and every configured alias through the manager's `validate` and `status` commands. It does not check HTTP endpoints, issue DNS updates, or send notifications. It does not prove that BIND will authorize a real update; that remains a real manager operation. Validation can write logs and the manager's source lock file.

### Preview one cycle

```bash
php /opt/dns-monitor/dns-monitor --config /opt/dns-monitor/dns-monitor.json run --dry-run
```

A dry run performs the internet and HTTP health checks and reports the resulting effective state and publication decision. It does not save counters or state, run DNS commands, or send notifications. It can create the monitor lock and append logs. Repeated dry runs therefore do not advance the persisted failure/success thresholds.

### Run one cycle

```bash
php /opt/dns-monitor/dns-monitor --config /opt/dns-monitor/dns-monitor.json run
```

`run` is the default action when omitted. With no existing state and `apply_on_first_run=false`, the initial baseline is an observation only; it is not proof that live DNS matches the observed health.

To explicitly publish the current effective state without waiting for a new transition:

```bash
php /opt/dns-monitor/dns-monitor --config /opt/dns-monitor/dns-monitor.json run --reconcile
```

`--reconcile` still performs health checks, applies the configured consecutive-check thresholds, and honors the internet and all-down guards. It overrides observation-only first-run behavior. Combine it with `--dry-run` to preview that decision.

### Inspect saved state

```bash
php /opt/dns-monitor/dns-monitor --config /opt/dns-monitor/dns-monitor.json status
```

Status prints JSON without probing servers or querying BIND. `servers` contains the most recent observations and effective availability. `baseline` is the last completed publication state, or the initial assumed baseline when startup publication was disabled. `pending_dns=true` means publication has not completed or remains blocked. A fresh installation has a `null` baseline.

Exit codes are `0` for a completed or deliberately skipped cycle, `1` for a runtime/publication failure, and `2` for invalid arguments, configuration, or inventory. An internet-check skip, all-down guard, or overlapping-process skip returns `0`; inspect logs and saved state for the reason.

## Initial setup

1. Configure the manager path, zone, server inventory, health checks, and notification script in `dns-monitor.json`.
2. Run `validate` using the account that will run the monitor.
3. Run `run --dry-run` and review the health results and publication decision.
4. Run a normal cycle to establish the initial baseline, or use `run --reconcile` to explicitly publish the current effective state. Reconciliation honors the internet check, consecutive-check thresholds, and all-down protection.
5. Review the log and `status`, then configure the cron job.

### State files

When the configured state file is absent, the monitor uses its first-run settings to establish a baseline and creates the file when it saves state. A malformed or empty existing state file causes an error; the monitor does not silently erase a damaged baseline. Use a distinct state file for each zone and monitoring instance.

## Scheduling

Install a cron entry for the same unprivileged account used during validation:

```cron
* * * * * /usr/bin/php /opt/dns-monitor/dns-monitor --config /opt/dns-monitor/dns-monitor.json run >> /opt/dns-monitor/var/cron.log 2>&1
```

This requests one cycle per minute. A cycle can run longer than a minute when manager verification or notification delivery is slow; another invocation sharing its lock logs a skip and exits. Consecutive-check thresholds count completed observations rather than elapsed wall-clock minutes.

Choose and maintain log rotation appropriate to the installation. State and log paths must be writable by the scheduled account. Use the same configuration and lock file for all invocations of one monitor instance.

## Tests

From the project directory:

```bash
php tests/run.php
php tests/config.php
php tests/adapters.php
```

The engine suite uses temporary state files and injected probes, manager calls, and notifications. It covers startup, thresholds, recovery, guards, pending publication, state persistence, and failure behavior. The configuration suite checks invalid inputs, relative paths, and overlapping files. The adapter suite uses temporary command fixtures and local HTTP listeners to check real process invocation and HTTP handling; it requires cURL and permission to open loopback sockets. It never calls the configured production manager or notification script.

With [dns-manager](https://github.com/dronm/dns-manager) installed beside this project and BIND tools available, run the full local integration suite:

```bash
php tests/end_to_end.php
```

It starts temporary BIND and HTTP servers bound only to loopback, generates a temporary TSIG key and zone, and records notification arguments locally. It checks the complete HTTP-to-monitor-to-manager-to-BIND path, including a partial publication failure followed by recovery. It does not use the operational monitor configuration, existing BIND service, production zone, or real notification script.

For a different manager location, set `DNS_MONITOR_MANAGER_SCRIPT` to its entry point. Tool paths can be supplied with `DNS_MONITOR_NAMED_BINARY`, `DNS_MONITOR_DIG_BINARY`, `DNS_MONITOR_NSUPDATE_BINARY`, and `DNS_MONITOR_CHECKZONE_BINARY`; otherwise the test uses `PATH`. If PHP cURL is loaded explicitly with `-d extension=...` rather than the normal CLI configuration, also set `DNS_MONITOR_CURL_EXTENSION` to the extension's absolute path so child monitor processes load it.

For changes to tagged DNS records, follow the [dns-manager](https://github.com/dronm/dns-manager) documentation, including its distinction between the annotated source and BIND's live zone data.
