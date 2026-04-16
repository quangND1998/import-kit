<?php

namespace Happytime\Application\Employee\Service\Import\Validation;

use App\Jobs\Employee\ValidateImportCreateEmployeeJob;
use App\Jobs\Employee\ValidateImportEmployeeJob;
use Happytime\Application\Employee\Service\Import\EmployeeImportValidator;
use Happytime\Domain\Employee\Excel\EmployeeExcel;
use Happytime\Domain\Employee\Excel\Error\InvalidHeaderError;
use Happytime\Domain\Employee\Exception\InvalidTemplateImportException;

class HeaderForCreateValidate implements AbstractValidate
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
            ValidateImportCreateEmployeeJob::INDEX_COL_STT => "1. STT",
            ValidateImportCreateEmployeeJob::INDEX_COL_FULL_NAME => "2. Họ và tên*",
            ValidateImportCreateEmployeeJob::INDEX_COL_EMPLOYEE_CODE => "3. Mã nhân viên",
            ValidateImportCreateEmployeeJob::INDEX_COL_COMPANY_EMAIL => "4. Email công ty",
            ValidateImportCreateEmployeeJob::INDEX_COL_PHONE => "5. Số điện thoại",
            ValidateImportCreateEmployeeJob::INDEX_COL_PERSONAL_EMAIL => "6. Email cá nhân",
            ValidateImportCreateEmployeeJob::INDEX_COL_DOB => "7. Ngày sinh",
            ValidateImportCreateEmployeeJob::INDEX_COL_GENDER => "8. Giới tính",
            ValidateImportCreateEmployeeJob::INDEX_COL_TAX_CODE => "9. Mã số thuế cá nhân",
            ValidateImportCreateEmployeeJob::INDEX_COL_BANK_ACCOUNT => "10. Số tài khoản",
            ValidateImportCreateEmployeeJob::INDEX_COL_BANK_NAME => "11. Ngân hàng",
            ValidateImportCreateEmployeeJob::INDEX_COL_BANK_BRANCH => "12. Chi nhánh",
            ValidateImportCreateEmployeeJob::INDEX_COL_ID_CARD_NUMBER => "13. Số CMND",
            ValidateImportCreateEmployeeJob::INDEX_COL_ID_CARD_ISSUED_DATE => "14. Ngày cấp",
            ValidateImportCreateEmployeeJob::INDEX_COL_ID_CARD_ISSUED_ADDRESS => "15. Nơi cấp",
            ValidateImportCreateEmployeeJob::INDEX_COL_PERMANENT_ADDRESS => "16. Địa chỉ thường trú",
            ValidateImportCreateEmployeeJob::INDEX_COL_CURRENT_ADDRESS => "17. Địa chỉ tạm trú",
            ValidateImportCreateEmployeeJob::INDEX_COL_EDUCATION => "18. Học vấn",
            ValidateImportCreateEmployeeJob::INDEX_COL_SCHOOL => "19. Trường học",
            ValidateImportCreateEmployeeJob::INDEX_COL_MAJOR => "20. Chuyên ngành",
            ValidateImportCreateEmployeeJob::INDEX_COL_GRADUATION_YEAR => "21. Năm tốt nghiệp",
            ValidateImportCreateEmployeeJob::INDEX_COL_MARRIED_STATUS => "22. Tình trạng hôn nhân",
            ValidateImportCreateEmployeeJob::INDEX_COL_GROUP_NAME => "23. Phòng ban*",
            ValidateImportCreateEmployeeJob::INDEX_COL_POSITION_NAME => "24. Vị trí công việc*",
            ValidateImportCreateEmployeeJob::INDEX_COL_EMPLOYMENT_CATEGORY => "25. Loại hình nhân sự*",
            ValidateImportCreateEmployeeJob::INDEX_COL_EMPLOYMENT_CATEGORY_FROM => "26. Ngày bắt đầu loại hình nhân sự*",
            ValidateImportCreateEmployeeJob::INDEX_COL_EMPLOYMENT_STATUS => "27. Trạng thái nhân sự*",
            ValidateImportCreateEmployeeJob::INDEX_COL_EMPLOYMENT_STATUS_FROM => "28. Ngày bắt đầu trạng thái nhân sự*",
            ValidateImportCreateEmployeeJob::INDEX_COL_START_DATE_OF_WORK => "29. Ngày bắt đầu đi làm*",
            ValidateImportCreateEmployeeJob::INDEX_COL_END_DATE_OF_WORK => "30. Ngày nghỉ việc",
            ValidateImportCreateEmployeeJob::INDEX_COL_BRANCH_NAME => "31. Chi nhánh làm việc",
            ValidateImportCreateEmployeeJob::INDEX_COL_ROLE => "32. Quyền thao tác trên HappyTime*",
            ValidateImportCreateEmployeeJob::INDEX_COL_LEAVE_COUNT => "33. Số phép năm nay",
            ValidateImportCreateEmployeeJob::INDEX_COL_LAST_YEAR_LEAVE_COUNT => "34. Số phép năm trước",
            ValidateImportCreateEmployeeJob::INDEX_COL_NOTE => "35. Ghi chú",
            ValidateImportCreateEmployeeJob::INDEX_COL_IS_ONBOARD => "36. Gửi email onboarding",
        ];

        foreach ($excelHeaders as $index => $excelHeader) {
            $index++;
            if ($index < ValidateImportCreateEmployeeJob::INDEX_START_CUSTOM_FIELD) {
                if ($excelHeader->getLabel() !== $compareHeader[$index]) {
                    $this->throwErrors($errors);
                }
            }

            if ($index >= ValidateImportCreateEmployeeJob::INDEX_START_CUSTOM_FIELD) {
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
