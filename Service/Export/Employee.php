<?php

namespace Happytime\Application\Employee\Service\Export;

use Carbon\Carbon;
use Happytime\Application\CustomFieldEmployee\DTO\CustomFieldValueDTO;
use Happytime\Domain\Employee\Enum\EmploymentCategory;
use Happytime\Domain\Employee\Enum\EmploymentStatus;
use Happytime\Domain\Employee\Enum\Gender;
use Happytime\Domain\Employee\Enum\MarriedStatus;
use Happytime\Domain\Workspace\Workspace;

class Employee
{
    private ?int $positionId = null;
    private ?EmploymentCategory $employmentCategory = null;
    private ?Carbon $employmentCategoryFrom = null;
    private ?string $school = null;
    private ?string $major = null;
    private ?Carbon $graduationYear = null;
    private ?MarriedStatus $marriedStatus = null;
    private ?int $leaveCount = null;
    private ?int $lastYearLeaveCount = null;
    private ?bool $isOnboard = null;

    /**
     * @param CustomFieldValueDTO[] $customFieldValues
     */
    public function __construct(
        private readonly Workspace $workspace,
        private readonly int $id,
        private readonly ?string $phone,
        private readonly ?int $roleId,
        private readonly ?string $roleString,
        private readonly string $fullName,
        private readonly ?string $personalEmail,
        private readonly ?string $companyEmail,
        private readonly ?string $employeeCode,
        private readonly ?EmploymentStatus $employeeStatus,
        private readonly ?Carbon $employeeStatusFrom,
        private readonly ?Carbon $birthDate,
        private readonly ?Gender $gender,
        private readonly ?string $idCardNumber,
        private readonly ?Carbon $issueDate,
        private readonly ?string $issueAddress,
        private readonly ?string $currentAddress,
        private readonly ?string $permanentAddress,
        private readonly ?string $education,
        private readonly ?string $taxCode,
        private readonly ?string $accountNumber,
        private readonly ?string $nameBank,
        private readonly ?string $branchBank,
        private readonly ?Carbon $endDateOfWork,
        private readonly ?Carbon $startDateOfWork,
        private readonly ?string $branchName,
        private readonly ?string $branchCodes,
        private readonly ?array $branchIds,
        private readonly ?string $note,
        private readonly array $customFieldValues = []
    ) {
    }

    public function getCustomFieldValues(): array
    {
        return $this->customFieldValues;
    }

    public function getWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getRoleId(): ?int
    {
        return $this->roleId;
    }

    public function getRoleString(): ?string
    {
        return $this->roleString;
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function getPersonalEmail(): ?string
    {
        return $this->personalEmail;
    }

    public function getCompanyEmail(): ?string
    {
        return $this->companyEmail;
    }

    public function getEmployeeCode(): ?string
    {
        return $this->employeeCode;
    }

    public function getEmployeeStatus(): ?EmploymentStatus
    {
        return $this->employeeStatus;
    }

    public function getEmployeeStatusFrom(): ?Carbon
    {
        return $this->employeeStatusFrom;
    }

    public function getBirthDate(): ?Carbon
    {
        return $this->birthDate;
    }

    public function getGender(): ?Gender
    {
        return $this->gender;
    }

    public function getIdCardNumber(): ?string
    {
        return $this->idCardNumber;
    }

    public function getIssueDate(): ?Carbon
    {
        return $this->issueDate;
    }

    public function getIssueAddress(): ?string
    {
        return $this->issueAddress;
    }

    public function getCurrentAddress(): ?string
    {
        return $this->currentAddress;
    }

    public function getPermanentAddress(): ?string
    {
        return $this->permanentAddress;
    }

    public function getEducation(): ?string
    {
        return $this->education;
    }

    public function getTaxCode(): ?string
    {
        return $this->taxCode;
    }

    public function getAccountNumber(): ?string
    {
        return $this->accountNumber;
    }

    public function getNameBank(): ?string
    {
        return $this->nameBank;
    }

    public function getBranchBank(): ?string
    {
        return $this->branchBank;
    }

    public function getEndDateOfWork(): ?Carbon
    {
        return $this->endDateOfWork;
    }

    public function getStartDateOfWork(): ?Carbon
    {
        return $this->startDateOfWork;
    }

    public function getBranchName(): ?string
    {
        return $this->branchName;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getBranchIds(): ?array
    {
        return $this->branchIds;
    }

    public function getBranchCodes(): ?string
    {
        return $this->branchCodes;
    }

    public function getPositionId(): ?int
    {
        return $this->positionId;
    }

    public function setPositionId(?int $positionId): void
    {
        $this->positionId = $positionId;
    }

    public function getEmploymentCategory(): ?EmploymentCategory
    {
        return $this->employmentCategory;
    }

    public function setEmploymentCategory(?EmploymentCategory $employmentCategory): void
    {
        $this->employmentCategory = $employmentCategory;
    }

    public function getEmploymentCategoryFrom(): ?Carbon
    {
        return $this->employmentCategoryFrom;
    }

    public function setEmploymentCategoryFrom(?Carbon $employmentCategoryFrom): void
    {
        $this->employmentCategoryFrom = $employmentCategoryFrom;
    }

    public function getSchool(): ?string
    {
        return $this->school;
    }

    public function setSchool(?string $school): void
    {
        $this->school = $school;
    }

    public function getMajor(): ?string
    {
        return $this->major;
    }

    public function setMajor(?string $major): void
    {
        $this->major = $major;
    }

    public function getMarriedStatus(): ?MarriedStatus
    {
        return $this->marriedStatus;
    }

    public function setMarriedStatus(?MarriedStatus $marriedStatus): void
    {
        $this->marriedStatus = $marriedStatus;
    }

    public function getGraduationYear(): ?Carbon
    {
        return $this->graduationYear;
    }

    public function setGraduationYear(?Carbon $graduationYear): void
    {
        $this->graduationYear = $graduationYear;
    }

    public function getLeaveCount(): ?int
    {
        return $this->leaveCount;
    }

    public function setLeaveCount(?int $leaveCount): void
    {
        $this->leaveCount = $leaveCount;
    }

    public function getLastYearLeaveCount(): ?int
    {
        return $this->lastYearLeaveCount;
    }

    public function setLastYearLeaveCount(?int $lastYearLeaveCount): void
    {
        $this->lastYearLeaveCount = $lastYearLeaveCount;
    }

    public function getIsOnboard(): ?bool
    {
        return $this->isOnboard;
    }

    public function setIsOnboard(?bool $isOnboard): void
    {
        $this->isOnboard = $isOnboard;
    }
}
