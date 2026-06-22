@php
    use App\Facades\LibrenmsConfig;
    use LibreNMS\Enum\Severity;
    use LibreNMS\Util\Number;

    /** @var \LibreNMS\Agent\Unix\Smart\HtmlData $data */

    $deviceId  = (int) $data->device['device_id'];

    // Reached directly via the dedicated device.apps.smart(.compare) routes, which always
    // pass smartPage explicitly — as opposed to the legacy device=X/tab=apps/app=smart route,
    // which already renders the device's "Apps »" selector panel before including this view.
    $viaDedicatedRoute = isset($smartPage);
    $smartPage = $smartPage ?? 'overview';

    // Builds a URL back to this same SMART app page, optionally for a specific disk.
    // Disk URLs use the disk's device_name (e.g. "sda", "nvme0") when known, falling back
    // to its disk_key — see HtmlData::diskUrlId()/resolveDiskKey().
    $smartUrl = static fn (?string $disk = null): string => route(
        'device.apps.smart',
        $disk !== null ? [$deviceId, 'disk' => $data->diskUrlId($disk)] : $deviceId
    );

    // Persisted display modes (cookie-backed, per device).
    $labelCookie = 'smart_label_mode_' . $deviceId;
    $labelModes  = $data->labelModes();
    $labelMode   = (isset($_COOKIE[$labelCookie]) && isset($labelModes[$_COOKIE[$labelCookie]]))
        ? $_COOKIE[$labelCookie] : 'device';

    $viewCookie = 'smart_disk_view_mode_' . $deviceId;
    $viewModes  = $data->diskViewModes();
    $viewMode   = (isset($_COOKIE[$viewCookie]) && isset($viewModes[$_COOKIE[$viewCookie]]))
        ? $_COOKIE[$viewCookie] : $data->defaultViewMode();

    // -------------------------------------------------------------------------
    // HTML helpers (closures)
    // -------------------------------------------------------------------------
    $panelStart = static function (string $title, string $badge = ''): void {
        $badgeHtml = $badge !== '' ? "<span class=\"pull-right\">{$badge}</span>" : '';
        echo "<div class=\"panel panel-default\"><div class=\"panel-heading\"><h3 class=\"panel-title\">{$title}{$badgeHtml}</h3></div><div class=\"panel-body\">";
    };
    $panelEnd = static function (): void {
        echo '</div></div>';
    };
    $tableRow = static function (string $label, string $value, string $tooltip = ''): string {
        $labelHtml = $tooltip !== ''
            ? '<abbr style="cursor:help;text-decoration:underline dotted" title="' . htmlspecialchars($tooltip, ENT_QUOTES) . '">' . htmlspecialchars($label) . '</abbr>'
            : htmlspecialchars($label);
        return '<tr><td style="text-align:right;padding-right:15px;white-space:nowrap"><strong>'
            . "{$labelHtml}</strong></td><td>{$value}</td></tr>\n";
    };
    $smartTooltips = [
        'offline data collection' => 'An old ATA SMART background routine that may collect or refresh some drive health data when the drive is idle. '
            . 'Disabled or Never started does not mean SMART is disabled, broken, or not updating — power-on hours, temperature, error counters, '
            . 'reallocated/pending sectors, and self-test logs may still update normally. It only means this specific automatic routine is disabled or has not run.',
        'auto offline data collection' => 'An old ATA SMART background routine that may collect or refresh some drive health data when the drive is idle. '
            . 'Disabled or Never started does not mean SMART is disabled, broken, or not updating — power-on hours, temperature, error counters, '
            . 'reallocated/pending sectors, and self-test logs may still update normally. It only means this specific automatic routine is disabled or has not run.',
        'power cycles' => 'Counts power-on resets or unique device startups during system boot.',
        'lifetime power on resets' => 'Counts power-on resets or unique device startups during system boot.',
        'power on hours' => 'Tracks the number of hours the device has been powered on; HDD spindle and head-load time may differ.',
        'spin power on hours' => 'Hours an HDD spindle motor has been spinning the platters.',
        'logical sectors written' => 'Counts logical sectors written. Multiply by the logical sector size to estimate bytes written.',
        'number of write commands' => 'Counts write commands to user-space sectors; one command can transfer one or many sectors.',
        'logical sectors read' => 'Counts logical sectors read. Multiply by the logical sector size to estimate bytes read.',
        'number of read commands' => 'Counts read commands to user-space sectors; one command can transfer one or many sectors.',
        'date and time timestamp' => 'Device timestamp programmed by the host via the "Set Date and Time Timestamp" command, measured as milliseconds since the Unix epoch.',
        'pending defects' => 'Sectors currently on the pending defect list until rewrite or reallocation resolves them.',
        'pending error count' => 'Counts unique sectors that reported read errors and are waiting for rewrite or reallocation.',
        'workload utilization' => 'Drive firmware percentage showing use relative to the vendor-rated workload.',
        'utilization usage rate' => 'Similar to workload utilization, calculated from manufacture time to the programmed timestamp.',
        'resource availability' => 'Percentage of internal drive resources available for workload handling, updated hourly.',
        'random write resources used' => 'Random-write-specific resource usage; high usage can degrade performance until workload drops.',
        'free fall events' => 'Counts detected free-fall events; drives with this feature may emergency-retract heads for protection.',
        'number of free fall events detected' => 'Counts detected free-fall events; drives with this feature may emergency-retract heads for protection.',
        'overlimit shock events' => 'Counts shock events whose magnitude exceeded the device maximum rating.',
        'spindle motor power on hours' => 'Hours an HDD spindle motor has been spinning the platters.',
        'head flying hours' => 'Hours HDD heads have been flying over the media, including idle time before unload or park.',
        'head load events' => 'Counts moves from parked heads to the media, such as standby-to-active transitions.',
        'number of reallocated logical sectors' => 'Counts logical sectors reallocated after manufacture because the original location was unusable.',
        'reallocated logical sectors' => 'Counts logical sectors reallocated after manufacture because the original location was unusable.',
        'read recovery attempts' => 'Counts reads that required three or more attempts to recover data from media.',
        'number of mechanical start failures' => 'Counts HDD startup failures where the device could not start normally.',
        'mechanical start failures' => 'Counts HDD startup failures where the device could not start normally.',
        'number of reallocation candidate logical sectors' => 'Counts sectors selected for future reallocation when next written.',
        'reallocation candidate logical sectors' => 'Counts sectors selected for future reallocation when next written.',
        'number of high priority unload events' => 'Counts emergency head unload events, such as unexpected power loss or self-protection.',
        'high priority unload events' => 'Counts emergency head unload events, such as unexpected power loss or self-protection.',
        'number of reported uncorrectable errors' => 'Counts each host read that reported an uncorrectable error, not unique sectors.',
        'reported uncorrectable errors' => 'Counts each host read that reported an uncorrectable error, not unique sectors.',
        'cumulative lifetime unrecoverable errors' => 'Counts unrecoverable errors accumulated over the drive lifetime.',
        'number of resets between command acceptance and command completion' => 'Counts resets after command acceptance but before completion, often seen as command timeouts.',
        'resets between command acceptance and command completion' => 'Counts resets after command acceptance but before completion, often seen as command timeouts.',
        'physical element status changed' => 'Counts physical elements, such as HDD heads, whose health moved outside manufacturer limits.',
        'current temperature' => 'Current device temperature at read time; SATA/SAS report Celsius and NVMe reports Kelvin.',
        'curent temp' => 'Current device temperature at read time.',
        'current temp' => 'Current device temperature at read time.',
        'average temp' => 'Short-term average temperature based on recent samples.',
        'average short term temperature' => 'Average of the most recent 144 ten-minute samples over a 24-hour period.',
        'average long term temperature' => 'Average of the most recent 42 short-term daily averages; valid after about 1008 hours.',
        'highest temperature' => 'Highest temperature recorded by the device since manufacture.',
        'highest temp' => 'Highest temperature recorded by the device since manufacture.',
        'lowest temperature' => 'Lowest temperature recorded by the device since manufacture.',
        'lowest temp' => 'Lowest temperature recorded by the device since manufacture.',
        'highest average short term temperature' => 'Highest recorded short-term average temperature.',
        'highest short temp' => 'Highest recorded short-term average temperature.',
        'lowest average short term temperature' => 'Lowest recorded short-term average temperature.',
        'lowest short temp' => 'Lowest recorded short-term average temperature.',
        'highest average long term temperature' => 'Highest recorded long-term average temperature.',
        'highest long temp' => 'Highest recorded long-term average temperature.',
        'lowest average long term temperature' => 'Lowest recorded long-term average temperature.',
        'lowest long temp' => 'Lowest recorded long-term average temperature.',
        'time in over temperature' => 'Minutes operated above the manufacturer-specified maximum operating temperature.',
        'over temp time' => 'Minutes operated above the manufacturer-specified maximum operating temperature.',
        'time in under temperature' => 'Minutes operated below the manufacturer-specified minimum operating temperature.',
        'under temp time' => 'Minutes operated below the manufacturer-specified minimum operating temperature.',
        'specified maximum operating temperature' => 'Manufacturer-specified maximum operating temperature for the device.',
        'max temp' => 'Manufacturer-specified maximum operating temperature for the device.',
        'specified minimum operating temperature' => 'Manufacturer-specified minimum operating temperature for the device.',
        'min temp' => 'Manufacturer-specified minimum operating temperature for the device.',
        'number of hardware resets' => 'Counts hardware resets received by the device; on SATA this includes COMRESETs.',
        'hardware resets' => 'Counts hardware resets received by the device; on SATA this includes COMRESETs.',
        'number of asr events' => 'Counts Asynchronous Signal Recovery events when interface signaling is lost.',
        'asr events' => 'Counts Asynchronous Signal Recovery events when interface signaling is lost.',
        'number of interface crc errors' => 'Counts interface CRC errors detected since manufacture.',
        'interface crc errors' => 'Counts interface CRC errors detected since manufacture.',
        'percentage used endurance indicator' => 'SSD lifetime estimate used by manufacturer prediction; 100% means expected life consumed, not necessarily failed.',
        'commands by disk radius' => 'Read and write command counts grouped by their approximate disk-radius location.',
        'estimated lifetime' => 'Projected total service life, extrapolated from the wear consumed so far against the drive\'s power-on age. Assumes a constant wear rate; actual results vary with workload.',
        'dwpd' => 'Drive Writes Per Day: average bytes written per day, divided by the drive\'s capacity, since it was first powered on.',
        'power state' => 'The drive\'s live power-saving state as of the agent\'s last poll. Only probed for ATA/SATA/SCSI/SAS in collect mode with standby checking enabled; otherwise reports Unknown or Active rather than a genuine reading.',
    ];
    $tooltipForLabel = static function (string $label) use ($smartTooltips): string {
        $key = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', html_entity_decode($label, ENT_QUOTES))));

        if (isset($smartTooltips[$key])) {
            return $smartTooltips[$key];
        }

        return match (true) {
            $key === 'model number' => 'The device model number. Matches Identify or Inquiry data.',
            $key === 'serial number' => 'The device serial number. Matches Identify or Unit Serial Number VPD data.',
            $key === 'firmware revision' => 'Current firmware revision of the device. Matches Identify or Inquiry data.',
            $key === 'world wide name' => 'Device-unique world wide name. Matches Identify or Device Identification VPD data.',
            $key === 'date of assembly' => 'The date the drive was assembled, reported as week and year.',
            $key === 'device interface' => 'String describing the device interface, such as SATA, SAS, or NVMe.',
            $key === 'capacity' => 'Device capacity in logical blocks. Matches Identify data or Read Capacity data.',
            $key === 'number of lbas hsmr swr capacity' => 'Number of LBAs on a host-managed SMR drive configured for Sequential Write Required.',
            $key === 'physical sector size' => 'Physical sector size in bytes.',
            $key === 'logical sector size' => 'Logical sector size in bytes.',
            $key === 'device buffer size' => 'Device buffer or cache size in bytes.',
            $key === 'number of heads' => 'Number of heads in the head disk assembly.',
            $key === 'drive recording type' => 'Reports whether the drive uses CMR, SMR, or a combination.',
            $key === 'form factor' => 'Drive form factor. Matches the form factor reported in Identify data.',
            $key === 'rotation rate' => 'Drive rotation rate. Matches Identify data or Block Device Characteristics VPD data.',
            $key === 'ata security state' => 'SATA only. Copy of Identify word 128.',
            $key === 'ata features supported' => 'SATA only. Copy of Identify word 78.',
            $key === 'ata features enabled' => 'SATA only. Copy of Identify word 79.',
            $key === 'spindle power on hours' => 'Hours an HDD spindle motor has been spinning the platters.',
            $key === 'head flight hours' => 'Hours HDD heads have been flying over the media; multi-actuator drives may report this per actuator.',
            $key === 'power cycle count' => 'Counts power-on resets or unique device startups during system boot.',
            $key === 'hardware reset count' => 'Counts hardware resets received by the device; on SATA this includes COMRESETs.',
            $key === 'spin up time' => 'Time in milliseconds the device takes to spin up.',
            $key === 'time to ready' => 'Time the drive took to become ready during the last power cycle, in milliseconds.',
            $key === 'time to ready last power cycle' => 'Time the drive took to become ready during the last power cycle, in milliseconds.',
            $key === 'time held' => 'Time the drive was held in staggered spin-up before being commanded to spin up, in milliseconds.',
            $key === 'time in staggered spin up last power on' => 'Time the drive was held in staggered spin-up before being commanded to spin up, in milliseconds.',
            $key === 'nvc status at power on' => 'SAS only. Status of the non-volatile cache at power on.',
            $key === 'time available to save user data to nvmem' => 'Time, in 100us units, available to save user data to media for non-volatile cache handling.',
            $key === 'lowest poh timestamp' => 'For time-bounded parameters, the lower-bound timestamp in hours.',
            $key === 'highest poh timestamp' => 'For time-bounded parameters, the upper-bound timestamp in hours.',
            $key === 'depopulate status' => 'Storage Element Depopulation status. Reports Depopulated if a head has been depopulated, otherwise Not Depopulated.',
            $key === 'depopulation head mask' => 'Bitmask indicating which specific heads have been depopulated.',
            $key === 'depopulated head mask' => 'Bitmask indicating which specific heads have been depopulated.',
            $key === 'regenerate head mask' => 'Mask of heads marked for regeneration.',
            $key === 'physical element status' => 'Per-head physical element status. Matches Get Physical Element Status output.',
            $key === 'max number for reasign' => 'Maximum disc sectors available for reassigning bad LBAs.',
            $key === 'max number for reassign' => 'Maximum disc sectors available for reassigning bad LBAs.',
            $key === 'maximum number of available disc sectors for reassignment' => 'Maximum disc sectors available for reassigning bad LBAs.',
            $key === 'hamr data protect status' => 'On HAMR drives, indicates the drive entered data-protect mode, meaning write protected.',
            $key === 'poh of most recent farm time series frame' => 'Power-on-hours timestamp in the most recent time-based FARM frame.',
            $key === 'poh of 2nd most recent farm time series frame' => 'Power-on-hours timestamp in the second most recent time-based FARM frame.',
            $key === 'seq or before req for active zone config' => 'Sequential or Before Required active-zone configuration on host-managed SMR drives.',
            $key === 'seq write req active zone config' => 'Sequential Write Required active-zone configuration on host-managed SMR drives.',
            $key === 'rated workload' => 'Percentage based on collected drive workload data. Obsolete on newer drives.',
            str_contains($key, 'random read commands') => 'Total random read commands to user LBA space; verify commands are not included.',
            str_contains($key, 'random write commands') => 'Total random write commands to user LBA space; write-verify and write-same commands are not included.',
            str_contains($key, 'total') && str_contains($key, 'read commands') => 'Total read commands to user LBA space; verify commands are not included.',
            str_contains($key, 'total') && str_contains($key, 'write commands') => 'Total write commands to user LBA space; write-verify and write-same commands are not included.',
            str_contains($key, 'other commands') => 'Total commands that are not reads or writes.',
            $key === 'lbas written' => 'Logical sectors that have received a write. Multiply by logical sector size to estimate bytes written.',
            $key === 'lbas read' => 'Logical sectors that have received a read. Multiply by logical sector size to estimate bytes read.',
            $key === 'dither' => 'Intentionally added random noise used to randomize and smooth out errors in digital processing; this counts events in the current power cycle.',
            $key === 'dither random' => 'Number of times dithering was held off due to random workloads in the current power cycle.',
            $key === 'dither sequential' => 'Number of times dithering was held off due to sequential workloads in the current power cycle.',
            str_contains($key, 'dither events') => 'Number of times dithering was performed in the current power cycle.',
            str_contains($key, 'dither pause random') => 'Number of times dithering was held off due to random workloads in the current power cycle.',
            str_contains($key, 'dither pause sequential') => 'Number of times dithering was held off due to sequential workloads in the current power cycle.',
            str_contains($key, 'r cmds between') => 'Count of read commands in the displayed user-LBA-space range for the covered time period.',
            str_contains($key, 'w cmds between') => 'Count of write commands in the displayed user-LBA-space range for the covered time period.',
            str_contains($key, 'r cmds with xfer') => 'Count of read commands whose transfer length falls in the displayed bin.',
            str_contains($key, 'w cmds with xfer') => 'Count of write commands whose transfer length falls in the displayed bin.',
            str_contains($key, 'queue depth') && str_contains($key, 'intervals') => 'Number of 30-second intervals where queue depth was in the displayed range.',
            str_contains($key, 'reads of xfer bin') => 'Reads that fall into the displayed transfer-length bin of the last three SMART Summary Frames.',
            str_contains($key, 'writes of xfer bin') => 'Writes that fall into the displayed transfer-length bin of the last three SMART Summary Frames.',
            str_contains($key, 'time that commands cover') => 'Number of hours covered by the related read/write command statistics.',
            str_contains($key, 'time that queue bins cover') => 'Number of hours covered by the queue-depth bin statistics.',
            str_contains($key, 'time that xfer bins cover') => 'Number of hours covered by the transfer-length bin statistics.',
            str_contains($key, 'unrecoverable read errors due to erc') => 'Error Recovery Control timeout prevented further read retries, so the command was considered an unrecoverable read error.',
            str_contains($key, 'unrecoverable read errors') => 'Total unrecoverable read command errors, including repeated errors at a given LBA.',
            str_contains($key, 'unrecoverable write errors') => 'Total unrecoverable write command errors, including repeated errors at a given LBA.',
            $key === 'number of reallocated candidate sectors' => 'Reallocation-candidate sectors. FARM may report this per actuator and as disc sectors rather than whole-drive logical sectors.',
            str_contains($key, 'reallocated sectors') && ! str_contains($key, 'farm time series frame') => 'Reallocated sectors. FARM may report this per actuator and as disc sectors rather than whole-drive logical sectors.',
            $key === 'total asr events' => 'Counts Asynchronous Signal Recovery events when interface signaling is lost. SATA only.',
            $key === 'total crc errors' => 'Counts interface CRC errors detected since manufacture.',
            str_contains($key, 'read recovery attempts') => 'Logical sectors that required three or more attempts to recover data from media.',
            str_contains($key, 'mechanical start retries') => 'Retries attempted to get the spindle motor spinning and up to speed; not the same as drive-level mechanical start failures.',
            $key === 'attr spin retry count' => 'SATA only. Raw Seagate SMART spin-retry counter for spindle-motor spin-up retries.',
            $key === 'spin retry count' => 'SATA only. Raw Seagate SMART spin-retry counter for spindle-motor spin-up retries.',
            $key === 'normal spin retry count' => 'SATA only. Normalized/current value field from the Seagate SMART spin-retry attribute.',
            $key === 'normalized spin retry count' => 'SATA only. Nominal/current value field from the Seagate SMART spin-retry attribute.',
            $key === 'worst spin rretry count' => 'SATA only. Worst-ever value field from the Seagate SMART spin-retry attribute.',
            $key === 'worst spin retry count' => 'SATA only. Worst-ever value field from the Seagate SMART spin-retry attribute.',
            $key === 'worst ever spin retry count' => 'SATA only. Worst-ever value field from the Seagate SMART spin-retry attribute.',
            str_contains($key, 'ioedc errors') => 'SATA only. Input/Output Error Detection Code errors detected in Seagate end-to-end data protection.',
            $key === 'command time out count total' => 'SATA only. Command timeout counter related to resets between command acceptance and completion.',
            $key === 'command time out over 7 seconds count' => 'SATA only. Command timeouts over 7.5 seconds; matches a Seagate SMART raw-data field.',
            $key === 'command time out over 5 seconds count' => 'SATA only. Command timeouts over 5 seconds; matches a Seagate SMART raw-data field.',
            str_contains($key, 'command timeouts 7 5 seconds') => 'SATA only. Command timeouts over 7.5 seconds; matches a Seagate SMART raw-data field.',
            str_contains($key, 'command timeouts 5 seconds') => 'SATA only. Command timeouts over 5 seconds; matches a Seagate SMART raw-data field.',
            str_contains($key, 'command timeouts') => 'SATA only. Command timeout counter related to resets between command acceptance and completion.',
            $key === 'fru of smart trip most recent frame' => 'SAS only. Field replaceable unit code associated with the most recently logged SMART trip.',
            preg_match('/port [ab] invalid dword count/', $key) === 1 => 'SAS only. Count of invalid interface dwords on the displayed port.',
            preg_match('/port [ab] disparity error count/', $key) === 1 => 'SAS only. Count of data-encoding mismatches between the drive and host path on the displayed port.',
            preg_match('/port [ab] loss of dword sync/', $key) === 1 => 'SAS only. Count of synchronization losses between the drive and host path on the displayed port.',
            preg_match('/port [ab] phy reset problem/', $key) === 1 => 'SAS only. Count of PHY resets caused by error conditions on the displayed port.',
            $key === 'total flash led' => 'Flash LED means a firmware error occurred and set an error code. These are severe errors. The term is a holdover from when drive LEDs blinked error-code patterns, though the LED no longer exists.',
            $key === 'index flash led' => 'Flash LED means a firmware error occurred and set an error code. These are severe errors. The term is a holdover from when drive LEDs blinked error-code patterns, though the LED no longer exists.',
            $key === 'total flash led errors' => 'Flash LED means a firmware error occurred and set an error code. These are severe errors. The term is a holdover from when drive LEDs blinked error-code patterns, though the LED no longer exists.',
            str_contains($key, 'flash led info') => 'Flash LED means a firmware error occurred and set an error code. These are severe errors. The term is a holdover from when drive LEDs blinked error-code patterns, though the LED no longer exists.',
            $key === 'uncorrectables' => 'Unrecoverable errors reported by FARM error data, including errors that may repeat at a given LBA.',
            $key === 'cumulative unrecoverable read erc' => 'Error Recovery Control timeout prevented further read retries, so the command was considered an unrecoverable read error.',
            str_contains($key, 'cumulative lifetime unrecoverable read repeating') => 'Unrecoverable errors that repeat at the same sector due to host read retries.',
            str_contains($key, 'cumulative lifetime unrecoverable read unique') => 'Uniquely identified unrecoverable errors first encountered at a given LBA.',
            str_contains($key, 'smart trip flags') => 'Bit field representing SMART trips that occurred.',
            str_contains($key, 'reallocated sectors since last farm time series frame') => 'Sectors reallocated since the last FARM time-series snapshot was taken.',
            str_contains($key, 'reallocated sectors between n n 1 farm time series frame') => 'Sectors reallocated between the last two FARM time-series snapshots.',
            str_contains($key, 'reallocation candidate sectors since last farm time series frame') => 'Reallocation-candidate sectors since the last FARM time-series snapshot was saved.',
            str_contains($key, 'reallocation candidate between n n 1 farm time series frame') => 'Reallocation-candidate sectors between the last two FARM time-series snapshots.',
            str_contains($key, 'unique unrecoverable sectors since last farm time series frame') => 'Uniquely identified unrecoverable sectors since the last FARM time-series snapshot was saved.',
            str_contains($key, 'unique unrecoverable sectors between n n 1 farm time series frame') => 'Uniquely identified unrecoverable sectors between the last two FARM time-series snapshots.',
            $key === 'current relative humidity' => 'Current relative humidity percentage, from 0-100%, within the head disk enclosure.',
            $key === 'current motor power scalar' => 'Current power scalar value used by the servo to keep the motor spinning.',
            $key === 'time coverage for motor power hours' => 'Number of hours covered by the Current Motor Power statistic value.',
            $key === 'time coverage for 12v 5v voltage hours' => 'Number of hours covered by the 12V and 5V voltage readings.',
            $key === 'time coverage for 12v 5v power hours' => 'Number of hours covered by the 12V and 5V power readings.',
            str_contains($key, 'dos scans performed') => 'Total Directed Offline Scan scans performed.',
            str_contains($key, 'dos ought to scan') => 'Number of times Directed Offline Scan is recommended to scan an area during idle operations.',
            str_contains($key, 'dos need to scan') => 'Number of times Directed Offline Scan has been marked as needing to scan an area during idle operations.',
            str_contains($key, 'dos write fault scans') => 'Number of Directed Offline Scan runs caused by write faults exceeding an unsafe limit for adjacent tracks.',
            str_contains($key, 'lbas corrected by isp') => 'Logical sectors corrected due to Intermediate Super Parity.',
            str_contains($key, 'lbas corrected by parity sector') => 'LBAs corrected by use of a parity sector.',
            str_contains($key, 'dvga skip write detect') => 'Number of times a write operation was stopped due to Delta Variable Gain Amplifier readings.',
            str_contains($key, 'rvga skip write detect') => 'Number of times a write operation was stopped due to Running Average Variable Gain Amplifier readings.',
            str_contains($key, 'fvga skip write detect') => 'Number of times a write operation was stopped due to Filter Variable Gain Amplifier readings.',
            str_contains($key, 'skip write detect threshold exceeded') => 'Number of times a write was stopped because a servo sample indicated higher fly height than expected.',
            str_contains($key, 'read after write') || str_contains($key, 'raw operations') => 'SAS only. Number of times firmware detected the need to read a sector after it was written.',
            $key === 'read error rate' => 'The read error rate attribute is a computed value based on the number of sectors read without error and the number of sectors which had an error and required a retry or failure to read.',
            $key === 'read error rate normalized' => 'Normalized/current value from the read-error-rate SMART attribute.',
            $key === 'read error rate worst ever' => 'Worst-ever value from the read-error-rate SMART attribute.',
            $key === 'seek error rate' => 'Similar to Read Error Rate, this attribute specifically tracks seeks and seeking errors. A seek is the movement of the servo to a specific sector for a read or write operation. Whether the read or write results in an error does not affect this as a seek is only related to positioning the heads in the correct location.',
            $key === 'seek error rate normalized' => 'Normalized/current value from the seek-error-rate SMART attribute.',
            $key === 'seek error rate worst ever' => 'Worst-ever value from the seek-error-rate SMART attribute.',
            $key === 'mr head resistance' || $key === 'second mr head resistance' => 'Old drives report ohms; newer drives report percent change since manufacturing to track head performance changes over time.',
            $key === 'velocity observer' => 'Servo velocity observer errors detected during seek mode in the last 3 SMART Summary Frames.',
            $key === 'velocity observer no tmd' => 'Servo timing mark detects missed during seek mode in the last 3 SMART Summary Frames.',
            $key === 'time coverage for velocity observer hours' => 'Time in hours covered by the velocity observer statistics.',
            str_contains($key, 'h2sat trimmed mean bits in error') => 'Mean bits in error from the read channel in processed codewords, measured on a non-user test track.',
            str_contains($key, 'h2sat iterations to converge') => 'Software retries between read retries during error recovery measurement on a non-user test track.',
            str_contains($key, 'average h2sat codeword at iteration level') => 'Percentage of codewords converged at the specified H2SAT iteration level.',
            str_contains($key, 'average h2sat amplitude') => 'Amplitude measured from the read channel as compensated by VGA response from a previous read.',
            str_contains($key, 'average h2sat asymmetry') => 'Asymmetry measured from the read channel as compensated by VGA response from a previous read.',
            str_contains($key, 'fafh appd clr delta') => 'Applied Fly Height Clearance delta tracking head fly-height changes at outer, inner, and middle disk diameters.',
            str_contains($key, 'disc slip recalibrations') => 'Count of servo recalibrations run to adjust for magnetic-disc position shifts after mishandling.',
            str_contains($key, 'super parity coverage smr hsmr swr') && str_contains($key, 'actuator 1') => 'Super Parity coverage percentage for SMR/HSMR Sequential Write Required zones on Actuator 1.',
            str_contains($key, 'super parity coverage smr hsmr swr') => 'Super Parity coverage percentage for SMR/HSMR drives with Sequential Write Required zones.',
            str_contains($key, 'super parity coverage') => 'Super Parity coverage for the drive, reported as a percentage.',
            default => '',
        };
    };
    $labelWithTooltip = static function (string $label, string $tooltip = '') use ($tooltipForLabel): string {
        $tooltip = $tooltip !== '' ? $tooltip : $tooltipForLabel($label);

        return $tooltip !== ''
            ? '<abbr style="cursor:help;text-decoration:underline dotted" title="' . htmlspecialchars($tooltip, ENT_QUOTES) . '">' . htmlspecialchars($label) . '</abbr>'
            : htmlspecialchars($label);
    };

    // Hours-elapsed → "-3 days 4 hours" style string (matches legacy formatting).
    $formatHoursAgo = static function (int $delta): string {
        $totalDays = intdiv($delta, 24);
        $remHours  = $delta % 24;
        if ($totalDays >= 365) {
            $years = intdiv($totalDays, 365);
            $days  = $totalDays % 365;
            $out   = "-{$years} year" . ($years !== 1 ? 's' : '');
            return $days > 0 ? $out . " {$days} day" . ($days !== 1 ? 's' : '') : $out;
        }
        if ($totalDays >= 30) {
            $months = intdiv($totalDays, 30);
            $days   = $totalDays % 30;
            $out    = "-{$months} month" . ($months !== 1 ? 's' : '');
            return $days > 0 ? $out . " {$days} day" . ($days !== 1 ? 's' : '') : $out;
        }
        if ($totalDays > 0) {
            $out = "-{$totalDays} day" . ($totalDays !== 1 ? 's' : '');
            return $remHours > 0 ? $out . " {$remHours} hour" . ($remHours !== 1 ? 's' : '') : $out;
        }
        return "-{$delta} hour" . ($delta !== 1 ? 's' : '');
    };

    // State sensor → coloured Bootstrap badge using its current translation.
    $stateBadge = static function ($sensor, string $tooltip = ''): string {
        if (! $sensor || $sensor->sensor_current === null || (int) $sensor->sensor_current < 0) {
            return '<span class="text-muted">-</span>';
        }
        $translation = $sensor->currentTranslation();
        $descr = $translation ? htmlspecialchars($translation->state_descr) : (string) (int) $sensor->sensor_current;
        $class = match ($translation?->severity()) {
            Severity::Ok      => 'default',
            Severity::Warning => 'warning',
            Severity::Error   => 'danger',
            default           => 'default',
        };
        $titleAttr = $tooltip !== '' ? ' title="' . htmlspecialchars($tooltip, ENT_QUOTES) . '" style="cursor:help"' : '';
        return '<span class="label label-' . $class . '"' . $titleAttr . '>' . $descr . '</span>';
    };

    // Temperature sensor → "NN°C" badge, coloured by warn/crit limits.
    $tempBadge = static function ($sensor): string {
        if (! $sensor || ! is_numeric($sensor->sensor_current)) {
            return '<span class="text-muted">-</span>';
        }
        $value = (float) $sensor->sensor_current;
        $class = 'default';
        if ($sensor->sensor_limit !== null && $value >= (float) $sensor->sensor_limit) {
            $class = 'danger';
        } elseif ($sensor->sensor_limit_warn !== null && $value >= (float) $sensor->sensor_limit_warn) {
            $class = 'warning';
        }
        $text = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
        return '<span class="label label-' . $class . '">' . htmlspecialchars($text) . '°C</span>';
    };

    // Percent sensor (NVMe Available Spare / Percentage Used) → badge coloured from the
    // sensor's own warn/crit limits, whichever direction the device reported them in.
    $percentBadge = static function ($sensor): string {
        if (! $sensor || ! is_numeric($sensor->sensor_current)) {
            return '<span class="text-muted">-</span>';
        }
        $value = (float) $sensor->sensor_current;
        $class = 'default';
        if ($sensor->sensor_limit !== null && $value >= (float) $sensor->sensor_limit) {
            $class = 'danger';
        } elseif ($sensor->sensor_limit_warn !== null && $value >= (float) $sensor->sensor_limit_warn) {
            $class = 'warning';
        } elseif ($sensor->sensor_limit_low !== null && $value <= (float) $sensor->sensor_limit_low) {
            $class = 'danger';
        } elseif ($sensor->sensor_limit_low_warn !== null && $value <= (float) $sensor->sensor_limit_low_warn) {
            $class = 'warning';
        }
        $rounded = (int) round($value);
        return '<span class="label label-' . $class . '">' . $rounded . '%</span>';
    };

    $selftestBadge = static function ($sensor) use ($formatHoursAgo): string {
        if ($sensor === null || $sensor->sensor_current === null) {
            return '<span class="text-muted">-</span>';
        }
        // sensor_current/limits are stored in minutes (runtime sensor convention); convert to hours for display.
        $minutes = (float) $sensor->sensor_current;
        $class = 'default';
        if ($sensor->sensor_limit !== null && $minutes >= (float) $sensor->sensor_limit) {
            $class = 'danger';
        } elseif ($sensor->sensor_limit_warn !== null && $minutes >= (float) $sensor->sensor_limit_warn) {
            $class = 'warning';
        }
        $hours = (int) round($minutes / 60);
        return '<span class="label label-' . $class . '">' . htmlspecialchars(ltrim($formatHoursAgo($hours), '-')) . ' ago</span>';
    };
@endphp

{{-- App selector, same as the device "Apps" tab uses to switch between a device's apps. --}}
@php
    if ($viaDedicatedRoute) {
        $appsLinkArray = ['page' => 'device', 'device' => $deviceId, 'tab' => 'apps'];
        $deviceApps = \App\Models\Application::where('device_id', $deviceId)->get()
            ->sortBy('show_name', SORT_NATURAL | SORT_FLAG_CASE);

        $appLinks = [];
        foreach ($deviceApps as $currentApp) {
            if ($currentApp->app_type === 'smart') {
                $appHref = $smartUrl();
            } else {
                $appLinkAdd = ['app' => $currentApp->app_type];
                if (! empty($currentApp->app_instance)) {
                    $appLinkAdd['instance'] = $currentApp->app_id;
                }
                $appHref = \LibreNMS\Util\Url::generate($appsLinkArray, $appLinkAdd);
            }
            $appText = htmlspecialchars($currentApp->displayName() . (! empty($currentApp->app_instance) ? '(' . $currentApp->app_instance . ')' : ''));
            $class = $currentApp->app_type === 'smart' ? ' class="pagemenu-selected"' : '';
            $appLinks[] = '<a href="' . htmlspecialchars($appHref, ENT_QUOTES) . '"' . $class . '>' . $appText . '</a>';
        }

        echo '<div class="panel panel-default"><div class="panel-heading"><span style="font-weight:bold">Apps</span> &#187; '
            . implode(' | ', $appLinks) . '</div></div>';
    }
@endphp

{{-- Optionbar --}}
@php
    print_optionbar_start();

    // Label-mode selector (right side).
    $currentUrl = $smartPage === 'compare'
        ? route('device.apps.smart.compare', $deviceId)
        : $smartUrl($selectedDisk);
    $modeOptions = '';
    foreach ($labelModes as $mode => $title) {
        $sel = $mode === $labelMode ? ' selected' : '';
        $modeOptions .= '<option value="' . htmlspecialchars($mode, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars($title) . '</option>';
    }
    echo '<div class="pull-right" style="margin-left:10px">'
        . '<label for="smart-label-mode" style="margin-right:6px">Label:</label>'
        . '<select id="smart-label-mode" class="form-control input-sm" style="display:inline-block;width:auto" '
        . 'onchange="document.cookie=\'' . htmlspecialchars($labelCookie, ENT_QUOTES) . '=\' + this.value + \'; path=/; max-age=31536000; samesite=lax\'; window.location.href=\'' . htmlspecialchars($currentUrl, ENT_QUOTES) . '\';">'
        . $modeOptions . '</select></div>';

    if (Auth::user()?->hasRole('admin')) {
        echo '<span class="pull-right">' . debug_toggle_button('smart-debug-panels') . '</span>';
    }

    echo '<a class="pull-right btn btn-default btn-sm" style="margin-left:10px" href="'
        . htmlspecialchars(route('device.apps.smart.settings', $device['device_id']), ENT_QUOTES)
        . '"><i class="fa fa-cog"></i> Settings</a>';

    $ovLabel = $selectedDisk === null && $smartPage === 'overview' ? '<span class="pagemenu-selected">Overview</span>' : 'Overview';
    $compareLabel = $smartPage === 'compare' ? '<span class="pagemenu-selected">Compare</span>' : 'Compare';
    $links = [
        '<a href="' . htmlspecialchars($smartUrl(), ENT_QUOTES) . '">' . $ovLabel . '</a>',
        '<a href="' . htmlspecialchars(route('device.apps.smart.compare', $deviceId), ENT_QUOTES) . '">' . $compareLabel . '</a>',
    ];
    foreach ($data->diskKeys() as $key) {
        $disk  = $data->disk($key);
        $label = htmlspecialchars($data->displayLabel($disk, $labelMode));
        if ($selectedDisk === $key) {
            $label = "<span class=\"pagemenu-selected\">{$label}</span>";
        }
        $links[] = '<a href="' . htmlspecialchars($smartUrl($key), ENT_QUOTES) . '">' . $label . '</a>';
    }
    echo implode(' | ', $links);

    // Per-disk view-mode sub-nav, filtered to the modes the selected disk's type supports.
    if ($selectedDisk !== null && $data->disk($selectedDisk) !== null) {
        $diskViewModes = $data->diskViewModesFor($data->disk($selectedDisk));
        if (! isset($diskViewModes[$viewMode])) {
            $viewMode = array_key_first($diskViewModes);
        }
        $viewLinks = [];
        foreach ($diskViewModes as $mode => $title) {
            $lbl = htmlspecialchars($title);
            if ($mode === $viewMode) {
                $lbl = '<span class="pagemenu-selected">' . $lbl . '</span>';
            }
            $viewLinks[] = '<a href="' . htmlspecialchars($currentUrl, ENT_QUOTES) . '" onclick="document.cookie=\''
                . htmlspecialchars($viewCookie, ENT_QUOTES) . '=' . htmlspecialchars($mode, ENT_QUOTES)
                . '; path=/; max-age=31536000; samesite=lax\';">' . $lbl . '</a>';
        }
        echo '<br>&nbsp;&nbsp; Disk: ' . implode(' | ', $viewLinks);
    }

    print_optionbar_end();
@endphp

{{-- Debug panels (admin only) --}}
@php
    smart_debug_render($data, $selectedDisk);
@endphp

@if($smartPage === 'compare')
        {{-- All-disk SMART Attributes cross-table (SATA/SAS disks only) --}}
        @php
            $sataKeys = array_values(array_filter($data->diskKeys(), static fn ($k) => ! $data->isNvme($data->disk($k))));

            if ($sataKeys !== []) {
                // Collect all unique attribute IDs+names across all SATA/SAS disks.
                $allAttrCols = [];  // id => display-name
                foreach ($sataKeys as $k) {
                    foreach ($data->disk($k)['attributes'] ?? [] as $a) {
                        $id = (int) ($a['attribute_id'] ?? 0);
                        if ($id > 0 && ! isset($allAttrCols[$id])) {
                            $allAttrCols[$id] = str_replace('_', ' ', (string) ($a['name'] ?? ''));
                        }
                    }
                }
                ksort($allAttrCols);

                if ($allAttrCols !== []) {
                    $dark    = session('applied_site_style') === 'dark';
                    $aaId    = 'smart-allattr-' . $deviceId;
                    $aaRadio = $aaId . '-mode';

                    $panelStart('SMART Attributes — All Disks');

                    // Mode selector.
                    echo '<div style="margin-bottom:10px;display:flex;gap:18px;align-items:center">'
                        . '<strong style="white-space:nowrap">Show:</strong>'
                        . '<label style="font-weight:normal;margin:0;cursor:pointer"><input type="radio" name="' . $aaRadio . '" value="rawdisp" checked onchange="smartAllAttrMode(\'' . $aaId . '\',this.value)"> Raw Display</label>'
                        . '<label style="font-weight:normal;margin:0;cursor:pointer"><input type="radio" name="' . $aaRadio . '" value="raw" onchange="smartAllAttrMode(\'' . $aaId . '\',this.value)"> Raw</label>'
                        . '<label style="font-weight:normal;margin:0;cursor:pointer"><input type="radio" name="' . $aaRadio . '" value="norm" onchange="smartAllAttrMode(\'' . $aaId . '\',this.value)"> Normalized</label>'
                        . '<span class="text-muted" style="font-size:12px">SATA/SAS only — cells coloured by attribute status.</span>'
                        . '</div>';

                    echo '<div class="table-responsive"><table id="' . $aaId . '" class="table table-condensed table-hover sa-mode-rawdisp" style="white-space:nowrap">';

                    // Header row: ID (small) above Name.
                    echo '<thead><tr><th style="white-space:nowrap">Disk</th>';
                    foreach ($allAttrCols as $id => $name) {
                        $tip = $tooltipForLabel($name);
                        $inner = '<small class="text-muted">' . $id . '</small><br>' . htmlspecialchars($name);
                        $hdr = $tip !== ''
                            ? '<abbr style="cursor:help;text-decoration:underline dotted;white-space:normal" title="'
                                . htmlspecialchars($tip, ENT_QUOTES) . '">' . $inner . '</abbr>'
                            : $inner;
                        echo '<th style="text-align:center;min-width:70px;font-weight:normal">' . $hdr . '</th>';
                    }
                    echo '</tr></thead><tbody>';

                    // One row per disk.
                    foreach ($sataKeys as $k) {
                        $d       = $data->disk($k);
                        $devLink = '<a href="' . htmlspecialchars($smartUrl($k), ENT_QUOTES) . '">' . htmlspecialchars($data->deviceLabel($d)) . '</a>';

                        // Build attribute lookup for this disk.
                        $attrMap = [];
                        foreach ($d['attributes'] ?? [] as $a) {
                            $attrMap[(int) ($a['attribute_id'] ?? 0)] = $a;
                        }

                        echo '<tr><td style="white-space:nowrap">' . $devLink . '</td>';
                        foreach ($allAttrCols as $id => $_) {
                            if (! isset($attrMap[$id])) {
                                echo '<td style="text-align:center" class="text-muted">-</td>';
                                continue;
                            }
                            $a       = $attrMap[$id];
                            $status  = (int) ($a['status'] ?? 0);
                            $rawDisp = htmlspecialchars($data->formatRawSpaced($a['value_raw_string'] ?? $a['value_raw'] ?? ''));
                            $rawNum  = is_numeric($a['value_raw'] ?? null) ? htmlspecialchars((string) (int) $a['value_raw']) : '-';
                            $norm    = is_numeric($a['value_norm'] ?? null) ? htmlspecialchars((string) (int) $a['value_norm']) : '-';

                            $bg = match ($status) {
                                2  => $dark ? 'background-color:#5a2a2a' : 'background-color:#f2a8a8',
                                3  => $dark ? 'background-color:#3f2a2c' : 'background-color:#fbdede',
                                -1 => $dark ? 'background-color:#15171a' : 'background-color:#f4f4f4',
                                default => '',
                            };
                            echo '<td style="text-align:center;' . $bg . '">'
                                . '<span class="sa-rawdisp">' . $rawDisp . '</span>'
                                . '<span class="sa-raw">' . $rawNum . '</span>'
                                . '<span class="sa-norm">' . $norm . '</span>'
                                . '</td>';
                        }
                        echo '</tr>';
                    }

                    echo '</tbody></table></div>';
                    echo <<<'SCRIPT'
<style>
.sa-mode-rawdisp .sa-raw,.sa-mode-rawdisp .sa-norm{display:none}
.sa-mode-raw .sa-rawdisp,.sa-mode-raw .sa-norm{display:none}
.sa-mode-norm .sa-rawdisp,.sa-mode-norm .sa-raw{display:none}
</style>
<script>
function smartAllAttrMode(id,mode){var t=document.getElementById(id);if(t)t.className=t.className.replace(/\bsa-mode-\S+/g,'')+' sa-mode-'+mode;}
</script>
SCRIPT;
                    $panelEnd();
                }
            }
        @endphp

        {{-- All-disk Device Statistics cross-table (SATA/SAS disks only), with a page selector --}}
        @php
            if ($sataKeys !== []) {
                $devStatSkipPages = \LibreNMS\Agent\Unix\Smart\HtmlData::DEV_STAT_SKIP_PAGES;
                $devStatSkipRows  = \LibreNMS\Agent\Unix\Smart\HtmlData::DEV_STAT_SKIP_ROWS;
                $devStatMetaPages = ['FARM Drive Information', 'FARM Log Header'];

                $fmtDevStatVal = static function ($v): string {
                    if ($v === null) {
                        return '<span class="text-muted">-</span>';
                    }
                    if (is_numeric($v) && abs((float) $v) >= 1000000) {
                        return htmlspecialchars(Number::formatSi((float) $v, 2, 0, ''));
                    }

                    return htmlspecialchars((string) $v);
                };

                // page_name => [disk_key => [stat_name => value]]
                $pageDiskStats = [];
                foreach ($sataKeys as $k) {
                    foreach ($data->disk($k)['dev_stats'] ?? [] as $page) {
                        $pn = $page['page_name'] ?: $data->decode('dev_stat_page', $page['page_num']);
                        if (in_array($pn, $devStatSkipPages, true) || in_array($pn, $devStatMetaPages, true)) {
                            continue;
                        }
                        $isFarmPage = str_starts_with($pn, 'FARM ');

                        // Per-head FARM stats (e.g. reallocated_sectors_by_head_0/_1/...) get
                        // merged into a single "base name" column showing the low-high range
                        // across heads, instead of one column per head.
                        $headGroups = [];
                        foreach ($page['rows'] as $r) {
                            $statName = (string) ($r['stat_name'] ?? '');
                            if ($statName === '' || in_array($statName, $devStatSkipRows, true)) {
                                continue;
                            }
                            if (! $isFarmPage && ($r['valid'] ?? 1) == 0) {
                                continue;
                            }
                            if ($isFarmPage && preg_match('/^(.+)_(?:by|from)_head_(\d+)$/', $statName, $m)) {
                                $headGroups[$m[1]][(int) $m[2]] = $r['value'] ?? null;
                                continue;
                            }
                            $pageDiskStats[$pn][$k][$statName] = $r['value'] ?? null;
                        }
                        foreach ($headGroups as $base => $heads) {
                            $vals = array_filter($heads, static fn ($v) => $v !== null);
                            if ($vals === []) {
                                continue;
                            }
                            $low  = min($vals);
                            $high = max($vals);
                            $pageDiskStats[$pn][$k][$base] = $low === $high ? $low : "{$low} - {$high}";
                        }
                    }
                }
                ksort($pageDiskStats);

                if ($pageDiskStats !== []) {
                    $dsId = 'smart-devstats-' . $deviceId;
                    $panelStart('Device Statistics — All Disks');

                    $pageOptions = '';
                    foreach (array_keys($pageDiskStats) as $i => $pn) {
                        $sel = $i === 0 ? ' selected' : '';
                        $pageOptions .= '<option value="' . htmlspecialchars($pn, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars($pn) . '</option>';
                    }
                    echo '<div style="margin-bottom:10px;display:flex;gap:10px;align-items:center">'
                        . '<strong style="white-space:nowrap">Page:</strong>'
                        . '<select class="form-control input-sm" style="display:inline-block;width:auto" onchange="smartDevStatPage(\'' . $dsId . '\', this.value)">'
                        . $pageOptions . '</select>'
                        . '<span class="text-muted" style="font-size:12px">SATA/SAS only.</span>'
                        . '</div>';

                    echo '<div id="' . $dsId . '">';
                    foreach ($pageDiskStats as $pn => $diskStats) {
                        $statNames = [];
                        foreach ($diskStats as $stats) {
                            foreach (array_keys($stats) as $sn) {
                                if (! in_array($sn, $statNames, true)) {
                                    $statNames[] = $sn;
                                }
                            }
                        }

                        $hidden = $pn !== array_key_first($pageDiskStats) ? ' style="display:none"' : '';
                        echo '<div class="smart-devstat-page" data-page="' . htmlspecialchars($pn, ENT_QUOTES) . '"' . $hidden . '>';
                        echo '<div class="table-responsive"><table class="table table-condensed table-hover" style="white-space:nowrap">';
                        echo '<thead><tr><th>Disk</th>';
                        foreach ($statNames as $sn) {
                            $tip = $tooltipForLabel($sn);
                            $hdr = $tip !== ''
                                ? '<abbr style="cursor:help;text-decoration:underline dotted;white-space:normal" title="'
                                    . htmlspecialchars($tip, ENT_QUOTES) . '">' . htmlspecialchars($sn) . '</abbr>'
                                : htmlspecialchars($sn);
                            echo '<th style="text-align:center;min-width:70px;font-weight:normal">' . $hdr . '</th>';
                        }
                        echo '</tr></thead><tbody>';
                        foreach ($sataKeys as $k) {
                            if (! isset($diskStats[$k])) {
                                continue;
                            }
                            $devLink = '<a href="' . htmlspecialchars($smartUrl($k), ENT_QUOTES) . '">' . htmlspecialchars($data->deviceLabel($data->disk($k))) . '</a>';
                            echo '<tr><td style="white-space:nowrap">' . $devLink . '</td>';
                            foreach ($statNames as $sn) {
                                echo '<td style="text-align:center">' . $fmtDevStatVal($diskStats[$k][$sn] ?? null) . '</td>';
                            }
                            echo '</tr>';
                        }
                        echo '</tbody></table></div></div>';
                    }
                    echo '</div>';

                    echo <<<'SCRIPT'
<script>
function smartDevStatPage(id,page){
    var c=document.getElementById(id); if(!c)return;
    c.querySelectorAll(".smart-devstat-page").forEach(function(el){el.style.display=(el.dataset.page===page)?"":"none";});
}
</script>
SCRIPT;

                    $panelEnd();
                }
            }
        @endphp

@elseif($selectedDisk === null || $data->disk($selectedDisk) === null)
    {{-- ================================================================== --}}
    {{-- Overview                                                            --}}
    {{-- ================================================================== --}}
    @if(! $data->hasDisks())
        <div class="alert alert-info">No SMART devices have been discovered for this application yet.</div>
    @else
        @php $panelStart('Drives'); @endphp
        <div class="table-responsive">
            <table class="table table-condensed table-striped table-hover">
                <thead><tr>
                    <th>Device</th><th>Model</th><th>Serial</th><th>Type</th>
                    <th>Temp</th><th>Health</th><th>Self-test Status</th><th>Wear</th><th>Available Spare</th>
                    <th>Last Short Self-test</th><th>Last Long Self-test</th>
                </tr></thead>
                <tbody>
                @foreach($data->diskKeys() as $key)
                    @php
                        $disk    = $data->disk($key);
                        $devName = htmlspecialchars($data->deviceLabel($disk));
                        $serial  = $data->serial($disk);
                        $deviceLink = '<a href="' . htmlspecialchars($smartUrl($key), ENT_QUOTES) . '">' . $devName . '</a>';
                        $modelLink  = '<a href="' . htmlspecialchars($smartUrl($key), ENT_QUOTES) . '">' . htmlspecialchars($data->model($disk)) . '</a>';
                        $serialCell = $serial !== ''
                            ? '<a href="' . htmlspecialchars($smartUrl($key), ENT_QUOTES) . '">' . htmlspecialchars($serial) . '</a>'
                            : '-';
                        $usedSensor = $data->percentageUsedSensor($key);
                        $spareSensor = $data->availableSpareSensor($key);
                    @endphp
                    <tr>
                        <td>{!! $deviceLink !!}</td>
                        <td>{!! $modelLink !!}</td>
                        <td>{!! $serialCell !!}</td>
                        <td>{{ $data->typeLabel($disk) }}</td>
                        <td>{!! $tempBadge($data->temperatureSensor($key)) !!}</td>
                        <td>{!! $stateBadge($data->healthSensor($key)) !!}</td>
                        <td>{!! $stateBadge($data->selftestStatusSensor($key)) !!}</td>
                        <td>{!! $percentBadge($usedSensor) !!}</td>
                        <td>{!! $percentBadge($spareSensor) !!}</td>
                        <td>{!! $selftestBadge($data->selftestAgeSensor($key, 'short')) !!}</td>
                        <td>{!! $selftestBadge($data->selftestAgeSensor($key, 'long')) !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @php $panelEnd(); @endphp

        {{-- Overview graphs + jump nav --}}
        @php
            $now    = LibrenmsConfig::get('time.now');
            $from   = LibrenmsConfig::get('time.day');
            $appId  = $data->app->app_id;
            $ovBase = $smartUrl();

            $sections = [
                ['id' => 'smart-overview-all-temp', 'title' => 'All Temperatures', 'type' => 'smart_v2_all_temp'],
                ['id' => 'smart-overview-all-wear', 'title' => 'Wear Remaining', 'type' => 'smart_v2_all_wear'],
            ];
            foreach ($data->overviewAttributeIds() as $id => $aname) {
                $sections[] = [
                    'id'    => 'smart-overview-attr-' . $id,
                    'title' => 'ID# ' . $id . ', ' . $aname,
                    'type'  => 'smart_v2_attr_multi',
                    'attr_id' => $id,
                ];
            }

            // Jump-to-graph nav.
            $jumpItems = '';
            foreach ($sections as $s) {
                $jumpItems .= '<div style="break-inside:avoid-column;-webkit-column-break-inside:avoid;padding:1px 0">'
                    . '<a href="' . htmlspecialchars($ovBase . '#' . $s['id'], ENT_QUOTES) . '">' . htmlspecialchars($s['title']) . '</a></div>';
            }
            echo '<div class="panel panel-default"><div class="panel-body" style="padding:10px 15px">'
                . '<strong>Jump to graph:</strong><div style="column-width:260px;column-gap:18px;margin-top:6px">'
                . $jumpItems . '</div></div></div>';

            foreach ($sections as $s) {
                $graph_array = [
                    'height' => '100', 'width' => '215', 'from' => $from, 'to' => $now,
                    'id'     => $appId, 'type' => 'application_' . $s['type'],
                    'page_title' => 'All Drives — ' . $s['title'],
                ];
                if (isset($s['attr_id'])) { $graph_array['attr_id'] = $s['attr_id']; }
                echo '<a id="' . htmlspecialchars($s['id']) . '" style="position:relative;top:-70px;display:block;visibility:hidden"></a>';
                $panelStart(htmlspecialchars($s['title']));
                echo '<div class="row">';
                include 'includes/html/print-graphrow.inc.php';
                echo '</div>';
                $panelEnd();
            }
        @endphp
    @endif

@else
    {{-- ================================================================== --}}
    {{-- Per-disk detail                                                     --}}
    {{-- ================================================================== --}}
    @php $detailDisk = $data->disk($selectedDisk); @endphp
    @if($detailDisk !== null && $data->isNvme($detailDisk) && $viewMode === 'basic')
        @include('device.apps.smart.nvme-basic', ['disk' => $detailDisk, 'viewMode' => $viewMode])
    @elseif($detailDisk !== null && $data->isNvme($detailDisk) && $viewMode === 'metadata')
        {{-- Static identity/capability metadata, namespaces, and power states (NVMe only). --}}
        @include('device.apps.smart.nvme-metadata', ['disk' => $detailDisk, 'viewMode' => $viewMode])
    @elseif($detailDisk !== null && $data->isNvme($detailDisk) && $viewMode === 'selftest')
        {{-- Self-test log (NVMe only). --}}
        @include('device.apps.smart.nvme-selftest', ['disk' => $detailDisk, 'viewMode' => $viewMode])
    @elseif($detailDisk !== null && $data->isNvme($detailDisk))
        {{-- Graphs (NVMe only; this is the only remaining viewMode that reaches here). --}}
        @include('device.apps.smart.nvme-graphs', ['disk' => $detailDisk, 'viewMode' => $viewMode])
    @elseif($viewMode === 'basic')
        {{-- Identity, health sensors, SMART attributes (SATA/SAS only). --}}
        @include('device.apps.smart.sata-basic', ['disk' => $detailDisk, 'viewMode' => $viewMode])
    @elseif($viewMode === 'metadata')
        {{-- Static identity/capability metadata + FARM header pages (SATA/SAS only). --}}
        @include('device.apps.smart.sata-metadata', ['disk' => $detailDisk, 'viewMode' => $viewMode])
    @elseif($viewMode === 'selftest')
        {{-- Self-test log, selective spans, offline collection and related capabilities. --}}
        @include('device.apps.smart.sata-selftest', ['disk' => $detailDisk, 'viewMode' => $viewMode])
    @elseif($viewMode === 'tables')
        {{-- Device statistics tables (General, Rotating Media, Errors, Transport, FARM*) + PHY counters. --}}
        @include('device.apps.smart.sata-tables', ['disk' => $detailDisk, 'viewMode' => $viewMode])
    @else
        {{-- Graphs (SATA/SAS only; this is the only remaining viewMode that reaches here). --}}
        @include('device.apps.smart.sata-graphs', ['disk' => $detailDisk, 'viewMode' => $viewMode])
    @endif
@endif
