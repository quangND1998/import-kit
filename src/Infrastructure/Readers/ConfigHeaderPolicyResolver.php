<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Infrastructure\Readers;

use Vendor\ImportKit\Contracts\HeaderPolicyResolverInterface;
use Vendor\ImportKit\DTO\HeaderPolicy;

final class ConfigHeaderPolicyResolver implements HeaderPolicyResolverInterface
{
    public function resolve(?string $kind = null): HeaderPolicy
    {
        $default = (array) config('import.header.default', []);
        $kindConfig = is_string($kind) && $kind !== ''
            ? (array) config('import.header.kinds.' . $kind, [])
            : [];

        $merged = array_replace_recursive($default, $kindConfig);

        return new HeaderPolicy(
            headerRowIndex: (int) ($merged['row'] ?? 1),
            requiredHeaders: array_values((array) ($merged['required_headers'] ?? [])),
            optionalHeaders: array_values((array) ($merged['optional_headers'] ?? [])),
            strictOrder: (bool) ($merged['strict_order'] ?? false),
            strictCoreColumns: array_map(
                static fn ($value): string => (string) $value,
                (array) ($merged['strict_core_columns'] ?? [])
            ),
            customFieldStartColumn: isset($merged['custom_field_start_column'])
                ? (int) $merged['custom_field_start_column']
                : null,
            customFieldPattern: (string) ($merged['custom_field_pattern'] ?? '/\|\s*(?<id>[A-Za-z0-9_-]+)\s*$/'),
            normalizeMode: (string) ($merged['normalize_mode'] ?? 'snake')
        );
    }
}

