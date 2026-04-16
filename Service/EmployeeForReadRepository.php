<?php

namespace Happytime\Application\Employee\Service;

use Happytime\Domain\Workspace\Workspace;

interface EmployeeForReadRepository
{
    public function getById(Workspace $workspace, int $id): ?Employee;
}
