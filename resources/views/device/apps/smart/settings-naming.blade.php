@extends('layouts.librenmsv1')

@section('content')
    <x-device.page :device="$device">
        {{-- App selector, same as the device "Apps" tab uses to switch between a device's apps. --}}
        @php
            $appsLinkArray = ['page' => 'device', 'device' => $device->device_id, 'tab' => 'apps'];
            $smartAppHref = route('device.apps.smart', $device);
            $deviceApps = $device->applications->sortBy('show_name', SORT_NATURAL | SORT_FLAG_CASE);
        @endphp
        <div class="panel panel-default">
            <div class="panel-heading">
                <span style="font-weight:bold">{{ __('Apps') }}</span> &#187;
                @foreach ($deviceApps as $i => $currentApp)
                    @if ($i > 0)
                        |
                    @endif
                    @php
                        if ($currentApp->app_type === 'smart') {
                            $appHref = $smartAppHref;
                        } else {
                            $appLinkAdd = ['app' => $currentApp->app_type];
                            if (! empty($currentApp->app_instance)) {
                                $appLinkAdd['instance'] = $currentApp->app_id;
                            }
                            $appHref = \LibreNMS\Util\Url::generate($appsLinkArray, $appLinkAdd);
                        }
                        $appText = $currentApp->displayName() . (! empty($currentApp->app_instance) ? '(' . $currentApp->app_instance . ')' : '');
                    @endphp
                    <a href="{{ $appHref }}" class="{{ $currentApp->app_type === 'smart' ? 'pagemenu-selected' : '' }}">{{ $appText }}</a>
                @endforeach
            </div>
        </div>

        {{-- Optionbar, matching the SMART app's own pagemenu-style navigation. --}}
        @php
            print_optionbar_start();
            echo '<a href="' . htmlspecialchars($smartAppHref, ENT_QUOTES) . '">' . __('Overview') . '</a>'
                . ' | <a href="' . htmlspecialchars(route('device.apps.smart.compare', $device), ENT_QUOTES) . '">' . __('Compare') . '</a>'
                . ' | <span class="pagemenu-selected">' . __('Settings') . '</span>'
                . '<br>&nbsp;&nbsp; ' . __('Setting') . ': <a href="' . htmlspecialchars(route('device.apps.smart.settings', $device), ENT_QUOTES) . '">' . __('Attribute Warning Thresholds') . '</a>'
                . ' | <span class="pagemenu-selected">' . __('Disk Naming') . '</span>';
            print_optionbar_end();
        @endphp

        @if ($appId === null)
            <div class="alert alert-info">{{ __('No SMART attributes discovered yet for this device.') }}</div>
        @else
            <div class="panel panel-default">
                <div class="panel-heading">{{ __('Disk naming') }}</div>
                <div class="panel-body">
                    <p class="text-muted">
                        {{ __('Used by the "Custom" label mode on the overview page and in per-disk graph titles. Available variables:') }}
                        @foreach (['device', 'model', 'serial', 'wwn', 'model_family'] as $var)
                            <a href="#" class="btn btn-default btn-xs smart-naming-var" data-var="{{ $var }}" style="margin:0 2px">${{ $var }}</a>
                        @endforeach
                    </p>

                    <div class="form-group" style="margin-bottom:18px">
                        <label>{{ __('Global template (applies to every device)') }}</label>
                        <input type="text"
                               class="form-control input-sm smart-naming-field"
                               data-disk_key=""
                               data-update-url="{{ route('device.apps.smart.settings.naming_template', $device) }}"
                               placeholder="$device"
                               value="{{ $namingTemplate }}">
                        <p class="text-muted" style="margin:4px 0 0">{{ __('Saved here, but used as the fallback on every device\'s SMART app, not just this one.') }}</p>
                        <div class="text-muted smart-naming-preview" data-disk_key="" style="margin-top:4px"></div>
                    </div>

                    @if (! empty($diskFields))
                        <label>{{ __('Per-disk overrides on this device (blank = inherit the global template)') }}</label>
                        <div class="table-responsive">
                            <table class="table table-condensed table-hover">
                                <thead>
                                <tr>
                                    <th>{{ __('Disk') }}</th>
                                    <th>{{ __('Template') }}</th>
                                    <th>{{ __('Preview') }}</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($diskFields as $diskKey => $fields)
                                    @php $hasOverride = ($perDiskTemplates[$diskKey] ?? '') !== ''; @endphp
                                    <tr data-disk_key="{{ $diskKey }}">
                                        <td>{{ $diskLabels[$diskKey] ?? $diskKey }}</td>
                                        <td>
                                            <input type="text"
                                                   class="form-control input-sm smart-naming-field"
                                                   data-disk_key="{{ $diskKey }}"
                                                   data-update-url="{{ route('device.apps.smart.settings.naming_template', $device) }}"
                                                   placeholder="{{ $namingTemplate ?: '$device' }}"
                                                   value="{{ $perDiskTemplates[$diskKey] ?? '' }}">
                                        </td>
                                        <td class="text-muted smart-naming-preview" data-disk_key="{{ $diskKey }}"></td>
                                        <td>
                                            <a type="button"
                                               class="btn btn-default btn-sm smart-naming-reset {{ $hasOverride ? '' : 'disabled' }}"
                                               data-disk_key="{{ $diskKey }}"
                                               title="{{ __('Clear this disk\'s override so it inherits the device-wide template again') }}">{{ __('Reset') }}</a>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if (! empty($viewModes))
                        <div class="form-group" style="margin:18px 0 0">
                            <label>{{ __('Default view mode') }}</label>
                            <select id="smart-default-view-mode"
                                    class="form-control input-sm"
                                    style="max-width:220px"
                                    data-update-url="{{ route('device.apps.smart.settings.default_view_mode', $device) }}">
                                @foreach ($viewModes as $mode => $title)
                                    <option value="{{ $mode }}" @selected($mode === $defaultViewMode)>{{ $title }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="form-group" style="margin:18px 0 0">
                        <label>{{ __('Log extra Device Statistics to RRD') }}</label>
                        <p class="text-muted" style="margin:0 0 6px">
                            {{ __('Adds a fixed set of ATA Device Statistics (GP Log 0x04) counters to each disk\'s RRD file, when present on that disk.') }}
                        </p>
                        <div class="checkbox">
                            <label>
                                <input type="checkbox"
                                       id="smart-log-extra-dev-stats-global"
                                       data-update-url="{{ route('device.apps.smart.settings.log_extra_dev_stats', $device) }}"
                                       @checked($logExtraDevStatsGlobal)>
                                {{ __('Enabled by default (applies to every device)') }}
                            </label>
                        </div>
                        <div class="checkbox">
                            <label>
                                <input type="checkbox"
                                       id="smart-log-extra-dev-stats-override"
                                       data-tristate="{{ $logExtraDevStatsOverride === null ? '' : ($logExtraDevStatsOverride ? '1' : '0') }}"
                                       data-update-url="{{ route('device.apps.smart.settings.log_extra_dev_stats', $device) }}"
                                       @checked($logExtraDevStatsOverride ?? $logExtraDevStatsGlobal)>
                                {{ __('Override for this device') }}
                            </label>
                            <a href="#" id="smart-log-extra-dev-stats-reset" class="{{ $logExtraDevStatsOverride === null ? 'disabled' : '' }}" style="margin-left:8px">{{ __('Reset to default') }}</a>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </x-device.page>
@endsection

@push('scripts')
    <script>
        (function () {
            var appId = {{ (int) ($appId ?? 0) }};
            var token = '{{ csrf_token() }}';

            // Disk naming: variable badges, live preview, and save-on-blur/Enter.
            var diskFields = @json($diskFields ?? []);
            var lastNamingInput = null;

            function renderNamingPreview(template, diskKey) {
                var fields = diskFields[diskKey] || diskFields[''] || {};
                return (template || '').replace(/\$(device|model|serial|wwn|model_family)\b/g, function (m, name) {
                    return fields[name] || '';
                });
            }

            function updateNamingPreview($input) {
                var diskKey = $input.data('disk_key');
                var template = $input.val() || $input.attr('placeholder');
                var previewKey = diskKey === '' ? (Object.keys(diskFields)[0] || '') : diskKey;
                $('.smart-naming-preview[data-disk_key="' + diskKey + '"]').text(renderNamingPreview(template, previewKey));
            }

            $('.smart-naming-field').each(function () { updateNamingPreview($(this)); });

            $('.smart-naming-field').on('focus', function () { lastNamingInput = this; });
            $('.smart-naming-field').on('input', function () { updateNamingPreview($(this)); });

            $('.smart-naming-var').on('click', function (event) {
                event.preventDefault();
                var input = lastNamingInput || document.querySelector('.smart-naming-field');
                if (!input) return;
                var token = '$' + $(this).data('var');
                var start = input.selectionStart ?? input.value.length;
                var end = input.selectionEnd ?? input.value.length;
                input.value = input.value.slice(0, start) + token + input.value.slice(end);
                input.selectionStart = input.selectionEnd = start + token.length;
                input.focus();
                updateNamingPreview($(input));
            });

            $('.smart-naming-field').on('focusin', function () {
                $(this).data('val', $(this).val());
            });

            $('.smart-naming-field').on('blur keyup', function (e) {
                if (e.type === 'keyup' && e.keyCode !== 13) return;
                var prev = $(this).data('val');
                var value = $(this).val();
                if (prev === value) return;

                var $this = $(this);
                $.ajax({
                    type: 'POST',
                    url: $(this).data('update-url'),
                    dataType: 'json',
                    data: {
                        _token: token,
                        app_id: appId,
                        disk_key: $(this).data('disk_key'),
                        value: value,
                    },
                    success: function (data) {
                        if (data.status === 'ok') {
                            $this.data('val', value);
                            toastr.success(data.message);
                            $this.closest('tr').find('.smart-naming-reset').toggleClass('disabled', value === '');
                        } else {
                            toastr.error(data.message);
                        }
                    },
                    error: function () { toastr.error('{{ __('Could not update naming template') }}'); },
                });
            });

            $('.smart-naming-reset').on('click', function (event) {
                event.preventDefault();
                if ($(this).hasClass('disabled')) return;
                var $input = $(this).closest('tr').find('.smart-naming-field');
                $input.val('');
                updateNamingPreview($input);
                $input.trigger('blur');
            });

            $('#smart-default-view-mode').on('change', function () {
                $.ajax({
                    type: 'POST',
                    url: $(this).data('update-url'),
                    dataType: 'json',
                    data: {
                        _token: token,
                        app_id: appId,
                        value: $(this).val(),
                    },
                    success: function (data) { toastr.success(data.message); },
                    error: function () { toastr.error('{{ __('Could not update default view mode') }}'); },
                });
            });

            // Log extra Device Statistics: global default + per-device override (tri-state via reset link).
            function saveLogExtraDevStats(scope, value) {
                return $.ajax({
                    type: 'POST',
                    url: $('#smart-log-extra-dev-stats-global').data('update-url'),
                    dataType: 'json',
                    data: {
                        _token: token,
                        app_id: appId,
                        scope: scope,
                        value: value,
                    },
                });
            }

            $('#smart-log-extra-dev-stats-global').on('change', function () {
                saveLogExtraDevStats('global', $(this).is(':checked'))
                    .done(function (data) { toastr.success(data.message); })
                    .fail(function () { toastr.error('{{ __('Could not update setting') }}'); });
            });

            $('#smart-log-extra-dev-stats-override').on('change', function () {
                var checked = $(this).is(':checked');
                saveLogExtraDevStats('disk', checked)
                    .done(function (data) {
                        toastr.success(data.message);
                        $('#smart-log-extra-dev-stats-override').data('tristate', checked ? '1' : '0');
                        $('#smart-log-extra-dev-stats-reset').removeClass('disabled');
                    })
                    .fail(function () { toastr.error('{{ __('Could not update setting') }}'); });
            });

            $('#smart-log-extra-dev-stats-reset').on('click', function (event) {
                event.preventDefault();
                if ($(this).hasClass('disabled')) return;

                var $reset = $(this);
                saveLogExtraDevStats('disk', null)
                    .done(function (data) {
                        toastr.success(data.message);
                        $('#smart-log-extra-dev-stats-override')
                            .prop('checked', $('#smart-log-extra-dev-stats-global').is(':checked'))
                            .data('tristate', '');
                        $reset.addClass('disabled');
                    })
                    .fail(function () { toastr.error('{{ __('Could not update setting') }}'); });
            });
        })();
    </script>
@endpush
