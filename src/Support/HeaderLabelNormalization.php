<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Support;

final class HeaderLabelNormalization
{
    /**
     * Build header keys for header_map matching.
     *
     * Modes:
     * - snake: lowercase (UTF-8), spaces/hyphens -> underscores, keep diacritics (e.g. mã_nhân_viên).
     * - snake_unaccent: same as snake but strip combining marks / fold to ASCII-ish (e.g. ma_nhan_vien).
     * - raw: lowercase only, no space/underscore folding.
     */
    public static function normalize(string $label, string $mode): string
    {
        $trimmed = trim($label);
        if ($trimmed === '') {
            return '';
        }

        $lower = mb_strtolower($trimmed, 'UTF-8');

        return match ($mode) {
            'raw' => $lower,
            'snake_unaccent' => self::toSnakeUnaccent($lower),
            default => self::toSnake($lower),
        };
    }

    private static function toSnake(string $lower): string
    {
        return str_replace([' ', '-'], '_', $lower);
    }

    private static function toSnakeUnaccent(string $lower): string
    {
        $folded = self::foldDiacritics($lower);

        return str_replace([' ', '-'], '_', $folded);
    }

    /**
     * Remove combining characters (NFD) and Vietnamese đ; optional iconv fallback.
     */
    private static function foldDiacritics(string $text): string
    {
        $text = str_replace(['đ'], ['d'], $text);

        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (is_string($decomposed) && $decomposed !== '') {
                $stripped = preg_replace('/\p{Mn}/u', '', $decomposed);
                if (is_string($stripped)) {
                    $text = $stripped;
                }
            }
        } elseif (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($converted) && $converted !== '') {
                $text = $converted;
            }
        }

        return mb_strtolower($text, 'UTF-8');
    }
}
