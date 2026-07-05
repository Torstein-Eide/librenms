{{-- Device tab: per-disk attribute thresholds, per-disk naming overrides, and this
     device's override toggles for log-extra-dev-stats / hw-forecast. Included by
     settings.blade.php. Expects $stateBadge from the parent view. --}}
<div class="panel-group" id="smart-settings-device-accordion">
    <div class="panel panel-default">
        <div class="panel-heading">
            <h4 class="panel-title">
                <a data-toggle="collapse" data-parent="#smart-settings-device-accordion" href="#smart-settings-thresholds-device-body">
                    {{ __('ATA Attributes: Rate-of-change thresholds') }}
                </a>
            </h4>
        </div>
        <div id="smart-settings-thresholds-device-body" class="panel-collapse collapse in">
            <div class="panel-body">
                <p class="text-muted">
                    {{ __('Rate-of-change thresholds (raw value change per hour) used to flag an attribute with a rate warning. Edits save immediately. A disk row with no override falls back to the "Global" tab\'s defaults. "Avg" columns show the attribute\'s current measured rate, for reference when picking a threshold.') }}
                </p>

                @if (empty($diskKeys))
                    <div class="alert alert-info">{{ __('No per-disk SMART attributes discovered yet for this device.') }}</div>
                @else
                    @php
                        $isBreached = static function ($item, $window) {
                            $rateVal = $item['rate_' . $window];
                            $warnVal = $item['warn_rate_' . $window];

                            return is_numeric($rateVal) && is_numeric($warnVal) && (float) $rateVal >= (float) $warnVal;
                        };
                        // Which tabs have at least one breached attribute, so the tab itself is
                        // flagged without having to click into each disk.
                        $diskHasBreach = [];
                        foreach ($itemsByDisk as $breachDiskKey => $breachItems) {
                            foreach ($breachItems as $breachItem) {
                                foreach (['8h', '24h', '168h', '672h'] as $breachWindow) {
                                    if ($isBreached($breachItem, $breachWindow)) {
                                        $diskHasBreach[$breachDiskKey] = true;
                                        break 2;
                                    }
                                }
                            }
                        }
                    @endphp
                    <ul class="nav nav-tabs" role="tablist">
                        @foreach ($diskKeys as $i => $diskKey)
                            @php $tabId = 'smart-thresh-disk-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $diskKey ?: 'default'); @endphp
                            <li role="presentation" class="{{ $i === 0 ? 'active' : '' }}">
                                <a href="#{{ $tabId }}" aria-controls="{{ $tabId }}" role="tab" data-toggle="tab" class="{{ ! empty($diskHasBreach[$diskKey]) ? 'text-danger' : '' }}">{{ $diskLabels[$diskKey] ?? $diskKey }}</a>
                            </li>
                        @endforeach
                    </ul>

                    <div class="tab-content" style="margin-top:12px">
                        @foreach ($diskKeys as $i => $diskKey)
                            @php $tabId = 'smart-thresh-disk-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $diskKey ?: 'default'); @endphp
                            <div role="tabpanel" class="tab-pane {{ $i === 0 ? 'active' : '' }}" id="{{ $tabId }}">
                                @include('device.apps.smart.settings-threshold-table', [
                                    'items' => $itemsByDisk[$diskKey] ?? collect(),
                                    'diskKey' => $diskKey,
                                    'isDefaultTab' => false,
                                    'device' => $device,
                                ])
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading">
            <h4 class="panel-title">
                <a data-toggle="collapse" data-parent="#smart-settings-device-accordion" href="#smart-settings-naming-device-body">
                    {{ __('Disk naming overrides') }}
                </a>
            </h4>
        </div>
        <div id="smart-settings-naming-device-body" class="panel-collapse collapse">
            <div class="panel-body">
                <p class="text-muted">
                    {{ __('Used by the "Custom" label mode on the overview page and in per-disk graph titles. Available variables:') }}
                    @foreach (['device', 'model', 'serial', 'wwn', 'model_family'] as $var)
                        <a href="#" class="btn btn-default btn-xs smart-naming-var" data-var="{{ $var }}" style="margin:0 2px">${{ $var }}</a>
                    @endforeach
                </p>

                @if (! empty($diskFields))
                    <label>{{ __('Per-drive overrides (blank = inherit the global template on the Global tab)') }}</label>
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
                                           title="{{ __('Clear this disk\'s override so it inherits the global template again') }}">{{ __('Reset') }}</a>
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

    <div class="panel panel-default">
        <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center">
            <a data-toggle="collapse" data-parent="#smart-settings-device-accordion" href="#smart-settings-extra-stats-device-body">
                {{ __('Log extra Device Statistics to RRD') }}
            </a>
            {!! $stateBadge($logExtraDevStatsOverride ?? $logExtraDevStatsGlobal, 'smart-log-extra-dev-stats-badge') !!}
        </div>
        <div id="smart-settings-extra-stats-device-body" class="panel-collapse collapse">
            <div class="panel-body">
                <p class="text-muted" style="margin:0 0 6px">
                    {{ __('Adds a fixed set of ATA Device Statistics (GP Log 0x04) counters to each disk\'s RRD file, when present on that disk.') }}
                </p>
                <div class="checkbox">
                    <label>
                        <input type="checkbox"
                               class="smart-toggle-switch"
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
        <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center">
            <a data-toggle="collapse" data-parent="#smart-settings-device-accordion" href="#smart-settings-hw-forecast-device-body">
                {{ __('Enable Holt-Winters forecasting') }}
            </a>
            {!! $stateBadge($enableHwForecastOverride ?? $enableHwForecastGlobal, 'smart-hw-forecast-badge') !!}
        </div>
        <div id="smart-settings-hw-forecast-device-body" class="panel-collapse collapse">
            <div class="panel-body">
                <p class="text-muted" style="margin:0 0 6px">
                    {{ __('Stores this disk\'s SMART data with RRDtool Holt-Winters trend prediction enabled for every numeric attribute (except temperature, which has its own graph). Because of how RRDtool works, this changes the storage format for the whole disk file, not just the predicted metrics, and RRAs cannot be added to an existing file: if this disk\'s RRD file predates the setting, enabling it has no effect until that file is deleted (check the device\'s Eventlog for a notice when this applies) so it can be recreated -- this loses the disk\'s existing history. Prediction bands become meaningful after ~2 days of data.') }}
                </p>
                <div class="checkbox">
                    <label>
                        <input type="checkbox"
                               class="smart-toggle-switch"
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

    <div class="panel panel-default">
        <div class="panel-heading">
            <h4 class="panel-title">
                <a data-toggle="collapse" data-parent="#smart-settings-device-accordion" href="#smart-settings-excluded-attrs-device-body">
                    {{ __('Rotating Wear Sensor: Excluded Attributes') }}
                </a>
            </h4>
        </div>
        <div id="smart-settings-excluded-attrs-device-body" class="panel-collapse collapse">
            <div class="panel-body">
                @if (empty($diskKeys))
                    <div class="alert alert-info">{{ __('No per-disk SMART attributes discovered yet for this device.') }}</div>
                @else
                    <ul class="nav nav-tabs" role="tablist">
                        @foreach ($diskKeys as $i => $diskKey)
                            @php $tabId = 'smart-excluded-attrs-disk-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $diskKey ?: 'default'); @endphp
                            <li role="presentation" class="{{ $i === 0 ? 'active' : '' }}">
                                <a href="#{{ $tabId }}" aria-controls="{{ $tabId }}" role="tab" data-toggle="tab">{{ $diskLabels[$diskKey] ?? $diskKey }}</a>
                            </li>
                        @endforeach
                    </ul>

                    <div class="tab-content" style="margin-top:12px">
                        @foreach ($diskKeys as $i => $diskKey)
                            @php
                                $tabId = 'smart-excluded-attrs-disk-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $diskKey ?: 'default');
                                $scope = 'disk';
                                $entries = $excludedAttributesByDisk[$diskKey] ?? [];
                                $isReset = ! ($excludedAttributesHasOverride[$diskKey] ?? false);
                            @endphp
                            <div role="tabpanel" class="tab-pane {{ $i === 0 ? 'active' : '' }}" id="{{ $tabId }}">
                                @include('device.apps.smart.settings-excluded-attributes')
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
