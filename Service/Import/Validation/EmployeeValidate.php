<?php

namespace Happytime\Application\Employee\Service\Import\Validation;

use App\Jobs\Employee\ValidateImportEmployeeJob;
use App\Models\EmployeeHistory;
use App\Rules\ExcelDate;
use Carbon\Carbon;
use Happytime\Application\Employee\Service\Export\EmployeeRepository;
use Happytime\Application\Employee\Service\Import\EmployeeImportValidator;
use Happytime\Domain\CustomField\CustomField;
use Happytime\Domain\CustomField\Enum\DataType;
use Happytime\Domain\Employee\Enum\EmploymentStatus;
use Happytime\Domain\Employee\Enum\EmploymentStatusStr;
use Happytime\Domain\Employee\Excel\EmployeeExcel;
use Happytime\Domain\Employee\Excel\Error\DataAlreadyExistError;
use Happytime\Domain\Employee\Excel\Error\DuplicateDataError;
use Happytime\Domain\Employee\Excel\Error\EmploymentStatusFromNotBetweenOldError;
use Happytime\Domain\Employee\Excel\Error\EmploymentStatusFromNotGreaterThanStartDateOfWork;
use Happytime\Domain\Employee\Excel\Error\EmploymentStatusFromNotInTheFutureError;
use Happytime\Domain\Employee\Excel\Error\EmploymentStatusFromNotLessThanStartDateOfWork;
use Happytime\Domain\Employee\Excel\Error\EmptyDataError;
use Happytime\Domain\Employee\Excel\Error\EndDateOfWorkMustBeEqualEmploymentStatusFromError;
use Happytime\Domain\Employee\Excel\Error\InvalidDataError;
use Happytime\Domain\Employee\Excel\Error\MustNullEndDateOfWork;
use Happytime\Domain\Employee\Excel\Info\Coordinate;

class EmployeeValidate implements AbstractValidate
{
    public function __construct(
        private readonly EmployeeRepository $employeeRepository,
    ) {
    }

    private array $customFields = [];

    public function setCustomFields(array $customFields): void
    {
        foreach ($customFields as $customField) {
            $this->customFields[$customField->getId()] = $customField;
        }
    }

    public function errors(EmployeeExcel $excel, &$errors): void
    {
        $employees = $this->employeeRepository->getEmployeesByWorkspace($excel->getWorkspace());

        $employeeIds = [];
        $employeeCodes = [];
        $companyEmails = [];
        $personalEmails = [];
        $phones = [];
        foreach ($employees as $employee) {
            $employeeIds[] = $employee->getId();
            $employeeCodes[$employee->getId()] = $employee->getEmployeeCode();
            $companyEmails[$employee->getId()] = $employee->getCompanyEmail();
            $personalEmails[$employee->getId()] = $employee->getPersonalEmail();
            $phones[$employee->getId()] = $employee->getPhone();
        }

        $this->validateEmployeeId($excel, $employeeIds, $errors);
        $this->validateFullName($excel, $errors);
        $this->validateEmployeeCode($excel, $employeeCodes, $errors);
        $this->validateCompanyEmail($excel, $companyEmails, $errors);
        $this->validatePersonalEmail($excel, $personalEmails, $errors);
        $this->validatePhone($excel, $phones, $errors);
        $this->validateDob($excel, $errors);
        $this->validateGender($excel, $errors);
        $this->validateIdCardIssuedDate($excel, $errors);
        $this->validateEmploymentStatus($excel, $errors);
        $this->validateEmploymentStatusFrom($excel, $errors);
        $this->validateStartDateOfWork($excel, $errors);
        $this->validateEndDateOfWork($excel, $errors);
        $this->validateBranch($excel, $errors);
        $this->validateRole($excel, $errors);
        $this->validateNote($excel, $errors);
        $this->validateCustomFields($excel, $errors);
    }

    private function validateCustomFields(EmployeeExcel $excel, &$errors): void
    {
        $headers = $excel->getHeaders();
        $countHeaders = count($headers);
        $customFieldsByIndexColumn = [];
        $columnLabelByIndexColumn = [];
        for ($i = ValidateImportEmployeeJob::INDEX_START_CUSTOM_FIELD - 1; $i < $countHeaders; $i++) {
            $excelHeader = $headers[$i];
            $nameColumn = str_replace(' ', '', $excelHeader->getLabel());
            $customFieldId = explode('|', $nameColumn)[1];
            $customFieldsByIndexColumn[$i + 1] = $this->customFields[$customFieldId];
            $columnLabelByIndexColumn[$i + 1] = $excelHeader->getLabel();
        }

        foreach ($excel->getEmployees() as $employee) {
            foreach ($employee->getCustomFieldValues() as $customFieldValue) {
                $value = $customFieldValue['value'];
                $indexColumn = $customFieldValue['index_column'];
                $title = $columnLabelByIndexColumn[$indexColumn];
                /**
                 * @var Coordinate $coordinate
                 */
                $coordinate = $customFieldValue['coordinate'];
                /**
                 * @var CustomField $customField
                 */
                $customField = $customFieldsByIndexColumn[$indexColumn];

                if (!is_null($value)) {
                    if (!is_numeric($value) && $customField->getDataType() === DataType::NUMBER) {
                        EmployeeImportValidator::addError(
                            $errors,
                            new InvalidDataError($title, $coordinate)
                        );
                        continue;
                    }
                    if ($customField->getDataType() === DataType::DATE) {
                        $pass = new ExcelDate();
                        if (!$pass->passes("field", $value)) {
                            EmployeeImportValidator::addError(
                                $errors,
                                new InvalidDataError($title, $coordinate)
                            );
                        }
                    }
                }
            }
        }
    }

    private function validateEmployeeId(EmployeeExcel $excel, array $employeeIds, &$errors): void
    {
        $title = 'Mã định danh nhân viên';
        foreach ($excel->getEmployees() as $employee) {
            $data = $employee->getId();
            if (!$data->getValue()) {
                EmployeeImportValidator::addError($errors, new EmptyDataError($title, $data->getCoordinate()));
                continue;
            }
            if (!in_array($data->getValue(), $employeeIds, true)) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $data->getCoordinate()));
            }
        }
    }

    private function validateFullName(EmployeeExcel $excel, &$errors): void
    {
        $title = 'Họ và tên';
        foreach ($excel->getEmployees() as $employee) {
            $data = $employee->getFullName();
            if (!$data->getValue()) {
                EmployeeImportValidator::addError($errors, new EmptyDataError($title, $data->getCoordinate()));
            }
        }
    }

    private function validateEmployeeCode(EmployeeExcel $excel, array $employeeCodes, &$errors): void
    {
        $title = 'Mã nhân viên';

        $excelEmployeeCodes = [];
        foreach ($excel->getEmployees() as $employee) {
            $employeeCode = $employee->getEmployeeCode()->getValue();
            $coordinate = $employee->getEmployeeCode()->getCoordinate();
            $employeeId = $employee->getId()->getValue();
            $tempEmployeeCodes = $employeeCodes;

            if (!$employeeCode) {
                continue;
            }
            if ($employeeId && array_key_exists($employeeId, $tempEmployeeCodes)) {
                unset($tempEmployeeCodes[$employeeId]);
            }
            if (in_array($employeeCode, $tempEmployeeCodes, true)) {
                EmployeeImportValidator::addError($errors, new DataAlreadyExistError($title, $coordinate));
                continue;
            }
            if (in_array($employeeCode, $excelEmployeeCodes, true)) {
                EmployeeImportValidator::addError($errors, new DuplicateDataError($title, $coordinate));
            } else {
                $excelEmployeeCodes[] = $employeeCode;
            }
        }
    }

    private function validateCompanyEmail(EmployeeExcel $excel, array $companyEmails, &$errors): void
    {
        $title = 'Email công ty';

        $excelCompanyEmails = [];
        foreach ($excel->getEmployees() as $employee) {
            $companyEmail = $employee->getCompanyEmail()->getValue();
            $coordinate = $employee->getCompanyEmail()->getCoordinate();
            $employeeId = $employee->getId()->getValue();
            $tempCompanyEmails = $companyEmails;

            if (!$companyEmail) {
                continue;
            }
            if (!filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
                continue;
            }
            if ($employeeId && array_key_exists($employeeId, $tempCompanyEmails)) {
                unset($tempCompanyEmails[$employeeId]);
            }
            if (in_array($companyEmail, $tempCompanyEmails, true)) {
                EmployeeImportValidator::addError($errors, new DataAlreadyExistError($title, $coordinate));
                continue;
            }
            if (in_array($companyEmail, $excelCompanyEmails, true)) {
                EmployeeImportValidator::addError($errors, new DuplicateDataError($title, $coordinate));
            } else {
                $excelCompanyEmails[] = $companyEmail;
            }
        }
    }

    private function validatePersonalEmail(EmployeeExcel $excel, array $personalEmails, &$errors): void
    {
        $title = 'Email cá nhân';

        $excelPersonalEmails = [];
        foreach ($excel->getEmployees() as $employee) {
            $personalEmail = $employee->getPersonalEmail()->getValue();
            $coordinate = $employee->getPersonalEmail()->getCoordinate();
            $employeeId = $employee->getId()->getValue();
            $tempPersonalEmails = $personalEmails;

            if (!$personalEmail) {
                continue;
            }
            if (!filter_var($personalEmail, FILTER_VALIDATE_EMAIL)) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
                continue;
            }
            if ($employeeId && array_key_exists($employeeId, $tempPersonalEmails)) {
                unset($tempPersonalEmails[$employeeId]);
            }
            if (in_array($personalEmail, $tempPersonalEmails, true)) {
                EmployeeImportValidator::addError($errors, new DataAlreadyExistError($title, $coordinate));
                continue;
            }
            if (in_array($personalEmail, $excelPersonalEmails, true)) {
                EmployeeImportValidator::addError($errors, new DuplicateDataError($title, $coordinate));
            } else {
                $excelPersonalEmails[] = $personalEmail;
            }
        }
    }

    private function validatePhone(EmployeeExcel $excel, array $phones, &$errors): void
    {
        $title = 'Số điện thoại';

        $excelPhones = [];
        foreach ($excel->getEmployees() as $employee) {
            $phone = $employee->getPhone()->getValue();
            $coordinate = $employee->getPhone()->getCoordinate();
            $employeeId = $employee->getId()->getValue();
            $tempPhones = $phones;

            if (!$phone) {
                continue;
            }
            if ($phone === 'undefined') {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
                continue;
            }
            if ($employeeId && array_key_exists($employeeId, $tempPhones)) {
                unset($tempPhones[$employeeId]);
            }
            if (in_array($phone, $tempPhones, true)) {
                EmployeeImportValidator::addError($errors, new DataAlreadyExistError($title, $coordinate));
                continue;
            }
            if (in_array($phone, $excelPhones, true)) {
                EmployeeImportValidator::addError($errors, new DuplicateDataError($title, $coordinate));
            } else {
                $excelPhones[] = $phone;
            }
        }
    }

    private function validateDob(EmployeeExcel $excel, &$errors): void
    {
        $title = 'Ngày sinh';
        foreach ($excel->getEmployees() as $employee) {
            $dob = $employee->getBirthDate()->getValue();
            $coordinate = $employee->getBirthDate()->getCoordinate();

            if ($dob && is_string($dob)) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
            }
        }
    }

    private function validateGender(EmployeeExcel $excel, &$errors): void
    {
        $title = 'Giới tính';
        foreach ($excel->getEmployees() as $employee) {
            $gender = $employee->getGender()->getValue();
            $coordinate = $employee->getGender()->getCoordinate();

            if ($gender && is_string($gender)) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
            }
        }
    }

    private function validateIdCardIssuedDate(EmployeeExcel $excel, &$errors): void
    {
        $title = 'Ngày cấp';
        foreach ($excel->getEmployees() as $employee) {
            $idCardIssuedDate = $employee->getIssueDate()->getValue();
            $coordinate = $employee->getIssueDate()->getCoordinate();

            if ($idCardIssuedDate && is_string($idCardIssuedDate)) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
            }
        }
    }

    private function validateEmploymentStatus(EmployeeExcel $excel, &$errors): void
    {
        $title = 'Trạng thái nhân sự';
        foreach ($excel->getEmployees() as $employee) {
            $employmentStatus = $employee->getEmployeeStatus()->getValue();
            $coordinate = $employee->getEmployeeStatus()->getCoordinate();

            if (!$employmentStatus) {
                EmployeeImportValidator::addError($errors, new EmptyDataError($title, $coordinate));
                continue;
            }
            if (is_string($employmentStatus)) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
            }
        }
    }

    private function validateEmploymentStatusFrom(EmployeeExcel $excel, &$errors): void
    {
        $title = 'Ngày bắt đầu trạng thái nhân sự';
        foreach ($excel->getEmployees() as $employee) {
            $employmentId = $employee->getId()->getValue();
            $employmentStatus = $employee->getEmployeeStatus()->getValue();
            $employmentStatusFrom = $employee->getEmployeeStatusFrom()->getValue();
            $startDateOfWork = $employee->getStartDateOfWork()->getValue() ?: Carbon::now();
            $coordinate = $employee->getEmployeeStatusFrom()->getCoordinate();

            if (!$employmentStatusFrom) {
                EmployeeImportValidator::addError($errors, new EmptyDataError($title, $coordinate));
                continue;
            }

            if (is_string($employmentStatusFrom)) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
                continue;
            }

            // Ngày bắt đầu chuyển trạng thái không được chọn ngày trong tương lai
            if ($employmentStatusFrom > Carbon::now()) {
                EmployeeImportValidator::addError($errors, new EmploymentStatusFromNotInTheFutureError($coordinate));
                continue;
            }

            // Không được chọn ngày bắt đầu nhỏ hơn ngày bắt đầu đã có
            $latest = EmployeeHistory::where('employee_id', $employmentId)
                ->where('field', 'employment_status')
                ->orderBy('from', 'desc')
                ->first();

//            if ($latest && $employmentStatusFrom->lt(Carbon::parse($latest->from))) {
//                EmployeeImportValidator::addError($errors, new EmploymentStatusFromNotBetweenOldError($coordinate));
//                break;
//            }

            $listStatus = [
                EmploymentStatus::WORKING,
                EmploymentStatus::TERMINATED,
                EmploymentStatus::LONG_TERM_UNPAID_LEAVE,
                EmploymentStatus::MATERNITY_LEAVE,
            ];

            // Ngày bắt đầu trạng thái trong $listStatus phải lớn hơn ngày bắt đầu làm việc
            if (
                $startDateOfWork instanceof Carbon &&
                in_array($employmentStatus, $listStatus, true) &&
                $employmentStatusFrom->lt(Carbon::parse($startDateOfWork))
            ) {
                $statusStr = EmploymentStatusStr::fromName($employmentStatus->name)->value;
                EmployeeImportValidator::addError(
                    $errors,
                    new EmploymentStatusFromNotLessThanStartDateOfWork($statusStr, $coordinate)
                );
                continue;
            }

            // Ngày bắt đầu trạng thái Sắp đi làm phải nhỏ hơn ngày bắt đầu làm việc
            if (
                $startDateOfWork instanceof Carbon &&
                $employmentStatus === EmploymentStatus::WAIT_TO_WORK &&
                $employmentStatusFrom->gte(Carbon::parse($startDateOfWork))
            ) {
                $statusStr = EmploymentStatusStr::fromName($employmentStatus->name)->value;
                EmployeeImportValidator::addError(
                    $errors,
                    new EmploymentStatusFromNotGreaterThanStartDateOfWork($statusStr, $coordinate)
                );
                continue;
            }
        }
    }

    private function validateStartDateOfWork(EmployeeExcel $excel, &$errors): void
    {
        $title = 'Ngày bắt đầu đi làm';
        foreach ($excel->getEmployees() as $employee) {
            $startDateOfWork = $employee->getStartDateOfWork()->getValue();
            $coordinate = $employee->getStartDateOfWork()->getCoordinate();

            if (!$startDateOfWork) {
                EmployeeImportValidator::addError($errors, new EmptyDataError($title, $coordinate));
                continue;
            }

            if (is_string($startDateOfWork)) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
            }
        }
    }

    private function validateEndDateOfWork(EmployeeExcel $excel, &$errors): void
    {
        $title = 'Ngày nghỉ việc';
        foreach ($excel->getEmployees() as $employee) {
            $endDateOfWork = $employee->getEndDateOfWork()->getValue();
            $employmentStatus = $employee->getEmployeeStatus()->getValue();
            $employmentStatusFrom = $employee->getEmployeeStatusFrom()->getValue();
            $coordinate = $employee->getEndDateOfWork()->getCoordinate();

            if (
                $employmentStatus === EmploymentStatus::TERMINATED &&
                !$endDateOfWork
            ) {
                EmployeeImportValidator::addError($errors, new EmptyDataError($title, $coordinate));
                continue;
            }

            if (
                $employmentStatus !== EmploymentStatus::TERMINATED &&
                $endDateOfWork
            ) {
                EmployeeImportValidator::addError($errors, new MustNullEndDateOfWork($coordinate));
                continue;
            }

            if (
                $employmentStatus === EmploymentStatus::TERMINATED &&
                $endDateOfWork &&
                $endDateOfWork->format('Y-m-d') !== $employmentStatusFrom->format('Y-m-d')
            ) {
                EmployeeImportValidator::addError(
                    $errors,
                    new EndDateOfWorkMustBeEqualEmploymentStatusFromError($coordinate)
                );
                continue;
            }

            if ($endDateOfWork && is_string($endDateOfWork)) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
            }
        }
    }

    private function validateBranch(EmployeeExcel $excel, &$errors): void
    {
        $title = 'Chi nhánh làm việc';
        foreach ($excel->getEmployees() as $employee) {
            $branchIds = $employee->getBranch()->getValue();
            $coordinate = $employee->getBranch()->getCoordinate();

            if (!is_array($branchIds)) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
            }
        }
    }

    private function validateRole(EmployeeExcel $excel, &$errors): void
    {
        $title = 'Quyền thao tác trên HappyTime';
        foreach ($excel->getEmployees() as $employee) {
            $role = $employee->getRole()->getValue();
            $coordinate = $employee->getRole()->getCoordinate();

            if (!$role) {
                EmployeeImportValidator::addError($errors, new EmptyDataError($title, $coordinate));
                continue;
            }

            if ($role && is_string($role)) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
            }
        }
    }

    private function validateNote(EmployeeExcel $excel, &$errors): void
    {
        $title = 'Ghi chú';
        foreach ($excel->getEmployees() as $employee) {
            $note = $employee->getNote()->getValue();
            $coordinate = $employee->getNote()->getCoordinate();

            if ($note && mb_strlen($note) > 30000) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
            }
        }
    }
}
