<?php

namespace LibreNMS\Polling\Modules;

class StatusAggregator
{
    private array $counts = [];

    public function add(string $category, int $code): void
    {
        if (! isset($this->counts[$category])) {
            $this->counts[$category] = [];
        }
        if (! isset($this->counts[$category][$code])) {
            $this->counts[$category][$code] = 0;
        }
        $this->counts[$category][$code]++;
    }

    public function hasAny(string $category): bool
    {
        return ! empty($this->counts[$category]);
    }

    public function has(string $category, int $code): bool
    {
        return ($this->counts[$category][$code] ?? 0) > 0;
    }

    public function count(string $category, int $code): int
    {
        return $this->counts[$category][$code] ?? 0;
    }

    public function total(string $category): int
    {
        return array_sum($this->counts[$category] ?? []);
    }

    public function categories(): array
    {
        return array_keys($this->counts);
    }

    public function codes(string $category): array
    {
        return array_keys($this->counts[$category] ?? []);
    }

    public function all(): array
    {
        return $this->counts;
    }
}
