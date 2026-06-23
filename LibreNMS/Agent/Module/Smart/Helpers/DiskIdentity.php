<?php

namespace LibreNMS\Agent\Module\Smart\Helpers;

/**
 * Disk identity formatting shared between the SMART poller/discovery side
 * ({@see \LibreNMS\Agent\Module\Smart\Common}) and the SMART HTML view layer
 * ({@see \LibreNMS\Agent\Unix\Smart\HtmlData}).
 *
 * Both sides must derive the exact same sensor/RRD index and the exact same
 * display label from a disk's identity columns, or sensor lookups and RRD
 * filenames silently stop matching between discovery/polling and the UI.
 * Keeping the logic in one place is what guarantees that.
 */
final class DiskIdentity
{
    /** Sanitized, stable sensor/RRD index from a disk key (max 80 chars, safe chars). */
    public static function index(string $diskKey): string
    {
        return substr((string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $diskKey), 0, 80);
    }

    /**
     * Human-readable label: "Model Serial (name)", with graceful fallbacks
     * when model/serial/name are missing. Returns $fallback when nothing
     * usable is present.
     *
     * @param  array<string, mixed>  $disk  must carry model_name, serial_number, device_name
     */
    public static function label(array $disk, string $fallback = ''): string
    {
        $model = trim((string) ($disk['model_name'] ?? ''));
        $serial = trim((string) ($disk['serial_number'] ?? ''));
        $name = trim((string) ($disk['device_name'] ?? ''));

        $label = trim(implode(' ', array_filter([$model, $serial])));
        if ($name !== '') {
            $label = $label !== '' ? "{$label} ({$name})" : $name;
        }

        return $label !== '' ? $label : $fallback;
    }
}
