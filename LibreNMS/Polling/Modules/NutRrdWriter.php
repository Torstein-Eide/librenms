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

    private static function extractValue(mixed $data): ?float
    {
        if ($data === null) {
            return null;
        }

        if (is_numeric($data)) {
            return (float) $data;
        }

        if (is_array($data) && isset($data['value'])) {
            return is_numeric($data['value']) ? (float) $data['value'] : null;
        }

        return null;
    }

    public function buildFields(array $data): array
    {
        $fields = [
            'charge' => self::extractValue($data['battery']['charge'] ?? null),
            'runtime' => isset($data['battery']['runtime']) ? (int) $data['battery']['runtime'] / 60 : null,
            'load' => self::extractValue($data['ups']['load'] ?? null),
            'realpower' => self::extractValue($data['ups']['realpower'] ?? null),
            'out_voltage' => self::extractValue($data['output']['voltage'] ?? null),
            'out_frequency' => self::extractValue($data['output']['frequency'] ?? null),
            'in_voltage' => self::extractValue($data['input']['voltage'] ?? null),
            'battery_voltage' => self::extractValue($data['battery']['voltage'] ?? null),
        ];

        return $fields;
    }
}
