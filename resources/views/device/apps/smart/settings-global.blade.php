{{-- Global tab: install-wide attribute-threshold defaults, the global naming
     template, this device's default view mode, and the global-default toggles
     for log-extra-dev-stats / hw-forecast. Included by settings.blade.php.
     Expects $stateBadge from the parent view. --}}
<div class="panel-group" id="smart-settings-global-accordion">
    <div class="panel panel-default">
        <div class="panel-heading">
            <h4 class="panel-title">
                <a data-toggle="collapse" data-parent="#smart-settings-global-accordion" href="#smart-settings-global-thresholds-body">
                    {{ __('ATA Attributes: Rate-of-change thresholds') }}
                </a>
            </h4>
        </div>
        <div id="smart-settings-global-thresholds-body" class="panel-collapse collapse in">
            <div class="panel-body">
                <p class="text-muted">
                    {{ __('Fallback rate-of-change thresholds used by any disk on any device that has no override of its own. "Max" columns show the noisiest disk\'s current measured rate, for reference when picking a threshold.') }}
                </p>
                @if ($globalDefaultItems->isEmpty())
                    <div class="alert alert-info">{{ __('No SMART attributes discovered yet.') }}</div>
                @else
                    @include('device.apps.smart.settings-threshold-table', [
                        'items' => $globalDefaultItems,
                        'diskKey' => '',
                        'isDefaultTab' => true,
                        'device' => $device,
                    ])
                @endif
            </div>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading">
            <h4 class="panel-title">
                <a data-toggle="collapse" data-parent="#smart-settings-global-accordion" href="#smart-settings-naming-global-body">
                    {{ __('Disk naming') }}
                </a>
            </h4>
        </div>
        <div id="smart-settings-naming-global-body" class="panel-collapse collapse">
            <div class="panel-body">
                <p class="text-muted">
                    {{ __('Used by the "Custom" label mode on the overview page and in per-disk graph titles. Available variables:') }}
                    @foreach (['device', 'model', 'serial', 'wwn', 'model_family'] as $var)
                        <a href="#" class="btn btn-default btn-xs smart-naming-var" data-var="{{ $var }}" style="margin:0 2px">${{ $var }}</a>
                    @endforeach
                </p>

                <div class="form-group" style="margin-bottom:0">
                    <label>{{ __('Naming template') }}</label>
                    <input type="text"
                           class="form-control input-sm smart-naming-field"
                           data-disk_key=""
                           data-update-url="{{ route('device.apps.smart.settings.naming_template', $device) }}"
                           placeholder="$device"
                           value="{{ $namingTemplate }}">
                    <p class="text-muted" style="margin:4px 0 0">{{ __('Saved here, but used as the fallback on every device\'s SMART app, not just this one.') }}</p>
                    <div class="text-muted smart-naming-preview" data-disk_key="" style="margin-top:4px"></div>
                </div>
            </div>
        </div>
    </div>

    @if (! empty($viewModes))
        <div class="panel panel-default">
            <div class="panel-heading">
                <h4 class="panel-title">
                    <a data-toggle="collapse" data-parent="#smart-settings-global-accordion" href="#smart-settings-viewmode-body">
                        {{ __('Default view mode') }}
                    </a>
                </h4>
            </div>
            <div id="smart-settings-viewmode-body" class="panel-collapse collapse">
                <div class="panel-body">
                    <div class="form-group" style="margin:0">
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
                </div>
            </div>
        </div>
    @endif

    <div class="panel panel-default">
        <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center">
            <a data-toggle="collapse" data-parent="#smart-settings-global-accordion" href="#smart-settings-extra-stats-global-body">
                {{ __('Log extra Device Statistics to RRD') }}
            </a>
            {!! $stateBadge($logExtraDevStatsGlobal, 'smart-log-extra-dev-stats-global-badge') !!}
        </div>
        <div id="smart-settings-extra-stats-global-body" class="panel-collapse collapse">
            <div class="panel-body">
                <p class="text-muted" style="margin:0 0 6px">
                    {{ __('Adds a fixed set of ATA Device Statistics (GP Log 0x04) counters to each disk\'s RRD file, when present on that disk. Applies to every device that has no override of its own.') }}
                </p>
                <div class="checkbox">
                    <label>
                        <input type="checkbox"
                               class="smart-toggle-switch"
                               id="smart-log-extra-dev-stats-global"
                               data-update-url="{{ route('device.apps.smart.settings.log_extra_dev_stats', $device) }}"
                               @checked($logExtraDevStatsGlobal)>
                        {{ __('Enabled by default') }}
                    </label>
                </div>
            </div>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center">
            <a data-toggle="collapse" data-parent="#smart-settings-global-accordion" href="#smart-settings-hw-forecast-global-body">
                {{ __('Enable Holt-Winters forecasting') }}
            </a>
            {!! $stateBadge($enableHwForecastGlobal, 'smart-hw-forecast-global-badge') !!}
        </div>
        <div id="smart-settings-hw-forecast-global-body" class="panel-collapse collapse">
            <div class="panel-body">
                <p class="text-muted" style="margin:0 0 6px">
                    {{ __('Stores SMART data with RRDtool Holt-Winters trend prediction enabled for every numeric attribute (except temperature, which has its own graph). Applies to every device that has no override of its own. Because of how RRDtool works, this changes the storage format for the whole disk file, not just the predicted metrics, and RRAs cannot be added to an existing file: if a disk\'s RRD file predates the setting, enabling it has no effect until that file is deleted so it can be recreated -- this loses that disk\'s existing history. Prediction bands become meaningful after ~2 days of data.') }}
                </p>
                <div class="checkbox">
                    <label>
                        <input type="checkbox"
                               class="smart-toggle-switch"
                               id="smart-hw-forecast-global"
                               data-update-url="{{ route('device.apps.smart.settings.hw_forecast', $device) }}"
                               @checked($enableHwForecastGlobal)>
                        {{ __('Enabled by default') }}
                    </label>
                </div>
            </div>
        </div>
    </div>
</div>
