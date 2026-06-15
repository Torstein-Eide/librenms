@php
    use App\Facades\LibrenmsConfig;
    use LibreNMS\Enum\Severity;
    use LibreNMS\Util\Number;
    use LibreNMS\Util\Url;

    /** @var \LibreNMS\Agent\Unix\Smart\HtmlData $data */

    $deviceId  = (int) $data->device['device_id'];
    $linkArray = [
        'page'   => 'device',
        'device' => $deviceId,
        'tab'    => 'apps',
        'app'    => 'smart',
    ];

    // Persisted display modes (cookie-backed, per device).
    $labelCookie = 'smart_label_mode_' . $deviceId;
    $labelModes  = $data->labelModes();
    $labelMode   = (isset($_COOKIE[$labelCookie]) && isset($labelModes[$_COOKIE[$labelCookie]]))
        ? $_COOKIE[$labelCookie] : 'device';

    $viewCookie = 'smart_disk_view_mode_' . $deviceId;
    $viewModes  = $data->diskViewModes();
    $viewMode   = (isset($_COOKIE[$viewCookie]) && isset($viewModes[$_COOKIE[$viewCookie]]))
        ? $_COOKIE[$viewCookie] : 'basic';

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
        'power cycles' => 'Counts power-on resets or unique device startups during system boot.',
        'lifetime power on resets' => 'Counts power-on resets or unique device startups during system boot.',
        'power on hours' => 'Tracks the number of hours the device has been powered on; HDD spindle and head-load time may differ.',
        'spin power on hours' => 'Hours an HDD spindle motor has been spinning the platters.',
        'logical sectors written' => 'Counts logical sectors written. Multiply by the logical sector size to estimate bytes written.',
        'number of write commands' => 'Counts write commands to user-space sectors; one command can transfer one or many sectors.',
        'logical sectors read' => 'Counts logical sectors read. Multiply by the logical sector size to estimate bytes read.',
        'number of read commands' => 'Counts read commands to user-space sectors; one command can transfer one or many sectors.',
        'date and time timestamp' => 'Device timestamp programmed by the host, measured as milliseconds since the Unix epoch.',
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
            $key === 'read error rate' => 'SATA only. Seagate read-error-rate SMART attribute value.',
            $key === 'read error rate normalized' => 'SATA only. Normalized/current value from the Seagate read-error-rate SMART attribute.',
            $key === 'read error rate worst ever' => 'SATA only. Worst-ever value from the Seagate read-error-rate SMART attribute.',
            $key === 'seek error rate' => 'SATA only. Seagate seek-error-rate SMART attribute value.',
            $key === 'seek error rate normalized' => 'SATA only. Normalized/current value from the Seagate seek-error-rate SMART attribute.',
            $key === 'seek error rate worst ever' => 'SATA only. Worst-ever value from the Seagate seek-error-rate SMART attribute.',
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
    $stateBadge = static function ($sensor): string {
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
        return '<span class="label label-' . $class . '">' . $descr . '</span>';
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

    // Wear-remaining percentage → coloured badge.
    $wearBadge = static function (?float $wear): string {
        if ($wear === null) {
            return '<span class="text-muted">-</span>';
        }
        $rounded = (int) round(max(0.0, min(100.0, $wear)));
        $class = $rounded <= 10 ? 'danger' : ($rounded <= 20 ? 'warning' : 'default');
        return '<span class="label label-' . $class . '">' . $rounded . '%</span>';
    };

    $selftestBadge = static function (?int $ageHours) use ($formatHoursAgo): string {
        if ($ageHours === null) {
            return '<span class="text-muted">-</span>';
        }
        return '<span class="label label-default">' . htmlspecialchars(ltrim($formatHoursAgo($ageHours), '-')) . ' ago</span>';
    };
@endphp

{{-- Optionbar --}}
@php
    print_optionbar_start();

    // Label-mode selector (right side).
    $currentUrl = $selectedDisk !== null
        ? Url::generate($linkArray + ['disk' => (string) $selectedDisk])
        : Url::generate($linkArray);
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

    $ovLabel = $selectedDisk === null ? '<span class="pagemenu-selected">All Drives</span>' : 'All Drives';
    $links = [generate_link($ovLabel, $linkArray)];
    foreach ($data->diskKeys() as $key) {
        $disk  = $data->disk($key);
        $label = htmlspecialchars($data->displayLabel($disk, $labelMode));
        if ($selectedDisk === $key) {
            $label = "<span class=\"pagemenu-selected\">{$label}</span>";
        }
        $links[] = generate_link($label, $linkArray, ['disk' => $key]);
    }
    echo implode(' | ', $links);

    // Per-disk view-mode sub-nav.
    if ($selectedDisk !== null && $data->disk($selectedDisk) !== null) {
        $viewLinks = [];
        foreach ($viewModes as $mode => $title) {
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

@if($selectedDisk === null || $data->disk($selectedDisk) === null)
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
                    <th>Temp</th><th>Health</th><th>Self-test Status</th><th>Wear</th>
                    <th>Last Short Self-test</th><th>Last Long Self-test</th>
                </tr></thead>
                <tbody>
                @foreach($data->diskKeys() as $key)
                    @php
                        $disk    = $data->disk($key);
                        $devName = htmlspecialchars($data->deviceLabel($disk));
                        $serial  = $data->serial($disk);
                        $deviceLink = generate_link($devName, $linkArray, ['disk' => $key]);
                        $modelLink  = generate_link(htmlspecialchars($data->model($disk)), $linkArray, ['disk' => $key]);
                        $serialCell = $serial !== ''
                            ? generate_link(htmlspecialchars($serial), $linkArray, ['disk' => $key])
                            : '-';
                    @endphp
                    <tr>
                        <td>{!! $deviceLink !!}</td>
                        <td>{!! $modelLink !!}</td>
                        <td>{!! $serialCell !!}</td>
                        <td>{{ $data->typeLabel($disk) }}</td>
                        <td>{!! $tempBadge($data->temperatureSensor($key)) !!}</td>
                        <td>{!! $stateBadge($data->healthSensor($key)) !!}</td>
                        <td>{!! $stateBadge($data->selftestStatusSensor($key)) !!}</td>
                        <td>{!! $wearBadge($data->wearRemaining($disk)) !!}</td>
                        <td>{!! $selftestBadge($data->selftestAgeHours($disk, 1)) !!}</td>
                        <td>{!! $selftestBadge($data->selftestAgeHours($disk, 2)) !!}</td>
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
            $ovBase = Url::generate($linkArray);

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
    @if($detailDisk !== null && $data->isNvme($detailDisk))
        @include('device.apps.smart-nvme-detail', ['disk' => $detailDisk, 'viewMode' => $viewMode])
    @else
    @php
        $disk    = $data->disk($selectedDisk);
        $idx     = $disk['idx'];
        $info    = $disk['info'];
        $health  = $disk['health'];
        $powerOnHours = $data->powerOnHours($disk);
        $passed  = $health['sct_smart_status_passed'] ?? $health['overall_status'] ?? null;
        $healthBadge = match (true) {
            (int) $passed === 1 => '<span class="label label-success">Passed</span>',
            $passed !== null    => '<span class="label label-danger">Failed</span>',
            default             => '',
        };

        // Self-test panel badge (running / passed / failed).
        $execRaw   = $health['selftest_exec_status_raw'] ?? null;
        $remaining = $health['selftest_remaining_pct'] ?? null;
        if ((int) $execRaw === 15 || (is_numeric($remaining) && (int) $remaining > 0)) {
            $donePct = is_numeric($remaining) ? max(0, min(100, 100 - (int) $remaining)) : null;
            $selftestPanelBadge = '<span class="label label-info">Running' . ($donePct !== null ? " {$donePct}%" : '') . '</span>';
        } elseif ($execRaw !== null) {
            $selftestPanelBadge = (int) $execRaw === 0
                ? '<span class="label label-success">Passed</span>'
                : '<span class="label label-warning">' . htmlspecialchars($data->decode('selftest_exec', (int) $execRaw)) . '</span>';
        } else {
            $selftestPanelBadge = $healthBadge;
        }

        $showDetailed = $viewMode === 'detailed';
        $showPanels   = $viewMode !== 'graphs';

        $devStatKnownPanels = [
            'General Statistics',
            'Free-Fall Statistics',
            'Rotating Media Statistics',
            'General Errors Statistics',
            'Transport Statistics',
            'FARM Log Header',
            'FARM Drive Information',
            'FARM Workload Statistics',
            'FARM Error Statistics',
            'FARM Environment Statistics',
            'FARM Reliability Statistics',
        ];
        $devStatUnknownPages = [];
        foreach ($disk['dev_stats'] as $page) {
            $pn = $page['page_name'] ?: $data->decode('dev_stat_page', $page['page_num']);
            if (in_array($pn, \LibreNMS\Agent\Unix\Smart\HtmlData::DEV_STAT_SKIP_PAGES, true)) { continue; }
            if (! in_array($pn, $devStatKnownPanels, true)) {
                $devStatUnknownPages[] = $pn;
            }
        }
    @endphp

    @if($showPanels)
    <style>
        .smart-panels { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-start; margin-bottom:15px }
        .smart-panels .panel { flex:0 0 auto; margin-bottom:0 }
        .smart-panels table { white-space:nowrap }
    </style>
    @if(! empty($devStatUnknownPages))
    <div class="alert alert-warning" style="padding:5px 10px;margin-bottom:10px;font-size:12px">
        <strong>Unrecognized device statistics page(s) — no panel defined:</strong>
        {{ implode(', ', $devStatUnknownPages) }}
    </div>
    @endif
    <div class="smart-panels">
        {{-- Identity --}}
        <div>
            @php
                $panelStart(htmlspecialchars($data->deviceLabel($disk)), $healthBadge);
                echo '<table class="table table-condensed table-hover" style="width:auto">';
                $cap = $info['user_capacity_bytes'] ?? null;
                $rot = $info['rotation_rate'] ?? null;
                $rows = [
                    'Model Family'    => $disk['model_family']   ?? null,
                    'Model'           => $disk['model_name']     ?? null,
                    'Serial'          => $disk['serial_number']  ?? null,
                    'Firmware'        => $disk['firmware_version'] ?? null,
                    'WWN'             => $disk['wwn']            ?? null,
                    'Device'          => $disk['device_name']   ?? null,
                    'Path'            => $disk['device_path']   ?? null,
                    'Capacity'        => is_numeric($cap) ? Number::formatBi((int) $cap) : null,
                    'Power On Hours'  => $powerOnHours !== null ? number_format($powerOnHours, 0, '.', ' ') : null,
                    'Power Cycles'    => isset($health['power_cycles']) && is_numeric($health['power_cycles']) ? number_format((int) $health['power_cycles'], 0, '.', ' ') : null,
                    'Interface Speed' => $data->interfaceSpeed($info),
                    'Rotation Rate'   => is_numeric($rot) ? ((int) $rot === 0 ? 'Solid State Device' : ((int) $rot) . ' RPM') : null,
                    'Form Factor'     => isset($info['form_factor']) ? $data->decode('form_factor', $info['form_factor']) : null,
                    'ATA Version'     => isset($info['ata_version']) ? $data->decode('ata_version', $info['ata_version']) : null,
                    'SATA Version'    => isset($info['sata_version']) ? $data->decode('sata_version', $info['sata_version']) : null,
                    'Logical Block'   => isset($info['logical_block_size']) && is_numeric($info['logical_block_size']) ? Number::formatSi((int) $info['logical_block_size'], 0, 0, 'B') : null,
                    'Physical Block'  => isset($info['physical_block_size']) && is_numeric($info['physical_block_size']) ? Number::formatSi((int) $info['physical_block_size'], 0, 0, 'B') : null,
                    'SMART'           => ($info['smart_available'] ?? null) !== null ? (((int) $info['smart_available']) ? 'Available' : 'Not available') : null,
                    'SMART Enabled'   => ($info['smart_enabled'] ?? null) !== null ? (((int) $info['smart_enabled']) ? 'Yes' : 'No') : null,
                    'Write Cache'     => ($info['write_cache_enabled'] ?? null) !== null ? (((int) $info['write_cache_enabled']) ? 'Enabled' : 'Disabled') : null,
                    'Read Look-ahead' => ($info['read_lookahead_enabled'] ?? null) !== null ? (((int) $info['read_lookahead_enabled']) ? 'Enabled' : 'Disabled') : null,
                    'TRIM'            => ($info['trim_supported'] ?? null) !== null ? (((int) $info['trim_supported']) ? 'Supported' : 'Not supported') : null,
                    'APM'             => $data->apmLabel($info) !== '-' ? $data->apmLabel($info) : null,
                    'Security'        => $data->securityLabel($info) !== '-' ? $data->securityLabel($info) : null,
                    'In smartctl DB'  => ($info['in_smartctl_database'] ?? null) !== null ? (((int) $info['in_smartctl_database']) ? 'Yes' : 'No') : null,
                    'Last Poll'       => $disk['last_poll_time'] ?? null,
                    'Last Poll Result' => $disk['last_poll_result'] !== null ? $data->decode('poll_result', $disk['last_poll_result']) : null,
                ];
                foreach ($rows as $label => $value) {
                    if ($value !== null && $value !== '') {
                        echo $tableRow($label, htmlspecialchars((string) $value), $tooltipForLabel($label));
                    }
                }
                echo '</table>';
                $panelEnd();
            @endphp
        </div>

        {{-- Self-test Log (Selective Self-test Spans embedded) --}}
        @if(! empty($disk['selftests']) || isset($info['selftest_polling_short_minutes']) || isset($info['offline_collection_completion_secs']) || ! empty($disk['selective_test']))
        <div>
            @php
                $panelStart('Self-test Log', $selftestPanelBadge);
                $pollingRows = [];
                if (isset($info['selftest_polling_short_minutes']) && is_numeric($info['selftest_polling_short_minutes'])) {
                    $pollingRows[] = 'Short: ' . (int) $info['selftest_polling_short_minutes'] . ' min';
                }
                if (isset($info['selftest_polling_extended_minutes']) && is_numeric($info['selftest_polling_extended_minutes'])) {
                    $pollingRows[] = 'Extended: ' . (int) $info['selftest_polling_extended_minutes'] . ' min';
                }
                if (isset($info['selftest_polling_conveyance_minutes']) && is_numeric($info['selftest_polling_conveyance_minutes'])) {
                    $pollingRows[] = 'Conveyance: ' . (int) $info['selftest_polling_conveyance_minutes'] . ' min';
                }
                if ($pollingRows !== []) {
                    echo '<p style="margin-bottom:6px"><strong>Est. polling minutes:</strong> ' . htmlspecialchars(implode(' / ', $pollingRows)) . '</p>';
                }
                $offlineSecs = $info['offline_collection_completion_secs'] ?? null;
                $offlineStatus = $health['offline_collection_status'] ?? null;
                if ($offlineSecs !== null && is_numeric($offlineSecs)) {
                    echo '<p style="margin-bottom:6px"><strong>Offline collection:</strong> ' . htmlspecialchars((int) $offlineSecs . ' s') . ($offlineStatus !== null ? ' — ' . htmlspecialchars($data->decode('offline_status', $offlineStatus)) : '') . '</p>';
                }
                if (! empty($disk['selftests'])) {
                    echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover">';
                    echo '<thead><tr><th>#</th><th>Type</th><th>Result</th><th>Hours</th><th>Remaining</th><th>First LBA Error</th></tr></thead><tbody>';
                    foreach ($disk['selftests'] as $entry) {
                        $h = $entry['power_on_hours'] ?? null;
                        $hoursCell = (string) ($h ?? '');
                        if ($powerOnHours !== null && is_numeric($h)) {
                            $delta = $powerOnHours - (int) $h;
                            $hoursCell = $delta > 0 ? $formatHoursAgo($delta) . " ({$h})" : "<0 hour ({$h})";
                        }
                        $rem = $entry['remaining_pct'] ?? null;
                        $lba = $entry['lba_first_error'] ?? null;
                        echo '<tr>'
                            . '<td>' . htmlspecialchars((string) ($entry['entry_num'] ?? '')) . '</td>'
                            . '<td>' . htmlspecialchars($data->decode('selftest_type', $entry['test_type'] ?? null)) . '</td>'
                            . '<td>' . htmlspecialchars($data->decode('selftest_result', $entry['result'] ?? null)) . '</td>'
                            . '<td>' . htmlspecialchars($hoursCell) . '</td>'
                            . '<td>' . ($rem !== null && is_numeric($rem) ? htmlspecialchars(((int) $rem) . '%') : '') . '</td>'
                            . '<td>' . ($lba !== null ? htmlspecialchars((string) $lba) : '') . '</td>'
                            . '</tr>';
                    }
                    echo '</tbody></table></div>';
                }
                if (! empty($disk['selective_test'])) {
                    echo '<h5 style="margin-top:12px;margin-bottom:6px"><strong>Selective Self-test Spans</strong></h5>';
                    echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover" style="width:auto">';
                    echo '<thead><tr><th>Slot</th><th>LBA Min</th><th>LBA Max</th><th>Status</th></tr></thead><tbody>';
                    foreach ($disk['selective_test'] as $entry) {
                        echo '<tr>'
                            . '<td>' . htmlspecialchars((string) ($entry['slot'] ?? '')) . '</td>'
                            . '<td>' . htmlspecialchars((string) ($entry['lba_min'] ?? '')) . '</td>'
                            . '<td>' . htmlspecialchars((string) ($entry['lba_max'] ?? '')) . '</td>'
                            . '<td>' . htmlspecialchars((string) ($entry['status_value'] ?? '')) . '</td>'
                            . '</tr>';
                    }
                    echo '</tbody></table></div>';
                }
                $panelEnd();
            @endphp
        </div>
        @endif

        {{-- Health / SCT (detailed only) --}}
        @if($showDetailed)
        <div>
            @php
                $panelStart('Health &amp; SCT', $healthBadge);
                echo '<table class="table table-condensed table-hover" style="width:auto">';
                $sctTemps = [];
                foreach (['sct_temp_lifetime_min' => 'Lifetime Min', 'sct_temp_lifetime_max' => 'Lifetime Max'] as $col => $lbl) {
                    if (isset($health[$col]) && is_numeric($health[$col])) {
                        $sctTemps[] = $lbl . ': ' . (int) $health[$col] . '°C';
                    }
                }
                $hrows = [
                    'SMART Status'         => isset($health['overall_status']) ? ((int) $health['overall_status'] === 1 ? 'Passed' : 'Not passed') : null,
                    'Self-test Status'     => $execRaw !== null ? $data->decode('selftest_exec', (int) $execRaw) : null,
                    'Self-test Remaining'  => is_numeric($remaining) ? ((int) $remaining) . '%' : null,
                    'Error Log Entries'    => $health['error_log_count'] ?? null,
                    'Self-test Log Count'  => $health['selftest_log_count'] ?? null,
                    'Pending Defects'      => $health['pending_defects_count'] ?? null,
                    'SCT Lifetime Temp'    => $sctTemps !== [] ? implode(' / ', $sctTemps) : null,
                    'SCT Over-limit Count' => $health['sct_temp_over_limit_count'] ?? null,
                    'SCT Under-limit Count' => $health['sct_temp_under_limit_count'] ?? null,
                ];
                foreach ($hrows as $label => $value) {
                    if ($value !== null && $value !== '') {
                        echo $tableRow($label, htmlspecialchars((string) $value), $tooltipForLabel($label));
                    }
                }
                echo '</table>';
                $panelEnd();
            @endphp
        </div>
        @endif
    </div>

    {{-- Attributes --}}
    @if(! empty($disk['attributes']))
        @php
            $panelStart('SMART Attributes');

            $attrAppId = $data->app->app_id;
            $attrNow   = LibrenmsConfig::get('time.now');
            $attrFrom  = LibrenmsConfig::get('time.day');
            $tblId     = 'smart-attr-tbl-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $idx);

            // Toolbar: text filter + failing-only toggle.
            echo '<div style="margin-bottom:8px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">'
                . '<input type="text" class="form-control input-sm" style="width:220px" placeholder="Filter attributes…"'
                . ' oninput="smartAttrFilter(\'' . $tblId . '\')" id="' . $tblId . '-q">'
                . '<label style="font-weight:normal;margin:0;cursor:pointer"><input type="checkbox" id="' . $tblId . '-fail"'
                . ' onchange="smartAttrFilter(\'' . $tblId . '\')"> Failing / failed only</label>'
                . '<span id="' . $tblId . '-flags" style="font-family:monospace"><span class="text-muted" style="font-size:12px;font-family:initial">Flags:</span> '
                . implode(' ', array_map(static fn ($f) => '<label style="font-weight:normal;margin:0;cursor:pointer">'
                    . '<input type="checkbox" value="' . $f . '" onchange="smartAttrFilter(\'' . $tblId . '\')"> ' . $f . '</label>', ['P', 'O', 'S', 'R', 'C', 'K']))
                . '</span>'
                . '<span class="text-muted" style="font-size:12px">Click a column header to sort.</span>'
                . '</div>';

            echo '<div class="table-responsive"><table id="' . $tblId . '" class="table table-condensed table-hover smart-attr-table">';
            echo '<thead><tr>'
                . '<th class="smart-attr-sort" data-type="num" onclick="smartAttrSort(this)" style="cursor:pointer">ID</th>'
                . '<th class="smart-attr-sort" data-type="str" onclick="smartAttrSort(this)" style="cursor:pointer">Name</th>'
                . '<th class="smart-attr-sort" data-type="num" onclick="smartAttrSort(this)" style="cursor:pointer">Status</th>'
                . '<th>Trend</th>'
                . '<th>Flags</th>'
                . '<th class="smart-attr-sort" data-type="num" onclick="smartAttrSort(this)" style="cursor:pointer">Value</th>'
                . '<th class="smart-attr-sort" data-type="num" onclick="smartAttrSort(this)" style="cursor:pointer">Worst</th>'
                . '<th class="smart-attr-sort" data-type="num" onclick="smartAttrSort(this)" style="cursor:pointer">Thresh</th>'
                . '<th class="smart-attr-sort" data-type="num" onclick="smartAttrSort(this)" style="cursor:pointer">Raw</th>'
                . '</tr></thead><tbody>';

            $dark = session('applied_site_style') === 'dark';

            foreach ($disk['attributes'] as $attr) {
                $status = (int) ($attr['status'] ?? 0);
                $statusLabel = $status === -1 ? 'NA' : $data->decode('attr_status', $attr['status'] ?? null);

                // Row shading by status (dark-mode aware): 2 red, 3 light red, -1 muted.
                $rowStyle = match ($status) {
                    2  => $dark ? 'background-color:#5a2a2a' : 'background-color:#f2a8a8',
                    3  => $dark ? 'background-color:#3f2a2c' : 'background-color:#fbdede',
                    -1 => $dark ? 'background-color:#15171a' : 'background-color:#f4f4f4',
                    default => '',
                };
                $isFail = ($status === 2 || $status === 3) ? '1' : '0';

                $thresh   = $attr['value_threshold'] ?? null;
                $value    = $attr['value_norm'] ?? null;
                $worst    = $attr['value_worst'] ?? null;
                $rawNum   = is_numeric($attr['value_raw'] ?? null) ? (float) $attr['value_raw'] : 0;
                $rawDisp  = $data->formatRawSpaced($attr['value_raw_string'] ?? $attr['value_raw'] ?? '');
                $attrId   = (int) ($attr['attribute_id'] ?? 0);
                $name     = str_replace('_', ' ', (string) ($attr['name'] ?? ''));

                $flagLines = $data->attributeFlagLines($attr);
                $flagsTip  = htmlspecialchars(implode("\n", $flagLines), ENT_QUOTES);
                $flagsRaw  = $data->attributeFlagsPositional($attr);
                $flagsStr  = htmlspecialchars($flagsRaw);
                $flagsCell = $flagLines !== []
                    ? '<span data-toggle="tooltip" data-placement="top" title="' . $flagsTip . '" style="cursor:default;border-bottom:1px dotted;font-family:monospace">' . $flagsStr . '</span>'
                    : '<span style="font-family:monospace">' . $flagsStr . '</span>';

                $valueTip = 'Normalized value (1–253, higher is better)';
                if (is_numeric($thresh) && is_numeric($value)) {
                    $valueTip .= (float) $value < (float) $thresh ? "\nFAIL: below threshold " . $thresh : "\nOK: above threshold " . $thresh;
                }
                $valueCell = '<span data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars($valueTip, ENT_QUOTES) . '" style="cursor:default;border-bottom:1px dotted">' . htmlspecialchars((string) ($value ?? '')) . '</span>';

                $worstTip = 'Worst normalized value ever recorded';
                if (is_numeric($thresh) && is_numeric($worst)) {
                    $worstTip .= (float) $worst < (float) $thresh ? "\nFAIL: below threshold " . $thresh : "\nOK: above threshold " . $thresh;
                }
                $worstCell = '<span data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars($worstTip, ENT_QUOTES) . '" style="cursor:default;border-bottom:1px dotted">' . htmlspecialchars((string) ($worst ?? '')) . '</span>';

                $threshCell = '<span data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars('Failure threshold - attribute fails when Value drops below this', ENT_QUOTES) . '" style="cursor:default;border-bottom:1px dotted">' . htmlspecialchars((string) ($thresh ?? '')) . '</span>';
                $rawCell = '<span data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars('Raw hardware reading - vendor-specific meaning', ENT_QUOTES) . '" style="cursor:default;border-bottom:1px dotted">' . htmlspecialchars($rawDisp) . '</span>';

                $statusBadge = match ($status) {
                    1  => '<span class="label label-default">' . htmlspecialchars($statusLabel) . '</span>',
                    2  => '<span class="label label-danger">' . htmlspecialchars($statusLabel) . '</span>',
                    3  => '<span class="label" style="background-color:#e8857f">' . htmlspecialchars($statusLabel) . '</span>',
                    default => '<span class="text-muted">' . htmlspecialchars($statusLabel) . '</span>',
                };

                // In-row mini graph: the same smart_v2_attributes graph, 60x15.
                $mini = '';
                if ($attrId > 0) {
                    $miniSrc = 'graph.php?type=application_smart_v2_attributes'
                        . '&id=' . rawurlencode((string) $attrAppId)
                        . '&disk=' . rawurlencode((string) $idx)
                        . '&attr_id=' . $attrId
                        . '&has_raw=1&has_norm=1&legend=no'
                        . '&from=' . rawurlencode((string) $attrFrom)
                        . '&to=' . rawurlencode((string) $attrNow)
                        . '&width=60&height=15';
                    $mini = '<img loading="lazy" width="60" height="15" src="' . htmlspecialchars($miniSrc, ENT_QUOTES) . '" alt="trend" style="display:block">';
                }

                echo '<tr style="' . $rowStyle . '" data-fail="' . $isFail . '" data-flags="' . htmlspecialchars($flagsRaw, ENT_QUOTES) . '">'
                    . '<td data-sort="' . $attrId . '">' . $attrId . '</td>'
                    . '<td data-sort="' . htmlspecialchars($name, ENT_QUOTES) . '">' . htmlspecialchars($name) . '</td>'
                    . '<td data-sort="' . $status . '">' . $statusBadge . '</td>'
                    . '<td>' . $mini . '</td>'
                    . '<td>' . $flagsCell . '</td>'
                    . '<td data-sort="' . htmlspecialchars((string) ($value ?? ''), ENT_QUOTES) . '">' . $valueCell . '</td>'
                    . '<td data-sort="' . htmlspecialchars((string) ($worst ?? ''), ENT_QUOTES) . '">' . $worstCell . '</td>'
                    . '<td data-sort="' . htmlspecialchars((string) ($thresh ?? ''), ENT_QUOTES) . '">' . $threshCell . '</td>'
                    . '<td data-sort="' . $rawNum . '">' . $rawCell . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table></div>';

            echo <<<'JS'
<script>
function smartAttrFilter(tblId) {
    var t = document.getElementById(tblId); if (!t) return;
    var q = (document.getElementById(tblId + '-q').value || '').toLowerCase();
    var failOnly = document.getElementById(tblId + '-fail').checked;
    var flagBox = document.getElementById(tblId + '-flags');
    var flags = flagBox ? Array.prototype.map.call(flagBox.querySelectorAll('input:checked'), function (c) { return c.value; }) : [];
    Array.prototype.forEach.call(t.tBodies[0].rows, function (r) {
        var hit = !q || r.textContent.toLowerCase().indexOf(q) !== -1;
        var fail = !failOnly || r.getAttribute('data-fail') === '1';
        var rf = r.getAttribute('data-flags') || '';
        var flagOk = flags.every(function (f) { return rf.indexOf(f) !== -1; });
        r.style.display = (hit && fail && flagOk) ? '' : 'none';
    });
}
function smartAttrSort(th) {
    var table = th.closest('table');
    var head = th.parentNode;
    var idx = Array.prototype.indexOf.call(head.children, th);
    var type = th.getAttribute('data-type') || 'str';
    var asc = !th.classList.contains('asc');
    Array.prototype.forEach.call(head.children, function (h) { h.classList.remove('asc', 'desc'); });
    th.classList.add(asc ? 'asc' : 'desc');
    var tbody = table.tBodies[0];
    var rows = Array.prototype.slice.call(tbody.rows);
    var key = function (r) {
        var c = r.cells[idx];
        return c.getAttribute('data-sort') !== null ? c.getAttribute('data-sort') : c.textContent.trim();
    };
    rows.sort(function (a, b) {
        var av = key(a), bv = key(b);
        if (type === 'num') { return (asc ? 1 : -1) * ((parseFloat(av) || 0) - (parseFloat(bv) || 0)); }
        return (asc ? 1 : -1) * String(av).localeCompare(String(bv));
    });
    rows.forEach(function (r) { tbody.appendChild(r); });
}
</script>
JS;
            $panelEnd();
        @endphp
    @endif

    @if($showDetailed)
        {{-- Error log --}}
        @if(! empty($disk['errors']))
            @php
                $panelStart('SMART Error Log', (string) count($disk['errors']));
                echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover">';
                echo '<thead><tr><th>#</th><th>Hours</th><th>Type</th><th>Device State</th><th>Previous Commands</th></tr></thead><tbody>';
                foreach ($disk['errors'] as $entry) {
                    $entryNum = (int) ($entry['entry_num'] ?? 0);
                    $h = $entry['lifetime_hours'] ?? null;
                    $hoursCell = (string) ($h ?? '');
                    if ($powerOnHours !== null && is_numeric($h)) {
                        $delta = $powerOnHours - (int) $h;
                        $hoursCell = $delta > 0 ? $formatHoursAgo($delta) . " ({$h})" : "<0 hour ({$h})";
                    }
                    $cmds = $disk['error_cmds'][$entryNum] ?? [];
                    $cmdHtml = '';
                    if ($cmds !== []) {
                        $cmdHtml = '<table class="table table-condensed" style="margin:0;background:transparent">'
                            . '<thead><tr><th>Cmd</th><th>LBA</th><th>Count</th><th>Feature</th><th>Uptime (ms)</th></tr></thead><tbody>';
                        foreach ($cmds as $cmd) {
                            $cmdHtml .= '<tr>'
                                . '<td>' . htmlspecialchars((string) ($cmd['description'] ?? $cmd['reg_command'] ?? '')) . '</td>'
                                . '<td>' . htmlspecialchars((string) ($cmd['reg_lba'] ?? '')) . '</td>'
                                . '<td>' . htmlspecialchars((string) ($cmd['reg_count'] ?? '')) . '</td>'
                                . '<td>' . htmlspecialchars((string) ($cmd['reg_feature'] ?? '')) . '</td>'
                                . '<td>' . htmlspecialchars((string) ($cmd['powerup_ms'] ?? '')) . '</td>'
                                . '</tr>';
                        }
                        $cmdHtml .= '</tbody></table>';
                    }
                    echo '<tr>'
                        . '<td>' . htmlspecialchars((string) ($entry['error_count'] ?? $entryNum)) . '</td>'
                        . '<td>' . htmlspecialchars($hoursCell) . '</td>'
                        . '<td>' . htmlspecialchars((string) ($entry['error_type'] ?? '')) . '</td>'
                        . '<td>' . htmlspecialchars($data->decode('device_state', $entry['device_state'] ?? null)) . '</td>'
                        . '<td>' . $cmdHtml . '</td>'
                        . '</tr>';
                }
                echo '</tbody></table></div>';
                $panelEnd();
            @endphp
        @endif

        {{-- Device statistics (one panel per page, flex row) --}}
        @php
            $fmtStatVal  = static function ($v): string {
                if (is_numeric($v) && abs((float) $v) >= 1000000) {
                    return Number::formatSi((float) $v, 2, 0, '');
                }
                return htmlspecialchars((string) ($v ?? ''));
            };
            $fmtStatName = static function (string $s): string {
                static $exactMap = [
                    'poh'  => 'Power-on hours',
                    'spoh' => 'Spin power-on hours',
                ];
                static $wordMap = [
                    'dvga'  => 'Delta Variable Gain Amplifier',
                    'rvga'  => 'Running Average Variable Gain Amplifier',
                    'fvga'  => 'Filter Variable Gain Amplifier',
                    'dos'   => 'Directed Offline Scan',
                    'isp'   => 'Intermediate Super Parity',
                    'h2sat' => 'Head Self-Assessment Test',
                    'mr'    => 'Magneto Resistive',
                ];
                if (isset($exactMap[$s])) {
                    return htmlspecialchars($exactMap[$s]);
                }
                $words = array_map(
                    static fn ($w) => $wordMap[$w] ?? ucfirst($w),
                    explode('_', strtolower($s))
                );
                return htmlspecialchars(implode(' ', $words));
            };
            $fmtStatLabel = static function (string $s) use ($fmtStatName, $labelWithTooltip): string {
                $label = html_entity_decode($fmtStatName($s), ENT_QUOTES);

                return $labelWithTooltip($label);
            };
            $fmtFarmStatLabel = static function (string $s) use ($fmtStatName, $labelWithTooltip, $tooltipForLabel): string {
                $label = html_entity_decode($fmtStatName($s), ENT_QUOTES);

                return $labelWithTooltip($label, $tooltipForLabel($label));
            };
            $fmtMilli = static function ($v, string $unit): string {
                if ($v === null || $v === '') { return ''; }
                return htmlspecialchars(number_format((float) $v / 1000, 3)) . ' ' . $unit;
            };

            $farmSubTables = static function (string $pageName, array $rows) use ($fmtStatVal): array {
                if (! str_starts_with($pageName, 'FARM ')) {
                    return ['scalars' => $rows, 'groups' => []];
                }
                $byName   = [];
                foreach ($rows as $r) { $byName[$r['stat_name'] ?? ''] = $r; }
                $scalars  = [];
                $groups   = [];
                $extract  = [];
                $consumed = [];

                if ($pageName === 'FARM Environment Statistics') {
                    $tempMap = [
                        'curent_temp'        => ['instant', 'current'],
                        'highest_temp'       => ['instant', 'highest'],
                        'lowest_temp'        => ['instant', 'lowest'],
                        'average_temp'       => ['short',   'average'],
                        'highest_short_temp' => ['short',   'highest'],
                        'lowest_short_temp'  => ['short',   'lowest'],
                        'average_long_temp'  => ['long',    'average'],
                        'highest_long_temp'  => ['long',    'highest'],
                        'lowest_long_temp'   => ['long',    'lowest'],
                    ];
                    $tempData = [];
                    foreach ($tempMap as $stat => [$row, $col]) {
                        if (isset($byName[$stat])) {
                            $tempData[$row][$col] = $byName[$stat]['value'];
                            $consumed[$stat]      = true;
                        }
                    }
                    if ($tempData) {
                        $groups[] = ['title' => 'Temperature (°C)', 'type' => 'temp_matrix', 'data' => $tempData];
                    }

                    $limitData = [];
                    foreach ([['max_temp','over_temp_time','Maximum'],['min_temp','under_temp_time','Minimum']] as [$lStat,$tStat,$label]) {
                        if (isset($byName[$lStat], $byName[$tStat])) {
                            $limitData[] = ['label' => $label, 'limit' => $byName[$lStat]['value'], 'time' => $byName[$tStat]['value']];
                            $consumed[$lStat] = $consumed[$tStat] = true;
                        }
                    }
                    if ($limitData) {
                        $groups[] = ['title' => 'Operating Limits', 'type' => 'limits', 'data' => $limitData];
                    }

                    $voltageRails = [
                        '12V (mV)' => ['Current' => 'current_12v_in_mv', 'Minimum' => 'minimum_12v_in_mv', 'Maximum' => 'maximum_12v_in_mv'],
                        '5V (mV)'  => ['Current' => 'current_5v_in_mv',  'Minimum' => 'minimum_5v_in_mv',  'Maximum' => 'maximum_5v_in_mv'],
                    ];
                    $voltData = [];
                    foreach ($voltageRails as $label => $statCols) {
                        $row = ['label' => $label];
                        foreach ($statCols as $col => $stat) {
                            $row[$col] = isset($byName[$stat]) ? $byName[$stat]['value'] : null;
                            if (isset($byName[$stat])) { $consumed[$stat] = true; }
                        }
                        $voltData[] = $row;
                    }
                    if ($voltData) {
                        $groups[] = ['title' => 'Voltage', 'type' => 'voltage', 'data' => $voltData];
                    }

                    $powerRails = [
                        '12V' => ['Average' => 'average_12v_power', 'Minimum' => 'minimum_12v_power', 'Maximum' => 'maximum_12v_power'],
                        '5V'  => ['Average' => 'average_5v_power',  'Minimum' => 'minimum_5v_power',  'Maximum' => 'maximum_5v_power'],
                    ];
                    $powerData = [];
                    foreach ($powerRails as $label => $statCols) {
                        $row = ['label' => $label];
                        foreach ($statCols as $col => $stat) {
                            $row[$col] = isset($byName[$stat]) ? $byName[$stat]['value'] : null;
                            if (isset($byName[$stat])) { $consumed[$stat] = true; }
                        }
                        $powerData[] = $row;
                    }
                    if (isset($byName['current_motor_power'])) {
                        $powerData[] = ['label' => 'Motor', 'Average' => null, 'Minimum' => null, 'Maximum' => null, 'Current' => $byName['current_motor_power']['value']];
                        $consumed['current_motor_power'] = true;
                    }
                    if ($powerData) {
                        $groups[] = ['title' => 'Power', 'type' => 'power', 'data' => $powerData];
                    }

                } elseif ($pageName === 'FARM Error Statistics') {
                    $flashEvents = [];
                    $cumulHead   = [];
                    foreach ($rows as $r) {
                        $stat = $r['stat_name'] ?? '';
                        if (preg_match('/^flash_led_event_(\d+)\.(.+)$/', $stat, $m)) {
                            $flashEvents[(int) $m[1]][$m[2]] = $r['value'];
                            $consumed[$stat] = true;
                        } elseif (preg_match('/^cum_lifetime_unrecoverable_by_head_(\d+)\.(.+)$/', $stat, $m)) {
                            $cumulHead[(int) $m[1]][$m[2]] = $r['value'];
                            $consumed[$stat] = true;
                        }
                    }
                    if ($flashEvents) {
                        ksort($flashEvents);
                        $extract[] = ['title' => 'Flash LED events', 'type' => 'flash_led', 'source' => $pageName,
                            'data' => ['events' => $flashEvents, 'fields' => array_keys(reset($flashEvents))]];
                    }
                    if ($cumulHead) {
                        ksort($cumulHead);
                        $extract[] = ['title' => 'Cumulative lifetime unrecoverable errors by head', 'type' => 'cum_head', 'source' => $pageName,
                            'data' => ['heads' => $cumulHead, 'fields' => array_keys(reset($cumulHead))]];
                    }

                } elseif ($pageName === 'FARM Reliability Statistics') {
                    $byHead = [];
                    foreach ($rows as $r) {
                        $stat = $r['stat_name'] ?? '';
                        if (preg_match('/^(.+)_by_head_(\d+)$/', $stat, $m) ||
                            preg_match('/^(.+)_from_head_(\d+)$/', $stat, $m)) {
                            $byHead[$m[1]][(int) $m[2]] = $r['value'];
                            $consumed[$stat] = true;
                        }
                    }
                    if ($byHead) {
                        $allHeads = [];
                        foreach ($byHead as $vals) { $allHeads = array_merge($allHeads, array_keys($vals)); }
                        $allHeads = array_values(array_unique($allHeads));
                        sort($allHeads);
                        $extract[] = ['title' => 'By head', 'type' => 'by_head', 'source' => $pageName,
                            'data' => ['metrics' => $byHead, 'heads' => $allHeads]];
                    }

                } elseif ($pageName === 'FARM Workload Statistics') {
                    $radRows = [];
                    foreach ($rows as $r) {
                        $stat = $r['stat_name'] ?? '';
                        if (preg_match('/^(read|write)_commands_by_radius_(\d+)_(\d+)$/', $stat, $m)) {
                            $range = $m[2] . '-' . $m[3] . '%';
                            $radRows[$range][$m[1]] = $r['value'];
                            $consumed[$stat] = true;
                        }
                    }
                    if ($radRows) {
                        $groups[] = ['title' => 'Commands by disk radius', 'type' => 'by_radius',
                            'data' => $radRows];
                    }
                }

                foreach ($rows as $r) {
                    if (! isset($consumed[$r['stat_name'] ?? ''])) {
                        $scalars[] = $r;
                    }
                }
                return ['scalars' => $scalars, 'groups' => $groups, 'extract' => $extract];
            };

            $renderSubTable = static function (array $group, bool $skipTitle = false, bool $fullWidth = false) use ($fmtStatVal, $fmtStatName, $fmtFarmStatLabel, $fmtMilli, $labelWithTooltip): void {
                $type  = $group['type'];
                $data  = $group['data'];
                $title = htmlspecialchars($group['title']);
                if (! $skipTitle) {
                    echo '<h5 style="margin:14px 0 6px;font-size:14px;font-weight:600">' . $title . '</h5>';
                }

                $tblStyle = ($fullWidth ? 'width:100%' : 'width:auto') . ';font-size:12px';

                if ($type === 'temp_matrix') {
                    $horizons = ['instant' => 'Instant', 'short' => 'Short-term avg', 'long' => 'Long-term avg'];
                    $cols     = ['current' => 'Current', 'average' => 'Average', 'highest' => 'Highest', 'lowest' => 'Lowest'];
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th></th>';
                    foreach ($cols as $col => $colLabel) { echo '<th>' . $colLabel . '</th>'; }
                    echo '</tr></thead><tbody>';
                    foreach ($horizons as $rowKey => $rowLabel) {
                        if (! isset($data[$rowKey])) { continue; }
                        $tooltip = match ($rowKey) {
                            'instant' => 'Current device temperature at read time.',
                            'short' => 'Average of the most recent 144 ten-minute samples over a 24-hour period.',
                            'long' => 'Average of the most recent 42 short-term daily averages; valid after about 1008 hours.',
                            default => '',
                        };
                        echo '<tr><td><strong>' . $labelWithTooltip($rowLabel, $tooltip) . '</strong></td>';
                        foreach ($cols as $col => $_) {
                            $v = $data[$rowKey][$col] ?? null;
                            echo '<td>' . ($v !== null ? $fmtStatVal($v) : '<span class="text-muted">—</span>') . '</td>';
                        }
                        echo '</tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'limits') {
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th></th><th>Limit (°C)</th><th>Time over (min)</th></tr></thead><tbody>';
                    foreach ($data as $row) {
                        $tooltipLabel = $row['label'] === 'Maximum' ? 'Specified maximum operating temperature' : 'Specified minimum operating temperature';
                        echo '<tr><td><strong>' . $labelWithTooltip($row['label'], $tooltipLabel === 'Specified maximum operating temperature'
                            ? 'Manufacturer-specified maximum operating temperature for the device.'
                            : 'Manufacturer-specified minimum operating temperature for the device.') . '</strong></td>'
                            . '<td>' . $fmtStatVal($row['limit']) . '</td>'
                            . '<td>' . $fmtStatVal($row['time']) . '</td></tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'voltage') {
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th>Rail</th><th>Current</th><th>Minimum</th><th>Maximum</th></tr></thead><tbody>';
                    foreach ($data as $row) {
                        $tooltip = str_starts_with($row['label'], '12V')
                            ? 'Voltage readings for the 12V power line: current, minimum observed, and maximum observed.'
                            : 'Voltage readings for the 5V power line: current, minimum observed, and maximum observed.';
                        echo '<tr><td><strong>' . $labelWithTooltip($row['label'], $tooltip) . '</strong></td>'
                            . '<td>' . $fmtMilli($row['Current'], 'V') . '</td>'
                            . '<td>' . $fmtMilli($row['Minimum'], 'V') . '</td>'
                            . '<td>' . $fmtMilli($row['Maximum'], 'V') . '</td></tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'power') {
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th>Rail</th><th>Current</th><th>Average</th><th>Minimum</th><th>Maximum</th></tr></thead><tbody>';
                    foreach ($data as $row) {
                        $tooltip = match ($row['label']) {
                            '12V' => 'Power readings in watts for the 12V power line: average, minimum, and maximum.',
                            '5V' => 'Power readings in watts for the 5V power line: average, minimum, and maximum.',
                            'Motor' => 'Current motor power scalar value used by the servo to keep the motor spinning.',
                            default => '',
                        };
                        echo '<tr><td><strong>' . $labelWithTooltip($row['label'], $tooltip) . '</strong></td>'
                            . '<td>' . $fmtMilli($row['Current'] ?? null, 'W') . '</td>'
                            . '<td>' . $fmtMilli($row['Average'] ?? null, 'W') . '</td>'
                            . '<td>' . $fmtMilli($row['Minimum'] ?? null, 'W') . '</td>'
                            . '<td>' . $fmtMilli($row['Maximum'] ?? null, 'W') . '</td></tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'flash_led') {
                    $events = $data['events'];
                    $fields = $data['fields'];
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th>Field</th>';
                    foreach (array_keys($events) as $ev) { echo '<th>Event ' . $ev . '</th>'; }
                    echo '</tr></thead><tbody>';
                    foreach ($fields as $field) {
                        echo '<tr><td>' . $fmtFarmStatLabel($field) . '</td>';
                        foreach ($events as $ev => $_) {
                            echo '<td>' . $fmtStatVal($events[$ev][$field] ?? null) . '</td>';
                        }
                        echo '</tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'cum_head') {
                    $heads  = $data['heads'];
                    $fields = $data['fields'];
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th></th>';
                    foreach (array_keys($heads) as $h) { echo '<th>H' . $h . '</th>'; }
                    echo '</tr></thead><tbody>';
                    foreach ($fields as $f) {
                        echo '<tr><td>' . $fmtFarmStatLabel($f) . '</td>';
                        foreach ($heads as $h => $vals) { echo '<td>' . $fmtStatVal($vals[$f] ?? null) . '</td>'; }
                        echo '</tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'by_head') {
                    $metrics = $data['metrics'];
                    $heads   = $data['heads'];
                    $avgMetrics = ['write_workload_power_on_time'];
                    echo '<table class="table table-condensed table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th>Metric</th>';
                    foreach ($heads as $h) { echo '<th style="text-align:right">H' . $h . '</th>'; }
                    echo '<th style="text-align:right">Total / Avg</th></tr></thead><tbody>';
                    foreach ($metrics as $metric => $headVals) {
                        $numVals = array_filter(
                            array_map(static fn ($h) => $headVals[$h] ?? null, $heads),
                            static fn ($v) => is_numeric($v)
                        );
                        $rowMax   = $numVals ? max($numVals) : 0;
                        $rowMin   = $numVals ? min($numVals) : 0;
                        $rowRange = $rowMax - $rowMin;
                        $isAvg    = in_array($metric, $avgMetrics, true);
                        $aggregate = $numVals
                            ? ($isAvg
                                ? array_sum($numVals) / count($numVals)
                                : array_sum($numVals))
                            : null;
                        echo '<tr><td>' . $fmtFarmStatLabel($metric) . '</td>';
                        foreach ($heads as $h) {
                            $v   = $headVals[$h] ?? null;
                            $pct = ($rowRange > 0 && is_numeric($v))
                                ? round(($v - $rowMin) / $rowRange * 100)
                                : 0;
                            $bg  = ($rowMax > 0 && $pct > 0)
                                ? ' style="text-align:right;background:linear-gradient(to top,rgba(70,130,180,0.22) ' . $pct . '%,transparent ' . $pct . '%)"'
                                : ' style="text-align:right"';
                            echo '<td' . $bg . '>' . $fmtStatVal($v) . '</td>';
                        }
                        $aggDisplay = $aggregate !== null ? $fmtStatVal(round($aggregate)) : '';
                        echo '<td style="text-align:right;font-weight:600">' . $aggDisplay . ($isAvg ? ' <small class="text-muted">avg</small>' : '') . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'by_radius') {
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th>Radius</th><th>Read</th><th>Write</th></tr></thead><tbody>';
                    foreach ($data as $range => $vals) {
                        echo '<tr><td>' . $labelWithTooltip((string) $range, 'Read and write command counts grouped by their approximate disk-radius location.') . '</td>'
                            . '<td>' . $fmtStatVal($vals['read'] ?? null) . '</td>'
                            . '<td>' . $fmtStatVal($vals['write'] ?? null) . '</td></tr>';
                    }
                    echo '</tbody></table>';
                }
            };

            $isFarmPage   = static fn (string $pn): bool => str_starts_with($pn, 'FARM ');
            $skipRows = \LibreNMS\Agent\Unix\Smart\HtmlData::DEV_STAT_SKIP_ROWS;

            $devStatPanelPages = [];
            foreach ($disk['dev_stats'] as $page) {
                $pn = $page['page_name'] ?: $data->decode('dev_stat_page', $page['page_num']);
                if (in_array($pn, \LibreNMS\Agent\Unix\Smart\HtmlData::DEV_STAT_SKIP_PAGES, true)) { continue; }
                if (! in_array($pn, $devStatKnownPanels, true)) { continue; }
                $isFarm = $isFarmPage($pn);
                $rows = array_filter(
                    $page['rows'],
                    static fn ($r) => ($isFarm || ($r['valid'] ?? 1) != 0)
                        && ! in_array((string) ($r['stat_name'] ?? ''), $skipRows, true)
                );
                if (! $rows) { continue; }
                $devStatPanelPages[] = ['page_name' => $pn, 'rows' => array_values($rows)];
            }
        @endphp
        @if(! empty($devStatPanelPages))
        @php $devStatExtractPanels = []; @endphp
        <div class="smart-panels">
            @foreach($devStatPanelPages as $devPage)
            <div>
                @php
                    $pageName = $devPage['page_name'];
                    $panelStart(htmlspecialchars($pageName));
                    if (str_starts_with($pageName, 'FARM ')) {
                        echo '<p style="font-size:11px;margin:0 0 8px">'
                            . '<a href="https://github.com/Seagate/openSeaChest/wiki/Drive-Health-and-SMART" target="_blank" rel="noopener">Seagate FARM reference</a>'
                            . '</p>';
                    }
                    $sub = $farmSubTables($pageName, $devPage['rows']);

                    if ($sub['scalars']) {
                        echo '<table class="table table-condensed table-striped table-hover" style="width:auto">';
                        echo '<thead><tr><th>Statistic</th><th>Value</th></tr></thead><tbody>';
                        foreach ($sub['scalars'] as $r) {
                            $statLabel = str_starts_with($pageName, 'FARM ')
                                ? $fmtFarmStatLabel((string) ($r['stat_name'] ?? ''))
                                : $fmtStatLabel((string) ($r['stat_name'] ?? ''));
                            echo '<tr><td>' . $statLabel . '</td>'
                                . '<td>' . $fmtStatVal($r['value'] ?? null) . '</td></tr>';
                        }
                        echo '</tbody></table>';
                    }
                    foreach ($sub['groups'] as $group) {
                        $renderSubTable($group);
                    }
                    foreach ($sub['extract'] as $ep) {
                        $devStatExtractPanels[] = $ep;
                    }
                    $panelEnd();
                @endphp
            </div>
            @endforeach
            @php
                // Merge by_head + cum_head into one panel
                $byHeadIdx  = null;
                $cumHeadIdx = null;
                foreach ($devStatExtractPanels as $i => $ep) {
                    if ($ep['type'] === 'by_head')  { $byHeadIdx  = $i; }
                    if ($ep['type'] === 'cum_head') { $cumHeadIdx = $i; }
                }
                if ($byHeadIdx !== null && $cumHeadIdx !== null) {
                    $cumEp = $devStatExtractPanels[$cumHeadIdx];
                    $cumMetrics = [];
                    foreach ($cumEp['data']['fields'] as $f) {
                        foreach ($cumEp['data']['heads'] as $h => $vals) {
                            $cumMetrics[$f][$h] = $vals[$f] ?? null;
                        }
                    }
                    $devStatExtractPanels[$byHeadIdx]['data']['metrics'] = array_merge(
                        $devStatExtractPanels[$byHeadIdx]['data']['metrics'],
                        $cumMetrics
                    );
                    $devStatExtractPanels[$byHeadIdx]['source'] =
                        $devStatExtractPanels[$byHeadIdx]['source'] . ' &amp; ' . htmlspecialchars($cumEp['source']);
                    $devStatExtractPanels[$byHeadIdx]['title'] = 'Per-head statistics';
                    unset($devStatExtractPanels[$cumHeadIdx]);
                }
            @endphp
            @foreach($devStatExtractPanels as $ep)
            <div style="flex: 0 0 100%; width: 100%">
                @php
                    $panelStart(htmlspecialchars($ep['title']));
                    echo '<p style="font-size:11px;margin:0 0 8px">'
                        . 'Data from <em>' . $ep['source'] . '</em>'
                        . ' &mdash; <a href="https://github.com/Seagate/openSeaChest/wiki/Drive-Health-and-SMART" target="_blank" rel="noopener">Seagate FARM reference</a>'
                        . '</p>';
                    $renderSubTable($ep, true, true);
                    $panelEnd();
                @endphp
            </div>
            @endforeach
        </div>
        @endif

        {{-- Pending Defects --}}
        @if(! empty($disk['pending_defects']))
            @php
                $panelStart('Pending Defects', (string) count($disk['pending_defects']));
                echo '<table class="table table-condensed table-hover" style="width:auto">';
                echo '<thead><tr><th>#</th><th>LBA</th></tr></thead><tbody>';
                foreach ($disk['pending_defects'] as $pd) {
                    echo '<tr><td>' . htmlspecialchars((string) ($pd['entry_num'] ?? ''))
                        . '</td><td>' . htmlspecialchars((string) ($pd['lba'] ?? '')) . '</td></tr>';
                }
                echo '</tbody></table>';
                $panelEnd();
            @endphp
        @endif

        @if($showDetailed)
        {{-- Last row: SATA PHY Event Counters, Error Recovery Control, Capabilities, Log Directory --}}
        @php
            $capFields = [
                'capability_selftests_supported'     => 'Self-tests supported',
                'capability_conveyance_supported'    => 'Conveyance self-test',
                'capability_selective_supported'     => 'Selective self-test',
                'capability_error_logging_supported' => 'Error logging',
                'capability_gp_logging_supported'    => 'GP logging',
                'capability_exec_offline_immediate'  => 'Exec offline immediate',
                'capability_offline_aborted_on_cmd'  => 'Offline aborted on command',
                'capability_offline_surface_scan'    => 'Offline surface scan',
                'capability_attr_autosave'           => 'Attribute autosave',
                'sct_error_recovery_supported'       => 'SCT error recovery control',
                'sct_feature_control_supported'      => 'SCT feature control',
                'sct_data_table_supported'           => 'SCT data table',
            ];
            $capRows = array_filter($capFields, fn ($col) => isset($info[$col]), ARRAY_FILTER_USE_KEY);
        @endphp
        @if(! empty($disk['phy_events']) || ! empty($disk['erc']) || $capRows !== [] || ! empty($disk['log_dir']))
        <div class="smart-panels">
            @if(! empty($disk['erc']))
            <div>
                @php
                    $panelStart('Error Recovery Control (SCT ERC)');
                    echo '<table class="table table-condensed table-hover" style="width:auto">';
                    foreach ($disk['erc'] as $direction => $row) {
                        $label = $data->decode('erc_direction', $direction);
                        $ds = $row['deciseconds'] ?? null;
                        $val = ($row['enabled'] ?? 0)
                            ? (is_numeric($ds) ? number_format($ds / 10, 1) . ' s' : 'Enabled')
                            : 'Disabled';
                        echo $tableRow($label, htmlspecialchars($val), $tooltipForLabel($label));
                    }
                    echo '</table>';
                    $panelEnd();
                @endphp
            </div>
            @endif

            @if($capRows !== [])
            <div>
                @php
                    $panelStart('Capabilities');
                    echo '<table class="table table-condensed table-hover" style="width:auto">';
                    foreach ($capRows as $col => $label) {
                        $val = (int) $info[$col];
                        $icon = $val ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>';
                        echo $tableRow($label, $icon, $tooltipForLabel($label));
                    }
                    echo '</table>';
                    $panelEnd();
                @endphp
            </div>
            @endif

            @if(! empty($disk['log_dir']))
            <div>
                @php
                    $panelStart('Log Directory', (string) count($disk['log_dir']));
                    echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover" style="width:auto">';
                    echo '<thead><tr><th>Address</th><th>Name</th><th>Readable</th><th>Writable</th><th>GP Sectors</th><th>SMART Sectors</th></tr></thead><tbody>';
                    foreach ($disk['log_dir'] as $entry) {
                        $rd = $entry['readable'] ?? null;
                        $wr = $entry['writable'] ?? null;
                        echo '<tr>'
                            . '<td>0x' . sprintf('%02X', (int) ($entry['log_address'] ?? 0)) . '</td>'
                            . '<td>' . htmlspecialchars((string) ($entry['name'] ?? '')) . '</td>'
                            . '<td>' . ($rd !== null ? ((int) $rd ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>') : '') . '</td>'
                            . '<td>' . ($wr !== null ? ((int) $wr ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>') : '') . '</td>'
                            . '<td>' . htmlspecialchars((string) ($entry['gp_sectors'] ?? '')) . '</td>'
                            . '<td>' . htmlspecialchars((string) ($entry['smart_sectors'] ?? '')) . '</td>'
                            . '</tr>';
                    }
                    echo '</tbody></table></div>';
                    $panelEnd();
                @endphp
            </div>
            @endif

            @if(! empty($disk['phy_events']))
            <div>
                @php
                    $panelStart('SATA PHY Event Counters');
                    echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover" style="width:auto">';
                    echo '<thead><tr><th>ID</th><th>Name</th><th>Value</th></tr></thead><tbody>';
                    foreach ($disk['phy_events'] as $ev) {
                        $val = (string) ($ev['value'] ?? '');
                        if (($ev['overflow'] ?? 0)) { $val .= ' <span class="text-warning">(overflow)</span>'; }
                        echo '<tr><td>' . htmlspecialchars((string) ($ev['event_id'] ?? '')) . '</td>'
                            . '<td>' . htmlspecialchars((string) ($ev['name'] ?? '')) . '</td>'
                            . '<td>' . $val . '</td></tr>';
                    }
                    echo '</tbody></table></div>';
                    $panelEnd();
                @endphp
            </div>
            @endif
        </div>
        @endif
        @endif
    @endif
    @endif

    {{-- Graphs --}}
    @php
        $now      = LibrenmsConfig::get('time.now');
        $appId    = $data->app->app_id;
        $anchorPrefix = 'smart-device-' . $idx . '-graph-';
        $tempSensor   = $data->temperatureSensor($selectedDisk);
        $healthSensor = $data->healthSensor($selectedDisk);
        $specs        = $data->attributeGraphSpecs($selectedDisk);
        $hasBig5      = $data->hasBig5Rrd($selectedDisk);
        $hasOther     = $data->hasOtherRrd($selectedDisk);
        $graphBase    = Url::generate($linkArray + ['disk' => (string) $selectedDisk]);

        $diskSensors  = $data->diskSensors($selectedDisk);
        $wearSensor   = $diskSensors[$idx . '_wear'] ?? null;
        $statusSensor = $data->selftestStatusSensor($selectedDisk);
        $shortSensor  = $diskSensors[$idx . '_selftest_short'] ?? null;
        $longSensor   = $diskSensors[$idx . '_selftest_long'] ?? null;
        $hasSelftest  = $shortSensor !== null || $longSensor !== null;

        // Power-on hours is ATA attribute 9; it rides the single per-disk attribute RRD.
        $powerSpec = $specs[9] ?? null;

        // Build jump-nav section list.
        $sections = [];
        if ($tempSensor)   { $sections[] = [$anchorPrefix . 'temperature', 'Temperature']; }
        if ($healthSensor) { $sections[] = [$anchorPrefix . 'health', 'Health']; }
        if ($wearSensor)   { $sections[] = [$anchorPrefix . 'wear', 'Wear Remaining']; }
        if ($statusSensor) { $sections[] = [$anchorPrefix . 'selftest-status', 'Self-test Status']; }
        if ($hasSelftest)  { $sections[] = [$anchorPrefix . 'selftest', 'Self-test Age']; }
        if ($powerSpec)    { $sections[] = [$anchorPrefix . 'power', 'Power-on Hours']; }
        if ($hasBig5)  { $sections[] = [$anchorPrefix . 'big5', 'Reliability / Age (Big 5 ATA Attributes)']; }
        if ($hasOther) { $sections[] = [$anchorPrefix . 'other', 'Other']; }
        foreach ($specs as $spec) {
            if ($spec['id'] === 9) { continue; }
            $sections[] = [$anchorPrefix . 'attr-' . $spec['id'], $spec['title']];
        }

        $jumpItems = '';
        foreach ($sections as [$sid, $stitle]) {
            $jumpItems .= '<div style="break-inside:avoid-column;-webkit-column-break-inside:avoid;padding:1px 0">'
                . '<a href="' . htmlspecialchars($graphBase . '#' . $sid, ENT_QUOTES) . '">' . htmlspecialchars($stitle) . '</a></div>';
        }
        if ($jumpItems !== '') {
            echo '<div class="panel panel-default"><div class="panel-body" style="padding:10px 15px">'
                . '<strong>Jump to graph:</strong><div style="column-width:260px;column-gap:18px;margin-top:6px">'
                . $jumpItems . '</div></div></div>';
        }

        $anchor = static function (string $id): void {
            echo '<a id="' . htmlspecialchars($id) . '" style="position:relative;top:-70px;display:block;visibility:hidden"></a>';
        };

        $appGraph = static function (string $type, string $title, string $anchorId, string $headerBadge = '', array $extra = []) use ($appId, $idx, $now, $panelStart, $panelEnd, $anchor) {
            $graph_array = array_merge([
                'height' => '100', 'width' => '215', 'to' => $now,
                'id'     => $appId, 'type' => "application_{$type}",
                'disk'   => $idx, 'scale_min' => '0',
            ], $extra);
            $badge = $headerBadge !== '' ? '<span class="text-muted">' . htmlspecialchars($headerBadge) . '</span>' : '';
            $anchor($anchorId);
            $panelStart(htmlspecialchars($title), $badge);
            echo '<div class="row">';
            include 'includes/html/print-graphrow.inc.php';
            echo '</div>';
            $panelEnd();
        };

        $sensorGraph = static function ($sensor, string $title, string $anchorId, string $badge = '') use ($now, $panelStart, $panelEnd, $anchor) {
            $graph_array = [
                'height' => '100', 'width' => '215', 'to' => $now,
                'id'     => $sensor->sensor_id, 'type' => 'sensor_' . $sensor->sensor_class, 'legend' => 'no',
            ];
            $anchor($anchorId);
            $panelStart(htmlspecialchars($title), $badge);
            echo '<div class="row">';
            include 'includes/html/print-graphrow.inc.php';
            echo '</div>';
            $panelEnd();
        };

        if ($tempSensor) {
            $sensorGraph($tempSensor, 'Temperature', $anchorPrefix . 'temperature');
        }
        if ($healthSensor) {
            $sensorGraph($healthSensor, 'Health', $anchorPrefix . 'health', $healthBadge);
        }
        if ($wearSensor) {
            $sensorGraph($wearSensor, 'Wear Remaining', $anchorPrefix . 'wear');
        }
        if ($statusSensor) {
            $sensorGraph($statusSensor, 'Self-test Status', $anchorPrefix . 'selftest-status');
        }
        if ($hasSelftest) {
            $stParts = [];
            if ($shortSensor) { $stParts[] = 'Short: ' . (string) ($shortSensor->sensor_current ?? '-'); }
            if ($longSensor)  { $stParts[] = 'Long: '  . (string) ($longSensor->sensor_current ?? '-'); }
            $appGraph('smart_v2_selftest', 'Self-test Age', $anchorPrefix . 'selftest', $stParts !== [] ? implode(' | ', $stParts) : '');
        }
        if ($powerSpec) {
            $appGraph('smart_v2_attributes', 'Power-on Hours', $anchorPrefix . 'power', $data->powerHeader($disk), [
                'attr_id'     => '9',
                'attr_thresh' => $powerSpec['thresh'] !== null ? (string) $powerSpec['thresh'] : '',
                'has_raw'     => $powerSpec['has_raw'] ? '1' : '0',
                'has_norm'    => $powerSpec['has_norm'] ? '1' : '0',
            ]);
        }
        if ($hasBig5) {
            $appGraph('smart_v2_big5', 'Reliability / Age (Big 5 ATA Attributes)', $anchorPrefix . 'big5', $data->reliabilityHeader($disk));
        }
        if ($hasOther) {
            $appGraph('smart_v2_other', 'Other', $anchorPrefix . 'other');
        }

        // Per-attribute graphs with a "Scale from zero" toggle (id 9 is shown above as Power-on Hours).
        $attrSpecs = array_filter($specs, static fn ($spec) => $spec['id'] !== 9);
        if ($attrSpecs !== []) {
            $wrapperId = 'smart-attr-graphs-' . htmlspecialchars($idx);
            $toggleId  = 'smart-attr-scale-' . htmlspecialchars($idx);
            echo '<script>
function smartAttrScaleToggle(cb, wrapperId) {
    var w = document.getElementById(wrapperId); if (!w) return;
    w.querySelectorAll("img.graph-image").forEach(function(img) {
        if (cb.checked) {
            if (img.src.indexOf("scale_min=") === -1) { img.src += (img.src.indexOf("?") !== -1 ? "&" : "?") + "scale_min=0"; }
        } else { img.src = img.src.replace(/[&?]scale_min=[^&]*/g, ""); }
    });
}
</script>';
            echo '<h4 style="margin:20px 0 8px;border-bottom:1px solid #ddd;padding-bottom:6px">Attributes'
                . '<label style="float:right;font-size:13px;font-weight:normal;margin-bottom:0;cursor:pointer">'
                . '<input type="checkbox" id="' . $toggleId . '" checked onchange="smartAttrScaleToggle(this,\'' . $wrapperId . '\')"> Scale from zero</label></h4>';
            echo '<div id="' . $wrapperId . '">';
            foreach ($attrSpecs as $spec) {
                $appGraph('smart_v2_attributes', $spec['title'], $anchorPrefix . 'attr-' . $spec['id'], $spec['header'], [
                    'attr_id'     => (string) $spec['id'],
                    'attr_thresh' => $spec['thresh'] !== null ? (string) $spec['thresh'] : '',
                    'has_raw'     => $spec['has_raw'] ? '1' : '0',
                    'has_norm'    => $spec['has_norm'] ? '1' : '0',
                ]);
            }
            echo '</div>';
        }
    @endphp
    @endif
@endif
