# Sensor Groups and Hierarchical Grouping

LibreNMS supports grouping sensors under named headings in the web UI using the `group` field on a
sensor. This document describes how to use flat groups and how to express multi-level (hierarchical)
groups using a path-like notation.

Before reading this document, familiarise yourself with the sensor discovery system:

- [Health Information](Health-Information.md) — how sensors are defined, the available sensor
  classes, and the YAML discovery format where `group` is set.
- [Sensor State Support](../Sensor-State-Support.md) — how state sensors work, including the
  state index and translation tables referenced in the Btrfs examples below.
- [Device Sensors](../../Support/Device-Sensors.md) — end-user view of the health and sensor
  pages that this document affects.

Hierarchical groups are most useful for device types that expose **multiple named instances** of the
same kind of sensor, such as:

- **UPS-NUT / SNMP UPS** — a host monitoring several UPS units, each with its own input, output,
  bypass, and battery sensors.
- **Btrfs / storage** — a host with multiple filesystems, each containing multiple block devices.
- **PDU / power distribution** — outlets or phase groups with per-phase sensors.
- **Multi-tenant or multi-stack equipment** — any device where the same sensor class (voltage,
  temperature, state, …) appears under several logically separate entities.

---

## Flat Groups

Setting `group` to a plain string places all sensors with the same value under a single shared
heading. This is the basic case and requires no special syntax. Use flat groups when a device has
only one instance of a given sensor category — for example a single UPS where grouping by
measurement type (Input, Output, Bypass) is sufficient.

### sensor_descr must always be self-contained

`sensor_descr` appears on the health page, in alerts, in the eventlog, and anywhere else sensors
are listed without group context. It must be meaningful on its own — never rely on the group
heading to supply missing context.

On the device overview page, prefix stripping removes the redundant group name from the display
when the group heading is already visible. This means the correct pattern is:

- `sensor_descr` — full self-contained name, e.g. `Output L1`
- `group` — the category the sensor belongs to, e.g. `Output`

The overview strips `Output` from `Output L1` and shows `L1` under the `Output` heading.
All other pages show the full `Output L1`.

```yaml
sensors:
    voltage:
        data:
            -
                oid: NUT-MIB::nutUpsInputTable
                value: NUT-MIB::nutUpsInputVoltage
                descr: 'Input L{{ $index }}'
                group: 'Input'
            -
                oid: NUT-MIB::nutUpsOutputTable
                value: NUT-MIB::nutUpsOutputVoltage
                descr: 'Output L{{ $index }}'
                group: 'Output'
            -
                oid: NUT-MIB::nutUpsBypassTable
                value: NUT-MIB::nutUpsBypassVoltage
                descr: 'Bypass L{{ $index }}'
                group: 'Bypass'
```

Device overview page — the group name is stripped from `sensor_descr` under each heading.
The second column shows the full group path in muted text (for flat groups this repeats
the heading; it becomes useful for suppressed single-sensor groups where no heading appears):

```
Voltage
  Bypass
    L1    Bypass    236 V
    L2    Bypass    236 V
    L3    Bypass    236 V
  Input
    L1    Input     230 V
    L2    Input     230 V
    L3    Input     230 V
  Output
    L1    Output    230 V
    L2    Output    230 V
    L3    Output    230 V
```

`/health/metric=voltage` — full `sensor_descr` shown, meaningful without group context:

```
Device              Sensor      Current
ups-host.example    Bypass L1   236 V
ups-host.example    Bypass L2   236 V
ups-host.example    Bypass L3   236 V
ups-host.example    Input L1    230 V
ups-host.example    Input L2    230 V
ups-host.example    Input L3    230 V
ups-host.example    Output L1   230 V
ups-host.example    Output L2   230 V
ups-host.example    Output L3   230 V
```

---

## Hierarchical Groups

Use `::` as a path separator to express nested levels inside the `group` field:

```
group: 'Top Level::Sub Level::Deeper Level'
```

The overview page and the health page parse this and render indented sub-headings for each level
that differs from the previous sensor. Sort order is by `group` then `sensor_descr`, so sensors
with the same path prefix are naturally adjacent.

### UPS-NUT Example

A NUT (Network UPS Tools) host monitoring multiple UPS units over SNMP. Each unit has its own
input, output, and bypass sensors. Using two-level groups keeps units separated while
sub-headings name the measurement category.

```yaml
sensors:
    voltage:
        data:
            -
                oid: NUT-MIB::nutUpsOutputTable
                value: NUT-MIB::nutUpsOutputVoltage
                descr: 'UPS Eaton 3S 700 Output'
                group: 'UPS Eaton 3S 700'
            -
                oid: NUT-MIB::nutUpsOutputTable
                value: NUT-MIB::nutUpsOutputVoltage
                descr: 'Output L{{ $index }}'
                group: 'UPS Eaton 9135 6000::Output'
            -
                oid: NUT-MIB::nutUpsBypassTable
                value: NUT-MIB::nutUpsBypassVoltage
                descr: 'Bypass L{{ $index }}'
                group: 'UPS Eaton 9135 6000::Bypass'
            -
                oid: NUT-MIB::nutUpsInputTable
                value: NUT-MIB::nutUpsInputVoltage
                descr: 'Input L{{ $index }}'
                group: 'UPS Eaton 9135 6000::Input'
```

On the device overview page the last `::` segment of `group` is stripped as a prefix from
`sensor_descr` when the group contains more than one sensor, removing repeated text:

```
Voltage
  UPS Eaton 3S 700
    UPS Eaton 3S 700 Output    230 V    ← single sensor, heading suppressed; full descr shown
  UPS Eaton 9135 6000
    Bypass
      L1    236 V
      L2    236 V
      L3    236 V
    Input
      L1    230 V
      L2    230 V
      L3    230 V
    Output
      L1    230 V
      L2    230 V
      L3    230 V
```

On the `/health/metric=voltage` page the full unmodified `sensor_descr` is always shown together
with the device hostname:

```
Device              Sensor                              Current
nut-host.example    UPS Eaton 3S 700 Output             230 V
nut-host.example    UPS Eaton 9135 6000 Bypass L1       236 V
nut-host.example    UPS Eaton 9135 6000 Bypass L2       236 V
nut-host.example    UPS Eaton 9135 6000 Bypass L3       236 V
nut-host.example    UPS Eaton 9135 6000 Input L1        230 V
nut-host.example    UPS Eaton 9135 6000 Input L2        230 V
nut-host.example    UPS Eaton 9135 6000 Input L3        230 V
nut-host.example    UPS Eaton 9135 6000 Output L1       230 V
nut-host.example    UPS Eaton 9135 6000 Output L2       230 V
nut-host.example    UPS Eaton 9135 6000 Output L3       230 V
```

### Btrfs / Storage Example

A Linux host exporting Btrfs filesystem health via SNMP. Each filesystem label becomes the
top-level group. Filesystem-wide sensors (IO, Scrub, Scrub Ops, Balance) sit directly under
it; per-device sensors use a second level keyed on the block device path.

The filesystem label is used as both the group name and the `sensor_descr` prefix so that the
health page shows a fully self-contained name (e.g. `volum1 IO`), while the overview strips
the prefix and shows just `IO` under the `volum1` heading.

```yaml
sensors:
    state:
        data:
            -
                oid: BTRFS-MIB::btrfsFsTable
                value: BTRFS-MIB::btrfsFsIo
                descr: '{{ BTRFS-MIB::btrfsFsLabel }} IO'
                group: '{{ BTRFS-MIB::btrfsFsLabel }}'
            -
                oid: BTRFS-MIB::btrfsFsTable
                value: BTRFS-MIB::btrfsFsScrub
                descr: '{{ BTRFS-MIB::btrfsFsLabel }} Scrub'
                group: '{{ BTRFS-MIB::btrfsFsLabel }}'
            -
                oid: BTRFS-MIB::btrfsFsTable
                value: BTRFS-MIB::btrfsFsScrubOps
                descr: '{{ BTRFS-MIB::btrfsFsLabel }} Scrub Ops'
                group: '{{ BTRFS-MIB::btrfsFsLabel }}'
            -
                oid: BTRFS-MIB::btrfsFsTable
                value: BTRFS-MIB::btrfsFsBalance
                descr: '{{ BTRFS-MIB::btrfsFsLabel }} Balance'
                group: '{{ BTRFS-MIB::btrfsFsLabel }}'
            -
                oid: BTRFS-MIB::btrfsDevTable
                value: BTRFS-MIB::btrfsDevIo
                descr: '{{ BTRFS-MIB::btrfsDevPath }} IO'
                group: '{{ BTRFS-MIB::btrfsFsLabel }}::{{ BTRFS-MIB::btrfsDevPath }}'
            -
                oid: BTRFS-MIB::btrfsDevTable
                value: BTRFS-MIB::btrfsDevScrub
                descr: '{{ BTRFS-MIB::btrfsDevPath }} Scrub'
                group: '{{ BTRFS-MIB::btrfsFsLabel }}::{{ BTRFS-MIB::btrfsDevPath }}'
```

With filesystem `volum1` backed by five block devices this renders on the device overview page.
`volum1` has four filesystem-level sensors plus device sub-groups, so its heading is always
shown. The `volum1` prefix is stripped from filesystem-level descrs; `/dev/sdX` is stripped
from device-level descrs:

```
State
  volum1
    Balance    OK
    IO         OK
    Scrub      OK
    Scrub Ops  OK
    /dev/sdc
      IO       OK
      Scrub    OK
    /dev/sdd
      IO       OK
      Scrub    OK
    /dev/sde
      IO       OK
      Scrub    OK
    /dev/sdf
      IO       OK
      Scrub    OK
    /dev/sdg
      IO       OK
      Scrub    OK
```

On the `/health/metric=state` page the full `sensor_descr` is shown — self-contained without
any group context:

```
Device              Sensor              Current
btrfs-host.example  volum1 Balance      OK
btrfs-host.example  volum1 IO           OK
btrfs-host.example  volum1 Scrub        OK
btrfs-host.example  volum1 Scrub Ops    OK
btrfs-host.example  /dev/sdc IO         OK
btrfs-host.example  /dev/sdc Scrub      OK
btrfs-host.example  /dev/sdd IO         OK
btrfs-host.example  /dev/sdd Scrub      OK
btrfs-host.example  /dev/sde IO         OK
btrfs-host.example  /dev/sde Scrub      OK
btrfs-host.example  /dev/sdf IO         OK
btrfs-host.example  /dev/sdf Scrub      OK
btrfs-host.example  /dev/sdg IO         OK
btrfs-host.example  /dev/sdg Scrub      OK
```

---

## Prefix Stripping on the Overview Page

When a sensor is rendered inside a group heading on the device overview page, any leading
portion of `sensor_descr` that matches the **last `::` segment** of the `group` value
(case-insensitive, with trailing whitespace and common separators ignored) is stripped. This
keeps the display compact without losing information.

| `group` | Last segment | `sensor_descr` | Displayed as |
|---|---|---|---|
| `UPS Eaton 9135 6000::Output` | `Output` | `UPS Eaton 9135 6000 Output L1` | `UPS Eaton 9135 6000 Output L1` ← no match |
| `UPS Eaton 9135 6000::Output` | `Output` | `Output L1` | `L1` |
| `UPS Eaton 9135 6000::Output` | `Output` | `output L2` | `L2` (case-insensitive) |
| `UPS Eaton 9135 6000::Output` | `Output` | `Output-L3` | `L3` |
| `UPS Eaton 9135 6000::Output` | `Output` | `Output: Phase 1` | `Phase 1` |
| `UPS Eaton 9135 6000::Output` | `Output` | `Output` | `Output` ← result empty, not stripped |

Separators trimmed between the prefix and the remainder: space, tab, `-`, `_`, `:`.

Prefix stripping only applies when **more than one sensor** shares the same group path. When a
group contains exactly one sensor, the full `sensor_descr` is shown and no group heading is
rendered for that group — the sensor label already carries enough context on its own.

---

## Edge Case: Single-Sensor Groups

If only one sensor belongs to a particular group path **and** no sub-groups exist under it, the
group heading row is suppressed and the full sensor description is shown at the parent level.
This avoids cluttered headings for one-off values.

In the UPS-NUT example above, `UPS Eaton 3S 700` has exactly one voltage sensor and no
sub-groups, so its heading is suppressed and the full descr `UPS Eaton 3S 700 Output` is
shown directly:

```
Voltage
  UPS Eaton 3S 700
    UPS Eaton 3S 700 Output    230 V    ← heading suppressed; full descr shown
  UPS Eaton 9135 6000
    ...
```

A group that has a sub-group is **never** suppressed even if it has only one directly-associated
sensor, because the heading is needed to anchor the nested levels (see the `backup` / `data`
groups in the Btrfs example above).

---

## Summary

| Scenario | `group` value | Behaviour |
|---|---|---|
| No grouping | `null` / empty | Sensor listed ungrouped |
| Flat group | `'Output'` | Single heading row |
| Two-level | `'UPS Eaton 9135 6000::Output'` | Nested headings |
| Three-level | `'Filesystem data::Devices'` | Two levels of nesting |
| Single sensor, no sub-groups | any | Heading suppressed, full descr shown |
| Single sensor, has sub-groups | `'data'` with `'data::Devices'` present | Heading kept to anchor sub-groups |
| Prefix match in descr | `'UPS Eaton 9135 6000::Output'` + descr `'Output L1'` | Displayed as `L1` under heading |
| Transceiver (special) | `'transceiver'` | Shown with port, not in health sensors |
