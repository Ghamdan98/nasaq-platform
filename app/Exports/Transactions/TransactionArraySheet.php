<?php

namespace App\Exports\Transactions;

use App\Services\SystemSettingsService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TransactionArraySheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithEvents, WithStyles, WithTitle
{
    public function __construct(
        private readonly string $title,
        private readonly array $rows,
        private readonly array $currencyColumns = [],
    ) {}

    public function array(): array
    {
        return array_map(function (array $row): array {
            return array_map(function ($value) {
                if (is_string($value) && preg_match('/^[=+\-@]/u', $value)) {
                    return "'".$value;
                }

                return $value;
            }, $row);
        }, $this->rows);
    }

    public function title(): string
    {
        return mb_substr($this->title, 0, 31);
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => ltrim(app(SystemSettingsService::class)->get('appearance.primary'), '#')]],
                'alignment' => ['horizontal' => 'right', 'vertical' => 'center', 'wrapText' => true],
            ],
        ];

        foreach ($this->rows as $index => $row) {
            if (isset($row[0]) && is_string($row[0]) && str_contains($row[0], 'الإجمالي')) {
                $styles[$index + 1] = [
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'DBEAFE']],
                ];
            }
        }

        return $styles;
    }

    public function columnFormats(): array
    {
        $formats = [];
        foreach ($this->currencyColumns as $column) {
            $precision = (int) app(SystemSettingsService::class)->get('display.money_decimals');
            $pattern = '#,##0'.($precision ? '.'.str_repeat('0', $precision) : '');
            $formats[$column] = $pattern.';[Red]-'.$pattern;
        }

        return $formats;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $sheet->setRightToLeft(true);
                $sheet->freezePane('A2');
                if ($sheet->getHighestRow() > 1) {
                    $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
                }
            },
        ];
    }
}
