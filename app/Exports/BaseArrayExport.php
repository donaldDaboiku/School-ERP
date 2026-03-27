<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class BaseArrayExport implements FromCollection, WithHeadings, WithMapping
{
    protected Collection $rows;
    protected array $headings;

    public function __construct($rows)
    {
        $this->rows = collect($rows);

        $firstRow = $this->rows->first();
        $this->headings = is_array($firstRow) ? array_keys($firstRow) : [];
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function map($row): array
    {
        if (!is_array($row)) {
            return [];
        }

        if (empty($this->headings)) {
            return array_values($row);
        }

        return array_map(function ($key) use ($row) {
            return $this->getRowValue($row, $key);
        }, $this->headings);
    }

    private function getRowValue(array $row, string $key)
    {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }

        return null;
    }
}
