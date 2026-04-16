<?php

namespace Happytime\Application\Employee\Service\Export;

use Happytime\Domain\Workspace\Workspace;

interface EmployeeRepository
{
    /**
     * @return Employee[]
     */
    public function getEmployeesByWorkspace(
        Workspace $workspace,
    ): array;

    /**
     * @param int[] $ids
     * @return Employee[]
     */
    public function getEmployeesByGroupIds(
        Workspace $workspace,
        array $ids,
    ): array;

    /**
     * @param int[] $ids
     * @return Employee[]
     */
    public function getEmployeesByPositionIds(
        Workspace $workspace,
        array $ids,
    ): array;

    /**
     * @param int[] $ids
     * @return Employee[]
     */
    public function getEmployeesByEmployeeIds(
        Workspace $workspace,
        array $ids,
    ): array;
}
