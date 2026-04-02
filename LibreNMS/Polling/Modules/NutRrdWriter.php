<?php

namespace LibreNMS\Polling\Modules;

class NutRrdWriter extends AppRrdWriter
{
    protected function defineDatasets(): array
    {
        return [
            'charge' => 'GAUGE',
            'runtime' => 'GAUGE',
            'load' => 'GAUGE',
            'realpower' => 'GAUGE',
            'out_voltage' => 'GAUGE',
            'out_frequency' => 'GAUGE',
            'in_voltage' => 'GAUGE',
            'battery_voltage' => 'GAUGE',
        ];
    }

    public function buildFields(array $data): array
    {
        $fields = [
            'charge' => $data['battery']['charge'] ?? null,
            'runtime' => isset($data['battery']['runtime']) ? (int) $data['battery']['runtime'] / 60 : null,
            'load' => $data['ups']['load'] ?? null,
            'realpower' => $data['ups']['realpower'] ?? null,
            'out_voltage' => $data['output']['voltage'] ?? null,
            'out_frequency' => $data['output']['frequency'] ?? null,
            'in_voltage' => $data['input']['voltage'] ?? null,
            'battery_voltage' => $data['battery']['voltage'] ?? null,
        ];

        echo "<!-- NutRrdWriter: buildFields fields=" . json_encode($fields) . " -->\n";

        return $fields;
    }
}
