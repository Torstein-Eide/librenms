# RRD Spike Remover

Tool to remove spikes/anomalies from RRD files and optionally interpolate gaps.

**Location:** `scripts/rrdclean.py`

**IMPORTANT:** Always make a backup before making changes!

## Comparison with Other RRD Spike Removers

LibreNMS includes multiple spike removal tools with different capabilities:

| Feature | rrdclean.py | removespikes.php | removespikes.pl |
|---------|-------------|------------------|-----------------|
| Language | Python | PHP | Perl |
| Spike detection | stddev, variance | stddev, variance | Percentage-based |
| Replace zeros | Yes (--zero) | No | No |
| Interpolate gaps | Yes [EXPERIMENTAL] | No | No |
| Histogram analysis | Yes | No | No |
| Suggested parameters | Yes | No | No |
| Dry-run | Yes | Yes (-D) | No |
| Per-DS thresholds | Yes | Yes | No |
| Verbose output | Yes (-v) | Yes (-d) | No |

### When to Use Each Tool

- **rrdclean.py** - Recommended for most use cases. Offers the most features including histogram analysis, parameter suggestions, gap interpolation, and zero handling.

- **removespikes.php** - Original Cacti-era script. Use when you need PHP-only environment or familiar interface.

- **removespikes.pl** - Simple percentage-based spike removal. Lightweight option for basic spike removal without statistical analysis.

```bash
cp file.rrd file.rrd.bak && ./scripts/rrdclean.py file.rrd [options]
```

## Features

- Remove spikes using statistical methods (stddev or variance)
- Replace zero values with average, NaN, or previous value
- Interpolate gaps between non-NaN values [EXPERIMENTAL]
- Preview changes before applying
- Generate histograms with suggested parameters

## Usage

```bash
./scripts/rrdclean.py input.rrd [options]
```

### Options

| Option | Description |
|--------|-------------|
| `-o, --output-rrd FILE` | Output RRD file (default: overwrite input) |
| `-M, --method METHOD` | Spike removal method (stddev or variance) |
| `-A, --avgnan METHOD` | Replacement method for spikes, zeros, gaps (avg, nan, or prev) |
| `-S, --stddev NUM` | Number of standard deviations allowed |
| `-P, --percent NUM` | Percentage variation for variance method |
| `-v, --verbose` | Show verbose output |
| `-n, --dry-run` | Don't write changes, just show what would be done |
| `--histogram` | Show data histogram and suggested parameters |
| `--interpolate` | [EXPERIMENTAL] Fill gaps between non-NaN values |
| `--max-gap NUM` | Max gap size to interpolate (default: 20) |
| `--zero` | Replace zero values (uses -A method) |

**Note:** Operations are only performed if explicitly requested. Without options, nothing happens.

## Examples

**Important:** Operations must be explicitly requested. Nothing happens without options.

### Basic Spike Removal

```bash
# Remove spikes using stddev method, 3 standard deviations
./scripts/rrdclean.py -S 3 file.rrd

# Use variance method with 5% threshold
./scripts/rrdclean.py -M variance -P 5 file.rrd

# Specify all spike parameters
./scripts/rrdclean.py -M stddev -S 3 -A nan file.rrd
```

### Preview and Analyze

```bash
# Show histogram with suggested parameters
./scripts/rrdclean.py --histogram file.rrd

# Dry-run of ALL operations (spikes + interpolate + zero) - no changes written
./scripts/rrdclean.py --dry-run -S 3 --zero avg --interpolate file.rrd

# Dry-run with verbose output
./scripts/rrdclean.py --dry-run -v -S 3 --interpolate file.rrd
```

### Output to New File

```bash
# Write to new file instead of overwriting
./scripts/rrdclean.py -o cleaned.rrd input.rrd
```

### Zero Handling

```bash
# Replace zeros with average (default if -A not specified)
./scripts/rrdclean.py --zero file.rrd

# Replace zeros with NaN
./scripts/rrdclean.py -A nan --zero file.rrd

# Replace zeros with previous value
./scripts/rrdclean.py -A prev --zero file.rrd
```

### Gap Interpolation [EXPERIMENTAL]

```bash
# Interpolate gaps (default max 20 rows)
./scripts/rrdclean.py --interpolate file.rrd

# Interpolate only small gaps (max 10 rows)
./scripts/rrdclean.py --interpolate --max-gap 10 file.rrd

# Interpolate without limit
./scripts/rrdclean.py --interpolate --max-gap 0 file.rrd
```

### Combined Operations

```bash
# Remove spikes and interpolate gaps
./scripts/rrdclean.py -S 3 --interpolate file.rrd

# Remove spikes, replace zeros, and interpolate
./scripts/rrdclean.py -S 3 --zero avg --interpolate file.rrd

# Preview combined operations
./scripts/rrdclean.py --preview -S 3 --zero avg --interpolate file.rrd
```

## Example Outputs

### Histogram Output

```
=== Data Histogram ===

[ifInOctets] (index 0):
  Count: 826
  Mean:  1.0645e+10
  StdDev: 1.4952e+09
  Min:   1.0587e+07
  Max:   1.0890e+10
  VarianceAvg (no outliers): 1.0645e+10
  Histogram (20 buckets, size=5.45e+08):
  Range: 1.06e+07 - 1.09e+10
      1.06e+07 - 5.55e+08 |     9 ████
      5.55e+08 - 1.10e+09 |     5 ███
      ...
      1.03e+10 - 1.09e+10 |   803 ██████████████████████████████████████████████████

  Suggested -S (stddev) values:
    -S 1: max_cutoff=1.2140e+10, min_cutoff=9.1499e+09, outliers=23
    -S 2: max_cutoff=1.3635e+10, min_cutoff=7.6549e+09, outliers=15
    -S 3: max_cutoff=1.5130e+10, min_cutoff=6.1599e+09, outliers=8
    -S 5: max_cutoff=1.8119e+10, min_cutoff=3.1699e+09, outliers=3
    -S 10: max_cutoff=2.5598e+10, min_cutoff=-4.3071e+09, outliers=0

  Suggested -P (percent) values for variance method:
    -P 1: limit=1.0751e+10, outliers=12
    -P 5: limit=1.1177e+10, outliers=2
    -P 10: limit=1.1709e+10, outliers=0
```

### Preview Output

```
=== Preview ===
Settings: -M stddev -S 3 -P 5 -A avg

Total rows: 826, Total values: 826, Would remove: 8

Preview mode: No changes made (dry-run)
```

### Dry-run with Verbose

```
$ ./scripts/rrdclean.py --dry-run -S 3 -v file.rrd
$ rrdtool dump file.rrd > /tmp/rrdclean_xxx/dump.xml
Std Kill: Value 8.3450e+06, StdDev 1.4952e+09, MaxCutoff 1.5130e+10
Std Kill: Value 9.1230e+06, StdDev 1.4952e+09, MaxCutoff 1.5130e+10
...
Total rows: 826, Total values: 826, Spikes removed: 8

Dry-run: No changes written.
```

### Normal Run

```
$ ./scripts/rrdclean.py -S 3 file.rrd
Total rows: 826, Total values: 826, Spikes removed: 8
Done: file.rrd
```

### Combined Operations

```
$ ./scripts/rrdclean.py -S 3 --zero avg --interpolate file.rrd
Total rows: 826, Total values: 826, Spikes removed: 8
Gaps interpolated: 45
Zeros replaced: 12
Done: file.rrd
```

## How It Works

Operations are processed in this order:

1. **Dump** - RRD file is dumped to XML format using `rrdtool dump`
2. **Analyze** - Data is parsed and statistics are calculated per DS
3. **Spike Detection** - Values outside thresholds are identified
4. **Spike Replacement** - Spikes are replaced with average or NaN
5. **Gap Interpolation** - [EXPERIMENTAL] NaN gaps are filled with linear interpolation
6. **Zero Replacement** - Zero values are replaced with avg/nan/prev
7. **Restore** - XML is converted back to RRD using `rrdtool restore`

**Note:** Each operation modifies the data. Use `--dry-run` to see combined results.

### Processing Order

When combining multiple operations, they are processed in order:

```
1. Spike removal    → outliers become avg or NaN
2. Gap interpolation → NaN gaps between valid values are filled
3. Zero replacement → 0 values are replaced
```

Example - what happens with `--interpolate --zero avg`:

```bash
./scripts/rrdclean.py --interpolate --zero avg file.rrd
```

| Step | Data | Notes |
|------|------|-------|
| Start | `1.0 → 0 → NaN → NaN → 5.0 → 0` | |
| After spike | `1.0 → 0 → NaN → NaN → 5.0 → 0` | No spikes detected |
| After interpolate | `1.0 → 0 → 2.0 → 4.0 → 5.0 → 0` | Gap filled |
| After zero | `1.0 → 2.0 → 2.0 → 4.0 → 5.0 → 3.0` | Zeros replaced with avg (3.0) |

### Spike Detection Methods

#### Standard Deviation Method (-M stddev)
```
if value > mean + (S * stddev) OR value < mean - (S * stddev):
    value is a spike
```

#### Variance Method (-M variance)
```
# Variance average excludes outliers from calculation
if value > variance_avg * (1 + P):
    value is a spike
```

### Gap Interpolation [EXPERIMENTAL]

Gaps (sequences of NaN values) are filled using linear interpolation only if:
- Gap has valid values on both sides
- Gap size is within --max-gap limit (default: 20)

```
Before: 1.0 → NaN → NaN → 4.0
After:  1.0 → 2.0 → 3.0 → 4.0
```

## Troubleshooting

### No spikes detected

Your data might have extreme outliers affecting statistics. Try:
```bash
# Use histogram to analyze data
./scripts/rrdclean.py --histogram file.rrd

# Try variance method (ignores outliers in calculation)
./scripts/rrdclean.py -M variance -P 2 file.rrd

# Use lower stddev threshold
./scripts/rrdclean.py -S 2 file.rrd
```

### All values become NaN

You may have set thresholds too aggressive. Check with preview first:
```bash
./scripts/rrdclean.py --preview -S 1 file.rrd
```

## See Also

- [RRDTool Documentation](https://oss.oetiker.ch/rrdtool/)
- LibreNMS Data Collection: `doc/General/01-General.md`
