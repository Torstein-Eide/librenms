#!/usr/bin/env python3
"""
RRD Spike Remover - Remove spikes/anomalies from RRD files and interpolate gaps.

This script dumps an RRD file to XML, analyzes the data to identify spikes
(outliers beyond statistical thresholds), and restores the cleaned data.

IMPORTANT: Always make a backup before making changes!

Features:
- Remove spikes using stddev or variance methods (-S, -M, -P)
- Interpolate gaps (NaN values) between valid values (--fill-gaps) [EXPERIMENTAL]
- Replace zero values (--zero)
- List available data sources (--list-ds)
- Restrict operations to specific data sources (--ds)
- Preview changes before applying (--dry-run)
- Generate histograms with suggested parameters (--histogram)

Note: Operations must be explicitly requested. Running without options shows help.

Usage:
    ./scripts/rrdclean.py --histogram file.rrd    # Analyze data
    ./scripts/rrdclean.py -S 3 file.rrd          # Remove spikes
    ./scripts/rrdclean.py --fill-gaps file.rrd # Interpolate gaps
    ./scripts/rrdclean.py --zero avg file.rrd    # Replace zeros
    cp file.rrd file.rrd.bak && ./scripts/rrdclean.py file.rrd [options]  # With backup
"""

import argparse
import math
import re
import statistics
import subprocess
import sys
import tempfile
from pathlib import Path


def run(cmd, *, stdout=None, verbose=False):
    """
    Execute a shell command and handle errors.

    Args:
        cmd: Command as list of strings
        stdout: File handle for stdout redirection
        verbose: If True, print the command before executing
    """
    if verbose:
        print(f"$ {' '.join(cmd)}")
    try:
        return subprocess.run(cmd, check=True, stdout=stdout)
    except FileNotFoundError:
        print(f"Command not found: {cmd[0]}", file=sys.stderr)
        sys.exit(2)
    except subprocess.CalledProcessError as e:
        print(f"Command failed: {' '.join(cmd)}", file=sys.stderr)
        sys.exit(e.returncode)


def parse_xml_values(data: str) -> list[list[float]]:
    """
    Parse RRD XML dump and extract all data values.

    Args:
        data: XML content as string

    Returns:
        List of rows, where each row is a list of float values (one per DS)
    """
    rows = []
    for line in data.split("\n"):
        if "<v>" in line:
            values = []
            for match in re.finditer(r"<v>\s*([^\s<]+)\s*</v>", line):
                val_str = match.group(1)
                if val_str.lower() == "nan":
                    values.append(float("nan"))
                else:
                    try:
                        values.append(float(val_str))
                    except ValueError:
                        values.append(float("nan"))
            if values:
                rows.append(values)
    return rows


def extract_ds_names(data: str, num_ds: int) -> list[str]:
    """Extract DS names from RRD XML content and align count to num_ds."""
    ds_names = []
    for line in data.split("\n"):
        if "<name>" in line:
            match = re.search(r"<name>([^<]+)</name>", line)
            if match:
                ds_names.append(match.group(1))

    while len(ds_names) < num_ds:
        ds_names.append(f"ds{len(ds_names)}")

    return ds_names[:num_ds]


def parse_ds_selection(ds_selector: str, ds_names: list[str], num_ds: int) -> set[int]:
    """Parse comma-separated DS selection by index or exact name."""
    selected = set()

    for raw_item in ds_selector.split(","):
        item = raw_item.strip()
        if not item:
            continue

        if item.isdigit():
            index = int(item)
            if index < 0 or index >= num_ds:
                raise ValueError(f"DS index out of range: {index}")
            selected.add(index)
            continue

        matches = [i for i, name in enumerate(ds_names[:num_ds]) if name == item]
        if not matches:
            raise ValueError(f"Unknown DS name: {item}")
        selected.update(matches)

    if not selected:
        raise ValueError("No DS selected")

    return selected


def calculate_stddev(values: list[float]) -> float:
    """
    Calculate standard deviation of a list of values.

    Args:
        values: List of numeric values

    Returns:
        Standard deviation, or 0.0 if less than 2 values
    """
    valid = [v for v in values if not math.isnan(v)]
    if len(valid) < 2:
        return 0.0
    return statistics.stdev(valid)


def calculate_variance_avg(values: list[float], outliers: int) -> float:
    """
    Calculate average after removing outliers from both ends.

    This is useful for spike detection because it calculates a "typical" value
    that ignores extreme values at both ends of the distribution.

    Args:
        values: List of numeric values
        outliers: Number of values to remove from each end

    Returns:
        Mean of trimmed values, or NaN if not enough data
    """
    valid = sorted([v for v in values if not math.isnan(v)])
    if len(valid) < outliers * 3:
        return float("nan")
    valid = valid[outliers:-outliers] if outliers < len(valid) else valid
    return statistics.mean(valid) if valid else float("nan")


def print_histogram(
    values: list[float], num_buckets: int = 20, indent: str = "  "
) -> None:
    """
    Print a text-based histogram of the data distribution.

    Args:
        values: List of numeric values
        num_buckets: Number of histogram buckets
        indent: String to prepend to each line (for formatting)
    """
    valid = [v for v in values if not math.isnan(v)]
    if not valid:
        print(f"{indent}No valid values")
        return

    min_val = min(valid)
    max_val = max(valid)
    bucket_size = (max_val - min_val) / num_buckets if max_val > min_val else 1
    buckets = [0] * num_buckets

    # Count values in each bucket
    for v in valid:
        idx = min(int((v - min_val) / bucket_size), num_buckets - 1)
        buckets[idx] += 1

    max_count = max(buckets)
    bar_width = 50

    print(f"{indent}Histogram ({num_buckets} buckets, size={bucket_size:.2e}):")
    print(f"{indent}Range: {min_val:.2e} - {max_val:.2e}")
    for i, count in enumerate(buckets):
        bucket_start = min_val + i * bucket_size
        bucket_end = bucket_start + bucket_size
        bar_len = int((count / max_count) * bar_width) if max_count > 0 else 0
        bar = "█" * bar_len
        print(f"{indent}{bucket_start:12.2e} - {bucket_end:12.2e} | {count:5} {bar}")


def get_data_stats(input_path: Path) -> tuple[list[list[float]], list[dict], list[str]]:
    """
    Extract data statistics from an RRD XML dump.

    Calculates mean, standard deviation, and variance-based averages
    for each data source (DS) in the RRD file.

    Args:
        input_path: Path to the XML dump file

    Returns:
        Tuple of (all_samples, rra_data, ds_names)
        - all_samples: List of sample lists, one per DS
        - rra_data: List of dicts with statistics per DS
        - ds_names: List of DS names from the XML
    """
    data = input_path.read_text(encoding="utf-8", errors="replace")
    rows = parse_xml_values(data)

    if not rows:
        return [], [], []

    num_ds = len(rows[0])
    all_samples = [[] for _ in range(num_ds)]
    ds_names = extract_ds_names(data, num_ds)

    # Collect all samples per DS
    for row in rows:
        for ds in range(num_ds):
            if not math.isnan(row[ds]):
                all_samples[ds].append(row[ds])

    # Calculate statistics per DS
    rra_data = []
    for ds in range(num_ds):
        samples = all_samples[ds]
        if len(samples) < 2:
            rra_data.append(
                {
                    "average": statistics.mean(samples) if samples else float("nan"),
                    "stddev": 0.0,
                    "variance_avg": float("nan"),
                    "max_cutoff": float("nan"),
                    "min_cutoff": float("nan"),
                }
            )
        else:
            avg = statistics.mean(samples)
            std = statistics.stdev(samples) if len(samples) > 1 else 0.0
            var_avg = calculate_variance_avg(samples, 5)
            rra_data.append(
                {
                    "average": avg,
                    "stddev": std,
                    "variance_avg": var_avg,
                    "max_cutoff": avg + (10 * std),
                    "min_cutoff": avg - (10 * std),
                }
            )

    return all_samples, rra_data, ds_names


def replace_zeros(
    data: str, method: str, selected_ds: set[int] | None = None
) -> tuple[str, int]:
    """
    Replace zero values based on the specified method.

    Args:
        data: XML content as string
        method: 'avg' (replace with average), 'nan' (set to NaN), 'prev' (use previous value)

    Returns:
        Tuple of (modified XML, count of replaced zeros)
    """
    # First pass: collect samples and calculate averages per DS
    rows = parse_xml_values(data)
    if not rows:
        return data, 0

    num_ds = len(rows[0])
    all_samples = [[] for _ in range(num_ds)]

    for row in rows:
        for ds in range(num_ds):
            if not math.isnan(row[ds]):
                all_samples[ds].append(row[ds])

    # Calculate average per DS
    ds_avg = []
    for ds in range(num_ds):
        samples = [v for v in all_samples[ds] if v != 0]
        if samples:
            ds_avg.append(statistics.mean(samples))
        else:
            ds_avg.append(0)

    # Second pass: replace zeros
    lines = data.split("\n")
    new_lines = []
    replaced_count = 0
    prev_values = [None] * num_ds

    for line in lines:
        if "<v>" not in line:
            new_lines.append(line)
            continue

        # Parse all values
        parsed_values = []
        for match in re.finditer(r"<v>\s*([^\s<]+)\s*</v>", line):
            val_str = match.group(1)
            if val_str.lower() == "nan":
                parsed_values.append((val_str, float("nan")))
            else:
                try:
                    parsed_values.append((val_str, float(val_str)))
                except ValueError:
                    parsed_values.append((val_str, float("nan")))

        new_values = []
        for ds, (orig, val) in enumerate(parsed_values):
            if selected_ds is not None and ds not in selected_ds:
                new_values.append(orig)
                if not math.isnan(val):
                    prev_values[ds] = val
                continue

            if val == 0:
                replaced_count += 1
                if method == "nan":
                    new_values.append("NaN")
                elif method == "avg":
                    new_values.append(str(ds_avg[ds]) if ds_avg[ds] != 0 else "NaN")
                elif method == "prev":
                    if prev_values[ds] is not None:
                        new_values.append(str(prev_values[ds]))
                    else:
                        new_values.append("NaN")
            else:
                new_values.append(orig)
                if not math.isnan(val):
                    prev_values[ds] = val

        # Rebuild line
        for val in new_values:
            line = re.sub(r"<v>\s*[^\s<]+\s*</v>", f"<v> {val} </v>", line, count=1)

        new_lines.append(line)

    return "\n".join(new_lines), replaced_count


def interpolate_gaps(
    data: str, max_gap: int = 0, selected_ds: set[int] | None = None
) -> tuple[str, int]:
    """
    Fill gaps (NaN values) between non-NaN values using linear interpolation.

    Works across rows (timestamps), interpolating NaN values in each DS column
    based on the surrounding valid values. Only interpolates gaps that have
    valid values on BOTH sides (excludes start and end of data).

    Args:
        data: XML content as string
        max_gap: Maximum gap size to interpolate (0 = unlimited)

    Returns:
        XML content with interpolated values
    """
    # First pass: collect all rows and their values per DS
    rows = parse_xml_values(data)
    if not rows:
        return data, 0

    num_ds = len(rows[0])

    # Build value matrix: matrix[ds][row_idx] = value
    matrix = []
    for ds in range(num_ds):
        matrix.append([])
        for row in rows:
            if ds < len(row):
                matrix[ds].append(row[ds])
            else:
                matrix[ds].append(float("nan"))

    # Interpolate gaps for each DS
    total_interpolated = 0
    for ds in range(num_ds):
        if selected_ds is not None and ds not in selected_ds:
            continue

        values = matrix[ds]

        # Skip if entire DS is empty
        if all(math.isnan(v) for v in values):
            continue

        # Find and interpolate all gaps (only between valid values)
        i = 0
        while i < len(values):
            if not math.isnan(values[i]):
                i += 1
                continue

            # Found start of a gap
            start = i

            # Find end of gap
            while i < len(values) and math.isnan(values[i]):
                i += 1
            end = i - 1  # Last NaN index

            gap_size = end - start + 1

            # Check if gap should be interpolated
            if start > 0 and end < len(values) - 1:  # Has valid values on both sides
                if max_gap == 0 or gap_size <= max_gap:  # Within max gap limit
                    prev_val = values[start - 1]
                    next_val = values[end + 1]

                    step = (next_val - prev_val) / (gap_size + 1)
                    for j in range(start, end + 1):
                        values[j] = prev_val + step * (j - start + 1)
                    total_interpolated += gap_size

    # Rebuild XML with interpolated values
    new_lines = []
    row_idx = 0

    for line in data.split("\n"):
        if "<v>" not in line:
            new_lines.append(line)
            continue

        # Get interpolated values for this row
        row_values = [
            matrix[ds][row_idx] if ds < len(matrix) else float("nan")
            for ds in range(num_ds)
        ]

        # Rebuild line with interpolated values
        new_line = line
        for val in row_values:
            if math.isnan(val):
                new_line = re.sub(
                    r"<v>\s*[^\s<]+\s*</v>", "<v> NaN </v>", new_line, count=1
                )
            else:
                new_line = re.sub(
                    r"<v>\s*[^\s<]+\s*</v>", f"<v> {val} </v>", new_line, count=1
                )

        new_lines.append(new_line)
        row_idx += 1

    return "\n".join(new_lines), total_interpolated


def clean_spikes(
    input_path: Path,
    output_path: Path,
    method: str,
    avgnan: str,
    stddev_val: float,
    percent: float,
    verbose: bool,
    selected_ds: set[int] | None = None,
) -> tuple[int, int, int]:
    """
    Remove spikes from RRD data based on statistical thresholds.

    Two detection methods:
    - stddev: Values beyond mean +/- (S * stddev) are spikes
    - variance: Values above variance_avg * (1 + P) are spikes

    Args:
        input_path: Source XML file
        output_path: Destination XML file (cleaned)
        method: 'stddev' or 'variance'
        avgnan: 'avg' to replace with mean, 'nan' to set to NaN
        stddev_val: Number of standard deviations for threshold
        percent: Percentage for variance method (as decimal, e.g., 0.05 for 5%)
        verbose: Print details of each removal

    Returns:
        Tuple of (spikes_removed, total_rows, total_values)
    """
    data = input_path.read_text(encoding="utf-8", errors="replace")
    rows = parse_xml_values(data)

    if not rows:
        output_path.write_text(data, encoding="utf-8")
        return 0, 0, 0

    num_ds = len(rows[0])
    all_samples = [[] for _ in range(num_ds)]

    # Collect samples for statistics calculation
    for row in rows:
        for ds in range(num_ds):
            if not math.isnan(row[ds]):
                all_samples[ds].append(row[ds])

    # Calculate thresholds per DS
    rra_data = []
    for ds in range(num_ds):
        samples = all_samples[ds]
        if len(samples) < 2:
            rra_data.append(
                {
                    "average": statistics.mean(samples) if samples else float("nan"),
                    "stddev": 0.0,
                    "variance_avg": float("nan"),
                    "max_cutoff": float("nan"),
                    "min_cutoff": float("nan"),
                }
            )
        else:
            avg = statistics.mean(samples)
            std = statistics.stdev(samples) if len(samples) > 1 else 0.0
            var_avg = calculate_variance_avg(samples, 5)
            rra_data.append(
                {
                    "average": avg,
                    "stddev": std,
                    "variance_avg": var_avg,
                    "max_cutoff": avg + (stddev_val * std),
                    "min_cutoff": avg - (stddev_val * std),
                }
            )

    total_kills = 0
    lines = data.split("\n")
    new_lines = []

    # Process each line and identify/replace spikes
    for line in lines:
        if "<v>" in line:
            values = []
            for match in re.finditer(r"<v>\s*([^\s<]+)\s*</v>", line):
                val_str = match.group(1)
                if val_str.lower() == "nan":
                    values.append(("nan", float("nan")))
                else:
                    try:
                        values.append((val_str, float(val_str)))
                    except ValueError:
                        values.append((val_str, float("nan")))

            new_values = []
            for ds, (orig, val) in enumerate(values):
                if math.isnan(val) or ds >= len(rra_data):
                    new_values.append(orig)
                    continue

                if selected_ds is not None and ds not in selected_ds:
                    new_values.append(orig)
                    continue

                rra = rra_data[ds]
                kill = False

                # Check if value is a spike based on method
                if method == "variance":
                    if not math.isnan(rra["variance_avg"]) and val > rra[
                        "variance_avg"
                    ] * (1 + percent):
                        kill = True
                        if verbose:
                            print(
                                f"Var Kill: Value {val:.4e}, VarianceAvg {rra['variance_avg']:.4e}, Limit {rra['variance_avg'] * (1 + percent):.4e}"
                            )
                else:
                    if val > rra["max_cutoff"] or val < rra["min_cutoff"]:
                        kill = True
                        if verbose:
                            print(
                                f"Std Kill: Value {val:.4e}, StdDev {rra['stddev']:.4e}, MaxCutoff {rra['max_cutoff']:.4e}"
                            )

                # Replace spike with specified value
                if kill:
                    if avgnan == "avg":
                        new_values.append(str(rra["average"]))
                    else:
                        new_values.append("NaN")
                    total_kills += 1
                else:
                    new_values.append(orig)

            # Rebuild the line with replaced values
            line = re.sub(r"<v>\s*[^\s<]+\s*</v>", "<v> NaN </v>", line)
            for i, val in enumerate(new_values):
                line = re.sub(r"<v>\s*NaN\s*</v>", f"<v> {val} </v>", line, count=1)

        new_lines.append(line)

    output_path.write_text("\n".join(new_lines), encoding="utf-8")
    return total_kills, len(rows), len(rows) * num_ds


def main():
    """Main entry point - parse arguments and orchestrate the cleaning process."""
    parser = argparse.ArgumentParser(
        description="Dump an RRD, remove spikes, and restore it."
    )
    parser.add_argument("input_rrd", help="Input .rrd file")
    parser.add_argument(
        "-o",
        "--output-rrd",
        help="Output .rrd file (default: overwrite input)",
    )
    parser.add_argument(
        "-M",
        "--method",
        choices=["stddev", "variance"],
        default=None,
        help="Spike removal method (stddev or variance)",
    )
    parser.add_argument(
        "-A",
        "--avgnan",
        choices=["avg", "nan", "prev"],
        default=None,
        help="Replacement method for spikes, zeros, and gaps (avg, nan, or prev)",
    )
    parser.add_argument(
        "-S",
        "--stddev",
        type=float,
        default=None,
        help="Number of standard deviations allowed",
    )
    parser.add_argument(
        "-P",
        "--percent",
        type=float,
        default=None,
        help="Percentage variation for variance method",
    )
    parser.add_argument(
        "-v", "--verbose", action="store_true", help="Show verbose output"
    )
    parser.add_argument("--histogram", action="store_true", help="Show data histogram")
    parser.add_argument(
        "--suggested-S",
        type=str,
        default="1,2,3,5,10",
        help="Comma-separated list of -S values to suggest (default: 1,2,3,5,10)",
    )
    parser.add_argument(
        "--suggested-P",
        type=str,
        default="75,100,150,200",
        help="Comma-separated list of -P values to suggest (default: 75,100,150,200)",
    )
    parser.add_argument(
        "-n",
        "--dry-run",
        action="store_true",
        help="Don't write changes, just show what would be done (runs all operations)",
    )
    parser.add_argument(
        "--fill-gaps",
        action="store_true",
        help="[EXPERIMENTAL] Fill gaps between non-NaN values (uses -A method)",
    )
    parser.add_argument(
        "--max-gap",
        type=int,
        default=20,
        help="Maximum gap size to fill (default: 20, ~100min at 5min intervals)",
    )
    parser.add_argument(
        "--zero",
        action="store_true",
        help="Replace zero values (uses -A method)",
    )
    parser.add_argument(
        "--list-ds",
        action="store_true",
        help="List available data sources and exit",
    )
    parser.add_argument(
        "--ds",
        type=str,
        default=None,
        help="Comma-separated DS indexes or names to target (e.g. '0,ifInOctets')",
    )
    args = parser.parse_args()

    verbose = args.verbose
    input_rrd = Path(args.input_rrd)
    if not input_rrd.is_file():
        print(f"Input file not found: {input_rrd}", file=sys.stderr)
        sys.exit(1)

    output_rrd = Path(args.output_rrd) if args.output_rrd else input_rrd

    with tempfile.TemporaryDirectory(prefix="rrdclean_") as tmpdir:
        tmp = Path(tmpdir)
        dump_xml = tmp / "dump.xml"
        clean_xml_file = tmp / "cleaned.xml"
        restored_rrd = tmp / "restored.rrd"

        # Dump RRD to XML format
        if verbose:
            print(f"Dumping {input_rrd} -> {dump_xml}")
        with dump_xml.open("wb") as f:
            run(["rrdtool", "dump", str(input_rrd)], stdout=f, verbose=verbose)

        all_samples, rra_data, ds_names = get_data_stats(dump_xml)
        num_ds = len(all_samples)

        if args.list_ds:
            if num_ds == 0:
                print("No DS found in RRD data.")
            else:
                print("Available DS:")
                for ds in range(num_ds):
                    print(f"  [{ds}] {ds_names[ds]}")
            return

        selected_ds = None
        if args.ds is not None:
            try:
                selected_ds = parse_ds_selection(args.ds, ds_names, num_ds)
            except ValueError as e:
                print(f"Invalid --ds value: {e}", file=sys.stderr)
                if num_ds > 0:
                    print("Available DS:", file=sys.stderr)
                    for ds in range(num_ds):
                        print(f"  [{ds}] {ds_names[ds]}", file=sys.stderr)
                sys.exit(1)

            selected_ds_list = ", ".join(
                f"{idx}:{ds_names[idx]}" for idx in sorted(selected_ds)
            )
            print(f"Selected DS: {selected_ds_list}")

        # Histogram mode: analyze data and suggest parameters
        if args.histogram:
            print("\n=== Data Histogram ===")
            ds_to_show = (
                sorted(selected_ds)
                if selected_ds is not None
                else range(len(all_samples))
            )
            for ds in ds_to_show:
                samples = all_samples[ds]
                avg = rra_data[ds]["average"]
                std = rra_data[ds]["stddev"]
                var_avg = rra_data[ds]["variance_avg"]
                ds_name = ds_names[ds] if ds < len(ds_names) else f"DS{ds}"

                print(f"\n[{ds_name}] (index {ds}):")
                print(f"  Count: {len(samples)}")
                if len(samples) == 0:
                    print(f"  (No valid data)")
                    continue
                print(f"  Mean:  {avg:.4e}")
                print(f"  StdDev: {std:.4e}")
                print(f"  Min:   {min(samples):.4e}")
                print(f"  Max:   {max(samples):.4e}")
                print(f"  VarianceAvg (no outliers): {var_avg:.4e}")
                print_histogram(samples)

                # Parse comma-separated suggested values
                suggested_s = [float(x.strip()) for x in args.suggested_S.split(",")]
                suggested_p = [float(x.strip()) for x in args.suggested_P.split(",")]

                # Suggest -S values based on current data
                print(f"\n  Suggested -S (stddev) values:")
                for s in suggested_s:
                    max_cut = avg + (s * std)
                    min_cut = avg - (s * std)
                    outliers_above = sum(1 for v in samples if v > max_cut)
                    outliers_below = sum(1 for v in samples if v < min_cut)
                    print(
                        f"    -S {s}: max_cutoff={max_cut:.4e}, min_cutoff={min_cut:.4e}, outliers={outliers_above + outliers_below}"
                    )

                # Suggest -P values for variance method
                if not math.isnan(var_avg):
                    print(f"\n  Suggested -P (percent) values for variance method:")
                    for p in suggested_p:
                        limit = var_avg * (1 + p / 100)
                        outliers = sum(1 for v in samples if v > limit)
                        print(f"    -P {p}: limit={limit:.4e}, outliers={outliers}")
            print("\nHistogram mode: No changes made (dry-run)")
            return

        # Check if any operations are requested
        run_spike_removal = (
            args.method is not None
            or args.stddev is not None
            or args.percent is not None
        )
        run_interpolate = args.fill_gaps
        run_zero = args.zero

        if not run_spike_removal and not run_interpolate and not run_zero:
            print(
                "No operations specified. Use --histogram, -S, --fill-gaps, or --zero"
            )
            return

        total_rows = 0
        total_values = 0
        kills = 0

        # Clean spikes only if spike parameters specified
        if run_spike_removal:
            # Use defaults if not specified
            method = args.method if args.method else "stddev"
            stddev_val = args.stddev if args.stddev else 10
            percent_val = (args.percent if args.percent else 5) / 100
            avgnan = args.avgnan if args.avgnan else "avg"

            kills, total_rows, total_values = clean_spikes(
                dump_xml,
                clean_xml_file,
                method,
                avgnan,
                stddev_val,
                percent_val,
                verbose,
                selected_ds,
            )

            print(
                f"Total rows: {total_rows}, Total values: {total_values}, Spikes removed: {kills}"
            )
        else:
            # Copy dump to clean file for other operations
            with open(dump_xml, "r") as f:
                dump_content = f.read()
            with open(clean_xml_file, "w") as f:
                f.write(dump_content)

        # Fill gaps if requested
        gaps_filled = 0
        if run_interpolate:
            if verbose:
                print(f"Filling gaps in {clean_xml_file}")
            with open(clean_xml_file, "r") as f:
                original_data = f.read()

            interpolated_data, gaps_filled = interpolate_gaps(
                original_data,
                args.max_gap,
                selected_ds,
            )

            if not args.dry_run:
                with open(clean_xml_file, "w") as f:
                    f.write(interpolated_data)

            print(f"Gaps filled: {gaps_filled}")
            if gaps_filled == 0:
                print("No gaps to fill.")

        # Replace zeros if requested (uses -A method)
        zeros_replaced = 0
        if run_zero:
            zero_method = args.avgnan if args.avgnan else "avg"
            if verbose:
                print(f"Replacing zeros with {zero_method} in {clean_xml_file}")
            with open(clean_xml_file, "r") as f:
                original_data = f.read()
            modified_data, zeros_replaced = replace_zeros(
                original_data,
                zero_method,
                selected_ds,
            )

            if not args.dry_run:
                with open(clean_xml_file, "w") as f:
                    f.write(modified_data)

            print(f"Zeros replaced: {zeros_replaced}")
            if zeros_replaced == 0:
                print("No zeros found.")

        if kills == 0 and gaps_filled == 0 and zeros_replaced == 0:
            print("No changes to make.")
            return

        # Dry-run: don't write changes
        if args.dry_run:
            print("\nDry-run: No changes written.")
            return

        # Restore the cleaned XML back to RRD format
        if output_rrd.resolve() == input_rrd.resolve():
            if verbose:
                print(f"Restoring to temp file -> {restored_rrd}")
            run(
                ["rrdtool", "restore", str(clean_xml_file), str(restored_rrd)],
                verbose=verbose,
            )
            restored_rrd.replace(input_rrd)
            print(f"Done: {input_rrd}")
        else:
            if verbose:
                print(f"Restoring {clean_xml_file} -> {output_rrd}")
            run(
                ["rrdtool", "restore", str(clean_xml_file), str(output_rrd)],
                verbose=verbose,
            )
            print(f"Done: {output_rrd}")


if __name__ == "__main__":
    main()
