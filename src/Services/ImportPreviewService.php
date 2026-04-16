<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Services;

use Vendor\ImportKit\Contracts\ImportRegistryInterface;
use Vendor\ImportKit\Contracts\PreviewSessionStoreInterface;
use Vendor\ImportKit\Contracts\SourceReaderInterface;
use Vendor\ImportKit\Contracts\SourceReaderResolverInterface;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\PreviewResult;
use Vendor\ImportKit\DTO\StoredFile;
use Vendor\ImportKit\Pipeline\ImportPipeline;
use Vendor\ImportKit\Support\ImportMode;
use Vendor\ImportKit\Support\RowWindow;

final class ImportPreviewService
{
    public function __construct(
        private readonly ImportRegistryInterface $registry,
        private readonly ImportPipeline $pipeline,
        private readonly ColumnLabelService $columnLabelService,
        private readonly PreviewSessionStoreInterface $sessions,
        private readonly SourceReaderResolverInterface $sourceReaderResolver
    ) {
    }

    public function preview(
        string $kind,
        string $sessionId,
        StoredFile $file,
        ?ImportRunContext $runContext = null,
        ?SourceReaderInterface $reader = null,
        ?RowWindow $rowWindow = null,
        bool $validate = true
    ): PreviewResult {
        $module = $this->registry->get($kind);
        $resolvedReader = $reader ?? $this->sourceReaderResolver->resolve($file, $kind, $module, $runContext);
        $result = $this->pipeline->run(
            ImportMode::PREVIEW,
            $sessionId,
            $module,
            $file,
            $resolvedReader,
            $runContext,
            $rowWindow,
            $validate
        );

        if (!$result instanceof PreviewResult) {
            throw new \RuntimeException('Preview pipeline returned invalid result.');
        }

        $decorated = new PreviewResult(
            sessionId: $result->sessionId,
            kind: $result->kind,
            summary: $result->summary,
            pagination: $result->pagination,
            rows: $result->rows,
            columnLabels: $this->columnLabelService->labelsFor($module),
            validated: $validate,
            dataSource: 'file'
        );

        $this->sessions->savePreviewSnapshot(
            $sessionId,
            array_map(static fn ($row): array => $row->toArray(), $decorated->rows),
            $decorated->columnLabels,
            [
                'validated' => $validate,
                'source' => 'file',
            ]
        );

        return $decorated;
    }
}
