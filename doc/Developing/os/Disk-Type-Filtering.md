# Disk Type Filtering

The disk I/O health page (`includes/html/pages/device/health/diskio.inc.php`) provides categorized views of disk devices using `LibreNMS\Util\DiskTypeFilter`.

## How It Works

### View Hierarchy

1. **Main Views** (primary navigation)
   - `physical` - Whole block devices (e.g., sda, nvme0n1, da0)
   - `logical` - Partitions and virtual devices (e.g., sda1, dm-0, md0, loop0)
   - `all` - Shows everything

2. **Subtypes** (secondary filtering, shown below main view)
   - Each view has its own set of subtypes
   - Subtypes are dynamically shown only when matching drives exist on the device
   - "All" is always visible

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

## Adding New Disk Types

### 1. Add Subtype to `LibreNMS/Util/DiskTypeFilter.php`

Update `subtypesFor()` to include the new subtype key:

```php
public static function subtypesFor(string $view): array
{
    return match ($view) {
        'physical' => ['all', 'sd_family', 'nvme', 'mmcblk', 'memory', 'other'],
        'logical' => ['all', 'partitions', 'dm', 'sw_raid', 'loop', 'other'],
        default => ['all'],
    };
}
```

### 2. Add Classification Logic

Add a new condition in `classify()` to detect the new device type:

```php
public static function classify(string $diskName, ?string $os = null): array
{
    // ... existing conditions ...

    // Your new device type
    if (preg_match('/^yourdev\d+$/i', $diskName)) {
        return ['view' => 'physical', 'subtype' => 'your_new_subtype'];
    }

    // ... rest of conditions ...
}
```

The order of conditions matters - more specific patterns should come before general ones.

### 3. Add OS-Aware Classification (Optional)

If the device type is OS-specific, use the `isSoftwareRaid()` pattern:

```php
private static function isSoftwareRaid(string $diskName, ?string $os): bool
{
    // BSD family
    if ($os !== null && in_array($os, ['freebsd', 'openbsd', 'netbsd'], true)) {
        if (preg_match('/^ccd\d+$/i', $diskName)) {
            return true;
        }
    }

    // Linux
    if (preg_match('/^md\d+$/i', $diskName)) {
        return true;
    }

    return false;
}
```

### 4. Add UI Labels in `includes/html/pages/device/health/diskio.inc.php`

Add to `$diskioSubtypes`:

```php
$diskioSubtypes = [
    'physical' => [
        // ... existing ...
        'your_new_subtype' => 'Your Label',
    ],
    'logical' => [
        // ... existing ...
        'your_new_subtype' => 'Your Label',
    ],
];
```

### 5. Add Descriptions

Add to `$subtypeDescriptions`:

```php
$subtypeDescriptions = [
    'physical' => [
        // ... existing ...
        'your_new_subtype' => 'Description of your device type (e.g., example names).',
    ],
    'logical' => [
        // ... existing ...
        'your_new_subtype' => 'Description of your device type (e.g., example names).',
    ],
];
```

### 6. Add Unit Tests

Add tests in `tests/Unit/DiskTypeFilterTest.php`:

```php
public function testClassifyNewDeviceType()
{
    $this->assertEquals(
        ['view' => 'physical', 'subtype' => 'your_new_subtype'],
        DiskTypeFilter::classify('yourdev0')
    );

    // OS-aware example
    $this->assertEquals(
        ['view' => 'physical', 'subtype' => 'your_new_subtype'],
        DiskTypeFilter::classify('yourdev0', 'freebsd')
    );
}
```

Also update `testSubtypesFor()`:

```php
public function testSubtypesFor()
{
    $this->assertContains('your_new_subtype', DiskTypeFilter::subtypesFor('physical'));
    // or 'logical' depending on your view
}
```

## Device Pattern Reference

### Linux

| Pattern | Type | View | Subtype |
|---------|------|------|---------|
| `sd*`, `hd*`, `vd*`, `xvd*` | Physical disk | physical | sd_family |
| `nvme*n*` | NVMe device | physical | nvme |
| `mmcblk*` | MMC/SD card | physical | mmcblk |
| `ram*`, `zram*` | Memory-backed | physical | memory |
| `sda1`, `nvme0n1p1` | Partition | logical | partitions |
| `dm-*` | Device mapper | logical | dm |
| `md*` | Software RAID | logical | sw_raid |
| `loop*` | Loop device | logical | loop |

### BSD (FreeBSD, OpenBSD, NetBSD, DragonFly)

| Pattern | Type | View | Subtype |
|---------|------|------|---------|
| `da*`, `ad*`, `ada*` | Physical disk | physical | sd_family |
| `wd*` | IDE disk | physical | other |
| `da0p1`, `ad0s1c` | Partition | logical | partitions |
| `ccd*` | Configurable disk (RAID) | logical | sw_raid |
| `vnd*` | Vnode disk | logical | sw_raid |
| `md*` | Memory disk | physical | memory |

## Running Tests

```bash
# Run disk type filter tests
php vendor/bin/phpunit tests/Unit/DiskTypeFilterTest.php

# Run with debug on the page
# Navigate to: device/DEVICE_ID/tab=health/metric=diskio/debug=1
```

## Adding a New Main View

If you need to add a completely new view (e.g., `network`), update:

1. `DiskTypeFilter::normalizeSelection()` - add to valid views list
2. `DiskTypeFilter::subtypesFor()` - add match case with subtypes
3. `diskio.inc.php`:
   - `$diskioViews` - add main view option
   - `$diskioSubtypes` - add subtype definitions
   - `$viewDescriptions` - add description
   - `$subtypeDescriptions` - add subtype descriptions
   - Update the subtype filter loop condition
