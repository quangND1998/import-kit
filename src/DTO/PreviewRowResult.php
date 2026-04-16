<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class PreviewRowResult
{
    /**
     * @param array<int, ValidationError> $errors
     * @param array<string, mixed> $normalized
     * @param array<string, mixed>|null $preview
     */
    public function __construct(
        public readonly int $line,
        public readonly string $status,
        public readonly array $errors,
        public readonly array $normalized,
        public readonly ?array $preview = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $preview = $this->preview;
        if (is_array($preview) && isset($preview['custom_field_values']) && is_array($preview['custom_field_values'])) {
            $preview['custom_field_values'] = $this->normalizeCustomFieldValues($preview['custom_field_values']);
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
        $data = $preview ?? $this->normalized;
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
            'preview' => $preview,
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
