<?php

namespace Happytime\Application\Employee\Service\Import;

use App\Events\Employee\ValidateImportEmployeeSuccessfully;
use Happytime\Application\Employee\Service\Import\Validation\EmployeeValidate;
use Happytime\Application\Employee\Service\Import\Validation\EmployeeForCreateValidate;
use Happytime\Application\Employee\Service\Import\Validation\HeaderForCreateValidate;
use Happytime\Application\Employee\Service\Import\Validation\HeaderValidate;
use Happytime\Domain\CustomField\CustomField;
use Happytime\Domain\Employee\Excel\EmployeeExcel;
use Happytime\Domain\Employee\Excel\EmployeeImportValidatorResult;
use Happytime\Domain\Employee\Excel\Error\AbstractError;
use Happytime\Domain\Employee\Exception\InvalidTemplateImportException;

class EmployeeImportValidator
{
    public function __construct(
        private readonly HeaderValidate $headerValidate,
        private readonly EmployeeValidate $employeeValidate,
        private readonly EmployeeForCreateValidate $employeeForCreateValidate,
        private readonly HeaderForCreateValidate $headerForCreateValidate,
    ) {
    }

    /**
     * @param CustomField[] $customFields
     * @throws InvalidTemplateImportException
     * @throws ExceedsTheAllowedNumberOfEmployeesException
     */
    public function handle(
        EmployeeExcel $excel,
        \Happytime\Domain\Employee\Excel\EmployeeImportResult $result,
        array $customFields,
        bool $isCreate = false
    ): void {
        $errors = [];
        if ($isCreate) {
            $this->headerForCreateValidate->setCustomFields($customFields);
            $this->headerForCreateValidate->errors($excel, $errors);
            $this->employeeForCreateValidate->setCustomFields($customFields);
            $this->employeeForCreateValidate->errors($excel, $errors);
        } else {
            $this->headerValidate->setCustomFields($customFields);
            $this->headerValidate->errors($excel, $errors);
            $this->employeeValidate->setCustomFields($customFields);
            $this->employeeValidate->errors($excel, $errors);
        }


        $validateResult = new EmployeeImportValidatorResult(
            $excel,
            $errors,
        );

        event(new ValidateImportEmployeeSuccessfully($validateResult, $result));
    }

    public static function addError(array &$errors, AbstractError $error): void
    {
        $errors[] = $error;
    }
}
