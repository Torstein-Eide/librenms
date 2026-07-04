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
            @php
                $logExtraDevStatsState = $logExtraDevStatsOverride ?? $logExtraDevStatsGlobal;
                $enableHwForecastState = $enableHwForecastOverride ?? $enableHwForecastGlobal;
                $stateBadge = static function (bool $enabled, string $id) {
                    return '<span id="' . $id . '" class="label ' . ($enabled ? 'label-success' : 'label-default') . '" data-label-enabled="' . __('Enabled') . '" data-label-disabled="' . __('Disabled') . '" style="margin-left:8px">' . ($enabled ? __('Enabled') : __('Disabled')) . '</span>';
                };
                // Every control on this page acts at exactly one of three scopes; badge
                // each one so it's clear at a glance what a change will actually affect.
                $scopeBadge = static function (string $scope) {
                    $variants = [
                        'global' => ['label-info', __('Global')],
                        'device' => ['label-primary', __('Device')],
                        'drive' => ['label-warning', __('Drive')],
                    ];
                    [$class, $text] = $variants[$scope];

                    return '<span class="label ' . $class . '" style="min-width:58px;display:inline-block;margin-right:8px">' . $text . '</span>';
                };
            @endphp
            <p class="text-muted" style="margin-bottom:14px">
                {!! $scopeBadge('global') !!} {{ __('= applies to every device.') }}
                {!! $scopeBadge('device') !!} {{ __('= this device\'s SMART app as a whole.') }}
                {!! $scopeBadge('drive') !!} {{ __('= one physical drive on this device.') }}
            </p>
            @php
            @endphp
            <div class="panel-group" id="smart-settings-accordion">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <a data-toggle="collapse" data-parent="#smart-settings-accordion" href="#smart-settings-naming-body">
                                {{ __('Disk naming') }}
                            </a>
                        </h4>
                    </div>
                    <div id="smart-settings-naming-body" class="panel-collapse collapse in">
                        <div class="panel-body">
                            <p class="text-muted">
                                {{ __('Used by the "Custom" label mode on the overview page and in per-disk graph titles. Available variables:') }}
                                @foreach (['device', 'model', 'serial', 'wwn', 'model_family'] as $var)
                                    <a href="#" class="btn btn-default btn-xs smart-naming-var" data-var="{{ $var }}" style="margin:0 2px">${{ $var }}</a>
                                @endforeach
                            </p>

                            <div class="form-group" style="margin-bottom:18px">
                                <label>{!! $scopeBadge('global') !!} {{ __('Naming template') }}</label>
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
                                <label>{!! $scopeBadge('drive') !!} {{ __('Per-drive overrides (blank = inherit the global template above)') }}</label>
                                <div class="table-responsive">
                                    <table class="table table-condensed table-hover">
                                        <thead>
                                        <tr>
                                            <th>{{ __('Drive') }}</th>
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
                        </div>
                    </div>
                </div>

                @if (! empty($viewModes))
                    <div class="panel panel-default">
                        <div class="panel-heading">
                            <h4 class="panel-title">
                                <a data-toggle="collapse" data-parent="#smart-settings-accordion" href="#smart-settings-viewmode-body">
                                    {{ __('Default view mode') }}
                                </a>
                            </h4>
                        </div>
                        <div id="smart-settings-viewmode-body" class="panel-collapse collapse">
                            <div class="panel-body">
                                <div class="form-group" style="margin:0">
                                    <label>{!! $scopeBadge('device') !!} {{ __('Default view mode') }}</label>
                                    <select id="smart-default-view-mode"
                                            class="form-control input-sm"
                                            style="max-width:220px"
                                            data-update-url="{{ route('device.apps.smart.settings.default_view_mode', $device) }}">
                                        @foreach ($viewModes as $mode => $title)
                                            <option value="{{ $mode }}" @selected($mode === $defaultViewMode)>{{ $title }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title" style="display:flex;justify-content:space-between;align-items:center">
                            <a data-toggle="collapse" data-parent="#smart-settings-accordion" href="#smart-settings-extra-stats-body">
                                {{ __('Log extra Device Statistics to RRD') }}
                            </a>
                            {!! $stateBadge($logExtraDevStatsState, 'smart-log-extra-dev-stats-badge') !!}
                        </h4>
                    </div>
                    <div id="smart-settings-extra-stats-body" class="panel-collapse collapse">
                        <div class="panel-body">
                            <p class="text-muted" style="margin:0 0 6px">
                                {{ __('Adds a fixed set of ATA Device Statistics (GP Log 0x04) counters to each disk\'s RRD file, when present on that disk.') }}
                            </p>
                            <div class="checkbox">
                                <label>
                                    {!! $scopeBadge('global') !!}
                                    <input type="checkbox"
                                           id="smart-log-extra-dev-stats-global"
                                           data-update-url="{{ route('device.apps.smart.settings.log_extra_dev_stats', $device) }}"
                                           @checked($logExtraDevStatsGlobal)>
                                    {{ __('Enabled by default') }}
                                </label>
                            </div>
                            <div class="checkbox">
                                <label>
                                    {!! $scopeBadge('device') !!}
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

                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title" style="display:flex;justify-content:space-between;align-items:center">
                            <a data-toggle="collapse" data-parent="#smart-settings-accordion" href="#smart-settings-hw-forecast-body">
                                {{ __('Enable Holt-Winters forecasting') }}
                            </a>
                            {!! $stateBadge($enableHwForecastState, 'smart-hw-forecast-badge') !!}
                        </h4>
                    </div>
                    <div id="smart-settings-hw-forecast-body" class="panel-collapse collapse">
                        <div class="panel-body">
                            <p class="text-muted" style="margin:0 0 6px">
                                {{ __('Stores this disk\'s SMART data with RRDtool Holt-Winters trend prediction enabled for every numeric attribute (except temperature, which has its own graph). Enabled by default. Because of how RRDtool works, this changes the storage format for the whole disk file, not just the predicted metrics, and RRAs cannot be added to an existing file: if this disk\'s RRD file predates the setting, enabling it has no effect until that file is deleted (check the device\'s Eventlog for a notice when this applies) so it can be recreated -- this loses the disk\'s existing history. Prediction bands become meaningful after ~2 days of data.') }}
                            </p>
                            <div class="checkbox">
                                <label>
                                    {!! $scopeBadge('global') !!}
                                    <input type="checkbox"
                                           id="smart-hw-forecast-global"
                                           data-update-url="{{ route('device.apps.smart.settings.hw_forecast', $device) }}"
                                           @checked($enableHwForecastGlobal)>
                                    {{ __('Enabled by default') }}
                                </label>
                            </div>
                            <div class="checkbox">
                                <label>
                                    {!! $scopeBadge('device') !!}
                                    <input type="checkbox"
                                           id="smart-hw-forecast-override"
                                           data-tristate="{{ $enableHwForecastOverride === null ? '' : ($enableHwForecastOverride ? '1' : '0') }}"
                                           data-update-url="{{ route('device.apps.smart.settings.hw_forecast', $device) }}"
                                           @checked($enableHwForecastOverride ?? $enableHwForecastGlobal)>
                                    {{ __('Override for this device') }}
                                </label>
                                <a href="#" id="smart-hw-forecast-reset" class="{{ $enableHwForecastOverride === null ? 'disabled' : '' }}" style="margin-left:8px">{{ __('Reset to default') }}</a>
                            </div>
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

            // Logs the full failed response to the console (status, server JSON/body)
            // and returns a short message to append to the toastr error, since the
            // generic "Could not update setting" text alone isn't enough to debug
            // a 419/422/500 from here.
            function debugAjaxError(context, jqXHR) {
                console.error('[SMART settings] ' + context + ' failed', {
                    status: jqXHR.status,
                    statusText: jqXHR.statusText,
                    responseJSON: jqXHR.responseJSON,
                    responseText: jqXHR.responseText,
                });

                if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                    return jqXHR.responseJSON.message;
                }
                if (jqXHR.responseJSON && jqXHR.responseJSON.errors) {
                    return JSON.stringify(jqXHR.responseJSON.errors);
                }

                return jqXHR.status + ' ' + jqXHR.statusText;
            }

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
                    error: function (jqXHR) { toastr.error('{{ __('Could not update naming template') }}' + ': ' + debugAjaxError('naming template', jqXHR)); },
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
                    error: function (jqXHR) { toastr.error('{{ __('Could not update default view mode') }}' + ': ' + debugAjaxError('default view mode', jqXHR)); },
                });
            });

            function setStateBadge(id, enabled) {
                var $badge = $('#' + id);
                $badge.toggleClass('label-success', enabled).toggleClass('label-default', ! enabled);
                $badge.text(enabled ? $badge.data('label-enabled') : $badge.data('label-disabled'));
            }

            // jQuery serializes JS booleans as the strings "true"/"false", but Laravel's
            // `boolean` validation rule only accepts true, false, 0, 1, "0", "1" -- not
            // the word strings -- so normalize to 1/0/null before every boolean-setting post.
            function toBooleanParam(value) {
                return value === null || value === undefined ? null : (value ? 1 : 0);
            }

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
                        value: toBooleanParam(value),
                    },
                });
            }

            $('#smart-log-extra-dev-stats-global').on('change', function () {
                var checked = $(this).is(':checked');
                saveLogExtraDevStats('global', checked)
                    .done(function (data) {
                        toastr.success(data.message);
                        if ($('#smart-log-extra-dev-stats-override').data('tristate') === '') {
                            setStateBadge('smart-log-extra-dev-stats-badge', checked);
                        }
                    })
                    .fail(function (jqXHR) { toastr.error('{{ __('Could not update setting') }}' + ': ' + debugAjaxError('log extra dev stats (global)', jqXHR)); });
            });

            $('#smart-log-extra-dev-stats-override').on('change', function () {
                var checked = $(this).is(':checked');
                saveLogExtraDevStats('disk', checked)
                    .done(function (data) {
                        toastr.success(data.message);
                        $('#smart-log-extra-dev-stats-override').data('tristate', checked ? '1' : '0');
                        $('#smart-log-extra-dev-stats-reset').removeClass('disabled');
                        setStateBadge('smart-log-extra-dev-stats-badge', checked);
                    })
                    .fail(function (jqXHR) { toastr.error('{{ __('Could not update setting') }}' + ': ' + debugAjaxError('log extra dev stats (override)', jqXHR)); });
            });

            $('#smart-log-extra-dev-stats-reset').on('click', function (event) {
                event.preventDefault();
                if ($(this).hasClass('disabled')) return;

                var $reset = $(this);
                saveLogExtraDevStats('disk', null)
                    .done(function (data) {
                        toastr.success(data.message);
                        var globalChecked = $('#smart-log-extra-dev-stats-global').is(':checked');
                        $('#smart-log-extra-dev-stats-override')
                            .prop('checked', globalChecked)
                            .data('tristate', '');
                        $reset.addClass('disabled');
                        setStateBadge('smart-log-extra-dev-stats-badge', globalChecked);
                    })
                    .fail(function (jqXHR) { toastr.error('{{ __('Could not update setting') }}' + ': ' + debugAjaxError('log extra dev stats (reset)', jqXHR)); });
            });

            // Holt-Winters forecast: global default + per-device override (tri-state via reset link).
            function saveHwForecast(scope, value) {
                return $.ajax({
                    type: 'POST',
                    url: $('#smart-hw-forecast-global').data('update-url'),
                    dataType: 'json',
                    data: {
                        _token: token,
                        app_id: appId,
                        scope: scope,
                        value: toBooleanParam(value),
                    },
                });
            }

            $('#smart-hw-forecast-global').on('change', function () {
                var checked = $(this).is(':checked');
                saveHwForecast('global', checked)
                    .done(function (data) {
                        toastr.success(data.message);
                        if ($('#smart-hw-forecast-override').data('tristate') === '') {
                            setStateBadge('smart-hw-forecast-badge', checked);
                        }
                    })
                    .fail(function (jqXHR) { toastr.error('{{ __('Could not update setting') }}' + ': ' + debugAjaxError('hw forecast (global)', jqXHR)); });
            });

            $('#smart-hw-forecast-override').on('change', function () {
                var checked = $(this).is(':checked');
                saveHwForecast('disk', checked)
                    .done(function (data) {
                        toastr.success(data.message);
                        $('#smart-hw-forecast-override').data('tristate', checked ? '1' : '0');
                        $('#smart-hw-forecast-reset').removeClass('disabled');
                        setStateBadge('smart-hw-forecast-badge', checked);
                    })
                    .fail(function (jqXHR) { toastr.error('{{ __('Could not update setting') }}' + ': ' + debugAjaxError('hw forecast (override)', jqXHR)); });
            });

            $('#smart-hw-forecast-reset').on('click', function (event) {
                event.preventDefault();
                if ($(this).hasClass('disabled')) return;

                var $reset = $(this);
                saveHwForecast('disk', null)
                    .done(function (data) {
                        toastr.success(data.message);
                        var globalChecked = $('#smart-hw-forecast-global').is(':checked');
                        $('#smart-hw-forecast-override')
                            .prop('checked', globalChecked)
                            .data('tristate', '');
                        $reset.addClass('disabled');
                        setStateBadge('smart-hw-forecast-badge', globalChecked);
                    })
                    .fail(function (jqXHR) { toastr.error('{{ __('Could not update setting') }}' + ': ' + debugAjaxError('hw forecast (reset)', jqXHR)); });
            });
        })();
    </script>
@endpush
