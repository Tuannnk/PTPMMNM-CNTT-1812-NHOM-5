<?php
declare(strict_types=1);

function field(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

/** Đọc số nguyên dương; trả null khi rỗng hoặc sai định dạng. */
function intField(array $input, string $key): ?int
{
    $value = filter_var(field($input, $key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $value === false ? null : $value;
}

/** Đọc mảng chuỗi (vd. item_id[]); bỏ phần tử không phải chuỗi. */
function listField(array $input, string $key): array
{
    $value = $input[$key] ?? [];
    if (!is_array($value)) {
        return [];
    }
    return array_values(array_map(fn($v) => is_string($v) ? trim($v) : '', $value));
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function qty(int $value): string
{
    return number_format($value, 0, ',', '.');
}
