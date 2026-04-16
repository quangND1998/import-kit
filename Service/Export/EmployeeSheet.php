<?php

namespace Happytime\Application\Employee\Service\Export;

use App\Jobs\Employee\ValidateImportEmployeeJob;
use Happytime\Domain\CustomField\CustomField;
use Happytime\Domain\CustomFieldEmployee\ListResultEmployeeValues;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class EmployeeSheet implements FromView, WithColumnFormatting, WithEvents
{
    /**
     * @param Employee[] $employees
     * @param CustomField[] $customFields
     */
    public function __construct(
        private readonly array $employees,
        private readonly array $customFields,
        private readonly ListResultEmployeeValues $listResultEmployeeValues
    ) {
    }

    public function view(): View
    {
        return View('excel.templates.employee.employee_export')->with([
            'employees' => $this->employees,
            'customFields' => $this->customFields,
            'listResultEmployeeValues' => $this->listResultEmployeeValues,
        ]);
    }

    public function columnFormats(): array
    {
        return [
            $this->getNameColumnByIndex(ValidateImportEmployeeJob::INDEX_COL_DOB) => NumberFormat::FORMAT_TEXT,
            $this->getNameColumnByIndex(ValidateImportEmployeeJob::INDEX_COL_ID_CARD_ISSUED_DATE) => NumberFormat::FORMAT_TEXT,
            $this->getNameColumnByIndex(ValidateImportEmployeeJob::INDEX_COL_EMPLOYMENT_STATUS_FROM) => NumberFormat::FORMAT_TEXT,
            $this->getNameColumnByIndex(ValidateImportEmployeeJob::INDEX_COL_START_DATE_OF_WORK) => NumberFormat::FORMAT_TEXT,
            $this->getNameColumnByIndex(ValidateImportEmployeeJob::INDEX_COL_END_DATE_OF_WORK) => NumberFormat::FORMAT_TEXT,
        ];
    }

    public function getNameColumnByIndex(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet;
                $sheet->getDelegate()->getComment("B2")->getText()->createTextRun("- Bắt buộc nhập");
                $sheet->getDelegate()->getComment("G2")->getText()->createTextRun("- Định dạng: dd/mm/yyyy");
                $sheet->getDelegate()->getComment("H2")->getText()->createTextRun(
                    "- Chọn 1 trong các lựa chọn:
+ Nam
+ Nữ");
                $sheet->getDelegate()->getComment("N2")->getText()->createTextRun("- Định dạng: dd/mm/yyyy");
                $sheet->getDelegate()->getComment("S2")->getText()->createTextRun(
                    "- Bắt buộc nhập
- Chọn 1 trong các lựa chọn:
+ Sắp đi làm
+ Đang làm việc
+ Đã nghỉ việc
+ Nghỉ không lương dài hạn
+ Nghỉ thai sản");
                $sheet->getDelegate()->getComment("T2")->getText()->createTextRun(
                    "- Bắt buộc nhập
- Định dạng: dd/mm/yyyy");
                $sheet->getDelegate()->getComment("U2")->getText()->createTextRun(
                    "- Bắt buộc nhập
- Định dạng: dd/mm/yyyy");
                $sheet->getDelegate()->getComment("V2")->getText()->createTextRun("- Định dạng: dd/mm/yyyy");
                $sheet->getDelegate()->getComment("W2")->getText()->createTextRun(
                    "- Nhập mã chi nhánh
- Nếu nhập nhiều chi nhánh, thì các chi nhánh được ngăn cách nhanh bởi dấu phẩy ( , )");
                $sheet->getDelegate()->getComment("X2")->getText()->createTextRun(
                    "- Bắt buộc nhập
- Chọn 1 trong các vai trò đang có của workspace");
            }
        ];
    }
}
