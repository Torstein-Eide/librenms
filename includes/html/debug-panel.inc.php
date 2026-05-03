<?php

use Illuminate\Support\Facades\Auth;

/**
 * Shared debug-panel helpers for app/device debug views.
 *
 * Functions:
 *   debug_render(string $collapseId, string ...$panels): void
 *     Admin-gated entry point: renders nothing for non-admins; wraps $panels
 *     in a collapse div for admins. Call this from any app debug view,
 *     passing pre-built panel HTML strings.
 *
 *   debug_collapsible(string $id, string $label, string ...$panels): void
 *     Renders the collapsible toggle button and wraps panels inside a collapse div.
 *
 *   debug_panel(string $title, string $body, string $toolbar = ''): string
 *     Returns one Bootstrap panel (heading + body) as an HTML string.
 *
 *   debug_toolbar(string $textId, string $filename, string $mimeType = 'application/json'): string
 *     Returns copy-to-clipboard + download buttons for a <pre>/<textarea> element
 *     whose id is $textId. The download href is built from the element content at
 *     click time via a small inline script.
 *
 *   debug_pre(string $id, string $content): string
 *     Returns a scrollable <pre> element with the given id and escaped content.
 *
 *   debug_csv_data_uri(array $headers, array $rows): string
 *     Encodes column headers and data rows as a text/csv data URI suitable for
 *     use as an href/download attribute. Each row must be a sequential array of
 *     string values parallel to $headers.
 *
 *   debug_rrd_last_point(string $rrdFile): ?object
 *     Returns the most recent data point from an RRD file as an object with
 *     'timestamp' (int) and 'data' (array<string, float|null>) properties.
 *     Falls back to a raw rrdtool shell call when Rrd::lastUpdate() returns null.
 *     Returns null when the output cannot be parsed.
 *
 *   debug_format_datastore_list(array $stores): string
 *     Returns a comma-separated, HTML-escaped list of datastore names, or 'none'.
 */

/**
 * Return the toggle button for a collapsible debug section (no wrapper div).
 * Place this inside an optionbar or navbar using a pull-right span.
 *
 * @param  string  $id     HTML id of the collapse target
 * @param  string  $label  Button label text
 */
function debug_toggle_button(string $id, string $label = 'Debug'): string
{
    return <<<HTML
        <a class="btn btn-xs btn-default" data-toggle="collapse" href="#{$id}"
           aria-expanded="false" aria-controls="{$id}">
            <i class="fa fa-bug"></i> {$label}
        </a>
        HTML;
}

/**
 * Render the collapse wrapper div that holds the debug panels.
 * Pair with debug_toggle_button() when the button lives elsewhere (e.g. navbar).
 *
 * @param  string   $id      Must match the id passed to debug_toggle_button()
 * @param  string   ...$panels  Rendered panel HTML strings
 */
function debug_collapse_div(string $id, string ...$panels): void
{
    $inner = implode("\n", $panels);
    echo <<<HTML
        <div id="{$id}" class="collapse">
            {$inner}
        </div>
        HTML;
}

/**
 * Convenience wrapper: renders button + collapse div together.
 * Use debug_toggle_button() + debug_collapse_div() separately when the
 * button needs to live in a navbar.
 *
 * @param  string   $id      HTML id for the collapse target (must be unique on page)
 * @param  string   $label   Button label text
 * @param  string   ...$panels  HTML strings — each already a complete panel block
 */
function debug_collapsible(string $id, string $label, string ...$panels): void
{
    $btn = debug_toggle_button($id, $label);
    $inner = implode("\n", $panels);
    echo <<<HTML
        <div class="text-right" style="margin-bottom:6px">{$btn}</div>
        <div id="{$id}" class="collapse">
            {$inner}
        </div>
        HTML;
}

/**
 * Return one Bootstrap panel as an HTML string.
 *
 * @param  string  $title    Panel heading text (not escaped — caller must escape if needed)
 * @param  string  $body     Panel body HTML
 * @param  string  $toolbar  Optional HTML prepended inside the body (e.g. copy/export buttons)
 */
function debug_panel(string $title, string $body, string $toolbar = ''): string
{
    $toolbarHtml = $toolbar !== '' ? "<div class=\"text-right\" style=\"margin-bottom:8px\">{$toolbar}</div>" : '';

    return <<<HTML
        <div class="panel panel-default">
            <div class="panel-heading"><h3 class="panel-title">{$title}</h3></div>
            <div class="panel-body">
                {$toolbarHtml}{$body}
            </div>
        </div>
        HTML;
}

/**
 * Return a scrollable <pre> element.
 *
 * @param  string  $id       HTML id (referenced by debug_toolbar)
 * @param  string  $content  Already HTML-escaped content
 */
function debug_pre(string $id, string $content): string
{
    return <<<HTML
        <pre id="{$id}" style="max-height:260px;overflow:auto">{$content}</pre>
        HTML;
}

/**
 * Return copy-to-clipboard + download buttons for the element identified by $textId.
 *
 * Copy reads textContent from the element at click time.
 * Download builds a Blob from the same text and triggers a save-as dialog.
 *
 * @param  string  $textId    id of the <pre> or element containing the text to copy/export
 * @param  string  $filename  Default filename for the download dialog
 * @param  string  $mimeType  MIME type for the download Blob (default application/json)
 */
function debug_toolbar(string $textId, string $filename, string $mimeType = 'application/json'): string
{
    $filenameEsc = htmlspecialchars($filename, ENT_QUOTES);
    $mimeTypeEsc = htmlspecialchars($mimeType, ENT_QUOTES);
    $textIdEsc = htmlspecialchars($textId, ENT_QUOTES);

    return <<<HTML
        <button type="button" class="btn btn-xs btn-default"
                onclick="(function(){
                    var t = document.getElementById('{$textIdEsc}');
                    var s = t ? t.textContent : '';
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(s);
                    } else {
                        var ta = document.createElement('textarea');
                        ta.value = s;
                        document.body.appendChild(ta);
                        ta.select();
                        document.execCommand('copy');
                        document.body.removeChild(ta);
                    }
                })()">
            <i class="fa fa-copy"></i> Copy
        </button>
        <button type="button" class="btn btn-xs btn-default"
                onclick="(function(){
                    var t = document.getElementById('{$textIdEsc}');
                    var s = t ? t.textContent : '';
                    var b = new Blob([s], {type: '{$mimeTypeEsc}'});
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(b);
                    a.download = '{$filenameEsc}';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(a.href);
                })()">
            <i class="fa fa-download"></i> Export
        </button>
        HTML;
}

/**
 * Build a CSV data URI from column headers and data rows.
 *
 * Each element of $rows must be a sequential array of string values parallel
 * to $headers. The returned URI is suitable for use directly as an href or
 * download attribute value.
 *
 * @param  string[]    $headers  Column header labels
 * @param  string[][]  $rows     Data rows — each a sequential array of string values
 * @return string  data:text/csv;charset=utf-8,... URI
 */
function debug_csv_data_uri(array $headers, array $rows): string
{
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, $headers);
    foreach ($rows as $row) {
        fputcsv($fh, $row);
    }
    rewind($fh);
    $csv = stream_get_contents($fh) ?: '';
    fclose($fh);

    return 'data:text/csv;charset=utf-8,' . rawurlencode($csv);
}

/**
 * Return the most recent data point from an RRD file.
 * Falls back to a raw rrdtool shell call when Rrd::lastUpdate() returns null.
 * Returns null when output cannot be parsed.
 */
function debug_rrd_last_point(string $rrdFile): ?object
{
    $point = App\Facades\Rrd::lastUpdate($rrdFile);
    if ($point !== null) {
        return $point;
    }

    $rrdtool = (string) App\Facades\LibrenmsConfig::get('rrdtool', 'rrdtool');
    $output = shell_exec(escapeshellcmd($rrdtool) . ' lastupdate ' . escapeshellarg($rrdFile) . ' 2>&1');
    if (! is_string($output) || trim($output) === '') {
        return null;
    }

    $lines = preg_split('/\R/', trim($output)) ?: [];
    $header = '';
    $dataLine = '';
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_contains($line, 'ERROR')) {
            continue;
        }
        if (preg_match('/^\d+:/', $line) === 1) {
            $dataLine = $line;
            break;
        }
        if (! str_contains($line, ':')) {
            $header = $line;
        }
    }

    if ($header === '' || $dataLine === '') {
        return null;
    }

    if (preg_match('/^(\d+):\s*(.+)$/', $dataLine, $matches) !== 1) {
        return null;
    }

    $datasets = preg_split('/\s+/', trim($header)) ?: [];
    $values = preg_split('/\s+/', trim((string) $matches[2])) ?: [];
    if (empty($datasets) || empty($values)) {
        return null;
    }

    $data = [];
    foreach ($datasets as $index => $name) {
        $value = $values[$index] ?? null;
        if ($value === null) {
            $data[$name] = null;
            continue;
        }
        $valueLower = strtolower((string) $value);
        $data[$name] = in_array($valueLower, ['nan', 'u', 'unknown'], true) ? null : (float) $value;
    }

    return (object) [
        'timestamp' => (int) $matches[1],
        'data' => $data,
    ];
}

/**
 * Return a comma-separated, HTML-escaped list of datastore names, or 'none'.
 */
function debug_format_datastore_list(array $stores): string
{
    if (empty($stores)) {
        return 'none';
    }

    $escaped = array_map(static fn ($store) => htmlspecialchars((string) $store), $stores);

    return implode(', ', $escaped);
}

/**
 * Admin-gated entry point for any app debug view.
 *
 * Renders nothing when the current user is not an admin.
 * Pass pre-built panel HTML strings (from debug_panel()) as variadic arguments.
 *
 * @param  string   $collapseId  Unique HTML id for the collapse wrapper
 * @param  string   ...$panels   Pre-rendered panel HTML strings
 */
function debug_render(string $collapseId, string ...$panels): void
{
    if (! Auth::user()->hasRole('admin')) {
        return;
    }
    debug_collapse_div($collapseId, ...$panels);
}
