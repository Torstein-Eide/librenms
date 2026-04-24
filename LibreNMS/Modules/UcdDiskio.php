<?php

/*
 * UcdDiskio.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    LibreNMS
 * @link       http://librenms.org
 * @copyright  2025 Peca Nesovanovic
 * @author     Peca Nesovanovic <peca.nesovanovic@sattrakt.com>
 */

namespace LibreNMS\Modules;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use App\Models\DiskIo;
use App\Observers\ModuleModelObserver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LibreNMS\Data\Store\Rrd as RrdStore;
use LibreNMS\DB\SyncsModels;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Interfaces\Module;
use LibreNMS\OS;
use LibreNMS\Polling\ModuleStatus;
use LibreNMS\RRD\RrdDefinition;
use SnmpQuery;

class UcdDiskio implements Module
{
    use SyncsModels;

    private string $rrdName = 'ucd_diskio';

    /** @var array<string, array{type: string, min: int, max: int}> */
    private array $rrdDatasetConfig = [
        'read'    => ['type' => 'DERIVE', 'min' => 0, 'max' => 125000000000],
        'written' => ['type' => 'DERIVE', 'min' => 0, 'max' => 125000000000],
        'reads'   => ['type' => 'DERIVE', 'min' => 0, 'max' => 125000000000],
        'writes'  => ['type' => 'DERIVE', 'min' => 0, 'max' => 125000000000],
        'la1'     => ['type' => 'GAUGE',  'min' => 0, 'max' => 100],
        'la5'     => ['type' => 'GAUGE',  'min' => 0, 'max' => 100],
        'la15'    => ['type' => 'GAUGE',  'min' => 0, 'max' => 100],
        'busy_usec' => ['type' => 'DERIVE', 'min' => 0, 'max' => 1000000],
    ];

    /**
     * @inheritDoc
     */
    public function dependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function shouldDiscover(OS $os, ModuleStatus $status): bool
    {
        return $status->isEnabledAndDeviceUp($os->getDevice());
    }

    /**
     * @inheritDoc
     */
    public function shouldPoll(OS $os, ModuleStatus $status): bool
    {
        return $status->isEnabledAndDeviceUp($os->getDevice());
    }

    /**
     * @inheritDoc
     */
    public function discover(OS $os): void
    {
        $this->poll($os);
        $this->syncMissingRrdDatasets($os);
    }

    /**
     * @inheritDoc
     */
    public function poll(OS $os, ?DataStorageInterface $datastore = null): void
    {
        $oids = SnmpQuery::hideMib()->walk('UCD-DISKIO-MIB::diskIOTable')->table(1);
        $ucddisk = new Collection;

        foreach ($oids as $diskData) {
            if (is_array($diskData)) { // invalid snmp response
                $readCounter = $diskData['diskIONReadX'] ?? $diskData['diskIONRead'] ?? null;
                $writtenCounter = $diskData['diskIONWrittenX'] ?? $diskData['diskIONWritten'] ?? null;

                if ($this->valid_disk($os, $diskData['diskIODevice']) &&
                    ($this->isPositive($readCounter) || $this->isPositive($writtenCounter))) {
                    $ucddisk->push(new DiskIo([
                        'diskio_index' => $diskData['diskIOIndex'],
                        'diskio_descr' => $diskData['diskIODevice'],
                    ]));

                    $tags = [
                        'rrd_name' => [$this->rrdName, $diskData['diskIODevice']],
                        'rrd_def' => $this->makeRrdDefinition(),
                        'descr' => $diskData['diskIODevice'],
                    ];

                    $fields = [
                        'read' => $readCounter,
                        'written' => $writtenCounter,
                        'reads' => $diskData['diskIOReads'],
                        'writes' => $diskData['diskIOWrites'],
                        'la1' => $diskData['diskIOLA1'],
                        'la5' => $diskData['diskIOLA5'],
                        'la15' => $diskData['diskIOLA15'],
                        'busy_usec' => $diskData['diskIOBusyTime'],
                    ];

                    if ($datastore) {
                        $datastore->put($os->getDeviceArray(), $this->rrdName, $tags, $fields);
                    }
                } else {
                    Log::info('Skip Disk: ' . $diskData['diskIODevice']);
                }
            }
        }

        ModuleModelObserver::observe(DiskIo::class);
        $this->syncModels($os->getDevice(), 'diskIo', $ucddisk);
    }

    /**
     * @inheritDoc
     */
    public function dataExists(Device $device): bool
    {
        return $device->diskIo()->exists();
    }

    /**
     * @inheritDoc
     */
    public function cleanup(Device $device): int
    {
        return $device->diskIo()->delete();
    }

    /**
     * @inheritDoc
     */
    public function dump(Device $device, string $type): ?array
    {
        return [
            'disks' => $device->diskIo()
                ->orderBy('diskio_descr')
                ->get()->map->makeHidden(['diskio_id', 'device_id']),
        ];
    }

    private function valid_disk($os, $disk): bool
    {
        foreach (LibrenmsConfig::getCombined($os->getDevice()->os, 'bad_disk_regexp') as $bir) {
            if (preg_match($bir . 'i', (string) $disk)) {
                Log::debug('Ignored Disk: ' . $disk . ' (matched: ' . $bir . ')');

                return false;
            }
        }

        return true;
    }

    private function makeRrdDefinition(): RrdDefinition
    {
        $definition = RrdDefinition::make();
        foreach ($this->rrdDatasetConfig as $name => $config) {
            $definition->addDataset($name, $config['type'], $config['min'], $config['max']);
        }

        return $definition;
    }

    /** @return array<string, array{type: string, heartbeat: int, min: int, max: int}> */
    private function tuneDatasetConfig(): array
    {
        $heartbeat = max((int) LibrenmsConfig::get('rrd.heartbeat', 600), 1);
        $config = [];

        foreach ($this->rrdDatasetConfig as $name => $dataset) {
            $config[$name] = [
                'type' => $dataset['type'],
                'heartbeat' => $heartbeat,
                'min' => $dataset['min'],
                'max' => $dataset['max'],
            ];
        }

        return $config;
    }

    private function syncMissingRrdDatasets(OS $os): void
    {
        if (! RrdStore::isEnabled()) {
            return;
        }

        /** @var RrdStore $rrd */
        $rrd = app(RrdStore::class);
        $device = $os->getDevice();
        $datasetConfig = $this->tuneDatasetConfig();

        $discoveredCount = 0;
        foreach ($device->diskIo()->pluck('diskio_descr') as $diskDescr) {
            if (! $this->valid_disk($os, $diskDescr)) {
                continue;
            }

            $rrdFilename = $rrd->name($device->hostname, [$this->rrdName, $diskDescr]);
            if (! $rrd->checkRrdExists($rrdFilename)) {
                continue;
            }

            $existingDatasets = $rrd->listDatasets($rrdFilename);
            $newDatasets = array_keys(array_diff_key($datasetConfig, array_flip($existingDatasets)));
            if (empty($newDatasets)) {
                continue;
            }

            Log::info("UcdDiskio: Missing datasets for $diskDescr: " . implode(', ', $newDatasets));

            $newConfig = [];
            foreach ($newDatasets as $ds) {
                $newConfig[$ds] = $datasetConfig[$ds];
            }
            $added = $rrd->addDatasetsFromConfig($rrdFilename, $newConfig);
            if ($added) {
                $discoveredCount += count($newDatasets);
            }
        }

        if ($discoveredCount > 0) {
            Log::info("UcdDiskio: Added $discoveredCount missing datasets for device {$device->hostname}");
        }
    }

    private function getCounterValue(array $diskData, string $preferredKey, string $fallbackKey): int|float|string|null
    {
        $preferred = $diskData[$preferredKey] ?? null;
        if (is_numeric($preferred)) {
            return $preferred;
        }

        $fallback = $diskData[$fallbackKey] ?? null;

        return is_numeric($fallback) ? $fallback : null;
    }

    private function isPositive(int|float|string|null $value): bool
    {
        return is_numeric($value) && (float) $value > 0;
    }
}
