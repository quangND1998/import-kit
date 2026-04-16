<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Tests\Pipeline;

use PHPUnit\Framework\TestCase;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\StoredFile;
use Vendor\ImportKit\DTO\TemplateValidationError;
use Vendor\ImportKit\DTO\TemplateValidationResult;
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
}

