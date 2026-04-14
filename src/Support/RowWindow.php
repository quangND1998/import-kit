<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Support;

final class RowWindow
{
    public function __construct(
        public readonly int $offset = 0,
        public readonly int $limit = 20
    ) {
    }

    public static function fromPage(int $page, int $perPage): self
    {
        $safePage = max(1, $page);
        $safePerPage = max(1, $perPage);

        return new self(
            offset: ($safePage - 1) * $safePerPage,
            limit: $safePerPage
        );
    }

    public function page(): int
    {
        return (int) floor($this->offset / max(1, $this->limit)) + 1;
    }
}
