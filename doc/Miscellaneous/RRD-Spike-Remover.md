# RRD Spike Remover

Tool to remove spikes/anomalies from RRD files and optionally interpolate gaps.

**Location:** `scripts/rrdclean.py`

**IMPORTANT:** Always make a backup before making changes!

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

| Option | Description | Default |
|--------|-------------|---------|
| `-o, --output-rrd FILE` | Output RRD file | Overwrite input |
| `-M, --method METHOD` | Spike removal method | stddev |
| `-A, --avgnan METHOD` | Replacement method | avg |
| `-S, --stddev NUM` | Standard deviations allowed | 10 |
| `-P, --percent NUM` | Percentage variation | 5 |
| `-v, --verbose` | Show verbose output | - |
| `-n, --dry-run` | Don't write changes | - |
| `--histogram` | Show data histogram | - |
| `--preview` | Preview what would be removed | - |
| `--interpolate` | [EXPERIMENTAL] Fill gaps | - |
| `--max-gap NUM` | Max gap size to interpolate | 20 |
| `--zero METHOD` | Replace zero values | - |

### Methods

#### Spike Removal Methods (-M)

- `stddev` - Values beyond mean +/- (S * stddev) are spikes
- `variance` - Values above variance_avg * (1 + P) are spikes

#### Replacement Methods (-A)

- `avg` - Replace spike with average value
- `nan` - Replace spike with NaN

#### Zero Replacement Methods (--zero)

- `avg` - Replace with average value
- `nan` - Replace with NaN
- `prev` - Replace with previous valid value

## Examples

### Basic Spike Removal

```bash
# Remove spikes using default settings (stddev, -S 10, -A avg)
./scripts/rrdclean.py router_traffic.rrd

# Use 3 standard deviations
./scripts/rrdclean.py -S 3 file.rrd

# Use variance method with 5% threshold
./scripts/rrdclean.py -M variance -P 5 file.rrd
```

### Preview and Analyze

```bash
# Show histogram with suggested parameters
./scripts/rrdclean.py --histogram file.rrd

# Preview what would be removed (dry-run)
./scripts/rrdclean.py --preview file.rrd

# Dry-run (don't write changes)
./scripts/rrdclean.py --dry-run -S 3 file.rrd
```

### Output to New File

```bash
# Write to new file instead of overwriting
./scripts/rrdclean.py -o cleaned.rrd input.rrd
```

### Zero Handling

```bash
# Replace zeros with average
./scripts/rrdclean.py --zero avg file.rrd

# Replace zeros with NaN
./scripts/rrdclean.py --zero nan file.rrd

# Replace zeros with previous value
./scripts/rrdclean.py --zero prev file.rrd
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

1. **Dump** - RRD file is dumped to XML format using `rrdtool dump`
2. **Analyze** - Data is parsed and statistics are calculated per DS
3. **Detect** - Values outside thresholds are identified as spikes
4. **Clean** - Spikes are replaced with average or NaN
5. **Restore** - XML is converted back to RRD using `rrdtool restore`

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
