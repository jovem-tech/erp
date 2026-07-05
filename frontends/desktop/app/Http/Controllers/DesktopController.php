<?php

namespace App\Http\Controllers;

abstract class DesktopController
{
    /**
     * @param mixed $value
     */
    protected function normalizeDecimalValue(mixed $value, int $scale = 2): string
    {
        if ($value === null || $value === '') {
            return number_format(0, $scale, '.', '');
        }

        $normalized = preg_replace('/[^\d,.\-]/u', '', trim((string) $value)) ?? '';

        if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === ',') {
            return number_format(0, $scale, '.', '');
        }

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($lastComma !== false) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif ($lastDot !== false) {
            $parts = explode('.', $normalized);
            $lastPart = (string) end($parts);

            if (count($parts) > 2 || strlen($lastPart) === 3) {
                $normalized = str_replace('.', '', $normalized);
            }
        }

        $valueFloat = round((float) $normalized, $scale);

        return number_format($valueFloat, $scale, '.', '');
    }

    /**
     * @param mixed $value
     */
    protected function normalizeMoneyValue(mixed $value): string
    {
        return $this->normalizeDecimalValue($value, 2);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $moneyFields
     * @param array<string, array<int, string>> $nestedMoneyFields
     * @return array<string, mixed>
     */
    protected function normalizeMoneyPayload(array $payload, array $moneyFields = [], array $nestedMoneyFields = []): array
    {
        foreach ($moneyFields as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = $this->normalizeMoneyValue($payload[$field]);
            }
        }

        foreach ($nestedMoneyFields as $collectionKey => $fields) {
            if (!isset($payload[$collectionKey]) || !is_array($payload[$collectionKey])) {
                continue;
            }

            foreach ($payload[$collectionKey] as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $payload[$collectionKey][$index] = $this->normalizeMoneyPayload($row, $fields);
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $decimalFields
     * @param array<string, array<int, string>> $nestedDecimalFields
     * @return array<string, mixed>
     */
    protected function normalizeDecimalPayload(array $payload, array $decimalFields = [], array $nestedDecimalFields = [], int $scale = 4): array
    {
        foreach ($decimalFields as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = $this->normalizeDecimalValue($payload[$field], $scale);
            }
        }

        foreach ($nestedDecimalFields as $collectionKey => $fields) {
            if (! isset($payload[$collectionKey]) || ! is_array($payload[$collectionKey])) {
                continue;
            }

            foreach ($payload[$collectionKey] as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $payload[$collectionKey][$index] = $this->normalizeDecimalPayload($row, $fields, scale: $scale);
            }
        }

        return $payload;
    }
}
