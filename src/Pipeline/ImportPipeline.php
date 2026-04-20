<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Pipeline;

use Illuminate\Support\Facades\Config;
use RuntimeException;
use Vendor\ImportKit\Contracts\CustomFieldAwareImportModuleInterface;
use Vendor\ImportKit\Contracts\ImportModuleInterface;
use Vendor\ImportKit\Contracts\RowParserInterface;
use Vendor\ImportKit\Contracts\SourceReaderInterface;
use Vendor\ImportKit\Contracts\TemplateErrorMessageAwareImportModuleInterface;
use Vendor\ImportKit\DTO\CommitResult;
use Vendor\ImportKit\DTO\CustomFieldValue;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\ImportResultRowData;
use Vendor\ImportKit\DTO\PreviewResult;
use Vendor\ImportKit\DTO\PreviewRowResult;
use Vendor\ImportKit\DTO\StoredFile;
use Vendor\ImportKit\DTO\ValidationResult;
use Vendor\ImportKit\Exceptions\InvalidTemplateException;
use Vendor\ImportKit\Support\ImportKitTranslator;
use Vendor\ImportKit\Support\ImportMode;
use Vendor\ImportKit\Support\RowWindow;

final class ImportPipeline
{
    /**
     * @return PreviewResult|CommitResult
     */
    public function run(
        string $mode,
        string $sessionId,
        ImportModuleInterface $module,
        StoredFile $file,
        SourceReaderInterface $reader,
        ?ImportRunContext $runContext = null,
        ?RowWindow $rowWindow = null,
        bool $validateRows = true,
        int $lineOffset = 0
    ) {
        $context = $runContext ?? ImportRunContext::from(null, null, []);
        $parser = $module->makeRowParser();
        $validator = $module->makeRowValidator();
        $mapper = $module->makeRowMapper();
        $committer = $module->makeRowCommitter();

        $reader->open($file);
        try {
            $headers = $reader->headers();
            $metadata = $reader->metadata();
            $templateValidation = $reader->templateValidation();
            if (!$templateValidation->ok) {
                $message = $module instanceof TemplateErrorMessageAwareImportModuleInterface
                    ? $module->invalidTemplateMessage()
                    : 'Import template is invalid.';

                throw new InvalidTemplateException($templateValidation->errors, $message);
            }

            $missingHeaders = array_diff($module->requiredHeaders(), $headers);
            if ($missingHeaders !== []) {
                throw new RuntimeException(ImportKitTranslator::missingRequiredHeaders(array_values($missingHeaders)));
            }

            $customFieldMap = (array) ($metadata['custom_field_map'] ?? []);

            $filteredTotalForPagination = null;
            if ($mode === ImportMode::PREVIEW && $rowWindow !== null) {
                $filteredTotalForPagination = $this->countNonBlankParsedRows($reader, $parser, $context);
                $reader->close();
                $reader->open($file);
                $metadata = $reader->metadata();
                $customFieldMap = (array) ($metadata['custom_field_map'] ?? []);
            }

            $line = 1 + max(0, $lineOffset);
            $summary = [
                'total_seen' => 0,
                'ok' => 0,
                'error' => 0,
                'skipped_blank' => 0,
            ];
            $rows = [];

            foreach ($reader->rows($rowWindow) as $row) {
                $line++;
                $summary['total_seen']++;

                $normalized = $parser->parse($row, $context);
                if ($this->isBlankRow($normalized)) {
                    $summary['skipped_blank']++;
                    continue;
                }

                $validation = $validateRows
                    ? $validator->validate($normalized, $context)
                    : ValidationResult::ok();
                $customFieldValues = $this->extractCustomFieldValues($normalized, $customFieldMap);
                if ($validateRows && $module instanceof CustomFieldAwareImportModuleInterface && $customFieldValues !== []) {
                    $customFieldErrors = $module->validateCustomFieldValues($normalized, $customFieldValues, $context);
                    if ($customFieldErrors !== []) {
                        $validation = ValidationResult::fail(array_merge($validation->errors, $customFieldErrors));
                    }
                }
                if (!$validation->ok) {
                    $summary['error']++;
                    if ($mode === ImportMode::PREVIEW) {
                        $rows[] = new PreviewRowResult($line, 'error', $validation->errors, $normalized);
                    } elseif ($mode === ImportMode::COMMIT) {
                        $rows[] = new ImportResultRowData($line, 'error', $validation->errors, $normalized);
                    }
                    continue;
                }

                $mapped = $mapper->map($normalized, $context);
                if ($customFieldValues !== []) {
                    $mapped['custom_field_values'] = array_map(
                        static fn (CustomFieldValue $value): array => $value->toArray(),
                        $customFieldValues
                    );
                }
                $summary['ok']++;

                if ($mode === ImportMode::COMMIT) {
                    $committer->commit($mapped, $context);
                    $rows[] = new ImportResultRowData($line, 'ok', [], $normalized, $mapped);
                    continue;
                }

                $rows[] = new PreviewRowResult($line, 'ok', [], $normalized, $mapped);
            }
        } finally {
            $reader->close();
        }

        if ($mode === ImportMode::COMMIT) {
            return new CommitResult($sessionId, 'completed', $summary, $rows);
        }

        $window = $rowWindow ?? new RowWindow(0, (int) Config::get('import.preview.default_per_page', 20));
        $page = $window->page();
        $perPage = $window->limit;
        if ($rowWindow === null) {
            $filteredTotal = count($rows);
            $nextCursor = null;
        } else {
            $filteredTotal = $filteredTotalForPagination ?? count($rows);
            $nextCursor = ($window->offset + count($rows)) < $filteredTotal
                ? (string) ($window->offset + $perPage)
                : null;
        }

        return new PreviewResult(
            sessionId: $sessionId,
            kind: $module->kind(),
            summary: $summary,
            pagination: [
                'page' => $page,
                'per_page' => $perPage,
                'filtered_total' => $filteredTotal,
                'next_cursor' => $nextCursor,
            ],
            rows: $rows,
            columnLabels: $module->columnLabels(),
            validated: $validateRows,
            dataSource: 'file'
        );
    }

    /**
     * Count logical data rows (non-blank after parse) for the whole file.
     */
    private function countNonBlankParsedRows(
        SourceReaderInterface $reader,
        RowParserInterface $parser,
        ImportRunContext $context
    ): int {
        $count = 0;
        foreach ($reader->rows(null) as $row) {
            $normalized = $parser->parse($row, $context);
            if ($this->isBlankRow($normalized)) {
                continue;
            }
            ++$count;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function isBlankRow(array $normalized): bool
    {
        foreach ($normalized as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $normalized
     * @param array<string, array<string, mixed>> $customFieldMap
     * @return array<int, CustomFieldValue>
     */
    private function extractCustomFieldValues(array $normalized, array $customFieldMap): array
    {
        if ($customFieldMap === []) {
            return [];
        }

        $values = [];
        foreach ($customFieldMap as $columnKey => $mapping) {
            $customFieldId = (string) ($mapping['custom_field_id'] ?? '');
            if ($customFieldId === '') {
                continue;
            }

            $value = $normalized[$columnKey] ?? null;
            $values[] = new CustomFieldValue(
                customFieldId: $customFieldId,
                value: $value,
                columnIndex: isset($mapping['column_index']) ? (int) $mapping['column_index'] : null,
                columnKey: $columnKey,
                meta: [
                    'label' => $mapping['label'] ?? null,
                    'data_type' => $mapping['data_type'] ?? null,
                ]
            );
        }

        return $values;
    }
}
