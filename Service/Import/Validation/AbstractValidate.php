<?php

namespace Happytime\Application\Employee\Service\Import\Validation;

use Happytime\Domain\Employee\Excel\EmployeeExcel;

interface AbstractValidate
{
    public function errors(EmployeeExcel $excel, &$errors): void;
}
