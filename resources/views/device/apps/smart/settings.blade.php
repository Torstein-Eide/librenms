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

        {{-- Optionbar, matching the SMART app's own pagemenu-style navigation. The
             Device/Global switch below is a client-side Bootstrap tab pair, not a
             navigation link -- this is one page/route now, not two. --}}
        @php
            print_optionbar_start();
            echo '<a href="' . htmlspecialchars($smartAppHref, ENT_QUOTES) . '">' . __('Overview') . '</a>'
                . ' | <a href="' . htmlspecialchars(route('device.apps.smart.compare', $device), ENT_QUOTES) . '">' . __('Compare') . '</a>'
                . ' | <span class="pagemenu-selected">' . __('Settings') . '</span>'
                . '<br>&nbsp;&nbsp; ' . __('Setting') . ': ';
        @endphp
        <span class="smart-settings-outer-tabs" style="display:inline-block;vertical-align:middle">
            <a href="#smart-outer-device" class="pagemenu-selected">{{ __('Device') }}</a>
            <span> | </span>
            <a href="#smart-outer-global">{{ __('Global') }}</a>
        </span>
        @php
            print_optionbar_end();
        @endphp

        @if ($appId === null)
            <div class="alert alert-info">{{ __('No SMART attributes discovered yet for this device.') }}</div>
        @else
            @php
                $stateBadge = static function (bool $enabled, string $id) {
                    return '<span id="' . $id . '" class="label ' . ($enabled ? 'label-success' : 'label-default') . '" data-label-enabled="' . __('Enabled') . '" data-label-disabled="' . __('Disabled') . '" style="margin-left:8px">' . ($enabled ? __('Enabled') : __('Disabled')) . '</span>';
                };
            @endphp

            <div class="tab-content smart-settings-outer-tab-content">
                <div role="tabpanel" class="tab-pane active" id="smart-outer-device">
                    @include('device.apps.smart.settings-device')
                </div>
                <div role="tabpanel" class="tab-pane" id="smart-outer-global">
                    @include('device.apps.smart.settings-global')
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

            // Outer Device/Global switch: plain client-side show/hide, no page reload.
            // Deliberately not Bootstrap's data-toggle="tab" here -- that binds a
            // document-level delegated click handler to any [data-toggle="tab"]
            // element, which would fight this handler over the outer panes' active
            // class. Scoped to direct children of .smart-settings-outer-tab-content
            // so this never touches the per-disk tab-panes nested inside the Device
            // pane, which still use Bootstrap's own tab plugin independently.
            $('.smart-settings-outer-tabs a').on('click', function (event) {
                event.preventDefault();
                var target = $(this).attr('href');
                $('.smart-settings-outer-tabs a').removeClass('pagemenu-selected');
                $(this).addClass('pagemenu-selected');
                $('.smart-settings-outer-tab-content > .tab-pane').removeClass('active');
                $(target).addClass('active');
            });

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

            // Inline edit, save on blur or Enter. No save button.
            $('.smart-thresh-field').on('focusin', function () {
                $(this).data('val', $(this).val());
            });

            $('.smart-thresh-field').on('blur keyup', function (e) {
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
                        scope: $(this).data('scope'),
                        disk_key: $(this).data('disk_key'),
                        attribute_id: $(this).data('attribute_id'),
                        field: $(this).data('field'),
                        value: value === '' ? null : value,
                    },
                    success: function (data) {
                        if (data.status === 'ok') {
                            $this.data('val', value);
                            toastr.success(data.message);
                            $this.closest('tr').find('.smart-thresh-reset').removeClass('disabled');
                        } else {
                            toastr.error(data.message);
                        }
                    },
                    error: function () { toastr.error('{{ __('Could not update threshold') }}'); },
                });
            });

            $('.smart-thresh-alert').bootstrapSwitch('offColor', 'danger');
            $('.smart-thresh-alert').on('switchChange.bootstrapSwitch', function (event, state) {
                event.preventDefault();
                var $this = $(this);
                $.ajax({
                    type: 'POST',
                    url: $(this).data('alert-url'),
                    dataType: 'json',
                    data: {
                        _token: token,
                        app_id: appId,
                        scope: $(this).data('scope'),
                        disk_key: $(this).data('disk_key'),
                        attribute_id: $(this).data('attribute_id'),
                        state: state ? 1 : 0,
                    },
                    success: function (data) {
                        if (data.status === 'ok') {
                            toastr.success(data.message);
                            $this.closest('tr').find('.smart-thresh-reset').removeClass('disabled');
                        } else {
                            toastr.error(data.message);
                        }
                    },
                    error: function () { toastr.error('{{ __('Could not update alerting') }}'); },
                });
            });

            function applyRowValues($row, values) {
                ['8h', '24h', '168h', '672h'].forEach(function (window) {
                    var val = values['warn_rate_' + window];
                    var display = (val === null || val === undefined) ? '' : val;
                    $row.find('.smart-thresh-field[data-field="warn_rate_' + window + '"]').val(display).data('val', display);
                });
                var $alert = $row.find('.smart-thresh-alert');
                if ($alert.length) {
                    $alert.bootstrapSwitch('state', !!values.alert_enabled, true);
                }
            }

            $('.smart-thresh-reset').on('click', function (event) {
                event.preventDefault();
                if ($(this).hasClass('disabled')) return;
                var $this = $(this);
                var $row = $this.closest('tr');
                $.ajax({
                    type: 'POST',
                    url: $(this).data('reset-url'),
                    dataType: 'json',
                    data: {
                        _token: token,
                        app_id: appId,
                        scope: $(this).data('scope'),
                        disk_key: $(this).data('disk_key'),
                        attribute_id: $(this).data('attribute_id'),
                    },
                    success: function (data) {
                        if (data.status !== 'ok') {
                            toastr.error(data.message);
                            return;
                        }
                        toastr.success(data.message);
                        applyRowValues($row, data.values);
                        $this.addClass('disabled');
                    },
                    error: function () { toastr.error('{{ __('Could not reset threshold') }}'); },
                });
            });

            $('.smart-thresh-copy-default').on('click', function (event) {
                event.preventDefault();
                var $this = $(this);
                var $row = $this.closest('tr');
                $.ajax({
                    type: 'POST',
                    url: $(this).data('copy-url'),
                    dataType: 'json',
                    data: {
                        _token: token,
                        app_id: appId,
                        disk_key: $(this).data('disk_key'),
                        attribute_id: $(this).data('attribute_id'),
                    },
                    success: function (data) {
                        if (data.status !== 'ok') {
                            toastr.error(data.message);
                            return;
                        }
                        toastr.success(data.message);
                        applyRowValues($row, data.values);
                        $row.find('.smart-thresh-reset').removeClass('disabled');
                    },
                    error: function () { toastr.error('{{ __('Could not copy from global default') }}'); },
                });
            });

            // Disk naming: variable badges, live preview, and save-on-blur/Enter.
            // Buttons exist on both the Device and Global tabs; each inserts into
            // whichever .smart-naming-field was last focused, regardless of tab.
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

            $('.smart-toggle-switch').bootstrapSwitch('onColor', 'success');

            // Log extra Device Statistics: global default (Global tab) + per-device
            // override (Device tab, tri-state via reset link).
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

            $('#smart-log-extra-dev-stats-global').on('switchChange.bootstrapSwitch', function (event, state) {
                saveLogExtraDevStats('global', state)
                    .done(function (data) {
                        toastr.success(data.message);
                        setStateBadge('smart-log-extra-dev-stats-global-badge', state);
                        if ($('#smart-log-extra-dev-stats-override').data('tristate') === '') {
                            setStateBadge('smart-log-extra-dev-stats-badge', state);
                        }
                    })
                    .fail(function (jqXHR) { toastr.error('{{ __('Could not update setting') }}' + ': ' + debugAjaxError('log extra dev stats (global)', jqXHR)); });
            });

            $('#smart-log-extra-dev-stats-override').on('switchChange.bootstrapSwitch', function (event, state) {
                saveLogExtraDevStats('disk', state)
                    .done(function (data) {
                        toastr.success(data.message);
                        $('#smart-log-extra-dev-stats-override').data('tristate', state ? '1' : '0');
                        $('#smart-log-extra-dev-stats-reset').removeClass('disabled');
                        setStateBadge('smart-log-extra-dev-stats-badge', state);
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
                            .bootstrapSwitch('state', globalChecked, true)
                            .data('tristate', '');
                        $reset.addClass('disabled');
                        setStateBadge('smart-log-extra-dev-stats-badge', globalChecked);
                    })
                    .fail(function (jqXHR) { toastr.error('{{ __('Could not update setting') }}' + ': ' + debugAjaxError('log extra dev stats (reset)', jqXHR)); });
            });

            // Holt-Winters forecast: global default (Global tab) + per-device
            // override (Device tab, tri-state via reset link).
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

            $('#smart-hw-forecast-global').on('switchChange.bootstrapSwitch', function (event, state) {
                saveHwForecast('global', state)
                    .done(function (data) {
                        toastr.success(data.message);
                        setStateBadge('smart-hw-forecast-global-badge', state);
                        if ($('#smart-hw-forecast-override').data('tristate') === '') {
                            setStateBadge('smart-hw-forecast-badge', state);
                        }
                    })
                    .fail(function (jqXHR) { toastr.error('{{ __('Could not update setting') }}' + ': ' + debugAjaxError('hw forecast (global)', jqXHR)); });
            });

            $('#smart-hw-forecast-override').on('switchChange.bootstrapSwitch', function (event, state) {
                saveHwForecast('disk', state)
                    .done(function (data) {
                        toastr.success(data.message);
                        $('#smart-hw-forecast-override').data('tristate', state ? '1' : '0');
                        $('#smart-hw-forecast-reset').removeClass('disabled');
                        setStateBadge('smart-hw-forecast-badge', state);
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
                            .bootstrapSwitch('state', globalChecked, true)
                            .data('tristate', '');
                        $reset.addClass('disabled');
                        setStateBadge('smart-hw-forecast-badge', globalChecked);
                    })
                    .fail(function (jqXHR) { toastr.error('{{ __('Could not update setting') }}' + ': ' + debugAjaxError('hw forecast (reset)', jqXHR)); });
            });
        })();
    </script>
@endpush
