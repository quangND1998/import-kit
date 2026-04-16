<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class ImportRunContext
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly ?int $tenantId,
        public readonly ?int $workspaceId,
        public readonly array $context = []
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function from(?int $tenantId, ?int $workspaceId, array $context = []): self
    {
        return new self($tenantId, $workspaceId, $context);
    }

    public function with(string $key, mixed $value): self
    {
        $context = $this->context;
        $context[$key] = $value;

        return new self($this->tenantId, $this->workspaceId, $context);
    }

    /**
     * @param mixed $default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }
}
