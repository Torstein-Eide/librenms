<?php

/**
 * Build lookup tables for sensor group heading suppression and navigation.
 *
 * Returns [$groupCounts, $groupHasChildren, $groupNavigation]:
 *   $groupCounts      — exact group string → sensor count
 *   $groupHasChildren — group string → true when a deeper sub-path exists
 *   $groupNavigation  — exact group string → sensor_navigation URL path (first non-null wins)
 *
 * Group paths use '::' as a level separator, e.g. 'volum1::/dev/sdc'.
 */
function buildSensorGroupData(iterable $sensors): array
{
    $groupCounts = [];
    $groupHasChildren = [];
    $groupNavigation = [];
    foreach ($sensors as $sensor) {
        $g = $sensor->group ?? '';
        $groupCounts[$g] = ($groupCounts[$g] ?? 0) + 1;
        if (! isset($groupNavigation[$g]) && ! empty($sensor->sensor_navigation)) {
            $groupNavigation[$g] = $sensor->sensor_navigation;
        }
        $parts = $g !== '' ? explode('::', $g) : [];
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $ancestor = implode('::', array_slice($parts, 0, $i + 1));
            $groupHasChildren[$ancestor] = true;
        }
    }

    return [$groupCounts, $groupHasChildren, $groupNavigation];
}

/**
 * Return true when a group heading should be suppressed:
 *   - exactly one sensor has this exact group path, and
 *   - no sensor is in a deeper sub-group (no children).
 * Empty paths are always suppressed.
 */
function isSensorGroupSuppressed(string $groupPath, array $groupCounts, array $groupHasChildren): bool
{
    if ($groupPath === '') {
        return true;
    }

    return ($groupCounts[$groupPath] ?? 0) === 1 && empty($groupHasChildren[$groupPath]);
}

/**
 * Strip a repeated group prefix from $descr when the group heading is visible.
 *
 * Candidates tried in order:
 *   1) full group path with '::' replaced by spaces (e.g. 'A::B' → 'A B')
 *   2) first group segment
 *   3) last group segment (when different from the first)
 *
 * Stripping is skipped when $groupStr is empty, the group is suppressed,
 * or stripping would leave an empty string.
 */
function stripSensorDescrGroupPrefix(string $descr, string $groupStr, array $parts, array $groupCounts, array $groupHasChildren): string
{
    if ($groupStr === '' || isSensorGroupSuppressed($groupStr, $groupCounts, $groupHasChildren)) {
        return $descr;
    }

    $candidates = [];
    $normalizedGroup = trim(str_replace('::', ' ', $groupStr));
    if ($normalizedGroup !== '') {
        $candidates[] = $normalizedGroup;
    }
    $firstSegment = $parts[0] ?? '';
    if ($firstSegment !== '') {
        $candidates[] = $firstSegment;
    }
    $lastSegment = count($parts) > 0 ? $parts[count($parts) - 1] : '';
    if ($lastSegment !== '' && $lastSegment !== $firstSegment) {
        $candidates[] = $lastSegment;
    }

    foreach (array_unique($candidates) as $candidate) {
        if (stripos($descr, $candidate) === 0) {
            $stripped = ltrim(substr($descr, strlen($candidate)), " \t-_:");
            if ($stripped !== '') {
                return $stripped;
            }
        }
    }

    return $descr;
}

/**
 * Count the number of visible group heading levels for a sensor path.
 * Used to compute indentation for sensor rows: each visible level adds 16 px.
 */
function sensorGroupVisibleDepth(array $parts, array $groupCounts, array $groupHasChildren): int
{
    $depth = 0;
    for ($d = 0; $d < count($parts); $d++) {
        $pathToHere = implode('::', array_slice($parts, 0, $d + 1));
        if (! isSensorGroupSuppressed($pathToHere, $groupCounts, $groupHasChildren)) {
            $depth++;
        }
    }

    return $depth;
}
