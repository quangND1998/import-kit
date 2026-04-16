<?php

namespace Happytime\Application\Employee\Service\Import;

use Happytime\Domain\Employee\Exception\NotFoundEmployeeImportResultException;
use Happytime\Domain\Workspace\Workspace;

class ImportEmployeeService
{
    public function __construct(
        private readonly EmployeeImportResultForReadRepository $employeeImportResultForReadRepository
    ) {
    }

    /**
     * @throws NotFoundEmployeeImportResultException
     */
    public function getResult(Workspace $workspace, string $resultId): EmployeeImportResult
    {
        $result = $this->employeeImportResultForReadRepository->getById($workspace, $resultId);

        if (!$result) {
            throw new NotFoundEmployeeImportResultException('Không tìm thấy kết quả import');
        }

        return $result;
    }
}
