<?php

namespace Happytime\Application\Employee\Service\Import\Validation;

use App\Helpers\EmploymentStatusHelper;
use App\Jobs\Employee\ValidateImportCreateEmployeeJob;
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
use Happytime\Domain\Employee\Enum\EmploymentCategory;
use App\Http\Services\EmployeeService;
use Happytime\Domain\Employee\Exception\ExceedsTheAllowedNumberOfEmployeesException;

class EmployeeForCreateValidate implements AbstractValidate
{
    public function __construct(
        private readonly EmployeeRepository $employeeRepository,
        private readonly EmployeeService $employeeService,
    ) {
    }

    private array $customFields = [];

    public function setCustomFields(array $customFields): void
    {
        foreach ($customFields as $customField) {
            $this->customFields[$customField->getId()] = $customField;
        }
    }

    /**
     * @throws ExceedsTheAllowedNumberOfEmployeesException
     */
    public function errors(EmployeeExcel $excel, &$errors): void
    {
        $this->validateMaxNumberOfEmployees($excel, $errors);

        $employees = $this->employeeRepository->getEmployeesByWorkspace($excel->getWorkspace());
        $employeeCodes = [];
        $companyEmails = [];
        $personalEmails = [];
        $phones = [];
        foreach ($employees as $employee) {
            $employeeCodes[$employee->getId()] = $employee->getEmployeeCode();
            $companyEmails[$employee->getId()] = $employee->getCompanyEmail();
            $personalEmails[$employee->getId()] = $employee->getPersonalEmail();
            $phones[$employee->getId()] = $employee->getPhone();
        }

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
        $this->validateGroup($excel, $errors);
        $this->validatePosition($excel, $errors);
        $this->validateEmploymentCategory($excel, $errors);
        $this->validateEmploymentCategoryFrom($excel, $errors);
        $this->validateStartDateOfWork($excel, $errors);
        $this->validateCustomFields($excel, $errors);
    }

    private function validateMaxNumberOfEmployees(EmployeeExcel $excel, &$errors): void
    {
        $countNewActive = 0;
        foreach ($excel->getEmployees() as $employee) {
            $employmentStatus = $employee->getEmployeeStatus()?->getValue() instanceof EmploymentStatus ? $employee->getEmployeeStatus()->getValue()->value : null;
            if ($employmentStatus && in_array($employmentStatus, EmploymentStatusHelper::getChargedableStatusIds(), true)) {
                ++$countNewActive;
            }
        }

        $check = $this->employeeService->reachMaxNumberEmployer(
            workspaceId: $excel->getWorkspace()->getId(),
            totalNewUser: $countNewActive,
        );

        if (!$check) {
            throw new ExceedsTheAllowedNumberOfEmployeesException();
        }
    }

    private function validateCustomFields(EmployeeExcel $excel, &$errors): void
    {
        $headers = $excel->getHeaders();
        $countHeaders = count($headers);
        $customFieldsByIndexColumn = [];
        $columnLabelByIndexColumn = [];
        for ($i = ValidateImportCreateEmployeeJob::INDEX_START_CUSTOM_FIELD - 1; $i < $countHeaders; $i++) {
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
            $tempEmployeeCodes = $employeeCodes;

            if (!$employeeCode) {
                break;
            }
            if (in_array($employeeCode, $tempEmployeeCodes, true)) {
                EmployeeImportValidator::addError($errors, new DataAlreadyExistError($title, $coordinate));
                break;
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
            $tempCompanyEmails = $companyEmails;

            if (!$companyEmail) {
                break;
            }
            if (!filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
                break;
            }
            if (in_array($companyEmail, $tempCompanyEmails, true)) {
                EmployeeImportValidator::addError($errors, new DataAlreadyExistError($title, $coordinate));
                break;
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
            $tempPersonalEmails = $personalEmails;

            if (!$personalEmail) {
                break;
            }
            if (!filter_var($personalEmail, FILTER_VALIDATE_EMAIL)) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
                break;
            }
            if (in_array($personalEmail, $tempPersonalEmails, true)) {
                EmployeeImportValidator::addError($errors, new DataAlreadyExistError($title, $coordinate));
                break;
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
            $tempPhones = $phones;

            if (!$phone) {
                break;
            }
            if ($phone === 'undefined') {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
                break;
            }
            if (in_array($phone, $tempPhones, true)) {
                EmployeeImportValidator::addError($errors, new DataAlreadyExistError($title, $coordinate));
                break;
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
                break;
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
            $employmentStatus = $employee->getEmployeeStatus()->getValue();
            $employmentStatusFrom = $employee->getEmployeeStatusFrom()->getValue();
            $startDateOfWork = $employee->getStartDateOfWork()->getValue() ?: Carbon::now();
            $coordinate = $employee->getEmployeeStatusFrom()->getCoordinate();

            if (!$employmentStatusFrom) {
                EmployeeImportValidator::addError($errors, new EmptyDataError($title, $coordinate));
                break;
            }

            if (is_string($employmentStatusFrom)) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
                break;
            }

            // Ngày bắt đầu chuyển trạng thái không được chọn ngày trong tương lai
            if ($employmentStatusFrom > Carbon::now()) {
                EmployeeImportValidator::addError($errors, new EmploymentStatusFromNotInTheFutureError($coordinate));
                break;
            }

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
                break;
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
                break;
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
                break;
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
                break;
            }

            if (
                $employmentStatus !== EmploymentStatus::TERMINATED &&
                $endDateOfWork
            ) {
                EmployeeImportValidator::addError($errors, new MustNullEndDateOfWork($coordinate));
                break;
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
                break;
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
            $branchIds = $employee->getBranch()->getValue() ?? [];
            $coordinate = $employee->getBranch()->getCoordinate();

            if (!is_array($branchIds) || in_array('undefined', $branchIds, true)) {
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

    private function validateGroup(EmployeeExcel $excel, &$errors): void
    {
        $title = 'Phòng ban';
        foreach ($excel->getEmployees() as $employee) {
            $group = $employee->getGroup()->getValue();
            $coordinate = $employee->getGroup()->getCoordinate();

            if (!$group) {
                EmployeeImportValidator::addError($errors, new EmptyDataError($title, $coordinate));
                break;
            }
        }
    }

    private function validatePosition(EmployeeExcel $excel, &$errors): void
    {
        $title = 'Vị trí công việc';
        foreach ($excel->getEmployees() as $employee) {
            $position = $employee->getPosition()->getValue();
            $coordinate = $employee->getPosition()->getCoordinate();

            if (!$position) {
                EmployeeImportValidator::addError($errors, new EmptyDataError($title, $coordinate));
                break;
            }
        }
    }

    private function validateEmploymentCategory(EmployeeExcel $excel, &$errors): void
    {
        $title = 'Loại hình nhân sự';
        foreach ($excel->getEmployees() as $employee) {
            $employmentCategory = $employee->getEmploymentCategory()->getValue();
            $coordinate = $employee->getEmploymentCategory()->getCoordinate();

            if (!$employmentCategory) {
                EmployeeImportValidator::addError($errors, new EmptyDataError($title, $coordinate));
                break;
            }

            if (is_string($employmentCategory)) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
                break;
            }
        }
    }

    private function validateEmploymentCategoryFrom(EmployeeExcel $excel, &$errors): void
    {
        $title = 'Ngày bắt đầu loại hình nhân sự';
        foreach ($excel->getEmployees() as $employee) {
            $employmentCategoryFrom = $employee->getEmploymentCategoryFrom()->getValue();
            $coordinate = $employee->getEmploymentCategoryFrom()->getCoordinate();

            if (!$employmentCategoryFrom) {
                EmployeeImportValidator::addError($errors, new EmptyDataError($title, $coordinate));
                break;
            }

            if (is_string($employmentCategoryFrom)) {
                EmployeeImportValidator::addError($errors, new InvalidDataError($title, $coordinate));
            }
        }
    }
}
