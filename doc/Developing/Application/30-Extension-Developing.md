---
title: 3.0 Developing SNMP Extensions
description: Developer guideline for serving a LibreNMS application over a custom MIB (pass_persist or AgentX) or the legacy JSON extend.
tags:
  - developing
  - snmp
  - extensions
---

# Developing SNMP Extensions

This guide covers the monitored-host side of a LibreNMS application: how the
agent exposes its data to `snmpd`, local configuration, and (for the legacy
model) cache files and refresh scheduling.

The LibreNMS-side application handler is covered in `02-Application-Developing.md` and `11-App-Based-Sensors.md`.

## Goal

A good extension should be predictable to install, safe to upgrade, and cheap for `snmpd` to serve.

## Choosing a transport

LibreNMS extensions can deliver data to the poller in three ways. For a new
extension, **prefer a custom MIB** - served over `pass_persist` (simplest) or
AgentX (most robust). The maintainers favour a custom MIB for anything beyond a
trivial scalar payload. The JSON `extend` model remains supported for simple
payloads and existing extensions but is considered legacy.

| | Custom MIB + `pass_persist` (preferred) | Custom MIB + AgentX (alternative) | JSON `extend` (legacy) |
| --- | --- | --- | --- |
| Wire format | Real SNMP OIDs you define in a MIB | Real SNMP OIDs you define in a MIB | A blob of JSON returned by one OID |
| Typing | Strong (INTEGER, Gauge64, enums, TruthValue) | Strong (INTEGER, Gauge64, enums, TruthValue) | Stringly-typed JSON |
| Discoverability | Self-describing; walkable; reusable by any SNMP manager | Self-describing; walkable; reusable by any SNMP manager | Opaque to anything but your handler |
| Partial polling | Poller can read only the tables it needs | Poller can read only the tables it needs | Whole payload returned every poll |
| Process model | `snmpd` spawns and keeps one persistent helper | Standalone daemon connects to `snmpd` over a socket | Heavy script must be cached to `/run` |
| Lifecycle | Tied to `snmpd`; restarts with it | Independent of `snmpd`; own user/privileges/restarts | Tied to the refresh timer/cron |
| Setup cost | Low | Higher (socket, daemon, registration) | Low |
| Best for | Structured, multi-table data; one host | Structured data; long-lived/large tables; isolation | Small/simple payloads, quick ports |

Both custom-MIB transports serve the *same* MIB and look identical to the
poller - they differ only in how the helper process talks to `snmpd`.

### Custom MIB + pass_persist (preferred)

Define an enterprise MIB describing your objects and serve it from a
`pass_persist` agent. `snmpd` keeps the agent running and forwards requests for
your OID subtree to it, so the poller reads typed, walkable tables directly -
no cache file and no `extend` JSON.

```text
snmpd pass_persist              LibreNMS poller
------------------              --------------
agent answers live   --->       walk MDADM-MIB tables
typed SNMP objects              map OIDs -> sensors/DB
```

LibreNMS ships these MIBs under
[`mibs/librenms/`](https://github.com/librenms/librenms/tree/master/mibs/librenms).
Use them as worked examples of the preferred style:

- `MDADM-MIB` - multi-table example (array/device metadata, health, and sync
  tables) consumed by the [Mdadm](../../Extensions/Applications/Mdadm.md) app.
- `XCP-NG-VMINFO-MIB` - VM inventory served via `pass_persist`, consumed by the
  [XCP-NG Virtual Machines](../../Extensions/Applications/XCP-NG%20Virtual%20Machines.md) app.

Register the agent with `snmpd` for your enterprise OID, for example:

```conf
pass_persist .1.3.6.1.4.1.60652.101 /usr/local/lib/snmpd/mdadm
```

`snmpd` speaks the simple line-based pass_persist protocol (`PING`, `get`,
`getnext`, `set`) to the helper on stdin/stdout; most language net-snmp bindings
provide a ready-made pass_persist loop. The helper replies with three lines per
value - OID, type, value - using the type tokens `integer`, `gauge`,
`counter`, `counter64`, `timeticks`, `ipaddress`, `objectid`, `string`, and
`opaque`.

!!! warning "pass_persist cannot transport binary OCTET STRINGs (the hex trap)"
    The protocol is line-based ASCII and has **no hex/binary string token** - the
    only string type is `string`, whose value snmpd treats as ASCII text. So any
    MIB object that is a *binary* `OCTET STRING` does not round-trip: snmpd
    mangles the bytes or silently drops the varbind before it reaches the poller.
    This bites the common binary textual conventions:

    - `DateAndTime` (an 8/11-byte OCTET STRING - the 2-byte year is non-printable)
    - raw UUIDs, `MacAddress`, `PhysAddress`, and similar packed-byte types

    Represent these as text instead. Define the object as `DisplayString` and
    emit it via the `string` token: timestamps as ISO-8601
    (`2026-06-10T19:45:09+00:00`), UUIDs/MACs as their canonical hex-with-
    separators string, and 64-bit "none"/"max" sentinels as a decimal
    `counter64` (e.g. `18446744073709551615`). This is why `MDADM-MIB` uses
    `DisplayString` for every timestamp rather than `DateAndTime`.

    AgentX (below) does not have this limitation - a real subagent can serve
    binary OCTET STRINGs natively - so it is the better choice if your MIB must
    carry packed-byte objects.

### Custom MIB + AgentX (alternative)

AgentX ([RFC 2741](https://www.rfc-editor.org/rfc/rfc2741)) serves the same
custom MIB, but the helper runs as an independent **subagent daemon** that
connects to the `snmpd` master over a socket and registers its OID subtree.
`snmpd` then routes requests for that subtree to the subagent. This is more
robust than `pass_persist` for long-lived or large tables: the subagent is not
forked per request, survives `snmpd` restarts (it reconnects), and can run under
its own user and privileges.

```text
AgentX subagent daemon          snmpd master              LibreNMS poller
----------------------          ------------              --------------
registers MIB subtree   <--->   routes OID subtree  <---  walk MIB tables
answers AgentX requests         over agentx socket        map OIDs -> sensors/DB
```

Enable the AgentX master in `snmpd.conf` and choose a socket:

```conf
master agentx
agentXSocket /var/agentx/master
# or a TCP socket, e.g. agentXSocket tcp:localhost:705
```

Restart `snmpd`, then run the subagent as a service so it reconnects on boot and
after a master restart:

```ini
[Unit]
Description=LibreNMS mdadm AgentX subagent
After=snmpd.service
Wants=snmpd.service

[Service]
ExecStart=/usr/local/lib/snmpd/mdadm --agentx
Restart=on-failure
User=Debian-snmp
Group=Debian-snmp

[Install]
WantedBy=multi-user.target
```

Implement the subagent with an AgentX-capable binding, for example Python
(`pyagentx`, `python-netsnmpagent`) or Perl (`NetSNMP::agent`). The MIB and the
LibreNMS-side handler are identical to the pass_persist case - only the
host-side process model changes.

Use AgentX when the agent maintains large tables, needs to run on its own
schedule or privileges, or must stay up independently of `snmpd`. Otherwise
`pass_persist` is simpler to ship.

### pass_persist vs AgentX

Both serve a custom MIB, but they make different trade-offs. The short version:
`pass_persist` is the duct-tape option - quick and good enough for small glue;
AgentX is the engineered option - the right architecture for a real, long-lived
agent.

| Topic | `pass_persist` | AgentX |
| --- | --- | --- |
| Best for | Quick scripts, simple read-only values | Real subagents and serious MIBs |
| Interface | Text protocol over stdin/stdout | AgentX protocol to the `snmpd` master agent |
| Complexity | Low | Medium/higher |
| Language | Any language | C/C++ via Net-SNMP, Python via libraries |
| Tables | Possible, but painful | Proper fit |
| `GETNEXT` / `snmpwalk` | You must implement OID ordering yourself | Library/framework helps |
| SET support | Awkward/limited | Much better transaction model |
| Traps/notifications | Not natural | Native subagent use case |
| Robustness | Fragile: stdout noise, blocking, parser bugs | More robust and SNMP-native |
| Production use | Fine for small glue | Better for long-term maintained agents |

**Use `pass_persist`** when you need a fast prototype or a small read-only
extension. It is simple, scriptable, and good enough for a handful of scalar
values.

**Use AgentX** when you have real SNMP tables, dynamic indexes, traps, proper
`snmpwalk` behaviour, or anything that should survive long-term maintenance -
i.e. a MIB like the multi-table `MDADM-MIB`.

#### Useful links

- [Net-SNMP AgentX overview](https://net-snmp.sourceforge.io/docs/README.agentx.html)
  - Net-SNMP ships a reasonably full AgentX implementation and supports the
  AgentX protocol operations from RFC 2741.
- [`snmp_agent_api` manpage](https://netsnmp.org/man/snmp_agent_api.html) - the
  C/C++ API for embedding an SNMP or AgentX agent into external software.
- [`netsnmpagent` (PyPI)](https://pypi.org/project/netsnmpagent/) - lets Python
  subagents connect to a local `snmpd` master over AgentX, usually through a
  Unix socket such as `/var/run/agentx/master`.
- [`pyagentx` (GitHub)](https://github.com/hosthvo/pyagentx) - a pure-Python
  AgentX client. Its project page notes it is looking for a new maintainer, so
  weigh that before relying on it in production.

### Custom MIB guidelines

These apply to both `pass_persist` and AgentX:

- Use your own enterprise OID arc; do not reuse another vendor's subtree.
- Split slow-changing identity/configuration into separate tables from
  frequently-polled health/status, so the poller can read each on its own
  interval.
- Expose a scalar version object (e.g. `mdadmVersion.0`) the poller can probe to
  detect the agent - custom-MIB agents have no `nsExtend` entry, so they are not
  found by the standard extend-discovery loop and must be probed directly.
- Use proper SNMP types and `TEXTUAL-CONVENTION` enums rather than encoding
  everything as strings.
- Ship the MIB in `mibs/librenms/` so the poller (and any SNMP manager) can
  resolve the symbolic names.

---

# Legacy: JSON extend

The remainder of this guide documents the legacy JSON `extend` model. Prefer a
custom MIB (above) for new extensions; this model remains supported for simple
payloads and existing extensions.

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
