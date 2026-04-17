<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Tests\Pipeline;

use PHPUnit\Framework\TestCase;
use Vendor\ImportKit\Contracts\ContextAwareRowValidatorInterface;
use Vendor\ImportKit\Contracts\RowCommitterInterface;
use Vendor\ImportKit\Contracts\RowMapperInterface;
use Vendor\ImportKit\Contracts\RowParserInterface;
use Vendor\ImportKit\Contracts\RowValidatorInterface;
use Vendor\ImportKit\Contracts\TemplateErrorMessageAwareImportModuleInterface;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\StoredFile;
use Vendor\ImportKit\DTO\TemplateValidationError;
use Vendor\ImportKit\DTO\TemplateValidationResult;
use Vendor\ImportKit\DTO\ValidationResult;
use Vendor\ImportKit\Exceptions\InvalidTemplateException;
use Vendor\ImportKit\Pipeline\ImportPipeline;
use Vendor\ImportKit\Support\ImportMode;
use Vendor\ImportKit\Tests\Fixtures\FakeContextAwareCommitter;
use Vendor\ImportKit\Tests\Fixtures\FakeImportModule;
use Vendor\ImportKit\Tests\Fixtures\FakeSourceReader;

final class ImportPipelineCustomHeaderTest extends TestCase
{
    public function testInvalidTemplateIsRaisedFromReaderValidation(): void
    {
        $pipeline = new ImportPipeline();
        $module = new FakeImportModule();
        $reader = new FakeSourceReader(
            headers: ['employee_id'],
            rows: [],
            metadata: [],
            templateValidation: TemplateValidationResult::fail([
                new TemplateValidationError('invalid_header_position', 'Header mismatch'),
            ])
        );

        $this->expectException(InvalidTemplateException::class);

        $pipeline->run(
            mode: ImportMode::PREVIEW,
            sessionId: 'session-1',
            module: $module,
            file: new StoredFile('h', 'local', 'x.csv'),
            reader: $reader
        );
    }

    public function testCustomFieldDatatypeValidationReturnsRowError(): void
    {
        $pipeline = new ImportPipeline();
        $module = new FakeImportModule();
        $reader = new FakeSourceReader(
            headers: ['employee_id', 'cf_income_123'],
            rows: [
                ['employee_id' => 'E001', 'cf_income_123' => 'abc'],
            ],
            metadata: [
                'custom_field_map' => [
                    'cf_income_123' => [
                        'custom_field_id' => '123',
                        'column_index' => 26,
                        'label' => '26. Thu nhap | 123',
                        'data_type' => 'NUMBER',
                    ],
                ],
            ]
        );

        $result = $pipeline->run(
            mode: ImportMode::PREVIEW,
            sessionId: 'session-1',
            module: $module,
            file: new StoredFile('h', 'local', 'x.csv'),
            reader: $reader
        );

        $this->assertSame(1, $result->summary['error']);
        $this->assertSame('error', $result->rows[0]->status);
        $this->assertSame('invalid_custom_field_number', $result->rows[0]->errors[0]->code);
    }

    public function testInvalidTemplateMessageCanBeCustomizedByModule(): void
    {
        $pipeline = new ImportPipeline();
        $module = new class() implements TemplateErrorMessageAwareImportModuleInterface {
            public function kind(): string
            {
                return 'user_import';
            }

            public function requiredHeaders(): array
            {
                return [];
            }

            public function optionalHeaders(): array
            {
                return [];
            }

            public function columnLabels(): array
            {
                return [];
            }

            public function invalidTemplateMessage(): string
            {
                return 'Template UserImportModule khong dung dinh dang.';
            }

            public function makeRowParser(): RowParserInterface
            {
                return new class() implements RowParserInterface {
                    public function parse(array $row): array
                    {
                        return $row;
                    }
                };
            }

            public function makeRowValidator(): RowValidatorInterface
            {
                return new class() implements RowValidatorInterface {
                    public function validate(array $normalizedRow): ValidationResult
                    {
                        return ValidationResult::ok();
                    }
                };
            }

            public function makeRowMapper(): RowMapperInterface
            {
                return new class() implements RowMapperInterface {
                    public function map(array $validatedRow): array
                    {
                        return $validatedRow;
                    }
                };
            }

            public function makeRowCommitter(): RowCommitterInterface
            {
                return new class() implements RowCommitterInterface {
                    public function commit(array $mappedRow): void
                    {
                    }
                };
            }
        };
        $reader = new FakeSourceReader(
            headers: ['employee_id'],
            rows: [],
            metadata: [],
            templateValidation: TemplateValidationResult::fail([
                new TemplateValidationError('invalid_header_position', 'Header mismatch'),
            ])
        );

        try {
            $pipeline->run(
                mode: ImportMode::PREVIEW,
                sessionId: 'session-1',
                module: $module,
                file: new StoredFile('h', 'local', 'x.csv'),
                reader: $reader
            );
            $this->fail('Expected InvalidTemplateException was not thrown.');
        } catch (InvalidTemplateException $exception) {
            $this->assertSame('Template UserImportModule khong dung dinh dang.', $exception->getMessage());
        }
    }

    public function testCommitUsesContextAwareCommitterAndIncludesCustomFieldValues(): void
    {
        $pipeline = new ImportPipeline();
        $committer = new FakeContextAwareCommitter();
        $module = new FakeImportModule([], $committer);
        $reader = new FakeSourceReader(
            headers: ['employee_id', 'cf_income_123'],
            rows: [
                ['employee_id' => 'E001', 'cf_income_123' => '1000'],
            ],
            metadata: [
                'custom_field_map' => [
                    'cf_income_123' => [
                        'custom_field_id' => '123',
                        'column_index' => 26,
                        'label' => '26. Thu nhap | 123',
                        'data_type' => 'NUMBER',
                    ],
                ],
            ]
        );

        $result = $pipeline->run(
            mode: ImportMode::COMMIT,
            sessionId: 'session-1',
            module: $module,
            file: new StoredFile('h', 'local', 'x.csv'),
            reader: $reader,
            runContext: ImportRunContext::from(tenantId: 77, workspaceId: 88, context: [])
        );

        $this->assertSame('completed', $result->status);
        $this->assertCount(1, $committer->committed);
        $this->assertSame(88, $committer->committed[0]['workspace_id']);
        $this->assertArrayHasKey('custom_field_values', $committer->committed[0]['row']);
    }

    public function testValidatorCanConsumeWorkspaceIdFromRunContext(): void
    {
        $pipeline = new ImportPipeline();
        $reader = new FakeSourceReader(
            headers: ['employee_id'],
            rows: [
                ['employee_id' => 'E001'],
            ],
            metadata: []
        );

        $validator = new class() implements ContextAwareRowValidatorInterface {
            public ?int $capturedWorkspaceId = null;

            public function validate(array $normalizedRow): ValidationResult
            {
                return ValidationResult::ok();
            }

            public function validateWithContext(array $normalizedRow, ImportRunContext $context): ValidationResult
            {
                $this->capturedWorkspaceId = $context->workspaceId;

                return ValidationResult::ok();
            }
        };

        $module = new class($validator) implements \Vendor\ImportKit\Contracts\ImportModuleInterface {
            public function __construct(private readonly ContextAwareRowValidatorInterface $validator)
            {
            }

            public function kind(): string
            {
                return 'position_import';
            }

            public function requiredHeaders(): array
            {
                return ['employee_id'];
            }

            public function optionalHeaders(): array
            {
                return [];
            }

            public function columnLabels(): array
            {
                return [];
            }

            public function makeRowParser(): RowParserInterface
            {
                return new class() implements RowParserInterface {
                    public function parse(array $row): array
                    {
                        return $row;
                    }
                };
            }

            public function makeRowValidator(): RowValidatorInterface
            {
                return $this->validator;
            }

            public function makeRowMapper(): RowMapperInterface
            {
                return new class() implements RowMapperInterface {
                    public function map(array $validatedRow): array
                    {
                        return $validatedRow;
                    }
                };
            }

            public function makeRowCommitter(): RowCommitterInterface
            {
                return new class() implements RowCommitterInterface {
                    public function commit(array $mappedRow): void
                    {
                    }
                };
            }
        };

        $pipeline->run(
            mode: ImportMode::PREVIEW,
            sessionId: 'session-ctx',
            module: $module,
            file: new StoredFile('h', 'local', 'x.csv'),
            reader: $reader,
            runContext: ImportRunContext::from(tenantId: 1, workspaceId: 999, context: [])
        );

        $this->assertSame(999, $validator->capturedWorkspaceId);
    }
}

