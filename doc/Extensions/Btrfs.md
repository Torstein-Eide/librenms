# BTRFS Monitoring

This plugin monitors btrfs filesystems using the LibreNMS unix-agent.

## Agent Payload Format

The btrfs agent sends JSON data that the poller processes. Below are the expected payload structures.

### Top Level Structure

```json
{
  "data": {
    "btrfs_version": { ... },
    "tables": { ... }
  },
  "version": 1,
  "error": 0,
  "errorString": "",
  "timestamp": "2026-03-31T07:29:07.326253+00:00"
}
```

### `data.btrfs_version`

```json
{
  "raw": "btrfs-progs v6.14\n...",
  "version": "6.14",
  "features": ["+LZO", "+ZSTD", "+ZONED", ...]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `raw` | string | Raw version string from `btrfs --version` |
| `version` | string | Parsed version number (e.g., "6.14") |
| `features` | string[] | Array of feature flags |

### `data.tables`

The `tables` object contains all the normalized data tables.

#### `tables.filesystems`

Keyed by filesystem UUID:

```json
{
  "<uuid>": {
    "mountpoint": "/",
    "label": "root",
    "rrd_key": "id",
    "total_devices": 1,
    "bytes_used": 36765609984
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `mountpoint` | string | Yes | Primary mountpoint path |
| `label` | string | No | Filesystem label (may be empty) |
| `rrd_key` | string | Yes | Unique identifier for RRD naming (alphanumeric + underscore) |
| `total_devices` | int | Yes | Total devices configured in filesystem |
| `bytes_used` | int | No | Bytes used (informational) |

#### `tables.filesystem_capacity`

Keyed by filesystem UUID:

```json
{
  "<uuid>": {
    "device_size": 268435456000,
    "device_allocated": 40835743744,
    "device_unallocated": 227599712256,
    "used": 36765609984,
    "free_estimated": 230050484224,
    "free_estimated_min": 230050484224,
    "free_statfs_df": 230049435648,
    "global_reserve": 236879872,
    "global_reserve_used": 0,
    "device_missing": 0,
    "device_slack": 0,
    "data_ratio": 1.0,
    "metadata_ratio": 1.0,
    "usage_data": 36507222016,
    "usage_metadata": 4294967296,
    "usage_system": 33554432,
    "scrub_bytes_scrubbed": null,
    "io_status_code": 0,
    "scrub_status_code": 0,
    "balance_status_code": 2,
    "io_errors": 0
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `device_size` | int | Yes | Total device size in bytes |
| `device_allocated` | int | Yes | Allocated bytes |
| `device_unallocated` | int | Yes | Unallocated bytes |
| `used` | int | Yes | Used bytes |
| `free_estimated` | int | Yes | Estimated free space |
| `free_statfs_df` | int | Yes | Free space from df |
| `device_missing` | int | No | Bytes on missing devices |
| `device_slack` | int | No | Slack space |
| `data_ratio` | float | No | Data RAID ratio |
| `metadata_ratio` | float | No | Metadata RAID ratio |
| `usage_data` | int | No | Data profile bytes |
| `usage_metadata` | int | No | Metadata profile bytes |
| `usage_system` | int | No | System profile bytes |
| `scrub_bytes_scrubbed` | int/null | No | Total bytes scrubbed |
| `io_status_code` | int | No | Pre-computed IO status (0-4) |
| `scrub_status_code` | int | No | Pre-computed scrub status (0-4) |
| `balance_status_code` | int | No | Pre-computed balance status (0-4) |
| `io_errors` | int | No | Total IO errors |

#### `tables.filesystem_devices`

Keyed by filesystem UUID, then device ID:

```json
{
  "<uuid>": {
    "1": {
      "device_path": "/dev/sda1",
      "missing": false,
      "size": 268435456000,
      "slack": 0,
      "unallocated": 227599712256,
      "data": 36507222016,
      "metadata": 4294967296,
      "system": 33554432,
      "write_io_errs": 0,
      "read_io_errs": 0,
      "flush_io_errs": 0,
      "corruption_errs": 0,
      "generation_errs": 0,
      "profiles": [
        { "profile": "data_single", "bytes": 36507222016 },
        { "profile": "metadata_single", "bytes": 4294967296 },
        { "profile": "system_single", "bytes": 33554432 }
      ],
      "backing_device_path": null
    }
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `device_path` | string | Yes | Device path (e.g., /dev/sda1) |
| `missing` | bool | No | Whether device is missing |
| `size` | int | Yes | Device size in bytes |
| `slack` | int | No | Slack space |
| `unallocated` | int | No | Unallocated bytes |
| `data` | int | No | Data profile bytes |
| `metadata` | int | No | Metadata profile bytes |
| `system` | int | No | System profile bytes |
| `write_io_errs` | int | No | Write IO errors |
| `read_io_errs` | int | No | Read IO errors |
| `flush_io_errs` | int | No | Flush IO errors |
| `corruption_errs` | int | No | Corruption errors |
| `generation_errs` | int | No | Generation errors |
| `profiles` | array | No | RAID profile usage |
| `backing_device_path` | string/null | No | For cache devices, the backing device |

#### `tables.filesystem_profiles`

Keyed by filesystem UUID:

```json
{
  "<uuid>": [
    { "profile": "data_single", "bytes": 36507222016 },
    { "profile": "metadata_single", "bytes": 4294967296 }
  ]
}
```

#### `tables.scrub_status_filesystems`

Keyed by filesystem UUID:

```json
{
  "<uuid>": {
    "status": "finished",
    "scrub_started": "Sun Mar 29 15:19:02 2026",
    "duration": "30:20:05",
    "time_left": null,
    "eta": null,
    "total_to_scrub": 36764920053,
    "bytes_scrubbed": null,
    "progress_percent": null,
    "rate": "529.15MiB/s",
    "error_summary": "no errors found",
    "is_running": false
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `status` | string | Yes | Status: running, finished, aborted |
| `scrub_started` | string | No | Human-readable start time |
| `duration` | string | No | Duration string |
| `total_to_scrub` | int | No | Total bytes to scrub |
| `bytes_scrubbed` | int/null | No | Bytes scrubbed so far |
| `progress_percent` | float/null | No | Progress percentage |
| `rate` | string | No | Scrub rate string |
| `error_summary` | string | No | Error summary |
| `is_running` | bool | No | Running flag |

#### `tables.scrub_status_devices`

Keyed by filesystem UUID, then device ID:

```json
{
  "<uuid>": {
    "1": {
      "path": "/dev/sda1",
      "status": "finished",
      "scrub_started": "Sun Mar 29 15:19:02 2026",
      "duration": "30:20:05",
      "data_extents_scrubbed": 86878846,
      "tree_extents_scrubbed": 971909,
      "data_bytes_scrubbed": 5606427664384,
      "tree_bytes_scrubbed": 15923757056,
      "read_errors": 0,
      "csum_errors": 0,
      "verify_errors": 0,
      "no_csum": 74251892,
      "csum_discards": 0,
      "super_errors": 0,
      "malloc_errors": 0,
      "corrected_errors": 0,
      "uncorrectable_errors": 0,
      "unverified_errors": 0,
      "last_physical": 12063557287936
    }
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `path` | string | Yes | Device path |
| `status` | string | Yes | Device scrub status |
| `scrub_started` | string | No | Start time |
| `duration` | string | No | Duration |
| `data_extents_scrubbed` | int | No | Data extents scrubbed |
| `tree_extents_scrubbed` | int | No | Tree extents scrubbed |
| `data_bytes_scrubbed` | int | No | Data bytes scrubbed |
| `tree_bytes_scrubbed` | int | No | Tree bytes scrubbed |
| `read_errors` | int | No | Read errors |
| `csum_errors` | int | No | Checksum errors |
| `verify_errors` | int | No | Verify errors |
| `no_csum` | int | No | Blocks without checksum |
| `csum_discards` | int | No | Discarded checksums |
| `super_errors` | int | No | Super block errors |
| `malloc_errors` | int | No | Memory allocation errors |
| `corrected_errors` | int | No | Corrected errors |
| `uncorrectable_errors` | int | No | Uncorrectable errors |
| `unverified_errors` | int | No | Unverified errors |
| `last_physical` | int | No | Last physical byte processed |

#### `tables.balance_status_filesystems`

Keyed by filesystem UUID:

```json
{
  "<uuid>": {
    "status": "idle",
    "is_running": false
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `status` | string | Yes | idle, running, paused |
| `is_running` | bool | No | Running flag |

### Required vs Optional Fields

| Table | Required Fields |
|-------|-----------------|
| `filesystems` | `mountpoint`, `rrd_key`, `total_devices` |
| `filesystem_capacity` | `device_size`, `used`, `device_allocated`, `device_unallocated`, `free_estimated`, `free_statfs_df` |
| `filesystem_devices` | `device_path`, `size` |
| `scrub_status_filesystems` | `status` |
| `scrub_status_devices` | `path`, `status` |
| `balance_status_filesystems` | `status` |
| `filesystem_profiles` | None (optional) |

### RRD Key Requirements

The `rrd_key` field in `tables.filesystems` is used for RRD filename generation. It should:

- Contain only alphanumeric characters and underscores
- Be unique per filesystem on a device
- Not exceed 63 characters (RRD naming limit)

The poller will normalize invalid characters to underscores.

### Agent Version

Set `version` to `1` in the top-level JSON. Future changes to the payload format will increment this number.

## Status Codes

The poller computes status codes (0-4) for IO, scrub, and balance operations:

| Code | Meaning |
|------|---------|
| 0 | OK / Idle |
| 1 | Running |
| 2 | Warning |
| 3 | Error |
| 4 | N/A |

The agent may optionally pre-compute these in `filesystem_capacity`, but the poller will recompute them based on device data.
