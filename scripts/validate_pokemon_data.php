<?php
declare(strict_types=1);

$path = $argv[1] ?? __DIR__ . '/../data/pokemon_data.json';
if (!is_file($path)) {
    fwrite(STDERR, "[ERROR] File not found: {$path}\n");
    exit(1);
}

$json = json_decode((string)file_get_contents($path), true);
if (!is_array($json)) {
    fwrite(STDERR, "[ERROR] Invalid JSON: " . json_last_error_msg() . "\n");
    exit(1);
}

$required = ['name', 'ing', 'amount', 'level', 'help_speed', 'ing_percent'];
$kanaRegex = '/[\x{3040}-\x{30ff}]/u';
$hasAbsol = false;
$hasApple = false;
$hasPumpkabooPattern = false;

foreach ($json as $index => $row) {
    if (!is_array($row)) {
        fail_row($index, 'row is not an object');
    }
    foreach ($required as $key) {
        if (!array_key_exists($key, $row) || !is_string($row[$key]) || $row[$key] === '') {
            fail_row($index, "missing non-empty string field {$key}");
        }
    }
    if (!in_array($row['level'], ['30', '60'], true)) {
        fail_row($index, "invalid level {$row['level']}");
    }
    if (preg_match($kanaRegex, $row['name']) || preg_match($kanaRegex, $row['ing'])) {
        fail_row($index, "Japanese kana remains in {$row['name']} / {$row['ing']}");
    }

    $hasAbsol = $hasAbsol || $row['name'] === '阿勃梭魯';
    $hasApple = $hasApple || $row['ing'] === '特選蘋果';
    $hasPumpkabooPattern = $hasPumpkabooPattern || str_contains($row['name'], '南瓜怪人（');
}

if (!$hasAbsol || !$hasApple) {
    fwrite(STDERR, "[ERROR] Expected known translated samples 阿勃梭魯 and 特選蘋果.\n");
    exit(1);
}

if (!$hasPumpkabooPattern) {
    fwrite(STDERR, "[WARN] 南瓜怪人型態樣本不存在；若資料來源已無此寶可夢可忽略。\n");
}

echo "Validated " . count($json) . " rows in {$path}\n";

function fail_row(int $index, string $reason): void
{
    fwrite(STDERR, "[ERROR] Invalid row #{$index}: {$reason}\n");
    exit(1);
}
