<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Support;

final class ImportKitTranslator
{
    /**
     * @param array<int, string> $missing
     */
    public static function missingRequiredHeaders(array $missing): string
    {
        $headers = implode(', ', $missing);

        return self::line('import.missing_required_headers', ['headers' => $headers], 'Missing required headers: ' . $headers);
    }

    public static function invalidCommitResult(): string
    {
        return self::line('import.invalid_commit_result', [], 'Commit pipeline returned invalid result.');
    }

    public static function invalidPreviewResult(): string
    {
        return self::line('import.invalid_preview_result', [], 'Preview pipeline returned invalid result.');
    }

    /**
     * @param array<string, mixed> $replace
     */
    private static function line(string $key, array $replace, string $fallback): string
    {
        if (function_exists('trans')) {
            $out = trans('import-kit::' . $key, $replace);
            if (is_string($out) && $out !== '') {
                return $out;
            }
        }

        return $fallback;
    }
}
