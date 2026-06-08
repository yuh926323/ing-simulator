<?php
declare(strict_types=1);

const OUTPUT_LEVELS = [30, 60];
const JAPANESE_KANA_REGEX = '/[\x{3040}-\x{30ff}]/u';

main();

function main(): void
{
    $opts = parse_options();
    if (isset($opts['help'])) {
        print_usage();
        exit(0);
    }

    $assetDir = option_string($opts, 'asset-dir');
    $outPath = option_string($opts, 'out');
    if ($assetDir === null || $outPath === null) {
        print_usage();
        fail('Missing required --asset-dir or --out.');
    }

    $translations = load_translations($assetDir);
    $warnings = [];

    if (isset($opts['inspect'])) {
        inspect_sources($opts);
        return;
    }

    if (isset($opts['source-json'])) {
        $rows = build_rows_from_source_json(option_string($opts, 'source-json'), $translations, $warnings);
    } else {
        $sqlitePath = option_string($opts, 'sqlite-db');
        if ($sqlitePath === null) {
            print_usage();
            fail('Missing --sqlite-db. Use --source-json only for bootstrap translation of an existing pokemon_data.json.');
        }

        $sqlitePath = resolve_sqlite_path($sqlitePath, option_string($opts, 'wsl-distro'));
        $sqlite = connect_sqlite($sqlitePath);
        $records = load_records_from_sqlite($sqlite, $warnings);

        $overlay = load_mysql_overlay($opts, $warnings);
        if ($overlay !== []) {
            apply_record_overlay($records, $overlay);
        }

        $rows = build_output_rows($records, $translations, $warnings);
    }

    validate_output_rows($rows);

    if (!isset($opts['dry-run'])) {
        write_json_file($outPath, $rows);
    }

    foreach ($warnings as $warning) {
        fwrite(STDERR, "[WARN] {$warning}\n");
    }

    $verb = isset($opts['dry-run']) ? 'Validated' : 'Wrote';
    echo "{$verb} " . count($rows) . " rows to {$outPath}\n";
    echo "Translation files:\n";
    foreach ($translations['files'] as $label => $path) {
        echo "  {$label}: {$path}\n";
    }
}

function parse_options(): array
{
    $opts = getopt('', [
        'asset-dir:',
        'sqlite-db:',
        'wsl-distro:',
        'mysql-host:',
        'mysql-port:',
        'mysql-db:',
        'mysql-table:',
        'mysql-user:',
        'mysql-password:',
        'require-mysql',
        'skip-mysql',
        'source-json:',
        'out:',
        'inspect',
        'dry-run',
        'help',
    ]);

    return $opts === false ? [] : $opts;
}

function print_usage(): void
{
    echo <<<TEXT
Usage:
  php scripts/generate_pokemon_data.php --asset-dir <Asset_Bundles> --sqlite-db <masterdata_server.db> --out data/pokemon_data.json

Windows / PowerShell example:
  \$env:PSS_MYSQL_USER = "your_user"
  \$env:PSS_MYSQL_PASSWORD = "your_password"
  php .\\scripts\\generate_pokemon_data.php `
    --asset-dir "C:\\cygwin64\\home\\Chester.Yang\\work\\pokemon-sleep-assets\\grouped\\ExportedProject\\Assets\\Asset_Bundles" `
    --sqlite-db "\\\\wsl.localhost\\Ubuntu\\home\\chester_yang\\works\\pokemon-sleep-longlong\\database\\masterdata_server.db" `
    --mysql-host "127.0.0.1" `
    --mysql-port "3306" `
    --mysql-db "stable" `
    --mysql-table "pickup_status_extra_data_list" `
    --out ".\\data\\pokemon_data.json"

Useful modes:
  --inspect       Print SQLite/MySQL table schemas without writing output.
  --dry-run       Validate generation without writing --out.
  --source-json   Bootstrap mode: translate an existing pokemon_data.json when DBs are unavailable.

TEXT;
}

function fail(string $message): never
{
    fwrite(STDERR, "[ERROR] {$message}\n");
    exit(1);
}

function option_string(array $opts, string $key): ?string
{
    if (!array_key_exists($key, $opts)) {
        return null;
    }
    $value = $opts[$key];
    if (is_array($value)) {
        $value = end($value);
    }
    if ($value === false || $value === null) {
        return null;
    }
    return trim((string)$value);
}

function load_translations(string $assetDir): array
{
    if (!is_dir($assetDir)) {
        fail("Asset directory does not exist: {$assetDir}");
    }

    $files = [
        'pokemon-ja' => find_bytes_json($assetDir, 'MD_pokemons.bytes.json', ['アブソル']),
        'pokemon-zh' => find_bytes_json($assetDir, 'MD_pokemons.bytes.json', ['妙蛙種子', '阿勃梭魯']),
        'ingredient-ja' => find_bytes_json($assetDir, 'MD_cooking_foods.bytes.json', ['とくせんリンゴ']),
        'ingredient-zh' => find_bytes_json($assetDir, 'MD_cooking_foods.bytes.json', ['特選蘋果']),
        'pattern-ja' => find_bytes_json($assetDir, 'MD_pokemon_pattern_name.bytes.json', ['ギガだましゅ']),
        'pattern-zh' => find_bytes_json($assetDir, 'MD_pokemon_pattern_name.bytes.json', ['巨顆種']),
    ];

    $pokemonJa = load_string_table_by_id($files['pokemon-ja'], 'md_pokemons_name_');
    $pokemonZh = load_string_table_by_id($files['pokemon-zh'], 'md_pokemons_name_');
    $ingredientJa = load_string_table_by_id($files['ingredient-ja'], 'md_cooking_ingredient_name_');
    $ingredientZh = load_string_table_by_id($files['ingredient-zh'], 'md_cooking_ingredient_name_');
    $patternJa = load_string_table_by_id($files['pattern-ja'], 'md_pokemon_pattern_name_');
    $patternZh = load_string_table_by_id($files['pattern-zh'], 'md_pokemon_pattern_name_');

    return [
        'files' => $files,
        'pokemonById' => $pokemonZh,
        'pokemonJaToZh' => align_translation_map($pokemonJa, $pokemonZh),
        'ingredientById' => $ingredientZh,
        'ingredientJaToZh' => align_translation_map($ingredientJa, $ingredientZh),
        'patternById' => $patternZh,
        'patternJaToZh' => align_translation_map($patternJa, $patternZh),
    ];
}

function find_bytes_json(string $assetDir, string $basename, array $sentinels): string
{
    $matches = [];
    $pathsByBasename = asset_file_index($assetDir);

    foreach (($pathsByBasename[$basename] ?? []) as $path) {
        $contents = file_get_contents($path);
        if ($contents === false) {
            continue;
        }
        foreach ($sentinels as $sentinel) {
            if (str_contains($contents, $sentinel)) {
                $matches[] = $path;
                break;
            }
        }
    }

    sort($matches, SORT_STRING);
    if ($matches === []) {
        fail("Could not find {$basename} containing one of: " . implode(', ', $sentinels));
    }
    return $matches[0];
}

function asset_file_index(string $assetDir): array
{
    static $cache = [];
    if (isset($cache[$assetDir])) {
        return $cache[$assetDir];
    }

    $wanted = [
        'MD_pokemons.bytes.json' => true,
        'MD_cooking_foods.bytes.json' => true,
        'MD_pokemon_pattern_name.bytes.json' => true,
    ];
    $index = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($assetDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $basename = $file->getBasename();
        if (!isset($wanted[$basename])) {
            continue;
        }
        $index[$basename][] = $file->getPathname();
    }

    foreach ($index as &$paths) {
        sort($paths, SORT_STRING);
    }
    unset($paths);

    $cache[$assetDir] = $index;
    return $index;
}

function load_string_table_by_id(string $path, string $prefix): array
{
    $json = decode_json_file($path);
    if (!isset($json['strings']) || !is_array($json['strings'])) {
        fail("Missing strings object in {$path}");
    }

    $result = [];
    foreach ($json['strings'] as $key => $value) {
        if (!str_starts_with((string)$key, $prefix)) {
            continue;
        }
        $id = (int)substr((string)$key, strlen($prefix));
        $text = text_value($value);
        if ($id > 0 && $text !== '') {
            $result[$id] = $text;
        }
    }

    if ($result === []) {
        fail("No strings found for prefix {$prefix} in {$path}");
    }
    ksort($result, SORT_NUMERIC);
    return $result;
}

function text_value($value): string
{
    if (is_string($value)) {
        return trim($value);
    }
    if (is_numeric($value)) {
        return trim((string)$value);
    }
    if (is_array($value)) {
        foreach ($value as $nested) {
            $text = text_value($nested);
            if ($text !== '') {
                return $text;
            }
        }
    }
    return '';
}

function align_translation_map(array $jaById, array $zhById): array
{
    $map = [];
    foreach ($jaById as $id => $ja) {
        if (isset($zhById[$id])) {
            $map[$ja] = $zhById[$id];
        }
    }
    return $map;
}

function resolve_sqlite_path(string $path, ?string $wslDistro): string
{
    $path = trim($path, "\"'");
    if (is_file($path)) {
        return $path;
    }

    if (str_starts_with($path, '/home/')) {
        $relative = str_replace('/', '\\', ltrim($path, '/'));
        $distros = [];
        if ($wslDistro !== null && $wslDistro !== '') {
            $distros[] = $wslDistro;
        } else {
            foreach (['\\\\wsl.localhost', '\\\\wsl$'] as $root) {
                if (is_dir($root)) {
                    $entries = scandir($root);
                    if ($entries !== false) {
                        foreach ($entries as $entry) {
                            if ($entry !== '.' && $entry !== '..') {
                                $distros[] = $entry;
                            }
                        }
                    }
                }
            }
        }

        foreach (array_unique($distros) as $distro) {
            foreach (['\\\\wsl.localhost', '\\\\wsl$'] as $root) {
                $candidate = "{$root}\\{$distro}\\{$relative}";
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }
    }

    if (str_contains($path, '<WSL_DISTRO>')) {
        fail('Replace <WSL_DISTRO> with your actual WSL distro name, for example Ubuntu.');
    }

    fail(
        "SQLite DB not found: {$path}\n" .
        "Use a Windows UNC path such as \\\\wsl.localhost\\Ubuntu\\home\\chester_yang\\works\\pokemon-sleep-longlong\\database\\masterdata_server.db " .
        "or pass --wsl-distro Ubuntu with the Linux /home/... path."
    );
}

function connect_sqlite(string $path): PDO
{
    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (Throwable $error) {
        fail("Could not open SQLite DB {$path}: " . $error->getMessage());
    }
}

function inspect_sources(array $opts): void
{
    $warnings = [];
    $sqlitePath = option_string($opts, 'sqlite-db');
    if ($sqlitePath !== null) {
        $sqlite = connect_sqlite(resolve_sqlite_path($sqlitePath, option_string($opts, 'wsl-distro')));
        echo "SQLite schema:\n";
        foreach (sqlite_schema($sqlite) as $table => $columns) {
            echo "  {$table}: " . implode(', ', array_keys($columns)) . "\n";
        }
    }

    $mysql = connect_mysql_if_configured($opts, $warnings);
    if ($mysql !== null) {
        $table = option_string($opts, 'mysql-table') ?? 'pickup_status_extra_data_list';
        echo "MySQL schema ({$table}):\n";
        foreach (mysql_table_columns($mysql, $table) as $column => $type) {
            echo "  {$column}: {$type}\n";
        }
    }

    foreach ($warnings as $warning) {
        fwrite(STDERR, "[WARN] {$warning}\n");
    }
}

function load_records_from_sqlite(PDO $pdo, array &$warnings): array
{
    $records = try_load_records_from_global_master_json($pdo, $warnings);
    if ($records !== []) {
        return $records;
    }

    $records = try_load_records_from_json_columns($pdo, $warnings);
    if ($records !== []) {
        return $records;
    }

    $records = try_load_records_from_normalized_tables($pdo, $warnings);
    if ($records !== []) {
        return $records;
    }

    fail(
        "Could not extract Pokemon records from SQLite. Run with --inspect and check that the DB contains " .
        "pokemon id, help frequency, Lv.30/Lv.60 ingredients, quantities, and ingredient rate columns."
    );
}

function sqlite_schema(PDO $pdo): array
{
    $schema = [];
    $tables = $pdo
        ->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $stmt = $pdo->query('PRAGMA table_info(' . quote_sqlite_identifier((string)$table) . ')');
        $columns = [];
        foreach ($stmt->fetchAll() as $row) {
            $columns[(string)$row['name']] = (string)$row['type'];
        }
        $schema[(string)$table] = $columns;
    }
    return $schema;
}

function quote_sqlite_identifier(string $name): string
{
    return '"' . str_replace('"', '""', $name) . '"';
}

function quote_mysql_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function try_load_records_from_global_master_json(PDO $pdo, array &$warnings): array
{
    $schema = sqlite_schema($pdo);
    foreach ($schema as $table => $columns) {
        foreach ($columns as $column => $type) {
            if (!is_textish_column($type)) {
                continue;
            }
            $sql = 'SELECT ' . quote_sqlite_identifier($column) .
                ' AS payload FROM ' . quote_sqlite_identifier($table) .
                ' WHERE ' . quote_sqlite_identifier($column) . " LIKE '%pokedexMap%' LIMIT 10";
            foreach ($pdo->query($sql)->fetchAll() as $row) {
                $json = decode_json_string_or_null((string)$row['payload']);
                if ($json === null) {
                    continue;
                }
                $records = records_from_global_master($json);
                if ($records !== []) {
                    $warnings[] = "Loaded data from JSON global master in SQLite table {$table}.{$column}.";
                    return $records;
                }
            }
        }
    }
    return [];
}

function try_load_records_from_json_columns(PDO $pdo, array &$warnings): array
{
    $schema = sqlite_schema($pdo);
    $merged = [];

    foreach ($schema as $table => $columns) {
        foreach ($columns as $column => $type) {
            if (!is_textish_column($type) || !preg_match('/pokemon|pokedex|ingredient|pickup|status|data|json/i', $column . ' ' . $table)) {
                continue;
            }
            $sql = 'SELECT ' . quote_sqlite_identifier($column) .
                ' AS payload FROM ' . quote_sqlite_identifier($table) . ' LIMIT 5000';
            foreach ($pdo->query($sql)->fetchAll() as $row) {
                $json = decode_json_string_or_null((string)$row['payload']);
                if ($json === null) {
                    continue;
                }
                foreach (records_from_any_json($json) as $record) {
                    merge_record($merged, $record);
                }
            }
        }
    }

    if ($merged !== []) {
        $warnings[] = 'Loaded data from JSON-like SQLite columns.';
    }
    return $merged;
}

function try_load_records_from_normalized_tables(PDO $pdo, array &$warnings): array
{
    $schema = sqlite_schema($pdo);
    $pokemonTable = find_table_with_columns($schema, [
        'pokemonId' => ['pokemon_id', 'pokemonid', 'pokedex_id', 'pokedexid', 'character_id', 'characterid', 'id'],
        'helpSpeed' => ['help_frequency_base_sec', 'helpfrequencybasesec', 'help_frequency', 'helpfrequency', 'frequency', 'help_speed', 'helpspeed'],
    ]);

    if ($pokemonTable === null) {
        return [];
    }

    [$table, $columns] = $pokemonTable;
    $idCol = find_column($columns, ['pokemon_id', 'pokemonid', 'pokedex_id', 'pokedexid', 'character_id', 'characterid', 'id']);
    $helpCol = find_column($columns, ['help_frequency_base_sec', 'helpfrequencybasesec', 'help_frequency', 'helpfrequency', 'frequency', 'help_speed', 'helpspeed']);
    $patternCol = find_column($columns, ['pokemon_pattern_id', 'pokemonpatternid', 'pattern_id', 'patternid', 'form_id', 'formid']);
    $nameCol = find_column($columns, ['name', 'pokemon_name', 'pokemonname', 'label']);

    $rows = $pdo->query('SELECT * FROM ' . quote_sqlite_identifier($table))->fetchAll();
    $records = [];
    foreach ($rows as $row) {
        $pokemonId = int_or_null($row[$idCol] ?? null);
        $helpSpeed = number_or_null($row[$helpCol] ?? null);
        if ($pokemonId === null || $helpSpeed === null) {
            continue;
        }
        merge_record($records, [
            'pokemonId' => $pokemonId,
            'patternId' => $patternCol ? int_or_null($row[$patternCol] ?? null) : null,
            'nameJa' => $nameCol ? string_or_null($row[$nameCol] ?? null) : null,
            'helpSpeed' => $helpSpeed,
            'ingredients' => extract_ingredients_from_row($row),
            'ingredientSplit' => extract_rate_from_row($row),
        ]);
    }

    merge_ingredient_tables($pdo, $schema, $records);
    merge_rate_tables($pdo, $schema, $records);

    if ($records !== []) {
        $warnings[] = "Loaded normalized SQLite data using pokemon table {$table}.";
    }
    return $records;
}

function find_table_with_columns(array $schema, array $groups): ?array
{
    $best = null;
    $bestScore = -1;
    foreach ($schema as $table => $columns) {
        $score = 0;
        foreach ($groups as $candidates) {
            if (find_column($columns, $candidates) !== null) {
                $score++;
            }
        }
        if ($score > $bestScore) {
            $best = [$table, $columns];
            $bestScore = $score;
        }
    }
    return $bestScore === count($groups) ? $best : null;
}

function find_column(array $columns, array $candidates): ?string
{
    $normalizedColumns = [];
    foreach (array_keys($columns) as $column) {
        $normalizedColumns[normalize_name($column)] = $column;
    }

    foreach ($candidates as $candidate) {
        $normalized = normalize_name($candidate);
        if (isset($normalizedColumns[$normalized])) {
            return $normalizedColumns[$normalized];
        }
    }

    foreach (array_keys($columns) as $column) {
        $normalized = normalize_name($column);
        foreach ($candidates as $candidate) {
            $candidate = normalize_name($candidate);
            if (str_contains($normalized, $candidate) || str_contains($candidate, $normalized)) {
                return $column;
            }
        }
    }
    return null;
}

function normalize_name(string $name): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($name)) ?? '';
}

function merge_ingredient_tables(PDO $pdo, array $schema, array &$records): void
{
    foreach ($schema as $table => $columns) {
        if (!preg_match('/ingredient|food|cooking/i', $table)) {
            continue;
        }
        $pokemonCol = find_column($columns, ['pokemon_id', 'pokemonid', 'pokedex_id', 'pokedexid', 'character_id', 'characterid']);
        $levelCol = find_column($columns, ['level', 'unlock_level', 'unlocklevel', 'ingredient_level', 'ingredientlevel']);
        $ingredientCol = find_column($columns, ['ingredient_id', 'ingredientid', 'food_id', 'foodid', 'item_id', 'itemid', 'cooking_food_id', 'cookingfoodid']);
        $qtyCol = find_column($columns, ['qty', 'quantity', 'amount', 'count', 'num', 'ingredient_num', 'ingredientnum']);

        if (!$pokemonCol || !$levelCol || !$ingredientCol || !$qtyCol) {
            continue;
        }

        $rows = $pdo->query('SELECT * FROM ' . quote_sqlite_identifier($table))->fetchAll();
        foreach ($rows as $row) {
            $pokemonId = int_or_null($row[$pokemonCol] ?? null);
            $level = int_or_null($row[$levelCol] ?? null);
            $ingredientId = int_or_null($row[$ingredientCol] ?? null);
            $qty = int_or_null($row[$qtyCol] ?? null);
            if ($pokemonId === null || !in_array($level, OUTPUT_LEVELS, true) || $ingredientId === null || $qty === null || $qty <= 0) {
                continue;
            }
            merge_record($records, [
                'pokemonId' => $pokemonId,
                'ingredients' => [
                    $level => [[
                        'ingredientId' => $ingredientId,
                        'amount' => $qty,
                    ]],
                ],
            ]);
        }
    }
}

function merge_rate_tables(PDO $pdo, array $schema, array &$records): void
{
    foreach ($schema as $table => $columns) {
        $pokemonCol = find_column($columns, ['pokemon_id', 'pokemonid', 'pokedex_id', 'pokedexid', 'character_id', 'characterid']);
        $rateCol = find_column($columns, [
            'ingredient_split',
            'ingredientsplit',
            'ingredient_rate',
            'ingredientrate',
            'ingredient_percent',
            'ingredientpercent',
            'ing_percent',
            'ingpercent',
            'food_rate',
            'foodrate',
            'pickup_ingredient_rate',
            'pickuprate',
        ]);
        if (!$pokemonCol || !$rateCol) {
            continue;
        }
        $rows = $pdo->query('SELECT * FROM ' . quote_sqlite_identifier($table))->fetchAll();
        foreach ($rows as $row) {
            $pokemonId = int_or_null($row[$pokemonCol] ?? null);
            $split = rate_to_split(number_or_null($row[$rateCol] ?? null));
            if ($pokemonId !== null && $split !== null) {
                merge_record($records, [
                    'pokemonId' => $pokemonId,
                    'ingredientSplit' => $split,
                ]);
            }
        }
    }
}

function connect_mysql_if_configured(array $opts, array &$warnings): ?PDO
{
    if (isset($opts['skip-mysql'])) {
        return null;
    }

    $host = option_string($opts, 'mysql-host');
    $user = option_string($opts, 'mysql-user') ?? getenv('PSS_MYSQL_USER') ?: null;
    $password = option_string($opts, 'mysql-password') ?? getenv('PSS_MYSQL_PASSWORD') ?: null;
    $db = option_string($opts, 'mysql-db') ?? 'stable';
    $port = option_string($opts, 'mysql-port') ?? '3306';

    if ($host === null && $user === null && $password === null && !isset($opts['require-mysql'])) {
        return null;
    }

    $host = $host ?? '127.0.0.1';
    if ($user === null || $password === null) {
        $message = 'MySQL credentials are missing. Set PSS_MYSQL_USER/PSS_MYSQL_PASSWORD or pass --mysql-user/--mysql-password.';
        if (isset($opts['require-mysql'])) {
            fail($message);
        }
        $warnings[] = $message . ' Skipping MySQL overlay.';
        return null;
    }

    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (Throwable $error) {
        $message = "Could not connect to MySQL {$host}:{$port}/{$db}: " . $error->getMessage();
        if (isset($opts['require-mysql'])) {
            fail($message);
        }
        $warnings[] = $message . ' Skipping MySQL overlay.';
        return null;
    }
}

function load_mysql_overlay(array $opts, array &$warnings): array
{
    $pdo = connect_mysql_if_configured($opts, $warnings);
    if ($pdo === null) {
        return [];
    }

    $table = option_string($opts, 'mysql-table') ?? 'pickup_status_extra_data_list';
    try {
        $columns = mysql_table_columns($pdo, $table);
        $rows = $pdo->query('SELECT * FROM ' . quote_mysql_identifier($table))->fetchAll();
    } catch (Throwable $error) {
        $message = "Could not read MySQL table {$table}: " . $error->getMessage();
        if (isset($opts['require-mysql'])) {
            fail($message);
        }
        $warnings[] = $message . ' Skipping MySQL overlay.';
        return [];
    }

    $overlay = [];
    foreach ($rows as $row) {
        foreach (records_from_row_or_json($row) as $record) {
            merge_record($overlay, $record);
        }
    }

    if ($overlay !== []) {
        $warnings[] = "Loaded MySQL overlay from {$table} (" . count($overlay) . ' pokemon records).';
    } elseif (isset($opts['require-mysql'])) {
        fail("MySQL table {$table} did not contain recognizable pickup status data.");
    } else {
        $warnings[] = "MySQL table {$table} did not contain recognizable pickup status data.";
    }

    return $overlay;
}

function mysql_table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query('SHOW COLUMNS FROM ' . quote_mysql_identifier($table));
    $columns = [];
    foreach ($stmt->fetchAll() as $row) {
        $columns[(string)$row['Field']] = (string)$row['Type'];
    }
    return $columns;
}

function records_from_row_or_json(array $row): array
{
    $records = [];
    $direct = record_from_object($row);
    if ($direct !== null) {
        $records[] = $direct;
    }

    foreach ($row as $value) {
        if (!is_string($value) || strlen($value) < 2) {
            continue;
        }
        $json = decode_json_string_or_null($value);
        if ($json !== null) {
            foreach (records_from_any_json($json) as $record) {
                $records[] = $record;
            }
        }
    }
    return $records;
}

function is_textish_column(string $type): bool
{
    $type = strtolower($type);
    return $type === '' || str_contains($type, 'text') || str_contains($type, 'char') || str_contains($type, 'json') || str_contains($type, 'clob');
}

function records_from_global_master(array $json): array
{
    if (!isset($json['pokedexMap']) || !is_array($json['pokedexMap'])) {
        return [];
    }

    $paramsMap = is_array($json['pokemonProducingParamsMap'] ?? null) ? $json['pokemonProducingParamsMap'] : [];
    $records = [];

    foreach ($json['pokedexMap'] as $id => $pokemon) {
        if (!is_array($pokemon)) {
            continue;
        }
        $pokemonId = int_or_null($pokemon['id'] ?? $pokemon['pokemonId'] ?? $id);
        if ($pokemonId === null) {
            continue;
        }

        $server = is_array($pokemon['serverSideData'] ?? null) ? $pokemon['serverSideData'] : $pokemon;
        $params = is_array($paramsMap[(string)$pokemonId] ?? null) ? $paramsMap[(string)$pokemonId] : ($paramsMap[$pokemonId] ?? []);

        merge_record($records, [
            'pokemonId' => $pokemonId,
            'patternId' => int_or_null($pokemon['patternId'] ?? $pokemon['pokemonPatternId'] ?? $server['patternId'] ?? null),
            'nameJa' => string_or_null($pokemon['name'] ?? $pokemon['nameJa'] ?? $server['name'] ?? null),
            'helpSpeed' => number_or_null($server['helpFrequencyBaseSec'] ?? $server['help_frequency_base_sec'] ?? null),
            'ingredients' => extract_ingredients_from_any($server['ingredients'] ?? $server),
            'ingredientSplit' => rate_to_split(number_or_null($params['ingredientSplit'] ?? $params['ingredient_split'] ?? $server['ingredientSplit'] ?? $server['ingredient_split'] ?? null)),
        ]);
    }

    return $records;
}

function records_from_any_json($json): array
{
    $fromGlobalMaster = is_array($json) ? records_from_global_master($json) : [];
    if ($fromGlobalMaster !== []) {
        return $fromGlobalMaster;
    }

    $records = [];
    if (is_array($json)) {
        $record = record_from_object($json);
        if ($record !== null) {
            $records[] = $record;
        }
        foreach ($json as $value) {
            if (is_array($value)) {
                foreach (records_from_any_json($value) as $nested) {
                    $records[] = $nested;
                }
            }
        }
    }
    return $records;
}

function record_from_object(array $object): ?array
{
    $pokemonId = pick_int($object, ['pokemonId', 'pokemon_id', 'pokedexId', 'pokedex_id', 'characterId', 'character_id', 'id']);
    if ($pokemonId === null) {
        return null;
    }

    $server = is_array($object['serverSideData'] ?? null) ? $object['serverSideData'] : $object;
    $record = [
        'pokemonId' => $pokemonId,
        'patternId' => pick_int($object, ['patternId', 'pattern_id', 'pokemonPatternId', 'pokemon_pattern_id', 'formId', 'form_id']),
        'nameJa' => pick_string($object, ['nameJa', 'name_ja', 'jaName', 'ja_name', 'name', 'pokemonName', 'pokemon_name']),
        'helpSpeed' => pick_number($server, ['helpFrequencyBaseSec', 'help_frequency_base_sec', 'helpFrequency', 'help_frequency', 'helpSpeed', 'help_speed', 'frequency']),
        'ingredients' => extract_ingredients_from_any($server['ingredients'] ?? $object['ingredients'] ?? $object),
        'ingredientSplit' => rate_to_split(pick_number($object, [
            'ingredientSplit',
            'ingredient_split',
            'ingredientRate',
            'ingredient_rate',
            'ingredientPercent',
            'ingredient_percent',
            'ingPercent',
            'ing_percent',
            'foodRate',
            'food_rate',
        ])),
    ];

    if ($record['helpSpeed'] === null && $record['ingredients'] === [] && $record['ingredientSplit'] === null) {
        return null;
    }
    return $record;
}

function extract_ingredients_from_row(array $row): array
{
    $fromJson = [];
    foreach ($row as $column => $value) {
        if (is_string($value) && preg_match('/ingredient|food|cooking/i', (string)$column)) {
            $json = decode_json_string_or_null($value);
            if ($json !== null) {
                $fromJson = merge_ingredients($fromJson, extract_ingredients_from_any($json));
            }
        }
    }
    return $fromJson;
}

function extract_rate_from_row(array $row): ?float
{
    return rate_to_split(pick_number($row, [
        'ingredientSplit',
        'ingredient_split',
        'ingredientRate',
        'ingredient_rate',
        'ingredientPercent',
        'ingredient_percent',
        'ingPercent',
        'ing_percent',
        'foodRate',
        'food_rate',
    ]));
}

function extract_ingredients_from_any($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $result = [];

    foreach (OUTPUT_LEVELS as $level) {
        if (isset($value[(string)$level]) || isset($value[$level])) {
            $levelData = $value[(string)$level] ?? $value[$level];
            $result = merge_ingredients($result, [$level => ingredient_list_from_level_data($levelData)]);
        }
    }

    if ($result !== []) {
        return $result;
    }

    if (is_list_array($value)) {
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $level = pick_int($item, ['level', 'unlockLevel', 'unlock_level', 'ingredientLevel', 'ingredient_level']);
            $ingredientId = pick_int($item, ['ingredientId', 'ingredient_id', 'foodId', 'food_id', 'itemId', 'item_id', 'id']);
            $amount = pick_int($item, ['qty', 'quantity', 'amount', 'count', 'num']);
            if (in_array($level, OUTPUT_LEVELS, true) && $ingredientId !== null && $amount !== null && $amount > 0) {
                $result[$level][] = [
                    'ingredientId' => $ingredientId,
                    'amount' => $amount,
                ];
            }
        }
        return normalize_ingredients($result);
    }

    foreach (['ingredients', 'ingredient', 'ingredientData', 'ingredient_data', 'foods', 'food'] as $key) {
        if (isset($value[$key]) && is_array($value[$key])) {
            $result = merge_ingredients($result, extract_ingredients_from_any($value[$key]));
        }
    }

    return normalize_ingredients($result);
}

function ingredient_list_from_level_data($levelData): array
{
    $items = [];
    if (!is_array($levelData)) {
        return $items;
    }

    if (is_list_array($levelData)) {
        foreach ($levelData as $item) {
            if (!is_array($item)) {
                continue;
            }
            $ingredientId = pick_int($item, ['ingredientId', 'ingredient_id', 'foodId', 'food_id', 'itemId', 'item_id', 'id']);
            $amount = pick_int($item, ['qty', 'quantity', 'amount', 'count', 'num']);
            if ($ingredientId !== null && $amount !== null && $amount > 0) {
                $items[] = [
                    'ingredientId' => $ingredientId,
                    'amount' => $amount,
                ];
            }
        }
        return $items;
    }

    foreach ($levelData as $ingredientId => $amount) {
        $id = int_or_null($ingredientId);
        $qty = int_or_null($amount);
        if ($id !== null && $qty !== null && $qty > 0) {
            $items[] = [
                'ingredientId' => $id,
                'amount' => $qty,
            ];
        }
    }

    return $items;
}

function is_list_array(array $array): bool
{
    if ($array === []) {
        return true;
    }
    return array_keys($array) === range(0, count($array) - 1);
}

function merge_record(array &$records, array $record): void
{
    $pokemonId = int_or_null($record['pokemonId'] ?? null);
    if ($pokemonId === null) {
        return;
    }
    $key = (string)$pokemonId;
    if (!isset($records[$key])) {
        $records[$key] = [
            'pokemonId' => $pokemonId,
            'patternId' => null,
            'nameJa' => null,
            'helpSpeed' => null,
            'ingredients' => [],
            'ingredientSplit' => null,
        ];
    }

    foreach (['patternId', 'nameJa', 'helpSpeed', 'ingredientSplit'] as $field) {
        if (array_key_exists($field, $record) && $record[$field] !== null && $record[$field] !== '') {
            $records[$key][$field] = $record[$field];
        }
    }
    if (isset($record['ingredients']) && is_array($record['ingredients'])) {
        $records[$key]['ingredients'] = merge_ingredients($records[$key]['ingredients'], $record['ingredients']);
    }
}

function merge_ingredients(array $base, array $overlay): array
{
    foreach (OUTPUT_LEVELS as $level) {
        if (!isset($overlay[$level]) || !is_array($overlay[$level])) {
            continue;
        }
        if (!isset($base[$level])) {
            $base[$level] = [];
        }
        $seen = [];
        foreach ($base[$level] as $item) {
            if (isset($item['ingredientId'])) {
                $seen[(string)$item['ingredientId']] = true;
            }
        }
        foreach ($overlay[$level] as $item) {
            $ingredientId = int_or_null($item['ingredientId'] ?? null);
            $amount = int_or_null($item['amount'] ?? null);
            if ($ingredientId === null || $amount === null || $amount <= 0) {
                continue;
            }
            if (isset($seen[(string)$ingredientId])) {
                foreach ($base[$level] as &$existing) {
                    if ((int)$existing['ingredientId'] === $ingredientId) {
                        $existing['amount'] = $amount;
                    }
                }
                unset($existing);
            } else {
                $base[$level][] = [
                    'ingredientId' => $ingredientId,
                    'amount' => $amount,
                ];
            }
        }
    }
    return normalize_ingredients($base);
}

function normalize_ingredients(array $ingredients): array
{
    foreach (OUTPUT_LEVELS as $level) {
        if (!isset($ingredients[$level]) || !is_array($ingredients[$level])) {
            continue;
        }
        usort($ingredients[$level], static fn(array $a, array $b): int => ($a['ingredientId'] ?? 0) <=> ($b['ingredientId'] ?? 0));
    }
    return $ingredients;
}

function apply_record_overlay(array &$records, array $overlay): void
{
    foreach ($overlay as $record) {
        merge_record($records, $record);
    }
}

function build_output_rows(array $records, array $translations, array &$warnings): array
{
    uasort($records, static fn(array $a, array $b): int => (int)$a['pokemonId'] <=> (int)$b['pokemonId']);
    $rows = [];
    $missing = [];

    foreach ($records as $record) {
        $pokemonId = int_or_null($record['pokemonId'] ?? null);
        $helpSpeed = number_or_null($record['helpSpeed'] ?? null);
        $split = rate_to_split(number_or_null($record['ingredientSplit'] ?? null));
        if ($pokemonId === null || $helpSpeed === null || $split === null) {
            continue;
        }

        $name = translate_pokemon_record($record, $translations, $missing);
        foreach (OUTPUT_LEVELS as $level) {
            foreach (($record['ingredients'][$level] ?? []) as $ingredient) {
                $ingredientId = int_or_null($ingredient['ingredientId'] ?? null);
                $amount = int_or_null($ingredient['amount'] ?? null);
                if ($ingredientId === null || $amount === null || $amount <= 0) {
                    continue;
                }
                $ingName = $translations['ingredientById'][$ingredientId] ?? null;
                if ($ingName === null) {
                    $missing[] = "ingredient id {$ingredientId}";
                    continue;
                }
                $rows[] = [
                    'name' => $name,
                    'ing' => $ingName,
                    'amount' => (string)$amount,
                    'level' => (string)$level,
                    'help_speed' => format_number_string($helpSpeed, 0),
                    'ing_percent' => format_percent($split),
                ];
            }
        }
    }

    if ($missing !== []) {
        $unique = array_values(array_unique($missing));
        fail('Missing translations: ' . implode(', ', array_slice($unique, 0, 50)) . (count($unique) > 50 ? ' ...' : ''));
    }
    if ($rows === []) {
        fail('No output rows were generated. Check DB schema with --inspect.');
    }

    return $rows;
}

function build_rows_from_source_json(?string $sourcePath, array $translations, array &$warnings): array
{
    if ($sourcePath === null || !is_file($sourcePath)) {
        fail("Source JSON not found: {$sourcePath}");
    }
    $sourceRows = decode_json_file($sourcePath);
    if (!is_array($sourceRows)) {
        fail("Source JSON must be an array: {$sourcePath}");
    }

    $rows = [];
    $missing = [];
    foreach ($sourceRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = translate_pokemon_name((string)($row['name'] ?? ''), $translations, $missing);
        $ing = translate_ingredient_name((string)($row['ing'] ?? ''), $translations, $missing);
        $rows[] = [
            'name' => $name,
            'ing' => $ing,
            'amount' => (string)($row['amount'] ?? ''),
            'level' => (string)($row['level'] ?? ''),
            'help_speed' => (string)($row['help_speed'] ?? ''),
            'ing_percent' => (string)($row['ing_percent'] ?? ''),
        ];
    }

    if ($missing !== []) {
        $unique = array_values(array_unique($missing));
        fail('Missing bootstrap translations: ' . implode(', ', $unique));
    }
    $warnings[] = 'Used --source-json bootstrap mode. This translates existing rows only; use --sqlite-db for all Pokemon.';
    return $rows;
}

function translate_pokemon_record(array $record, array $translations, array &$missing): string
{
    $pokemonId = int_or_null($record['pokemonId'] ?? null);
    $name = null;
    if ($pokemonId !== null && isset($translations['pokemonById'][$pokemonId])) {
        $name = $translations['pokemonById'][$pokemonId];
    } elseif (isset($record['nameJa'])) {
        $name = translate_pokemon_name((string)$record['nameJa'], $translations, $missing);
    }

    if ($name === null || $name === '') {
        $missing[] = "pokemon id {$pokemonId}";
        $name = "pokemon#{$pokemonId}";
    }

    $patternId = int_or_null($record['patternId'] ?? null);
    if ($patternId !== null && $patternId > 1 && isset($translations['patternById'][$patternId])) {
        $pattern = $translations['patternById'][$patternId];
        if ($pattern !== '平常' && !str_contains($name, '（')) {
            $name .= "（{$pattern}）";
        }
    }
    return $name;
}

function translate_pokemon_name(string $name, array $translations, array &$missing): string
{
    $name = trim($name);
    if ($name === '') {
        return $name;
    }
    if (isset($translations['pokemonJaToZh'][$name])) {
        return $translations['pokemonJaToZh'][$name];
    }

    if (preg_match('/^(.+)[\(（](.+)[\)）]$/u', $name, $matches)) {
        $base = translate_pokemon_name($matches[1], $translations, $missing);
        $pattern = translate_pattern_name($matches[2], $translations, $missing);
        return "{$base}（{$pattern}）";
    }

    if (preg_match(JAPANESE_KANA_REGEX, $name)) {
        $missing[] = "pokemon {$name}";
    }
    return $name;
}

function translate_ingredient_name(string $name, array $translations, array &$missing): string
{
    $name = trim($name);
    if ($name === '') {
        return $name;
    }
    if (isset($translations['ingredientJaToZh'][$name])) {
        return $translations['ingredientJaToZh'][$name];
    }
    if (preg_match(JAPANESE_KANA_REGEX, $name)) {
        $missing[] = "ingredient {$name}";
    }
    return $name;
}

function translate_pattern_name(string $name, array $translations, array &$missing): string
{
    $name = trim($name);
    $aliases = [
        'こだま' => 'こだましゅ',
        'ちゅうだま' => 'ちゅうだましゅ',
        'おおだま' => 'おおだましゅ',
        'ギガだま' => 'ギガだましゅ',
    ];
    $candidate = $aliases[$name] ?? $name;
    if (isset($translations['patternJaToZh'][$candidate])) {
        return $translations['patternJaToZh'][$candidate];
    }
    if (preg_match(JAPANESE_KANA_REGEX, $name)) {
        $missing[] = "pattern {$name}";
    }
    return $name;
}

function validate_output_rows(array $rows): void
{
    $required = ['name', 'ing', 'amount', 'level', 'help_speed', 'ing_percent'];
    foreach ($rows as $index => $row) {
        foreach ($required as $key) {
            if (!array_key_exists($key, $row) || !is_string($row[$key]) || $row[$key] === '') {
                fail("Invalid row #{$index}: missing string field {$key}");
            }
        }
        if (!in_array($row['level'], ['30', '60'], true)) {
            fail("Invalid row #{$index}: level must be 30 or 60, got {$row['level']}");
        }
        if (preg_match(JAPANESE_KANA_REGEX, $row['name']) || preg_match(JAPANESE_KANA_REGEX, $row['ing'])) {
            fail("Invalid row #{$index}: Japanese kana remains in {$row['name']} / {$row['ing']}");
        }
    }
}

function write_json_file(string $path, array $rows): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fail("Could not create output directory: {$dir}");
    }
    $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        fail('Could not encode output JSON: ' . json_last_error_msg());
    }
    if (file_put_contents($path, $json . PHP_EOL) === false) {
        fail("Could not write output JSON: {$path}");
    }
}

function decode_json_file(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        fail("Could not read {$path}");
    }
    $json = json_decode($contents, true);
    if (!is_array($json)) {
        fail("Could not parse JSON {$path}: " . json_last_error_msg());
    }
    return $json;
}

function decode_json_string_or_null(string $contents)
{
    $contents = trim($contents);
    if ($contents === '' || ($contents[0] !== '{' && $contents[0] !== '[')) {
        return null;
    }
    $json = json_decode($contents, true);
    return is_array($json) ? $json : null;
}

function pick_int(array $row, array $keys): ?int
{
    foreach ($keys as $key) {
        foreach ($row as $actualKey => $value) {
            if (normalize_name((string)$actualKey) === normalize_name($key)) {
                return int_or_null($value);
            }
        }
    }
    return null;
}

function pick_number(array $row, array $keys): ?float
{
    foreach ($keys as $key) {
        foreach ($row as $actualKey => $value) {
            if (normalize_name((string)$actualKey) === normalize_name($key)) {
                return number_or_null($value);
            }
        }
    }
    return null;
}

function pick_string(array $row, array $keys): ?string
{
    foreach ($keys as $key) {
        foreach ($row as $actualKey => $value) {
            if (normalize_name((string)$actualKey) === normalize_name($key)) {
                return string_or_null($value);
            }
        }
    }
    return null;
}

function int_or_null($value): ?int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_float($value)) {
        return (int)$value;
    }
    if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
        return (int)trim($value);
    }
    return null;
}

function number_or_null($value): ?float
{
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }
    if (is_string($value) && is_numeric(trim($value))) {
        return (float)trim($value);
    }
    return null;
}

function string_or_null($value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function rate_to_split(?float $value): ?float
{
    if ($value === null) {
        return null;
    }
    return $value > 1 ? $value / 100 : $value;
}

function format_percent(float $split): string
{
    return number_format($split * 100, 1, '.', '');
}

function format_number_string(float $value, int $decimals): string
{
    if ($decimals === 0) {
        return (string)(int)round($value);
    }
    return number_format($value, $decimals, '.', '');
}
