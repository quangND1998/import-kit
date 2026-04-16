<?php

namespace Happytime\Application\Employee\Service\Export;

use App\Repositories\GroupRepository;
use App\Services\ExcelService;
use Happytime\Domain\Common\Export\Enum\ExportTargetTypeEnum;
use Happytime\Domain\CustomField\Enum\Category;
use Happytime\Domain\CustomField\Enum\DataType;
use Happytime\Domain\CustomField\Repository\CustomFieldRepository;
use Happytime\Domain\CustomFieldEmployee\Repository\Custom\CustomFieldEmployeeValueRepository;
use Happytime\Domain\Workspace\Workspace;
use Illuminate\Support\Facades\Crypt;

class ExportEmployeeService
{
    public function __construct(
        private readonly EmployeeRepository $employeeRepository,
        private readonly GroupRepository $groupRepository,
        private readonly CustomFieldRepository $customFieldRepository,
        private readonly CustomFieldEmployeeValueRepository $customFieldEmployeeValueRepository,
    ) {
    }

    public function handle(
        Workspace $workspace,
        int $employeeId,
        ExportTargetTypeEnum $targetType,
        array $ids,
    ): string {
        $employees = [];
        switch ($targetType) {
            case ExportTargetTypeEnum::WORKSPACE:
                $employees = $this->employeeRepository->getEmployeesByWorkspace($workspace);
                break;
            case ExportTargetTypeEnum::GROUP:
                $groupIds = [];
                foreach ($ids as $id) {
                    try {
                        $groupIds[] = $this->groupRepository->getGroupIdWithChild($workspace->getId(), $id);
                    } catch (\Exception $e) {
                    }
                }
                $employees = $this->employeeRepository->getEmployeesByGroupIds($workspace, array_merge(...$groupIds));
                break;
            case ExportTargetTypeEnum::POSITION:
                $employees = $this->employeeRepository->getEmployeesByPositionIds($workspace, $ids);
                break;
            case ExportTargetTypeEnum::EMPLOYEE:
                $employees = $this->employeeRepository->getEmployeesByEmployeeIds($workspace, $ids);
                break;
        }

        $filePath = 'dependent-persons/export/danh_sach_nhan_vien_' . $workspace->getId() . '_' . time() . '.xlsx';

        $customFields = $this->customFieldRepository->getAllActive(
            workspace: $workspace,
            category: Category::CUSTOM,
            ignoreDataTypes: [DataType::FILE]
        );

        $listResultEmployeeValues = $this->customFieldEmployeeValueRepository->getEmployeeValues(
            workspace: $workspace,
            employeeIds: array_map(static fn($employee) => $employee->getId(), $employees),
            customFieldIds: array_map(static fn($customField) => $customField->getId(), $customFields)
        );

        $filePath = ExcelService::store(
            new EmployeeSheet(
                employees: $employees,
                customFields: $customFields,
                listResultEmployeeValues: $listResultEmployeeValues
            ),
            $filePath,
            config('filesystems.default')
        );

        return route('download-file-export-employee-for-update', [
            'encryptDownloadInfo' => Crypt::encrypt([
                'file_path' => $filePath,
                'workspace_id' => $workspace->getId(),
                'employee_id' => $employeeId,
            ])
        ]);
    }
}
