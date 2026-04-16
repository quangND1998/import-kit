<?php

namespace Happytime\Application\Employee\Service;

use Carbon\Carbon;
use Happytime\Domain\Employee\Enum\EmploymentCategory;
use Happytime\Domain\Employee\Enum\EmploymentStatus;
use Happytime\Domain\Employee\Enum\Gender;
use Happytime\Domain\Employee\Enum\MarriedStatus;
use Happytime\Domain\Workspace\Workspace;

class Employee
{
    public function __construct(
        private readonly int $id,
        private readonly ?int $identificationId,
        private readonly ?string $phone,
        private readonly ?int $role,
        private readonly ?int $roleId,
        private readonly string $fullName,
        private readonly ?string $personalEmail,
        private readonly ?string $companyEmail,
        private readonly ?string $employeeCode,
        private readonly ?EmploymentStatus $employeeStatus,
        private readonly ?Carbon $employeeStatusFrom,
        private readonly ?EmploymentCategory $employmentCategory,
        private readonly ?Carbon $employmentCategoryFrom,
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
        private readonly ?int $branchId,
        private readonly ?string $note,
        private readonly ?MarriedStatus $marriedStatus,
        private readonly ?string $school,
        private readonly ?string $major,
        private readonly ?int $graduationYear,
        private readonly ?string $isAttendanceExempted,
        private readonly ?string $avatar,
        private readonly ?int $positionId,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getIdentificationId(): ?int
    {
        return $this->identificationId;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getRole(): ?int
    {
        return $this->role;
    }

    public function getRoleId(): ?int
    {
        return $this->roleId;
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

    public function getEmploymentCategory(): ?EmploymentCategory
    {
        return $this->employmentCategory;
    }

    public function getEmploymentCategoryFrom(): ?Carbon
    {
        return $this->employmentCategoryFrom;
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

    public function getBranchId(): ?int
    {
        return $this->branchId;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getMarriedStatus(): ?MarriedStatus
    {
        return $this->marriedStatus;
    }

    public function getSchool(): ?string
    {
        return $this->school;
    }

    public function getMajor(): ?string
    {
        return $this->major;
    }

    public function getGraduationYear(): ?int
    {
        return $this->graduationYear;
    }

    public function getIsAttendanceExempted(): ?string
    {
        return $this->isAttendanceExempted;
    }

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function getPositionId(): ?int
    {
        return $this->positionId;
    }
}
