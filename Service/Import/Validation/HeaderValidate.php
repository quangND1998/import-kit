<?php

namespace Happytime\Application\Employee\Service\Import\Validation;

use App\Jobs\Employee\ValidateImportEmployeeJob;
use Happytime\Application\Employee\Service\Import\EmployeeImportValidator;
use Happytime\Domain\Employee\Excel\EmployeeExcel;
use Happytime\Domain\Employee\Excel\Error\InvalidHeaderError;
use Happytime\Domain\Employee\Exception\InvalidTemplateImportException;

class HeaderValidate implements AbstractValidate
{
    private array $customFields = [];

    public function setCustomFields(array $customFields): void
    {
        foreach ($customFields as $customField) {
            $this->customFields[$customField->getId()] = $customField;
        }
    }

    /**
     * @throws InvalidTemplateImportException
     */
    public function errors(EmployeeExcel $excel, &$errors): void
    {
        $excelHeaders = $excel->getHeaders();

        if (!$excelHeaders) {
            $this->throwErrors($errors);
        }

        $compareHeader = [
            ValidateImportEmployeeJob::INDEX_COL_EMPLOYEE_ID => 'Mã định danh nhân viên',
            ValidateImportEmployeeJob::INDEX_COL_FULL_NAME => 'Họ và tên*',
            ValidateImportEmployeeJob::INDEX_COL_EMPLOYEE_CODE => 'Mã nhân viên',
            ValidateImportEmployeeJob::INDEX_COL_COMPANY_EMAIL => 'Email công ty',
            ValidateImportEmployeeJob::INDEX_COL_PHONE => 'Số điện thoại',
            ValidateImportEmployeeJob::INDEX_COL_PERSONAL_EMAIL => 'Email cá nhân',
            ValidateImportEmployeeJob::INDEX_COL_DOB => 'Ngày sinh',
            ValidateImportEmployeeJob::INDEX_COL_GENDER => 'Giới tính',
            ValidateImportEmployeeJob::INDEX_COL_TAX_CODE => 'Mã số thuế cá nhân',
            ValidateImportEmployeeJob::INDEX_COL_BANK_ACCOUNT => 'Số tài khoản',
            ValidateImportEmployeeJob::INDEX_COL_BANK_NAME => 'Ngân hàng',
            ValidateImportEmployeeJob::INDEX_COL_BANK_BRANCH => 'Chi nhánh',
            ValidateImportEmployeeJob::INDEX_COL_ID_CARD_NUMBER => 'Số CMND',
            ValidateImportEmployeeJob::INDEX_COL_ID_CARD_ISSUED_DATE => 'Ngày cấp',
            ValidateImportEmployeeJob::INDEX_COL_ID_CARD_ISSUED_ADDRESS => 'Nơi cấp',
            ValidateImportEmployeeJob::INDEX_COL_PERMANENT_ADDRESS => 'Địa chỉ thường trú',
            ValidateImportEmployeeJob::INDEX_COL_CURRENT_ADDRESS => 'Địa chỉ hiện tại',
            ValidateImportEmployeeJob::INDEX_COL_EDUCATION => 'Học vấn',
            ValidateImportEmployeeJob::INDEX_COL_EMPLOYMENT_STATUS => 'Trạng thái nhân sự *',
            ValidateImportEmployeeJob::INDEX_COL_EMPLOYMENT_STATUS_FROM => 'Ngày bắt đầu trạng thái nhân sự *',
            ValidateImportEmployeeJob::INDEX_COL_START_DATE_OF_WORK => 'Ngày bắt đầu đi làm *',
            ValidateImportEmployeeJob::INDEX_COL_END_DATE_OF_WORK => 'Ngày nghỉ việc',
            ValidateImportEmployeeJob::INDEX_COL_BRANCH => 'Chi nhánh làm việc',
            ValidateImportEmployeeJob::INDEX_COL_ROLE => 'Quyền thao tác trên HappyTime*',
            ValidateImportEmployeeJob::INDEX_COL_NOTE => 'Ghi chú',
        ];

        foreach ($excelHeaders as $index => $excelHeader) {
            $index++;
            if ($index < ValidateImportEmployeeJob::INDEX_START_CUSTOM_FIELD) {
                if ($excelHeader->getLabel() !== $compareHeader[$index]) {
                    $this->throwErrors($errors);
                }
            }

            if ($index >= ValidateImportEmployeeJob::INDEX_START_CUSTOM_FIELD) {
                $nameColumn = str_replace(' ', '', $excelHeader->getLabel());
                $customFieldId = explode('|', $nameColumn)[1];
                if (!isset($this->customFields[$customFieldId])) {
                    $this->throwErrors($errors);
                }
            }
        }
    }

    /**
     * @throws InvalidTemplateImportException
     */
    private function throwErrors(&$errors): void
    {
        EmployeeImportValidator::addError($errors, new InvalidHeaderError());
        throw new InvalidTemplateImportException();
    }
}
