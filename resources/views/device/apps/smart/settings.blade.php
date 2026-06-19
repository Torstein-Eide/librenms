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
                . ' | <span class="text-muted">' . __('...(more to come)') . '</span>';
            print_optionbar_end();
        @endphp

        <p class="text-muted">
            {{ __('Rate-of-change thresholds (raw value change per hour) used to flag an attribute with a rate warning. A row with no per-disk value falls back to the global default for that attribute.') }}
        </p>

        @if ($appId === null || empty($diskKeys))
            <div class="alert alert-info">{{ __('No SMART attributes discovered yet for this device.') }}</div>
        @else
            <ul class="nav nav-tabs" role="tablist">
                @foreach ($diskKeys as $i => $diskKey)
                    @php $tabId = 'smart-thresh-disk-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $diskKey); @endphp
                    <li role="presentation" class="{{ $i === 0 ? 'active' : '' }}">
                        <a href="#{{ $tabId }}" aria-controls="{{ $tabId }}" role="tab" data-toggle="tab">{{ $diskLabels[$diskKey] ?? $diskKey }}</a>
                    </li>
                @endforeach
            </ul>

            <div class="tab-content" style="margin-top:12px">
                @foreach ($diskKeys as $i => $diskKey)
                    @php
                        $tabId = 'smart-thresh-disk-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $diskKey);
                        $tblId = $tabId . '-tbl';
                        $items = $itemsByDisk[$diskKey] ?? collect();
                    @endphp
                    <div role="tabpanel" class="tab-pane {{ $i === 0 ? 'active' : '' }}" id="{{ $tabId }}">
                        <form class="smart-threshold-form" data-disk_key="{{ $diskKey }}">
                            @csrf
                            <div style="margin-bottom:8px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
                                <input type="text" class="form-control input-sm smart-thresh-q" style="width:240px" placeholder="{{ __('Filter by attribute name…') }}" data-table="{{ $tblId }}">
                                <label style="font-weight:normal;margin:0"><input type="checkbox" class="smart-thresh-select-all" data-table="{{ $tblId }}"> {{ __('Select all visible') }}</label>
                            </div>

                            <div class="table-responsive">
                                <table id="{{ $tblId }}" class="table table-condensed table-hover">
                                    <thead>
                                    <tr>
                                        <th></th>
                                        <th>{{ __('ID') }}</th>
                                        <th>{{ __('Name') }}</th>
                                        <th>{{ __('Warn 8h') }}</th>
                                        <th>{{ __('Warn 24h') }}</th>
                                        <th>{{ __('Warn 1wk') }}</th>
                                        <th>{{ __('Warn 1mo') }}</th>
                                        <th>{{ __('Source') }}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach ($items as $item)
                                        <tr data-attribute_id="{{ $item['attribute_id'] }}">
                                            <td><input type="checkbox" class="smart-thresh-row"></td>
                                            <td>{{ $item['attribute_id'] }}</td>
                                            <td>{{ str_replace('_', ' ', (string) $item['name']) }}</td>
                                            <td>{{ $item['warn_rate_8h'] ?? '-' }}</td>
                                            <td>{{ $item['warn_rate_24h'] ?? '-' }}</td>
                                            <td>{{ $item['warn_rate_168h'] ?? '-' }}</td>
                                            <td>{{ $item['warn_rate_672h'] ?? '-' }}</td>
                                            <td>{{ $item['is_override'] ? __('This disk') : __('Global default') }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="panel panel-default" style="margin-top:12px">
                                <div class="panel-heading">{{ __('Apply to selected rows') }}</div>
                                <div class="panel-body" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
                                    <div class="form-group" style="margin:0">
                                        <label>{{ __('Warn 8h') }}</label>
                                        <input type="number" step="any" class="form-control input-sm smart-thresh-8h" style="width:110px">
                                    </div>
                                    <div class="form-group" style="margin:0">
                                        <label>{{ __('Warn 24h') }}</label>
                                        <input type="number" step="any" class="form-control input-sm smart-thresh-24h" style="width:110px">
                                    </div>
                                    <div class="form-group" style="margin:0">
                                        <label>{{ __('Warn 1wk') }}</label>
                                        <input type="number" step="any" class="form-control input-sm smart-thresh-168h" style="width:110px">
                                    </div>
                                    <div class="form-group" style="margin:0">
                                        <label>{{ __('Warn 1mo') }}</label>
                                        <input type="number" step="any" class="form-control input-sm smart-thresh-672h" style="width:110px">
                                    </div>
                                    <div class="form-group" style="margin:0">
                                        <label>{{ __('Scope') }}</label>
                                        <select class="form-control input-sm smart-thresh-scope">
                                            <option value="disk">{{ __('This disk only') }}</option>
                                            <option value="global">{{ __('Global default (all devices)') }}</option>
                                        </select>
                                    </div>
                                    <button type="button" class="btn btn-primary btn-sm smart-thresh-apply" data-table="{{ $tblId }}">{{ __('Apply to selected') }}</button>
                                    <button type="button" class="btn btn-default btn-sm smart-thresh-reset" data-table="{{ $tblId }}" title="{{ __('Delete the threshold row(s) so they inherit the global default again') }}">{{ __('Reset selected to default') }}</button>
                                </div>
                            </div>
                        </form>
                    </div>
                @endforeach
            </div>

            <div class="panel panel-default">
                <div class="panel-heading">{{ __('Copy thresholds to all disks') }}</div>
                <div class="panel-body" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
                    <div class="form-group" style="margin:0">
                        <label>{{ __('Source disk') }}</label>
                        <select id="smart-thresh-source-disk" class="form-control input-sm" style="min-width:220px">
                            @foreach ($diskKeys as $diskKey)
                                <option value="{{ $diskKey }}">{{ $diskLabels[$diskKey] ?? $diskKey }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="button" id="smart-thresh-copy" class="btn btn-default btn-sm">{{ __('Copy to all other disks') }}</button>
                </div>
            </div>
        @endif
    </x-device.page>
@endsection

@push('scripts')
    <script>
        (function () {
            var appId = {{ (int) ($appId ?? 0) }};
            var updateUrl = '{{ $appId !== null ? route('device.apps.smart.settings.update', $device) : '' }}';
            var resetUrl = '{{ $appId !== null ? route('device.apps.smart.settings.reset', $device) : '' }}';
            var copyUrl = '{{ $appId !== null ? route('device.apps.smart.settings.copy', $device) : '' }}';
            var token = '{{ csrf_token() }}';

            var selectedRows = function (form, tbl) {
                var diskKey = form.dataset.disk_key;
                var rows = [];
                tbl.querySelectorAll('tbody tr').forEach(function (row) {
                    var cb = row.querySelector('.smart-thresh-row');
                    if (cb && cb.checked) {
                        rows.push({ disk_key: diskKey, attribute_id: parseInt(row.dataset.attribute_id, 10) });
                    }
                });
                return rows;
            };

            document.querySelectorAll('.smart-thresh-q').forEach(function (input) {
                input.addEventListener('input', function () {
                    var q = this.value.toLowerCase();
                    var tbl = document.getElementById(this.dataset.table);
                    if (!tbl) return;
                    tbl.querySelectorAll('tbody tr').forEach(function (row) {
                        row.style.display = !q || row.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
                    });
                });
            });

            document.querySelectorAll('.smart-thresh-select-all').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    var checked = this.checked;
                    var tbl = document.getElementById(this.dataset.table);
                    if (!tbl) return;
                    tbl.querySelectorAll('tbody tr').forEach(function (row) {
                        if (row.style.display !== 'none') {
                            var rowCb = row.querySelector('.smart-thresh-row');
                            if (rowCb) rowCb.checked = checked;
                        }
                    });
                });
            });

            document.querySelectorAll('.smart-thresh-apply').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var form = this.closest('.smart-threshold-form');
                    var tbl = document.getElementById(this.dataset.table);
                    var rows = selectedRows(form, tbl);
                    if (rows.length === 0) {
                        toastr.error('{{ __('Select at least one row') }}');
                        return;
                    }

                    var numOrNull = function (el) {
                        var v = el.value;
                        return v === '' ? null : parseFloat(v);
                    };

                    $.ajax({
                        type: 'POST',
                        url: updateUrl,
                        dataType: 'json',
                        data: {
                            _token: token,
                            app_id: appId,
                            scope: form.querySelector('.smart-thresh-scope').value,
                            rows: rows,
                            warn_rate_8h: numOrNull(form.querySelector('.smart-thresh-8h')),
                            warn_rate_24h: numOrNull(form.querySelector('.smart-thresh-24h')),
                            warn_rate_168h: numOrNull(form.querySelector('.smart-thresh-168h')),
                            warn_rate_672h: numOrNull(form.querySelector('.smart-thresh-672h')),
                        },
                        success: function (data) {
                            if (data.status === 'ok') {
                                toastr.success(data.message);
                                setTimeout(function () { location.reload(true); }, 1200);
                            } else {
                                toastr.error(data.message);
                            }
                        },
                        error: function () { toastr.error('{{ __('Could not update thresholds') }}'); },
                    });
                });
            });

            document.querySelectorAll('.smart-thresh-reset').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var form = this.closest('.smart-threshold-form');
                    var tbl = document.getElementById(this.dataset.table);
                    var rows = selectedRows(form, tbl);
                    if (rows.length === 0) {
                        toastr.error('{{ __('Select at least one row') }}');
                        return;
                    }

                    $.ajax({
                        type: 'POST',
                        url: resetUrl,
                        dataType: 'json',
                        data: {
                            _token: token,
                            app_id: appId,
                            scope: form.querySelector('.smart-thresh-scope').value,
                            rows: rows,
                        },
                        success: function (data) {
                            if (data.status === 'ok') {
                                toastr.success(data.message);
                                setTimeout(function () { location.reload(true); }, 1200);
                            } else {
                                toastr.error(data.message);
                            }
                        },
                        error: function () { toastr.error('{{ __('Could not reset thresholds') }}'); },
                    });
                });
            });

            document.getElementById('smart-thresh-copy')?.addEventListener('click', function () {
                $.ajax({
                    type: 'POST',
                    url: copyUrl,
                    dataType: 'json',
                    data: {
                        _token: token,
                        app_id: appId,
                        source_disk_key: document.getElementById('smart-thresh-source-disk').value,
                    },
                    success: function (data) {
                        if (data.status === 'error') {
                            toastr.error(data.message);
                        } else {
                            toastr.success(data.message);
                            setTimeout(function () { location.reload(true); }, 1200);
                        }
                    },
                    error: function () { toastr.error('{{ __('Could not copy thresholds') }}'); },
                });
            });
        })();
    </script>
@endpush
