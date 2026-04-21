# Device » Health » Disk IO, Type Filtering

The disk I/O health page (`includes/html/pages/device/health/diskio.inc.php`) provides categorized views of disk devices using `LibreNMS\Util\DiskTypeFilter`.

## How It Works

### View Hierarchy

````none
Drives » Physical Drives | Logical Drives | All Drives
   Type » All | SATA/SCSI/Virtual | NVMe Drives | Other
````

#### 1. **Main Views** (primary navigation)

- `Physical` - Whole block devices (e.g., sda, nvme0n1, da0)
- `Logical` - Partitions and virtual devices (e.g., sda1, dm-0, md0, loop0)
- `All` - Shows everything

#### 2. **Subtypes** (secondary filtering, shown below main view)

- Each view has its own set of subtypes
- Subtypes are dynamically shown only when matching drives exist on the device
- `All` is always visible
- If the `DiskTypeFilter` lack knowledge of the pattern it will default to `Physical` and `Other`.

### Physical Subtypes

| Key | Label | Examples |
|-----|-------|----------|
| `all` | All | (always shown) |
| `sd_family` | SATA/SCSI/Virtual | sd*, hd*, vd*, xvd*, da*, ad* |
| `nvme` | NVMe Drives | nvme0n1, nvme1n1 |
| `mmcblk` | MMC/SD Drives | mmcblk0, mmcblk1 |
| `memory` | Memory | ram0, zram0 |
| `other` | Other | Anything not matching above patterns |

### Logical Subtypes

| Key | Label | Examples |
|-----|-------|----------|
| `all` | All | (always shown) |
| `partitions` | Partitions | sda1, nvme0n1p1, da0p1, ad0s1c |
| `dm` | Device Mapper | dm-0, dm-1 |
| `sw_raid` | Software RAID | md0 (Linux), ccd* (BSD) |
| `loop` | Image | loop0, loop1 |
| `other` | Other | Anything not matching above patterns |

## Device Pattern Reference

### Unix

The `sd_family` subtype groups traditional SATA, SCSI, and paravirtual block devices (e.g., VMware `sd*`, Xen `xvd*`, KVM/VirtIO `vd*`) that share sequential naming conventions across Linux and BSD. Both spinning disks and SSDs with these interfaces are classified here.

Common patterns:

| Pattern | Type | View | Subtype |
|---------|------|------|---------|
| `sd*`, `hd*`, `vd*`, `xvd*` | Physical disk | physical | sd_family |
| `nvme*n*` | NVMe device | physical | nvme |
| `mmcblk*` | MMC/SD card | physical | mmcblk |
| `ram*`, `zram*` | Memory-backed | physical | memory |
| `sda1`, `nvme0n1p1` | Partition | logical | partitions |
| `dm-*` | Device mapper | logical | dm |
| `loop*` | Loop device | logical | loop |

#### Linux

Unique to Linux:

| Pattern | Type | View | Subtype |
|---------|------|------|---------|
| `md*` | Software RAID | logical | sw_raid |

#### BSD (FreeBSD, OpenBSD, NetBSD, DragonFly)

Unique to BSD:

| Pattern | Type | View | Subtype |
|---------|------|------|---------|
| `da*`, `ad*`, `ada*` | Physical disk | physical | sd_family |
| `wd*` | IDE disk | physical | other |
| `da0p1`, `ad0s1c` | Partition | logical | partitions |
| `ccd*` | Configurable disk (RAID) | logical | sw_raid |
| `vnd*` | Vnode disk | logical | sw_raid |
| `md*` | Memory disk | physical | memory |

