<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Services;

use Vendor\ImportKit\Contracts\ImportModuleInterface;

final class ColumnLabelService
{
    /**
     * @return array<string, string>
     */
    public function labelsFor(ImportModuleInterface $module): array
    {
        $configLabels = config('import.column_labels.' . $module->kind(), []);
        $defaultLabels = config('import.column_labels.default', []);

        return array_merge($defaultLabels, $configLabels, $module->columnLabels());
    }
}
