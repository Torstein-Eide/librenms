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
                . '<br>&nbsp;&nbsp; ' . __('Setting') . ': <span class="pagemenu-selected">' . __('Attribute Warning Thresholds') . '</span>'
                . ' | <a href="' . htmlspecialchars(route('device.apps.smart.settings.naming', $device), ENT_QUOTES) . '">' . __('Disk Naming') . '</a>';
            print_optionbar_end();
        @endphp

        <p class="text-muted">
            {{ __('Rate-of-change thresholds (raw value change per hour) used to flag an attribute with a rate warning. Edits save immediately. A disk row with no override falls back to the "Global Defaults" tab. "Avg" columns show the attribute\'s current measured rate, for reference when picking a threshold.') }}
        </p>

        @if ($appId === null || empty($diskKeys))
            <div class="alert alert-info">{{ __('No SMART attributes discovered yet for this device.') }}</div>
        @else
            <ul class="nav nav-tabs" role="tablist">
                @foreach ($diskKeys as $i => $diskKey)
                    @php $tabId = 'smart-thresh-disk-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $diskKey ?: 'default'); @endphp
                    <li role="presentation" class="{{ $i === 0 ? 'active' : '' }}">
                        <a href="#{{ $tabId }}" aria-controls="{{ $tabId }}" role="tab" data-toggle="tab">{{ $diskLabels[$diskKey] ?? $diskKey }}</a>
                    </li>
                @endforeach
            </ul>

            <div class="tab-content" style="margin-top:12px">
                @foreach ($diskKeys as $i => $diskKey)
                    @php
                        $tabId = 'smart-thresh-disk-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $diskKey ?: 'default');
                        $items = $itemsByDisk[$diskKey] ?? collect();
                        $isDefaultTab = $diskKey === '';
                        $scope = $isDefaultTab ? 'global' : 'disk';
                        $fmtAvg = static function ($v) {
                            return is_numeric($v) ? number_format((float) $v, 1) : '-';
                        };
                    @endphp
                    <div role="tabpanel" class="tab-pane {{ $i === 0 ? 'active' : '' }}" id="{{ $tabId }}">
                        <div class="table-responsive">
                            <table class="table table-condensed table-hover">
                                <thead>
                                <tr>
                                    <th>{{ __('ID') }}</th>
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ $isDefaultTab ? __('Max 8h') : __('Avg 8h') }}</th>
                                    <th class="col-sm-1">{{ __('Warn 8h') }}</th>
                                    <th>{{ $isDefaultTab ? __('Max 24h') : __('Avg 24h') }}</th>
                                    <th class="col-sm-1">{{ __('Warn 24h') }}</th>
                                    <th>{{ $isDefaultTab ? __('Max 1wk') : __('Avg 1wk') }}</th>
                                    <th class="col-sm-1">{{ __('Warn 1wk') }}</th>
                                    <th>{{ $isDefaultTab ? __('Max 1mo') : __('Avg 1mo') }}</th>
                                    <th class="col-sm-1">{{ __('Warn 1mo') }}</th>
                                    <th>{{ __('Alert') }}</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($items as $item)
                                    <tr data-attribute_id="{{ $item['attribute_id'] }}" data-disk_key="{{ $diskKey }}">
                                        <td>{{ $item['attribute_id'] }}</td>
                                        <td>{{ str_replace('_', ' ', (string) $item['name']) }}</td>
                                        @foreach (['8h', '24h', '168h', '672h'] as $window)
                                            @php
                                                $rateVal = $item['rate_' . $window];
                                                $warnVal = $item['warn_rate_' . $window];
                                                $breached = is_numeric($rateVal) && is_numeric($warnVal) && (float) $rateVal >= (float) $warnVal;
                                            @endphp
                                            <td class="{{ $breached ? 'text-danger' : 'text-muted' }}">{{ $fmtAvg($rateVal) }}</td>
                                            <td>
                                                <div class="form-group has-feedback" style="margin:0">
                                                    <input type="text"
                                                           class="form-control input-sm smart-thresh-field"
                                                           style="width:90px"
                                                           data-scope="{{ $scope }}"
                                                           data-disk_key="{{ $diskKey }}"
                                                           data-attribute_id="{{ $item['attribute_id'] }}"
                                                           data-field="warn_rate_{{ $window }}"
                                                           data-update-url="{{ route('device.apps.smart.settings.field', $device) }}"
                                                           placeholder="{{ ! $isDefaultTab && is_numeric($item['default_warn_rate_' . $window]) ? number_format((float) $item['default_warn_rate_' . $window], 1) : '' }}"
                                                           @if ($item['has_row'] && is_numeric($item['warn_rate_' . $window])) value="{{ $item['warn_rate_' . $window] }}" @endif>
                                                </div>
                                            </td>
                                        @endforeach
                                        <td>
                                            <input type="checkbox"
                                                   class="smart-thresh-alert"
                                                   data-scope="{{ $scope }}"
                                                   data-disk_key="{{ $diskKey }}"
                                                   data-attribute_id="{{ $item['attribute_id'] }}"
                                                   data-attribute_name="{{ $item['name'] }}"
                                                   data-alert-url="{{ route('device.apps.smart.settings.alert', $device) }}"
                                                   {{ $item['alert_enabled'] ? 'checked' : '' }}>
                                        </td>
                                        <td style="white-space:nowrap">
                                            <a type="button"
                                               class="btn btn-default btn-sm smart-thresh-reset {{ $item['has_row'] ? '' : 'disabled' }}"
                                               data-scope="{{ $scope }}"
                                               data-disk_key="{{ $diskKey }}"
                                               data-attribute_id="{{ $item['attribute_id'] }}"
                                               data-reset-url="{{ route('device.apps.smart.settings.reset', $device) }}"
                                               title="{{ $isDefaultTab ? __('Delete the global default for this attribute') : __('Delete this override so it inherits the global default again') }}">{{ __('Reset') }}</a>
                                            @if (! $isDefaultTab)
                                                <a type="button"
                                                   class="btn btn-default btn-sm smart-thresh-copy-default"
                                                   data-disk_key="{{ $diskKey }}"
                                                   data-attribute_id="{{ $item['attribute_id'] }}"
                                                   data-copy-url="{{ route('device.apps.smart.settings.copy_default', $device) }}"
                                                   title="{{ __('Copy the global default\'s values into this disk as an editable override') }}">{{ __('Copy to default') }}</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>

        @endif
    </x-device.page>
@endsection

@push('scripts')
    <script>
        (function () {
            var appId = {{ (int) ($appId ?? 0) }};
            var resetUrl = '{{ $appId !== null ? route('device.apps.smart.settings.reset', $device) : '' }}';
            var token = '{{ csrf_token() }}';

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
        })();
    </script>
@endpush
