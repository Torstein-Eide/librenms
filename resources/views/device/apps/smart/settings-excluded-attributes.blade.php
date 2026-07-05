{{-- Rotating Wear Sensor: Excluded Attributes table for one scope: the
     global-default list ($scope='global', $diskKey='') or one disk's override
     ($scope='disk', $diskKey=real key). Included by settings-global.blade.php
     once and settings-device.blade.php once per disk-key tab. Expects
     $entries, $isReset (true when there's nothing to reset: global not yet
     customized, or disk has no override row), $device from the parent. --}}
@php
    $excludedAttrsTableId = 'smart-excluded-attrs-' . $scope . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $diskKey ?: 'default');
    // On the Device tab, a disk with no override of its own is just showing the
    // inherited Global list as a preview: those rows are read-only/grayed out
    // until "Override for this device" is clicked, which seeds an editable
    // override from the current (Global) rows. Never applies on the Global tab.
    $isInherited = $scope === 'disk' && $isReset;
@endphp
<div class="smart-excluded-attrs{{ $isInherited ? ' smart-excluded-attrs-readonly' : '' }}" data-scope="{{ $scope }}" data-disk_key="{{ $diskKey }}" id="{{ $excludedAttrsTableId }}">
    <p class="text-muted" style="margin:0 0 8px">
        {{ __('Attributes matching any row below are ignored when computing this disk\'s Rotating Wear sensor (the lowest normalized value among its remaining attributes), but are still fully discovered, polled, stored, and shown everywhere else: this only affects the Wear sensor\'s calculation, not discovery, polling, RRD storage, or the attribute table/graphs.') }}
        <a href="#{{ $excludedAttrsTableId }}-regex-cheatsheet" data-toggle="collapse">{{ __('Regex cheatsheet') }}</a>
    </p>
    @if ($isInherited)
        <p class="text-muted" style="margin:0 0 8px;font-style:italic">
            {{ __('These rows are inherited from the Global tab\'s defaults, so they\'re read-only here. Click "Override for this device" below to start editing a copy for this disk alone.') }}
        </p>
    @endif
    @php
        $nameTooltip = __('Matches the attribute\'s real name case-insensitively and ignoring underscore-vs-space, so "Reallocated Sector Ct" and "Reallocated_Sector_Ct" match the same attribute; this compares the attribute\'s real name, not the prettified label shown elsewhere in the app.');
        $regexTooltip = __('A PHP/PCRE pattern including delimiters (e.g. /^Spare_Blocks/i), matched against the attribute\'s raw underscore-separated name exactly as smartctl reports it: case-sensitive unless you add the i flag yourself, and spaces won\'t match underscores the way Name does.');
        // PHP's PCRE syntax specifically: \x{YYYY} is the Unicode-code-point escape
        // here, not \uYYYY (that's JavaScript syntax and isn't valid in PCRE).
        // Patterns still need delimiters (e.g. /^Spare_Blocks/i); these entries
        // describe what goes between them.
        $regexCheatsheet = [
            '[abx-z]' => __('One character of: a, b, or the range x-z'),
            '[^abx-z]' => __('One character except: a, b, or the range x-z'),
            'a|b' => __('a or b'),
            'a?' => __("Zero or one a's (greedy)"),
            'a??' => __("Zero or one a's (lazy)"),
            'a*' => __("Zero or more a's (greedy)"),
            'a*?' => __("Zero or more a's (lazy)"),
            'a+' => __("One or more a's (greedy)"),
            'a+?' => __("One or more a's (lazy)"),
            'a{4}' => __("Exactly 4 a's"),
            'a{4,8}' => __('Between (inclusive) 4 and 8 a\'s'),
            'a{9,}' => __("9 or more a's"),
            '(?=...)' => __('A positive lookahead'),
            '(?!...)' => __('A negative lookahead'),
            '(?:...)' => __('A non-capturing group'),
            '(...)' => __('A capturing group'),
            '^' => __('Beginning of the string'),
            '$' => __('End of the string'),
            '\\d' => __('A digit (same as [0-9])'),
            '\\D' => __('A non-digit (same as [^0-9])'),
            '\\w' => __('A word character (same as [_a-zA-Z0-9])'),
            '\\W' => __('A non-word character (same as [^_a-zA-Z0-9])'),
            '\\s' => __('A whitespace character'),
            '\\S' => __('A non-whitespace character'),
            '\\b' => __('A word boundary'),
            '\\B' => __('A non-word boundary'),
            '\\n' => __('A newline'),
            '\\t' => __('A tab'),
            '\\xYY' => __('The character with the hex code YY'),
            '\\x{YYYY}' => __('The Unicode character with the hex code YYYY'),
            '.' => __('Any character except a newline'),
            '\\1' => __('The 1st captured group (\2 for the 2nd, etc.)'),
            '/.../i' => __('Delimiters (required) plus the case-insensitive flag'),
        ];
    @endphp
    <div id="{{ $excludedAttrsTableId }}-regex-cheatsheet" class="collapse" style="margin:0 0 10px">
        <div style="column-width:280px;column-gap:18px;font-size:12px" class="text-muted">
            @foreach ($regexCheatsheet as $token => $desc)
                <div style="break-inside:avoid-column;-webkit-column-break-inside:avoid;padding:1px 0"><code>{{ $token }}</code>: {{ $desc }}</div>
            @endforeach
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-condensed">
            <thead>
            <tr>
                <th style="width:110px"><abbr style="cursor:help;text-decoration:underline dotted" title="{{ __('Name') }}: {{ $nameTooltip }}&#10;&#10;{{ __('Regex') }}: {{ $regexTooltip }}">{{ __('Type') }}</abbr></th>
                <th>{{ __('Pattern') }}</th>
                <th>{{ __('Comment') }}</th>
                <th style="width:40px"></th>
            </tr>
            </thead>
            <tbody class="smart-excluded-attrs-rows">
            @foreach ($entries as $entry)
                @php $entryType = $entry['type'] ?? 'name'; @endphp
                <tr class="smart-excluded-attr-row{{ $isInherited ? ' text-muted' : '' }}">
                    <td>
                        <select class="form-control input-sm smart-excluded-attr-type" @disabled($isInherited)>
                            <option value="name" @selected($entryType === 'name')>{{ __('Name') }}</option>
                            <option value="regex" @selected($entryType === 'regex')>{{ __('Regex') }}</option>
                            <option value="id" @selected($entryType === 'id')>{{ __('ID') }}</option>
                        </select>
                    </td>
                    <td>
                        <input type="text" class="form-control input-sm smart-excluded-attr-pattern"
                               list="{{ $entryType === 'id' ? '' : 'smart-attr-names' }}"
                               placeholder="{{ $entryType === 'id' ? __('Numeric attribute ID') : ($entryType === 'regex' ? '/^Pattern/i' : '') }}"
                               value="{{ $entry['pattern'] ?? '' }}" @disabled($isInherited)>
                    </td>
                    <td>
                        <input type="text" class="form-control input-sm smart-excluded-attr-comment" style="font-size:12px" placeholder="{{ __('Optional note') }}" value="{{ $entry['comment'] ?? '' }}" @disabled($isInherited)>
                    </td>
                    <td>
                        <a href="#" class="btn btn-default btn-sm smart-excluded-attr-remove {{ $isInherited ? 'hidden' : '' }}" title="{{ __('Remove') }}"><i class="fa fa-times"></i></a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <a href="#" class="btn btn-default btn-sm smart-excluded-attr-add {{ $isInherited ? 'hidden' : '' }}"><i class="fa fa-plus"></i> {{ __('Add') }}</a>
    <a href="#" class="btn btn-default btn-sm smart-excluded-attr-override-start {{ $isInherited ? '' : 'hidden' }}">{{ __('Override for this device') }}</a>
    <a href="#" class="smart-excluded-attr-reset {{ $isReset ? 'disabled' : '' }}" style="margin-left:12px">
        {{ $scope === 'global' ? __('Reset to built-in defaults') : __('Reset to inherit global') }}
    </a>
</div>
