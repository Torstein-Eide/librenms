<?php

use Illuminate\Support\Facades\Request;
use LibreNMS\Util\Time;

?>
<?php if ($relativeMode ?? false): ?>
<div style="text-align: center;">
    <?php
    // Build a GET action URL carrying all current vars except from/to (those are the editable fields).
    $relActionParams = array_filter($vars, static fn ($k) => ! in_array($k, ['from', 'to'], true), ARRAY_FILTER_USE_KEY);
    $relActionParams['page'] = 'graphs';
    $relAction = url('/') . '?' . http_build_query($relActionParams);
    ?>
    <form class="form-inline" method="get" action="<?= htmlspecialchars($relAction) ?>">
        <div class="form-group">
            <label for="relfrom"><?= __('From') ?></label>
            <input type="text"
                   class="form-control"
                   id="relfrom"
                   name="from"
                   value="<?= htmlspecialchars((string) ($graph_array['from'] ?? '-1d')) ?>"
                   style="width: 8em;"
                   placeholder="-1d">
        </div>
        <div class="form-group">
            <label for="relto"><?= __('To') ?></label>
            <input type="text"
                   class="form-control"
                   id="relto"
                   name="to"
                   value="<?= htmlspecialchars((string) ($graph_array['to'] ?? 'now')) ?>"
                   style="width: 8em;"
                   placeholder="now">
        </div>
        <input type="submit"
               class="btn btn-default"
               value="<?= __('Update') ?>">
    </form>
</div>
<?php else: ?>
<div style="text-align: center;">
    <form class="form-inline" id="customrange">
        <input type="hidden" id="selfaction" value="<?php echo Request::url(); ?>">
        <div class="form-group">
        <label for="dtpickerfrom"><?= __('From') ?></label>
            <input type="text"
                   class="form-control"
                   id="dtpickerfrom"
                   maxlength="16"
                   data-date-format="YYYY-MM-DD HH:mm">
        </div>
        <div class="form-group">
        <label for="dtpickerto"><?= __('To') ?></label>
            <input type="text"
                   class="form-control"
                   id="dtpickerto"
                   maxlength=16
                   data-date-format="YYYY-MM-DD HH:mm">
        </div>
        <input type="submit"
               class="btn btn-default"
               id="submit"
               value="<?= __('Update') ?>"
               onclick="submitCustomRange(this.form);">
    </form>
    <script src="<?php echo asset('js/RrdGraphJS/moment-timezone-with-data.js'); ?>"></script>
    <script type="text/javascript">
        $(function () {
            var ds_datefrom = new Date(<?= Time::parseAt($graph_array['from']) ?>*1000);
            var ds_dateto = new Date(<?= Time::parseAt($graph_array['to']) ?>*1000);
            var ds_tz = '<?php echo session('preferences.timezone'); ?>';
            if (ds_tz) {
                ds_datefrom = moment.tz(ds_datefrom, ds_tz);
                ds_dateto = moment.tz(ds_dateto, ds_tz);
            } else {
                ds_datefrom = moment(ds_datefrom);
                ds_dateto = moment(ds_dateto);
            }

            $("#dtpickerfrom").datetimepicker({useCurrent: true, sideBySide: true, useStrict: false, icons: {time: "fa fa-clock-o", date: "fa fa-calendar", up: "fa fa-chevron-up", down: "fa fa-chevron-down", previous: "fa fa-chevron-left", next: "fa fa-chevron-right", today: "fa fa-calendar-check-o", clear: "fa fa-trash-o", close: "fa fa-close"}, defaultDate: ds_datefrom, timeZone: ds_tz});
            $("#dtpickerto").datetimepicker({useCurrent: true, sideBySide: true, useStrict: false, icons: {time: "fa fa-clock-o", date: "fa fa-calendar", up: "fa fa-chevron-up", down: "fa fa-chevron-down", previous: "fa fa-chevron-left", next: "fa fa-chevron-right", today: "fa fa-calendar-check-o", clear: "fa fa-trash-o", close: "fa fa-close"}, defaultDate: ds_dateto, timeZone: ds_tz});
        });
    </script>
</div>
<?php endif; ?>
