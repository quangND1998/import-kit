<?php

namespace Happytime\Application\Employee\Service;

use App\Helpers\DateHelper;
use App\Helpers\SafeTemplateProcessor;
use App\Models\Employee as EmployeeModel;
use PhpOffice\PhpWord\TemplateProcessor;
use Happytime\Domain\CustomField\Repository\CustomFieldRepository;
use App\Repositories\EmployeeRepository;
use Happytime\Domain\CustomField\Enum\Category;
use Happytime\Domain\Common\Employee\Employee;
use Carbon\Carbon;
use DOMDocument;
use Happytime\Domain\CustomField\Enum\DataType;
use Happytime\Domain\CustomFieldEmployee\EmployeeValue;
use Happytime\Domain\CustomFieldEmployee\Repository\Custom\CustomFieldEmployeeValueRepository;
use Happytime\Domain\Employee\Enum\Gender;
use Happytime\Domain\Employee\Enum\MarriedStatus;
use Happytime\Domain\Workspace\Workspace;
use Illuminate\Support\Facades\Log;
use ReflectionObject;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

class ExportTemplateForTopcvService
{
    const CUSTOM_FIELD_KEYS = [
        'recruiter_relative_name' => 'has_recruiter_relative',
        'recruiter_relative_company' => 'has_recruiter_relative',
        'recruiter_relative_position' => 'has_recruiter_relative',
        'recruiter_relative_relationship' => 'has_recruiter_relative',
        'topcv_relative_name' => 'has_topcv_relative',
        'topcv_relative_position' => 'has_topcv_relative',
        'topcv_relative_relationship' => 'has_topcv_relative',
        'issue_company' => 'has_legal_issue',
        'issue_when' => 'has_legal_issue',
        'issue_reason' => 'has_legal_issue',
        'criminal_time' => 'has_criminal_record',
        'criminal_reason' => 'has_criminal_record',
        'fired_time' => 'has_been_fired',
        'fired_reason' => 'has_been_fired',
        'business_info' => 'has_business',
        'topcv_last_day' => 'worked_at_topcv',
        'topcv_position' => 'worked_at_topcv',
        'interview_time' => 'interviewed_at_topcv',
        'interview_position' => 'interviewed_at_topcv',
        'Thong_tin_lien_he_khan_cap' => null,
        'Thong_tin_lien_he_khan_cap_2' => null,
        'So_so_BHXH' => null,
    ];

    public function __construct(
        private readonly CustomFieldRepository $customFieldRepository,
        private readonly EmployeeRepository $employeeRepository,
        private readonly CustomFieldEmployeeValueRepository $customFieldEmployeeValueRepository,
    ) {
    }

    /**
     * @param int[] $employeeIds
     */
    public function handle(array $employeeIds): void
    {
        $workspace = new Workspace(config('topcv.topcv_workspace_id'));

        $customFieldActive = $this->customFieldRepository->getAllActive(
            workspace: $workspace,
            category: Category::CUSTOM
        );

        $employees = $this->employeeRepository->model()
            ->with([
                'dependentPersons',
                'position',
            ])
            ->where('workspace_id', $workspace->getId())
            ->whereIn('id', $employeeIds)
            ->get();

        $date = Carbon::now()->format('d-m-Y');
        foreach ($employees as $employee) {
            $templatePath = public_path('topcv-template/Ngay thang_Vi tri_Ho ten ung vien_Candidate information.docx');
            $outputPath = storage_path('app/topcv-template/employees/'.$date.'_'.$employee->position->name.'_'.$employee->id.'_'.Str::slug($employee->full_name).'_'.time().'.docx');
    
            $templateProcessor = new SafeTemplateProcessor($templatePath);

            $dependentPersons = $employee->dependentPersons;
            $educationHistories = $employee->education_histories ?? [];
            $totalChildren = $dependentPersons->whereIn('relationship_name', ['con', 'Con', 'Con trai', 'Con gái'])->count();
            $experiences = $employee->experiences ?? [];

            // Thông tin cá nhân
            $templateProcessor->setValue("full_name", $employee->full_name ?? '');
            $templateProcessor->setValue("gender", $employee->sex !== null ? Gender::from($employee->sex)->getLabel() : '');
            $templateProcessor->setValue("dob", $employee->birth_day ? Carbon::parse($employee->birth_day)->format('d/m/Y') : '');
            $templateProcessor->setValue("pob", '');
            $templateProcessor->setValue("marital_status", $employee->married_status ? MarriedStatus::from($employee->married_status)->getLabel() : '');
            $templateProcessor->setValue("children", $totalChildren);
            $templateProcessor->setValue("address_permanent", $employee->permanent_address ?? '');
            $templateProcessor->setValue("address_contact", $employee->current_address ?? '');
            $templateProcessor->setValue("phone", $employee->phone ?? '');
            $templateProcessor->setValue("email", $employee->email ?? '');
            $templateProcessor->setValue("identity_number", $employee->id_card ?? '');
            $templateProcessor->setValue("identity_issued_date", $employee->id_card_issue_date ?? '');
            $templateProcessor->setValue("identity_issued_place", $employee->id_card_issuer ?? '');
            $templateProcessor->setValue("bank_name", $employee->bank ?? '');
            $templateProcessor->setValue("bank_account", $employee->bank_account_number ?? '');
            $templateProcessor->setValue("bank_branch", $employee->bank_branch ?? '');
            $templateProcessor->setValue("pit_code", $employee->tax_code ?? '');

            $dependentPersonRows = [];
            foreach ($dependentPersons as $dependentPerson) {
                // Thông tin người thân
                $dependentPersonRows[] = [
                    'family.name' => $dependentPerson->full_name ?? '',
                    'family.yob' => $dependentPerson->dob ? Carbon::parse($dependentPerson->dob)->format('Y') : '',
                    'family.relationship' => $dependentPerson->relationship_name['name'] ?? '',
                    'family.job' => '',
                    'family.workplace' => '',
                ];
            }
            if (!empty($dependentPersonRows)) {
                $templateProcessor->cloneRowAndSetValues('family.name', $dependentPersonRows);
            } else {
                $templateProcessor->cloneRow('family.name', 1);
                $templateProcessor->setValue('family.name#1', '');
                $templateProcessor->setValue('family.yob#1', '');
                $templateProcessor->setValue('family.relationship#1', '');
                $templateProcessor->setValue('family.job#1', '');
                $templateProcessor->setValue('family.workplace#1', '');
            }

            $educationRows = [];
            foreach ($educationHistories as $educationHistory) {
                $to = $this->fixIncorrectValue($educationHistory['to'] ?? '');
                $schoolName = $this->fixIncorrectValue($educationHistory['school_name'] ?? '');
                $major = $this->fixIncorrectValue($educationHistory['major'] ?? '');

                // Thông tin học vấn
                $educationRows[] = [
                    'edu.school' => $schoolName,
                    'edu.major' => $major,
                    'edu.degree' => '',
                    'edu.graduation_year' => DateHelper::getYear($to),
                ];
            }
            if (!empty($educationRows)) {
                $templateProcessor->cloneRowAndSetValues('edu.school', $educationRows);
            } else {
                $templateProcessor->cloneRow('edu.school', 1);
                $templateProcessor->setValue('edu.school#1', '');
                $templateProcessor->setValue('edu.major#1', '');
                $templateProcessor->setValue('edu.degree#1', '');
                $templateProcessor->setValue('edu.graduation_year#1', '');
            }

            $experienceRows = [];
            $count = 1;
            $templateProcessor->cloneBlock('experience_block', count($experiences), true, true);
            foreach ($experiences as $key => $experience) {
                $from = $this->fixIncorrectValue($experience['from'] ?? '');
                $to = $this->fixIncorrectValue($experience['to'] ?? '');
                $companyName = $this->fixIncorrectValue($experience['company_name'] ?? '');
                $position = $this->fixIncorrectValue($experience['position'] ?? '');

                // Quá trình làm việc
                $experienceRows[] = [
                    'experience.from' => $from,
                    'experience.to' => $to,
                    'experience.company_name' => $companyName,
                    'experience.position' => $position,
                ];

                $templateProcessor->setValue('experience.company_name#'.$count, $companyName);
                $templateProcessor->setValue('experience.position#'.$count, $position);
                $templateProcessor->setValue('experience.from#'.$count, $from);
                $templateProcessor->setValue('experience.to#'.$count, $to);

                $count++;
            }

            $this->removeBlockMarkers($templateProcessor, 'delete');

            // Custom field
            $employeeValues = $this->customFieldEmployeeValueRepository->getEmployeeValue(
                workspace: $workspace,
                employee: new Employee(id: $employee->id, workspace: $workspace),
                customFieldIds: array_map(static fn($item) => $item->getId(), $customFieldActive)
            );

            $hasValue = [
                'has_recruiter_relative' => false,
                'has_topcv_relative' => false,
                'has_legal_issue' => false,
                'has_criminal_record' => false,
                'has_been_fired' => false,
                'has_business' => false,
                'worked_at_topcv' => false,
                'interviewed_at_topcv' => false,
            ];

            foreach ($employeeValues as $employeeValue) {
                $this->setEmployeeCustomFieldValues(
                    $templateProcessor,
                    $employeeValue,
                    $hasValue,
                );
            }

            $templateProcessor->setValue('has_recruiter_relative', $hasValue['has_recruiter_relative'] ? 'Có' : 'Không');
            $templateProcessor->setValue('has_topcv_relative', $hasValue['has_topcv_relative'] ? 'Có' : 'Không');
            $templateProcessor->setValue('has_legal_issue', $hasValue['has_legal_issue'] ? 'Có' : 'Không');
            $templateProcessor->setValue('has_criminal_record', $hasValue['has_criminal_record'] ? 'Có' : 'Không');
            $templateProcessor->setValue('has_been_fired', $hasValue['has_been_fired'] ? 'Có' : 'Không');
            $templateProcessor->setValue('has_business', $hasValue['has_business'] ? 'Có' : 'Không');
            $templateProcessor->setValue('worked_at_topcv', $hasValue['worked_at_topcv'] ? 'Có' : 'Không');
            $templateProcessor->setValue('interviewed_at_topcv', $hasValue['interviewed_at_topcv'] ? 'Có' : 'Không');

            $refObject = new ReflectionObject($templateProcessor);
            $property = $refObject->getProperty('tempDocumentMainPart');
            $property->setAccessible(true);
            
            $content = $property->getValue($templateProcessor);
            $content = preg_replace('/\$\{[^}]+\}/', '', $content);
            $property->setValue($templateProcessor, $content);

            $templateProcessor->saveAs($outputPath);
        }
    }

    private function setEmployeeCustomFieldValues(
        &$templateProcessor,
        EmployeeValue $employeeValue,
        &$hasValue,
    )
    {
        $customField = $employeeValue->getCustomField();
        $customFieldKey = $customField->getKey()?->getValue() ?? '';
        if (!empty($customFieldKey)) {
            $customFieldKey = str_replace('Custom_field/', '', $customFieldKey);
        }
        $customFieldValue = $employeeValue->getAbstractValue() ? $employeeValue->getAbstractValue()->getValue() : null;
        if ($customField->getDataType() === DataType::DATE) {
            $customFieldValue = $customFieldValue ? $customFieldValue->format('d/m/Y') : null;
        }
        if ($customField->getDataType() === DataType::FILE) {
            $customFieldValue = '';
        }

        foreach (self::CUSTOM_FIELD_KEYS as $key => $value) {
            if ($customFieldKey !== $key) {
                continue;
            }

            if ($value && array_key_exists($value, $hasValue) && $customFieldValue !== null && $customFieldValue !== '') {
                $hasValue[$value] = true;
            }

            try {
                $templateProcessor->setValue($key, $customFieldValue);
            } catch (\Exception $e) {
                $templateProcessor->setValue($key, '');
            }
        }
    }

    private function normalizeBlockTags($xml, $blockName)
    {
        // Ghép các tag bị tách
        $xml = preg_replace('/\{'.$blockName.'\}/', '{'.$blockName.'}', $xml);
        $xml = preg_replace('/\{\/'.$blockName.'\}/', '{/'.$blockName.'}', $xml);
    
        // Gộp lại nếu bị split
        $xml = preg_replace('/<w:t>\{(.*?)<\/w:t>\s*<w:t>(.*?)\}<\/w:t>/', '<w:t>{$1$2}</w:t>', $xml);
    
        return $xml;
    }

    private function removeBlockMarkers(TemplateProcessor $template, string $blockName): void {
        $reflection = new \ReflectionClass($template);
        $property = $reflection->getProperty('tempDocumentMainPart');
        $property->setAccessible(true);
    
        $xml = $property->getValue($template);

        // Tạo DOM
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        @$dom->loadXML($xml);
        // Tìm tất cả các <w:tr>
        $trs = $dom->getElementsByTagName('tr');

        foreach ($trs as $tr) {
            if ($tr->textContent !== null && strpos($tr->textContent, $blockName) !== false) {
                $tr->parentNode->removeChild($tr);
            }
        }

        $xml = $dom->saveXML();
        $property->setValue($template, $xml);
    }

    // Check với những data cũ đã bị lưu sai thành undefined
    private function fixIncorrectValue(?string $string): ?string
    {
        return $string === 'undefined' ? null : $string;
    }
}