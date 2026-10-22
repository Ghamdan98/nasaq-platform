<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\EmployeeImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as Writer;

class EmployeeSpreadsheet
{
    public function preview(UploadedFile $file, string $mode, int $userId): EmployeeImport
    {
        $zip = new \ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            throw ValidationException::withMessages(['file' => 'ملف XLSX غير صالح.']);
        }
        $size = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $size += $zip->statIndex($i)['size'];
        }
        $zip->close();
        if ($size > 50 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'حجم البيانات بعد فك الضغط يتجاوز 50 ميجابايت.']);
        }
        $reader = new Xlsx;
        $info = $reader->listWorksheetInfo($file->getRealPath());
        if (! $info || $info[0]['totalRows'] > 20001 || $info[0]['totalColumns'] > 50) {
            throw ValidationException::withMessages(['file' => 'الحد الأقصى 20000 موظف و50 عمودًا في الورقة الأولى.']);
        }
        $rows = [];
        $seen = [];
        $header = null;
        for ($start = 2; $start <= max(2, $info[0]['totalRows']); $start += 200) {
            $reader->setLoadSheetsOnly([$info[0]['worksheetName']]);
            $reader->setReadFilter(new class($start) implements IReadFilter
            {
                public function __construct(private int $start) {}

                public function readCell($columnAddress, $row, $worksheetName = '')
                {
                    return $row === 1 || ($row >= $this->start && $row < $this->start + 200);
                }
            });
            $book = $reader->load($file->getRealPath());
            $sheet = $book->getSheet(0);
            if ($header === null) {
                $header = [];
                for ($col = 1; $col <= $info[0]['totalColumns']; $col++) {
                    $name = trim((string) $sheet->getCell([$col, 1])->getValue(), " \t\r\n\xEF\xBB\xBF");
                    $key = array_search($name, EmployeeData::HEADERS, true);
                    if ($key !== false) {
                        if (in_array($key, $header, true)) {
                            throw ValidationException::withMessages(['file' => 'يوجد عنوان عمود مكرر: '.$name]);
                        }
                        $header[$col] = $key;
                    }
                }
                foreach (['employee_number', 'full_name', 'company', 'hire_date', 'basic_salary', 'status'] as $field) {
                    if (! in_array($field, $header, true)) {
                        throw ValidationException::withMessages(['file' => 'العمود المطلوب غير موجود: '.EmployeeData::HEADERS[$field]]);
                    }
                }
            }
            for ($row = $start; $row <= min($start + 199, $info[0]['totalRows']); $row++) {
                $data = [];
                $errors = [];
                $nonempty = false;
                foreach ($header as $col => $key) {
                    $cell = $sheet->getCell([$col, $row]);
                    $value = $cell->getValue();
                    if ($value !== null && $value !== '') {
                        $nonempty = true;
                    }
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        $errors[] = 'لا تُقبل المعادلات: '.EmployeeData::HEADERS[$key];
                        $value = null;
                    }
                    try {
                        if (in_array($key, ['hire_date', 'last_leave_settlement_date']) && $value !== null && $value !== '') {
                            $value = $this->date($value);
                        } elseif (in_array($key, ['employee_number', 'national_id_or_iqama'])) {
                            $value = $value === null ? null : trim($cell->getFormattedValue());
                        } elseif (is_string($value)) {
                            $value = trim($value);
                        }
                    } catch (\Throwable) {
                        $errors[] = 'تاريخ غير صالح: '.EmployeeData::HEADERS[$key];
                        $value = null;
                    }
                    $data[$key] = $value === '' ? null : $value;
                }
                if (! $nonempty) {
                    continue;
                }
                $data['status'] = ['نشط' => 'active', 'غير نشط' => 'inactive', 'منتهي الخدمة' => 'terminated'][$data['status'] ?? ''] ?? ($data['status'] ?? null);
                $existing = Employee::where('company', $data['company'] ?? '')->where('employee_number', $data['employee_number'] ?? '')->first();
                $data = array_filter($data, fn ($v) => $v !== null);
                if ($existing) {
                    $data = array_merge($existing->only(array_keys(EmployeeData::HEADERS)), $data);
                } else {
                    foreach (EmployeeData::MONEY as $field) {
                        if ($field !== 'basic_salary') {
                            $data[$field] ??= '0';
                        }
                    }
                }
                $validator = Validator::make($data, EmployeeData::rules($data, $existing));
                if ($validator->fails()) {
                    $errors = array_merge($errors, $validator->errors()->all());
                }
                $key = json_encode([$data['company'] ?? '', $data['employee_number'] ?? '']);
                if (isset($seen[$key])) {
                    $errors[] = 'كود الموظف مكرر داخل الشركة في الملف.';
                    $rows[$seen[$key]]['errors'][] = 'كود الموظف مكرر داخل الشركة في الملف.';
                } else {
                    $seen[$key] = count($rows);
                }
                $action = $existing ? ($mode === 'new_only' ? 'skip' : 'update') : ($mode === 'update_only' ? 'skip' : 'create');
                $rows[] = ['row' => $row, 'data' => $data, 'errors' => $errors, 'action' => $action,
                    'existing_id' => $existing?->id, 'fingerprint' => $existing ? hash('sha256', json_encode($existing->getAttributes())) : null];
            }
            $book->disconnectWorksheets();
            unset($book);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'الملف لا يحتوي على موظفين.']);
        }
        $counts = ['new_count' => 0, 'updated_count' => 0, 'rejected_count' => 0, 'skipped_count' => 0];
        foreach ($rows as $row) {
            $key = $row['errors'] ? 'rejected_count' : ['create' => 'new_count', 'update' => 'updated_count', 'skip' => 'skipped_count'][$row['action']];
            $counts[$key]++;
        }

        return EmployeeImport::create(array_merge($counts, ['user_id' => $userId, 'filename' => basename($file->getClientOriginalName()), 'mode' => $mode,
            'status' => $counts['rejected_count'] ? 'rejected' : 'preview', 'payload' => $rows, 'expires_at' => now()->addDay()]));
    }

    private function date(mixed $value): string
    {
        if (is_numeric($value)) {
            if ($value < 1 || $value > 2958465) {
                throw new \InvalidArgumentException;
            }

            return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }
        foreach (['Y-m-d', 'd/m/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, (string) $value);
            if ($date && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }
        throw new \InvalidArgumentException;
    }

    public function workbook(array $headers, array $rows): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setRightToLeft(true);
        foreach (array_merge([$headers], $rows) as $i => $row) {
            foreach (array_values($row) as $j => $value) {
                $sheet->setCellValueExplicit([$j + 1, $i + 1], (string) ($value ?? ''), DataType::TYPE_STRING);
            }
        }
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        if (! $rows && $headers === array_values(EmployeeData::HEADERS)) {
            // Text columns in the empty template preserve zeros when users type employee codes and identities.
            $sheet->getStyle('A1:A1048576')->getNumberFormat()->setFormatCode('@');
            $sheet->getStyle('G1:G1048576')->getNumberFormat()->setFormatCode('@');
        }
        for ($i = 1; $i <= count($headers); $i++) {
            $sheet->getColumnDimensionByColumn($i)->setWidth(24);
        }
        $stream = fopen('php://memory', 'w+');
        (new Writer($book))->save($stream);
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        $book->disconnectWorksheets();

        return $contents;
    }
}
