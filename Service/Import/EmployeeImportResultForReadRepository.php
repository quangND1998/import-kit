<?php

namespace Happytime\Application\Employee\Service\Import;

use Happytime\Domain\Workspace\Workspace;

interface EmployeeImportResultForReadRepository
{
    public function getById(Workspace $workspace, string $resultId): ?EmployeeImportResult;
}
