{{-- Attribute warning-threshold table for one scope: a real disk_key (Device tab) or the
     global-default row, disk_key='' (Global tab). Included by settings.blade.php. --}}
@php
    $fmtAvg = static function ($v) {
        return is_numeric($v) ? number_format((float) $v, 1) : '-';
    };
    $isBreached = static function ($item, $window) {
        $rateVal = $item['rate_' . $window];
        $warnVal = $item['warn_rate_' . $window];

        return is_numeric($rateVal) && is_numeric($warnVal) && (float) $rateVal >= (float) $warnVal;
    };
    $scope = $isDefaultTab ? 'global' : 'disk';
@endphp
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
                    @php $breached = $isBreached($item, $window); @endphp
                    <td class="{{ $breached ? 'danger' : 'text-muted' }}">{{ $fmtAvg($item['rate_' . $window]) }}</td>
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
