<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class ImportResultRowData
{
    /**
     * @param array<int, ValidationError> $errors
     * @param array<string, mixed> $normalized
     * @param array<string, mixed>|null $mapped
     */
    public function __construct(
        public readonly int $line,
        public readonly string $status,
        public readonly array $errors = [],
        public readonly array $normalized = [],
        public readonly ?array $mapped = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $mapped = $this->mapped;
        if (is_array($mapped) && isset($mapped['custom_field_values']) && is_array($mapped['custom_field_values'])) {
            $mapped['custom_field_values'] = $this->normalizeCustomFieldValues($mapped['custom_field_values']);
        }

        $fieldErrors = [];
        $messages = [];
        foreach ($this->errors as $error) {
            if (!$error instanceof ValidationError) {
                continue;
            }

            if (!isset($fieldErrors[$error->field])) {
                $fieldErrors[$error->field] = [];
            }
            $fieldErrors[$error->field][] = $error->message;
            $messages[] = $error->message;
        }

        $messages = array_values(array_unique($messages));
        $data = $mapped ?? $this->normalized;
        $firstError = $this->errors[0] ?? null;
        $errorCode = $firstError instanceof ValidationError ? $firstError->code : null;

        return [
            'row' => $this->line,
            'line' => $this->line,
            'status' => $this->status,
            'data' => $data,
            'message' => $messages,
            'error_code' => $errorCode,
            'errors' => $fieldErrors,
            'raw_errors' => array_map(static fn (ValidationError $error) => $error->toArray(), $this->errors),
            'normalized' => $this->normalized,
            'mapped' => $mapped,
        ];
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCustomFieldValues(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                $normalized[] = $value;
                continue;
            }

            if (is_object($value) && method_exists($value, 'toArray')) {
                /** @var array<string, mixed> $item */
                $item = $value->toArray();
                $normalized[] = $item;
            }
        }

        return $normalized;
    }
}
