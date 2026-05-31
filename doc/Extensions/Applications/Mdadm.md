# Mdadm

Monitors Linux `mdadm` arrays from the LibreNMS agent. See the [Linux md documentation](https://docs.kernel.org/admin-guide/md.html) for kernel RAID details.

Collected data includes array health, operation state, disk counts, sync progress, mismatch count, member disk health/errors, and disk I/O graphs. Current agent output is stored in database tables for the mdadm device/app pages and device overview panel.

!!! note
    Use the current agent script for full support. Versions 1 and 2 receive database records, health/operation/device-presence sensors, and legacy RRD graphs. Version 3 adds full per-device detail, mismatch sensors, and v3 graphs.

## Prerequisites

This extension requires `curl`, `snmpd`, `python3`, and `mdadm`. The installer can install missing packages on supported systems.

=== "Debian/Ubuntu"

    ```bash
    sudo apt install curl snmpd ca-certificates python3 mdadm
    ```

## SNMP Extend

1. Download and run the installer on the desired host.

    ```bash
    wget https://raw.githubusercontent.com/librenms/librenms-agent/master/snmp/mdadm/mdadm_install.sh
    sudo bash mdadm_install.sh
    ```

2. Optional: limit polling to specific arrays in `/etc/snmp/extension/mdadm.yaml`.

3. Verify it is working by running:

    ```bash
    snmpwalk -v2c -c public localhost NET-SNMP-EXTEND-MIB::nsExtendOutputFull."mdadm"
    ```

## Manual Install

1. Install the extension script and create the config/cache directories.

    ```bash
    sudo install -d -m 0755 /usr/local/lib/snmpd /etc/snmp/extension /run/snmp/extension /etc/snmp/snmpd.conf.d
    sudo curl -fsSL https://raw.githubusercontent.com/librenms/librenms-agent/master/snmp/mdadm/mdadm -o /usr/local/lib/snmpd/mdadm
    sudo chmod 0755 /usr/local/lib/snmpd/mdadm
    ```

2. Create `/etc/snmp/extension/mdadm.yaml`.

    ```bash
    sudo curl -fsSL https://raw.githubusercontent.com/librenms/librenms-agent/master/snmp/mdadm/mdadm.yaml.example -o /etc/snmp/extension/mdadm.yaml
    ```

3. Add the SNMP extend entry.

    ```bash
    echo 'extend mdadm /bin/cat /run/snmp/extension/mdadm' | sudo tee -a /etc/snmp/snmpd.conf.d/librenms.conf
    ```

4. Refresh the cache every 5 minutes.

    === "systemd"

        ```bash
        sudo curl -fsSL https://raw.githubusercontent.com/librenms/librenms-agent/master/snmp/common/librenms-snmp-extension@.timer -o /etc/systemd/system/librenms-snmp-extension@.timer
        sudo curl -fsSL https://raw.githubusercontent.com/librenms/librenms-agent/master/snmp/common/librenms-snmp-extension@.service -o /etc/systemd/system/librenms-snmp-extension@.service
        sudo install -d -m 0755 /etc/systemd/system/librenms-snmp-extension@mdadm.service.d
        sudo curl -fsSL https://raw.githubusercontent.com/librenms/librenms-agent/master/snmp/mdadm/systemd-override.conf -o /etc/systemd/system/librenms-snmp-extension@mdadm.service.d/override.conf
        sudo systemctl daemon-reload
        sudo systemctl enable --now librenms-snmp-extension@mdadm.timer
        ```

    === "cron"

        Use `snmp` instead of `Debian-snmp` on RHEL-like systems.

        ```bash
        sudo curl -fsSL https://raw.githubusercontent.com/librenms/librenms-agent/master/snmp/mdadm/librenms-snmp-extension-mdadm.cron -o /etc/cron.d/librenms-snmp-extension-mdadm
        ```

5. Ensure `/etc/snmp/snmpd.conf` includes `/etc/snmp/snmpd.conf.d`, then restart `snmpd`.

    ```bash
    echo 'includeDir /etc/snmp/snmpd.conf.d' | sudo tee -a /etc/snmp/snmpd.conf
    sudo service snmpd restart
    ```

    The application should be auto-discovered as described at the
    top of the page. If it is not, please follow the steps set out
    under `SNMP Extend` heading top of page.


## Agent Version Support

The agent script outputs a versioned JSON payload. LibreNMS supports all versions, but the feature set depends on the version.

### Version 3 (current)

Full support: array and device database records, per-array health/operation/mismatch sensors, per-device health/error sensors, sync progress with byte counters and speed limits, and v3 graphs.

### Version 2 (legacy)

Partial support. Discovery creates database records and health/operation/device-presence sensors. Graphs use the legacy RRD format (same as v1).

| Field | v2 | v3 |
|---|---|---|
| Array name | ✓ | ✓ |
| RAID level | ✓ | ✓ |
| State | ✓ | ✓ |
| Disk counts (total, active, spare, failed, working) | inferred | ✓ |
| Degraded flag | ✓  | ✓  |
| Sync action | ✓ | ✓ |
| Sync speed | ✓ | ✓  |
| Sync completion % | ✓  | ✓ |
| Sync byte counters (done/total) | — | ✓ |
| Sync speed min/max | — | ✓ |
| Last sync action | — | ✓ |
| Array size | ✓  | ✓  |
| Array UUID | synthetic (`v2:<name>`) | ✓ |
| User-assigned array name | — | ✓ |
| Metadata version | — | ✓ |
| Consistency policy | — | ✓ |
| Chunk size | — | ✓ |
| Mismatch count | — | ✓ |
| Per-device slot, size, model, serial | — | ✓ |
| Per-device state flags and errors | — | ✓ |
| Sensors (health, operation, mismatch, device health) | partial (no mismatch) | ✓ |
| Error reporting | ✓ (`error` field; 1 = jq missing, 2 = no arrays) | — |

Disk counts are inferred from the payload rather than reported directly:

- `hotspare_count` is recomputed as `max(0, slave_count − disc_count)`. The agent field can be negative when a device is physically removed from sysfs before the agent runs.
- `failed_devices` = `len(missing_devices_list)` + removed count, where removed = `max(0, disc_count − slave_count)`.
- `active_devices` = `disc_count − hotspare − failed`, `working_devices` = `disc_count − failed`.
- The `degraded` boolean flag is reflected in the array health sensor (0 = Healthy, 1 = Degraded) but is not stored as a numeric count in the database; the Disk Counts panel omits it for v1/v2 arrays.

**Removed devices:** when a device is physically removed it disappears from sysfs and is absent from both `device_list` and `missing_devices_list`. LibreNMS detects it via the count difference and marks the device sensor as **Unknown** on the next poll cycle. The DB record and sensor are cleaned up on the next discovery run.

### Version 1 (legacy)

Same support level as v2. Handled by the same code path after normalising the key difference (`missing_device_list` → `missing_devices_list`).

| Field | v1 | v2 |
|---|---|---|
| Missing device list key | `missing_device_list` | `missing_devices_list` |
| Value types | strings | numbers |
| Error reporting | `error` always `"0"` — not used | `error` non-zero signals agent error |
| All other fields | identical | identical |

