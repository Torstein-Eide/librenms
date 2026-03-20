# RRD Spike Remover

Tool to remove spikes/anomalies from RRD files and optionally fill gaps.

**Location:** `scripts/rrdclean.py`

!!! danger "Backup required"

    ```bash
    cp file.rrd file.rrd.bak && ./scripts/rrdclean.py file.rrd [options]
    ```

## Comparison with Other RRD Spike Removers

LibreNMS includes multiple spike removal tools with different capabilities:

| Feature | rrdclean.py | removespikes.php | removespikes.pl |
|---------|-------------|------------------|-----------------|
| Language | Python | PHP | Perl |
| Spike detection | stddev, variance | stddev, variance | Percentage-based |
| Replace zeros | Yes (--zero) | No | No |
| Fill gaps | Yes [EXPERIMENTAL] | No | No |
| Histogram analysis | Yes | No | No |
| Suggested parameters | Yes | No | No |
| Dry-run | Yes | Yes (-D) | No |
| Per-DS thresholds | Yes | Yes | No |
| Verbose output | Yes (-v) | Yes (-d) | No |

### When to Use Each Tool

- **rrdclean.py** - Recommended for most use cases. Offers the most features including histogram analysis, parameter suggestions, gap filling, and zero handling.

- **removespikes.php** - Original Cacti-era script. Use when you need PHP-only environment or familiar interface.

- **removespikes.pl** - Simple percentage-based spike removal. Lightweight option for basic spike removal without statistical analysis.

```bash
cp file.rrd file.rrd.bak && ./scripts/rrdclean.py file.rrd [options]
```

## Features

- Remove spikes using statistical methods (stddev or variance)
- Replace zero values with average, NaN, or previous value
- Fill gaps between non-NaN values [EXPERIMENTAL]
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
| `--fill-gaps` | [EXPERIMENTAL] Fill gaps between non-NaN values (uses -A method) |
| `--max-gap NUM` | Max gap size to fill (default: 20) |
| `--zero` | Replace zero values (uses -A method) |

!!! note ""
    Operations are only performed if explicitly requested. Without options, nothing happens.

## Examples

### Basic Spike Removal

```bash
# Remove spikes using stddev method, 3 standard deviations
./scripts/rrdclean.py -S 3 file.rrd

# Use variance method with 5% threshold
./scripts/rrdclean.py -M variance -P 5 file.rrd

# Specify all spike parameters
./scripts/rrdclean.py -M stddev -S 3 -A nan file.rrd
```

!!! tip "Use histogram first"
    Run with `--histogram` to analyze your data and get suggested `-S` and `-P` values before removing spikes.

### Preview and Analyze

```bash
# Show histogram with suggested parameters
./scripts/rrdclean.py --histogram file.rrd

# Dry-run of ALL operations (spikes + fill-gaps + zero) - no changes written
./scripts/rrdclean.py --dry-run -S 3 --zero --fill-gaps file.rrd

# Dry-run with verbose output
./scripts/rrdclean.py --dry-run -v -S 3 --fill-gaps file.rrd
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

### Gap Filling

!!! warning "Experimental feature"
    Gap filling cannot detect the true start/end of data in RRD files. It only fills gaps that have valid values on BOTH sides, so gaps at the beginning or end of data will not be filled. Always preview with `--dry-run` first and verify results.

```bash
# Fill gaps (default max 20 rows)
./scripts/rrdclean.py --fill-gaps file.rrd

# Fill only small gaps (max 10 rows)
./scripts/rrdclean.py --fill-gaps --max-gap 10 file.rrd

# Fill gaps without limit
./scripts/rrdclean.py --fill-gaps --max-gap 0 file.rrd
```

### Combined Operations

```bash
# Remove spikes and fill gaps
./scripts/rrdclean.py -S 3 --fill-gaps file.rrd

# Remove spikes, replace zeros, and fill gaps
./scripts/rrdclean.py -S 3 --zero --fill-gaps file.rrd

# Preview combined operations
./scripts/rrdclean.py --dry-run -S 3 --zero --fill-gaps file.rrd
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
    -P 75: limit=1.8629e+10, outliers=5
    -P 100: limit=2.1290e+10, outliers=2
    -P 150: limit=2.6613e+10, outliers=1
    -P 200: limit=3.1935e+10, outliers=0
```

### Dry-run Output

```
$ ./scripts/rrdclean.py --dry-run -S 3 file.rrd
Total rows: 826, Total values: 826, Spikes removed: 8

Dry-run: No changes written.
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

### Combined Operations

```
$ ./scripts/rrdclean.py -S 3 --zero --fill-gaps file.rrd
Total rows: 826, Total values: 826, Spikes removed: 8
Gaps filled: 45
Zeros replaced: 12
Done: file.rrd
```

## How It Works

Operations are processed in this order:

1. **Dump** - RRD file is dumped to XML format using `rrdtool dump`
2. **Analyze** - Data is parsed and statistics are calculated per DS
3. **Spike Detection** - Values outside thresholds are identified
4. **Spike Replacement** - Spikes are replaced with -A method
5. **Gap Filling** - [EXPERIMENTAL] NaN gaps are filled with linear interpolation
6. **Zero Replacement** - Zero values are replaced with -A method
7. **Restore** - XML is converted back to RRD using `rrdtool restore`

**Note:** Each operation modifies the data. Use `--dry-run` to see combined results.

### Processing Order

When combining multiple operations, they are processed in order:

```
1. Spike removal    → outliers become -A method
2. Gap filling       → NaN gaps between valid values are filled
3. Zero replacement  → 0 values are replaced with -A method
```

Example - what happens with `--fill-gaps --zero`:

```bash
./scripts/rrdclean.py --fill-gaps --zero file.rrd
```

| Step | Data | Notes |
|------|------|-------|
| Start | `1.0 → 0 → NaN → NaN → 5.0 → 0` | |
| After fill-gaps | `1.0 → 0 → 1.67 → 3.33 → 5.0 → 0` | Gap filled (linear) |
| After zero | `1.0 → 2.5 → 1.67 → 3.33 → 5.0 → 2.5` | Zeros replaced with avg |

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

### Gap Filling

!!! info "How it works"
    Gaps (sequences of NaN values) are filled using linear interpolation only if:
    - Gap has valid values on both sides
    - Gap size is within --max-gap limit (default: 20)

    ```
    Before: 1.0 → NaN → NaN → 4.0
    After:  1.0 → 2.0 → 3.0 → 4.0
    ```

## Troubleshooting

### No spikes detected

!!! info "Why no spikes detected"
    Your data might have extreme outliers affecting statistics. The mean and standard deviation are calculated from ALL values, so outliers can make thresholds too high.

    Try:
```bash
# Use histogram to analyze data
./scripts/rrdclean.py --histogram file.rrd

# Try variance method (ignores outliers in calculation)
./scripts/rrdclean.py -M variance -P 2 file.rrd

# Use lower stddev threshold
./scripts/rrdclean.py -S 2 file.rrd
```

### All values become NaN

You may have set thresholds too low. Check with `--dry-run` first:

```bash
./scripts/rrdclean.py --dry-run -S 1 file.rrd
```

## See Also

- [RRDTool Documentation](https://oss.oetiker.ch/rrdtool/)
- LibreNMS Data Collection: `doc/General/01-General.md`
