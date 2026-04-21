<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Infrastructure\Readers;

use InvalidArgumentException;
use Vendor\ImportKit\Contracts\HeaderLocatorRegistryInterface;
use Vendor\ImportKit\Contracts\HeaderPolicyAwareImportModuleInterface;
use Vendor\ImportKit\Contracts\HeaderPolicyResolverInterface;
use Vendor\ImportKit\Contracts\ImportModuleInterface;
use Vendor\ImportKit\Contracts\SourceReaderInterface;
use Vendor\ImportKit\Contracts\SourceReaderResolverInterface;
use Vendor\ImportKit\DTO\HeaderPolicy;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\StoredFile;

final class SourceReaderResolver implements SourceReaderResolverInterface
{
    public function __construct(
        private readonly HeaderLocatorRegistryInterface $headerLocatorRegistry,
        private readonly HeaderPolicyResolverInterface $headerPolicyResolver,
    ) {
    }

    public function resolve(
        StoredFile $file,
        ?string $kind = null,
        ?ImportModuleInterface $module = null,
        ?ImportRunContext $context = null
    ): SourceReaderInterface
    {
        $extension = strtolower(pathinfo($file->path, PATHINFO_EXTENSION));
        if ($extension === 'csv') {
            return new CsvSourceReader();
        }

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            $resolvedContext = $context ?? $this->resolveRunContext($file);
            $locator = $this->headerLocatorRegistry->resolve($kind);
            if ($locator instanceof DefaultHeaderLocator) {
                $policy = $this->resolveHeaderPolicy($kind, $module, $resolvedContext);
                $locator = new ConfigurableHeaderLocator(
                    policyResolver: $this->headerPolicyResolver,
                    kind: $kind,
                    policyOverride: $policy
                );
            }

            return new SpreadsheetSourceReader($locator);
        }

        throw new InvalidArgumentException("Unsupported import file extension '{$extension}'.");
    }

    private function resolveRunContext(StoredFile $file): ImportRunContext
    {
        $meta = $file->meta;
        $tenantId = isset($meta['tenant_id']) ? (int) $meta['tenant_id'] : null;
        $workspaceId = isset($meta['workspace_id']) ? (int) $meta['workspace_id'] : null;
        $context = is_array($meta['context'] ?? null) ? (array) $meta['context'] : [];

        return ImportRunContext::from($tenantId, $workspaceId, $context);
    }

    private function resolveHeaderPolicy(
        ?string $kind,
        ?ImportModuleInterface $module,
        ImportRunContext $context
    ): ?HeaderPolicy {
        if ($module instanceof HeaderPolicyAwareImportModuleInterface) {
            return $module->headerPolicy($context);
        }

        return $this->headerPolicyResolver->resolve($kind);
    }
}
