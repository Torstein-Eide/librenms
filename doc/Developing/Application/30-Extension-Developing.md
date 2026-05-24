---
title: 3.0 Developing SNMP Extensions
description: Developer guideline for creating SNMP extend scripts and cache refresh on the monitored host.
tags:
  - developing
  - snmp
  - extensions
---

# Developing SNMP Extensions

This guide covers the monitored-host side of a LibreNMS application: the SNMP extend script, local configuration, cache files, and refresh scheduling.

The LibreNMS-side application handler is covered in `02-Application-Developing.md` and `11-App-Based-Sensors.md`.

## Goal

A good extension should be predictable to install, safe to upgrade, and cheap for `snmpd` to serve.

Prefer this model:

```text
systemd/cron refresh         snmpd extend              LibreNMS poller
--------------------         -----------              --------------
run heavy script      --->   cat cache file     --->   read JSON payload
write /run cache             fast response             parse/process data
```

## Deliverables

When publishing an extension, provide:

| Deliverable | Recommended path |
| --- | --- |
| Executable | `/usr/local/lib/snmpd/<name>` |
| Configuration | `/etc/snmp/extension/<name>.conf` |
| Runtime cache | `/run/snmp/extension/<name>.json` |
| snmpd snippet | `/etc/snmp/snmpd.conf.d/librenms.conf` |
| systemd service/timer | reusable cache refresh unit |
| cron fallback | `/etc/cron.d/librenms-snmp-extension-<name>` |
| user documentation | install, verify, troubleshoot |

## Directory layout

```text
/usr/local/lib/snmpd/          extension executables
/etc/snmp/extension/           extension configuration
/etc/snmp/snmpd.conf.d/        snmpd include snippets
/run/snmp/extension/           runtime cache files
```

Use `/run` for cache files because they are runtime state and should not survive reboot.

## snmpd integration

Prefer cached output:

```conf
extend myext /bin/cat /run/snmp/extension/myext.json
```

Use direct execution only for very fast scripts:

```conf
extend myext /usr/local/lib/snmpd/myext --config /etc/snmp/extension/myext.conf
```

If the extension can take more than 250 ms, cache it. Slow extend scripts increase SNMP timeout risk and make polling noisy.

## Include directory

The recommended snippet file is:

```text
/etc/snmp/snmpd.conf.d/librenms.conf
```

The main `snmpd.conf` must include that directory, for example:

```conf
includeDir /etc/snmp/snmpd.conf.d
```

Your installer or documentation should verify this. Do not assume every distribution enables include directories by default.

## JSON output contract

Return JSON shaped like this:

```json
{
  "version": 1,
  "error": 0,
  "errorString": "success",
  "data": {}
}
```

| Key | Required | Meaning |
| --- | --- | --- |
| `version` | yes | Payload schema version |
| `error` | yes | `0` for success, non-zero for failure |
| `errorString` | yes | Human-readable result or error |
| `data` | yes | Extension-specific payload |

For large payloads, pipe output through `lnms_return_optimizer` so LibreNMS can decode compressed output automatically.


## Installation

???+ example 
  A example of installation skeleton can be found her: [Github Torstein Eide](https://gist.github.com/Torstein-Eide/0e184236d84eb8466a15613249d62cab)

### Debian/Ubuntu notes

```bash
apt-get update
apt-get install -y snmpd snmp ca-certificates

install -d -m 0755 /usr/local/lib/snmpd
install -d -m 0755 /etc/snmp/extension
install -d -m 0755 /etc/snmp/snmpd.conf.d
install -d -m 0755 /run/snmp/extension
```

`snmpd` commonly runs as `Debian-snmp`.

### RedHat-family notes

```bash
dnf install -y net-snmp net-snmp-utils ca-certificates
systemctl enable --now snmpd

install -d -m 0755 /usr/local/lib/snmpd
install -d -m 0755 /etc/snmp/extension
install -d -m 0755 /etc/snmp/snmpd.conf.d
install -d -m 0755 /run/snmp/extension
```

`snmpd` commonly runs as `snmp`.

### systemd cache refresh

A timer-based refresh is preferred on systemd hosts.

Example service:

```ini
[Unit]
Description=Refresh LibreNMS SNMP extension cache for %i

[Service]
Type=oneshot
ExecStart=/usr/local/lib/snmpd/%i --config /etc/snmp/extension/%i.conf --output /run/snmp/extension/%i.json
User=Debian-snmp
Group=Debian-snmp
```

Example timer:

```ini
[Unit]
Description=Refresh LibreNMS SNMP extension cache for %i every 5 minutes

[Timer]
OnBootSec=1min
OnUnitActiveSec=5min
AccuracySec=30s
Unit=librenms-snmp-extension@%i.service

[Install]
WantedBy=timers.target
```

Enable with:

```bash
systemctl daemon-reload
systemctl enable --now librenms-snmp-extension@myext.timer
```

### cron fallback

Use cron when systemd timers are not available.

```cron
PATH=/usr/local/bin:/usr/bin:/bin
*/5 * * * * Debian-snmp /usr/local/lib/snmpd/myext --config /etc/snmp/extension/myext.conf --output /run/snmp/extension/myext.json
```

Store this in:

```text
/etc/cron.d/librenms-snmp-extension-myext
```

### Minimal installer checklist

An installer should be:

- idempotent
- safe to run multiple times
- explicit about paths
- careful not to overwrite unrelated `snmpd` config
- able to install dependencies or tell the user what is missing
- able to choose systemd or cron
- able to verify `includeDir`

## Verification

After installation, verify the cache exists:

```bash
ls -l /run/snmp/extension/myext.json
cat /run/snmp/extension/myext.json
```

Verify the SNMP extend output locally:

```bash
snmpwalk -v2c -c COMMUNITY localhost NET-SNMP-EXTEND-MIB::nsExtendOutputFull."myext"
```

Verify from the LibreNMS server:

```bash
snmpwalk -v2c -c COMMUNITY HOSTNAME NET-SNMP-EXTEND-MIB::nsExtendOutputFull."myext"
```

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Extend entry missing | `snmpd.conf` includes `/etc/snmp/snmpd.conf.d` |
| Permission denied | cache file readable by `snmpd` user |
| Timeout | script is cached, not run directly by `snmpd` |
| Empty output | timer/cron wrote the cache successfully |
| LibreNMS parse error | JSON validates and version matches expected schema |

## Rules of thumb

- Keep `snmpd` fast.
- Cache heavy work.
- Keep code, config, and runtime output in separate paths.
- Use stable JSON field names.
- Include a schema `version`.
- Document how users verify both the cache and SNMP output.