<?php
declare(strict_types=1);

/*
 * FIX 2026-08-18
 * - Koneksi MySQL auto charset: utf8mb4, fallback utf8 untuk server legacy.
 * - Tampilan dibuat full screen / full width.
 * - Setelah COMMIT sukses, preview otomatis di-refresh.
 * - Preview menampilkan jumlah Data Inventory dan Data Inventorycopy.
 * - KHUSUS transtype 03 (Penjualan): preview dan INSERT hanya transtype 03.
 */

/*
|--------------------------------------------------------------------------
| INVENTORY -> INVENTORYCOPY
|--------------------------------------------------------------------------
| File khusus untuk menyalin data inventory berdasarkan TANGGAL YANG DIPILIH.
|
| Alur:
|   1. Pilih toko
|   2. Pilih tanggal
|   3. Cek Data
|   4. INSERT hanya data inventory pada tanggal tersebut
|      ke inventorycopy
|   5. Data yang sudah ada berdasarkan PRIMARY/UNIQUE KEY dilewati
|   6. Setelah COMMIT sukses, preview otomatis dihitung ulang
|
| File ini TIDAK bergantung pada:
|   - sales
|   - salescopy
|   - salesdetail
|   - salespayments
|   - transid sales
|   - proses delete / restore sales
|
| Membutuhkan:
|   - map.php
|   - tabel inventory
|   - tabel inventorycopy
|--------------------------------------------------------------------------
*/

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', __DIR__ . '/inventorycopy_php_error.log');

register_shutdown_function(function (): void {
    $error = error_get_last();

    if (!$error) {
        return;
    }

    $fatalTypes = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR
    ];

    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    @file_put_contents(
        __DIR__ . '/inventorycopy_php_error.log',
        '[' . date('c') . '] ' .
        $error['message'] . ' di ' .
        $error['file'] . ':' .
        $error['line'] . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
});

if (!extension_loaded('mysqli')) {
    http_response_code(500);
    die('Extension mysqli belum aktif di server.');
}

session_start();
date_default_timezone_set('Asia/Jakarta');

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

mysqli_report(MYSQLI_REPORT_OFF);

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['inventorycopy_csrf'])) {
    try {
        $_SESSION['inventorycopy_csrf'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['inventorycopy_csrf'] = hash(
            'sha256',
            uniqid((string) mt_rand(), true)
        );
    }
}

$csrfToken = (string) $_SESSION['inventorycopy_csrf'];

/*
|--------------------------------------------------------------------------
| Konsep Inventory
|--------------------------------------------------------------------------
| Inventory yang diproses hanya transaksi PENJUALAN, yaitu transtype 03.
| Transtype lain tidak ditampilkan pada preview dan tidak di-INSERT.
|--------------------------------------------------------------------------
*/
const INVENTORY_TRANSTYPE = '03';

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function js($value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?: 'null';
}

function qi(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function valid_date(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed instanceof DateTime
        && $parsed->format('Y-m-d') === $date;
}

function normalize_store_config(array $config): array
{
    $base = $config;

    foreach (['p1', 'primary', 'main'] as $nested) {
        if (isset($config[$nested]) && is_array($config[$nested])) {
            $base = array_merge($config, $config[$nested]);
            break;
        }
    }

    return [
        'host' => trim((string) ($base['ip'] ?? $base['host'] ?? $base['hostname'] ?? '')),
        'port' => (int) ($base['port'] ?? 3306),
        'user' => trim((string) ($base['user'] ?? $base['username'] ?? '')),
        'pass' => (string) ($base['pass'] ?? $base['password'] ?? ''),
        'db'   => trim((string) ($base['db'] ?? $base['database'] ?? $base['dbname'] ?? '')),
    ];
}

function connect_store(array $config): mysqli
{
    $cfg = normalize_store_config($config);

    if ($cfg['host'] === '' || $cfg['user'] === '' || $cfg['db'] === '') {
        throw new RuntimeException(
            'Konfigurasi database toko di map.php belum lengkap.'
        );
    }

    $port = $cfg['port'] > 0 ? $cfg['port'] : 3306;

    if (function_exists('fsockopen')) {
        $socketErrorNumber = 0;
        $socketErrorMessage = '';

        $socket = @fsockopen(
            $cfg['host'],
            $port,
            $socketErrorNumber,
            $socketErrorMessage,
            2.0
        );

        if (!$socket) {
            throw new RuntimeException(
                'TOKO_OFFLINE|Toko sedang offline atau database tidak dapat dijangkau.'
            );
        }

        fclose($socket);
    }

    $db = mysqli_init();

    if (!$db) {
        throw new RuntimeException('Gagal membuat koneksi MySQL.');
    }

    @mysqli_options($db, MYSQLI_OPT_CONNECT_TIMEOUT, 3);

    if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
        @mysqli_options($db, MYSQLI_OPT_READ_TIMEOUT, 90);
    }

    if (!@mysqli_real_connect(
        $db,
        $cfg['host'],
        $cfg['user'],
        $cfg['pass'],
        $cfg['db'],
        $port
    )) {
        $connectCode = (int) $db->connect_errno;

        if (in_array($connectCode, [2002, 2003, 2005, 2006], true)) {
            throw new RuntimeException(
                'TOKO_OFFLINE|Toko sedang offline atau database tidak dapat dijangkau.'
            );
        }

        throw new RuntimeException(
            'Koneksi database ditolak. Periksa konfigurasi database pada map.php.'
        );
    }

    /*
     * AUTO CHARSET COMPATIBILITY
     *
     * Prioritas tetap utf8mb4 untuk server modern.
     * Jika server MySQL/MariaDB lama belum mengenal utf8mb4,
     * otomatis fallback ke utf8 agar proses inventory tetap dapat berjalan.
     *
     * Catatan: mode utf8 legacy tidak mendukung karakter Unicode 4-byte
     * seperti sebagian emoji. Namun proses tidak lagi dibatalkan hanya karena
     * server lama tidak mengenal utf8mb4.
     */
    $activeCharset = '';

    if (@mysqli_set_charset($db, 'utf8mb4')) {
        $activeCharset = 'utf8mb4';

        /*
         * Jangan paksa COLLATE tertentu karena beberapa server MariaDB/MySQL
         * memiliki daftar collation yang berbeda. set_charset() sudah cukup
         * untuk mengatur character_set_client/connection/results.
         */
        @$db->query("SET NAMES utf8mb4");
    } elseif (@mysqli_set_charset($db, 'utf8')) {
        $activeCharset = 'utf8';
        @$db->query("SET NAMES utf8");
    } else {
        $charsetError = $db->error;
        $db->close();

        throw new RuntimeException(
            'Database tidak mendukung charset utf8mb4 maupun utf8. Detail: ' .
            $charsetError
        );
    }

    /*
     * Simpan mode charset pada session MySQL untuk kebutuhan diagnostik.
     * Kegagalan query ini tidak menggagalkan koneksi.
     */
    @ $db->query(
        "SET @inventorycopy_connection_charset = '" .
        $db->real_escape_string($activeCharset) . "'"
    );

    @$db->query('SET SESSION innodb_lock_wait_timeout = 180');
    @$db->query('SET SESSION lock_wait_timeout = 180');

    return $db;
}

function bind_params(mysqli_stmt $stmt, string $types, array &$params): void
{
    $refs = [];
    $refs[] = &$types;

    foreach ($params as &$value) {
        $refs[] = &$value;
    }

    unset($value);

    if (!call_user_func_array([$stmt, 'bind_param'], $refs)) {
        throw new RuntimeException('Gagal memasang parameter query.');
    }
}

function stmt_fetch_all_assoc(mysqli_stmt $stmt): array
{
    $metadata = $stmt->result_metadata();

    if (!$metadata) {
        return [];
    }

    $fields = [];
    $row = [];
    $bindValues = [];

    while ($field = $metadata->fetch_field()) {
        $fieldName = (string) $field->name;
        $fields[] = $fieldName;
        $row[$fieldName] = null;
        $bindValues[] = &$row[$fieldName];
    }

    if ($bindValues) {
        if (!call_user_func_array([$stmt, 'bind_result'], $bindValues)) {
            $metadata->free();
            throw new RuntimeException('Gagal membaca hasil query.');
        }
    }

    $rows = [];

    while ($stmt->fetch()) {
        $current = [];

        foreach ($fields as $fieldName) {
            $current[$fieldName] = $row[$fieldName];
        }

        $rows[] = $current;
    }

    $metadata->free();

    return $rows;
}

function query_one(
    mysqli $db,
    string $sql,
    string $types = '',
    array $params = []
): array {
    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Gagal menyiapkan query: ' . $db->error
        );
    }

    try {
        if ($types !== '') {
            bind_params($stmt, $types, $params);
        }

        if (!$stmt->execute()) {
            throw new RuntimeException(
                'Query gagal: ' . $stmt->error
            );
        }

        $rows = stmt_fetch_all_assoc($stmt);

        return $rows[0] ?? [];
    } finally {
        $stmt->close();
    }
}

function query_all(
    mysqli $db,
    string $sql,
    string $types = '',
    array $params = []
): array {
    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Gagal menyiapkan query: ' . $db->error
        );
    }

    try {
        if ($types !== '') {
            bind_params($stmt, $types, $params);
        }

        if (!$stmt->execute()) {
            throw new RuntimeException(
                'Query gagal: ' . $stmt->error
            );
        }

        return stmt_fetch_all_assoc($stmt);
    } finally {
        $stmt->close();
    }
}

function execute_sql(
    mysqli $db,
    string $sql,
    string $types = '',
    array $params = []
): int {
    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Gagal menyiapkan query: ' . $db->error
        );
    }

    try {
        if ($types !== '') {
            bind_params($stmt, $types, $params);
        }

        if (!$stmt->execute()) {
            throw new RuntimeException(
                'Query gagal: ' . $stmt->error
            );
        }

        return $stmt->affected_rows;
    } finally {
        $stmt->close();
    }
}

function table_exists(mysqli $db, string $table): bool
{
    $row = query_one(
        $db,
        "SELECT COUNT(*) AS jumlah
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?",
        's',
        [$table]
    );

    return (int) ($row['jumlah'] ?? 0) > 0;
}

function detect_table(
    mysqli $db,
    array $names,
    string $label
): string {
    foreach ($names as $name) {
        if (table_exists($db, $name)) {
            return $name;
        }
    }

    throw new RuntimeException(
        'Tabel ' . $label . ' tidak ditemukan. Dicari: ' .
        implode(', ', $names)
    );
}

function table_columns(mysqli $db, string $table): array
{
    return query_all(
        $db,
        "SELECT
            COLUMN_NAME,
            COLUMN_TYPE,
            DATA_TYPE,
            IS_NULLABLE,
            COLUMN_DEFAULT,
            COLUMN_KEY,
            EXTRA,
            ORDINAL_POSITION
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION",
        's',
        [$table]
    );
}

function table_text_charset_issues(mysqli $db, string $table): array
{
    return query_all(
        $db,
        "SELECT
            COLUMN_NAME,
            DATA_TYPE,
            CHARACTER_SET_NAME,
            COLLATION_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND CHARACTER_SET_NAME IS NOT NULL
           AND LOWER(CHARACTER_SET_NAME) <> 'utf8mb4'
         ORDER BY ORDINAL_POSITION",
        's',
        [$table]
    );
}

function assert_utf8mb4_text_columns(mysqli $db, string $table): void
{
    $issues = table_text_charset_issues($db, $table);

    if (!$issues) {
        return;
    }

    $fields = [];

    foreach ($issues as $issue) {
        $fields[] =
            (string) ($issue['COLUMN_NAME'] ?? '-') .
            ' [' . (string) ($issue['CHARACTER_SET_NAME'] ?? '-') . ']';
    }

    throw new RuntimeException(
        'Kolom teks pada tabel ' . $table .
        ' belum utf8mb4: ' . implode(', ', $fields) . '. ' .
        'Ubah charset kolom/tabel tersebut ke utf8mb4 agar emoji tidak menjadi ??.'
    );
}

function column_names(array $columns): array
{
    $names = [];

    foreach ($columns as $column) {
        $names[] = (string) ($column['COLUMN_NAME'] ?? '');
    }

    return $names;
}

function insert_columns(array $columns): array
{
    $names = [];

    foreach ($columns as $column) {
        $extra = strtoupper((string) ($column['EXTRA'] ?? ''));

        if (strpos($extra, 'GENERATED') !== false) {
            continue;
        }

        $names[] = (string) $column['COLUMN_NAME'];
    }

    return $names;
}

function unique_key_columns(mysqli $db, string $table): array
{
    $rows = query_all(
        $db,
        "SELECT
            INDEX_NAME,
            COLUMN_NAME,
            SEQ_IN_INDEX
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND NON_UNIQUE = 0
         ORDER BY
            CASE WHEN INDEX_NAME = 'PRIMARY' THEN 0 ELSE 1 END,
            INDEX_NAME,
            SEQ_IN_INDEX",
        's',
        [$table]
    );

    $indexes = [];

    foreach ($rows as $row) {
        $indexName = (string) $row['INDEX_NAME'];

        $indexes[$indexName][
            (int) $row['SEQ_IN_INDEX']
        ] = (string) $row['COLUMN_NAME'];
    }

    if (!$indexes) {
        return [];
    }

    $selected = array_key_first($indexes);

    if ($selected === null) {
        return [];
    }

    ksort($indexes[$selected]);

    return array_values($indexes[$selected]);
}

function detect_date_column(
    array $columnNames,
    string $table
): string {
    $lookup = [];

    foreach ($columnNames as $columnName) {
        $lookup[strtolower((string) $columnName)] =
            (string) $columnName;
    }

    foreach ([
        'transdate',
        'inventorydate',
        'invdate',
        'movementdate',
        'stockdate',
        'created_at',
        'createdat',
        'date'
    ] as $candidate) {
        if (isset($lookup[$candidate])) {
            return $lookup[$candidate];
        }
    }

    throw new RuntimeException(
        "Field tanggal inventory tidak ditemukan pada {$table}."
    );
}

function detect_transtype_column(
    array $columnNames,
    string $table
): string {
    $lookup = [];

    foreach ($columnNames as $columnName) {
        $lookup[strtolower((string) $columnName)] =
            (string) $columnName;
    }

    foreach ([
        'transtype',
        'trans_type',
        'transactiontype',
        'transaction_type'
    ] as $candidate) {
        if (isset($lookup[$candidate])) {
            return $lookup[$candidate];
        }
    }

    throw new RuntimeException(
        "Field transtype tidak ditemukan pada {$table}. " .
        "Proses inventory wajib memakai transtype 03 (Penjualan)."
    );
}

function assert_same_structure(
    string $source,
    array $sourceColumns,
    string $copy,
    array $copyColumns
): void {
    if (count($sourceColumns) !== count($copyColumns)) {
        throw new RuntimeException(
            "Struktur {$source} dan {$copy} berbeda jumlah field."
        );
    }

    foreach ($sourceColumns as $index => $sourceColumn) {
        $copyColumn = $copyColumns[$index] ?? [];

        $sourceName = strtolower(
            (string) ($sourceColumn['COLUMN_NAME'] ?? '')
        );

        $copyName = strtolower(
            (string) ($copyColumn['COLUMN_NAME'] ?? '')
        );

        if ($sourceName !== $copyName) {
            throw new RuntimeException(
                "Nama field {$source} dan {$copy} berbeda pada urutan ke-" .
                ($index + 1) . ': ' .
                $sourceName . ' != ' . $copyName
            );
        }

        $sourceType = strtolower(
            (string) ($sourceColumn['COLUMN_TYPE'] ?? '')
        );

        $copyType = strtolower(
            (string) ($copyColumn['COLUMN_TYPE'] ?? '')
        );

        $sourceDataType = strtolower(
            (string) ($sourceColumn['DATA_TYPE'] ?? '')
        );

        $copyDataType = strtolower(
            (string) ($copyColumn['DATA_TYPE'] ?? '')
        );

        $compatible =
            $sourceType === $copyType
            || (
                $sourceDataType === 'timestamp'
                && $copyDataType === 'datetime'
            );

        if (!$compatible) {
            throw new RuntimeException(
                "Tipe field {$source}.{$sourceName} dan " .
                "{$copy}.{$copyName} tidak kompatibel."
            );
        }
    }
}

function inventory_pair_meta(mysqli $db): array
{
    $inventory = detect_table(
        $db,
        ['inventory'],
        'inventory'
    );

    $inventoryCopy = detect_table(
        $db,
        ['inventorycopy'],
        'inventorycopy'
    );

    $inventoryColumns = table_columns(
        $db,
        $inventory
    );

    $copyColumns = table_columns(
        $db,
        $inventoryCopy
    );

    assert_same_structure(
        $inventory,
        $inventoryColumns,
        $inventoryCopy,
        $copyColumns
    );

    /*
     * Jangan paksa semua kolom menjadi utf8mb4.
     *
     * Beberapa toko masih memakai MySQL lama yang hanya mengenal utf8.
     * COPY dilakukan langsung di sisi server dengan INSERT ... SELECT,
     * sehingga aplikasi tetap dapat memproses database legacy tanpa berhenti
     * pada validasi utf8mb4.
     */

    $fieldNames = column_names($inventoryColumns);
    $copyFieldNames = column_names($copyColumns);

    if (count($fieldNames) !== count($copyFieldNames)) {
        throw new RuntimeException(
            'Struktur inventory dan inventorycopy tidak sama.'
        );
    }

    $keys = unique_key_columns(
        $db,
        $inventory
    );

    $copyKeys = unique_key_columns(
        $db,
        $inventoryCopy
    );

    if (!$keys) {
        throw new RuntimeException(
            'Tabel inventory wajib memiliki PRIMARY KEY atau UNIQUE KEY.'
        );
    }

    if ($keys !== $copyKeys) {
        throw new RuntimeException(
            'PRIMARY/UNIQUE KEY inventory dan inventorycopy tidak sama.'
        );
    }

    $dateColumn = detect_date_column(
        $fieldNames,
        $inventory
    );

    $transtypeColumn = detect_transtype_column(
        $fieldNames,
        $inventory
    );

    /*
     * Karena struktur inventory dan inventorycopy sudah divalidasi sama,
     * nama field transtype yang sama juga tersedia pada inventorycopy.
     */
    return [
        'source' => $inventory,
        'copy' => $inventoryCopy,
        'source_columns' => $inventoryColumns,
        'copy_columns' => $copyColumns,
        'field_names' => $fieldNames,
        'insert_columns' => insert_columns($inventoryColumns),
        'key_columns' => $keys,
        'date_column' => $dateColumn,
        'transtype_column' => $transtypeColumn,
    ];
}

/*
|--------------------------------------------------------------------------
| Preview inventory berdasarkan tanggal
|--------------------------------------------------------------------------
*/
function inventory_preview(
    mysqli $db,
    array $pair,
    string $selectedDate
): array {
    $start = $selectedDate . ' 00:00:00';

    $nextDate = (new DateTime($selectedDate))
        ->modify('+1 day')
        ->format('Y-m-d');

    $end = $nextDate . ' 00:00:00';

    $dateColumn = qi($pair['date_column']);
    $transtypeColumn = qi($pair['transtype_column']);
    $transtype = INVENTORY_TRANSTYPE;

    /*
     * Preview sumber HANYA transtype 03 (Penjualan).
     */
    $row = query_one(
        $db,
        "SELECT
            COUNT(*) AS total_rows,
            MIN(i.{$dateColumn}) AS min_date,
            MAX(i.{$dateColumn}) AS max_date
         FROM " . qi($pair['source']) . " i
         WHERE i.{$dateColumn} >= ?
           AND i.{$dateColumn} < ?
           AND i.{$transtypeColumn} = ?",
        'sss',
        [$start, $end, $transtype]
    );

    $keys = $pair['key_columns'];
    $joinParts = [];

    foreach ($keys as $key) {
        $joinParts[] =
            'c.' . qi($key) .
            ' <=> i.' . qi($key);
    }

    /*
     * Pastikan pasangan inventorycopy yang dianggap cocok juga
     * memiliki transtype yang sama. Sumber sudah dikunci ke 03.
     */
    $joinParts[] =
        'c.' . $transtypeColumn .
        ' <=> i.' . $transtypeColumn;

    $join = implode(' AND ', $joinParts);

    $copyState = query_one(
        $db,
        "SELECT
            COUNT(*) AS total_rows,
            SUM(c." . qi($keys[0]) . " IS NOT NULL) AS copy_rows,
            SUM(c." . qi($keys[0]) . " IS NULL) AS missing_rows
         FROM " . qi($pair['source']) . " i
         LEFT JOIN " . qi($pair['copy']) . " c
           ON {$join}
         WHERE i.{$dateColumn} >= ?
           AND i.{$dateColumn} < ?
           AND i.{$transtypeColumn} = ?",
        'sss',
        [$start, $end, $transtype]
    );

    return [
        'transtype' => $transtype,

        'total_rows' =>
            (int) ($row['total_rows'] ?? 0),

        'copy_rows' =>
            (int) ($copyState['copy_rows'] ?? 0),

        'missing_rows' =>
            (int) ($copyState['missing_rows'] ?? 0),

        'min_date' =>
            (string) ($row['min_date'] ?? ''),

        'max_date' =>
            (string) ($row['max_date'] ?? ''),
    ];
}

/*
|--------------------------------------------------------------------------
| INSERT INVENTORY -> INVENTORYCOPY
|--------------------------------------------------------------------------
| HANYA data pada tanggal yang dipilih.
|
| Contoh:
| pilih 2026-08-18
|
| WHERE tanggal >= '2026-08-18 00:00:00'
|   AND tanggal <  '2026-08-19 00:00:00'
|--------------------------------------------------------------------------
*/
function copy_inventory_by_date(
    mysqli $db,
    array $pair,
    string $selectedDate
): array {
    if (!valid_date($selectedDate)) {
        throw new RuntimeException(
            'Tanggal inventory wajib dipilih dan harus valid.'
        );
    }

    if ($selectedDate > date('Y-m-d')) {
        throw new RuntimeException(
            'Tanggal inventory tidak boleh berada di masa depan.'
        );
    }

    $start = $selectedDate . ' 00:00:00';

    $nextDate = (new DateTime($selectedDate))
        ->modify('+1 day')
        ->format('Y-m-d');

    $end = $nextDate . ' 00:00:00';

    $columns = $pair['insert_columns'];
    $keys = $pair['key_columns'];
    $dateColumn = qi($pair['date_column']);
    $transtypeColumn = qi($pair['transtype_column']);
    $transtype = INVENTORY_TRANSTYPE;

    $quotedColumns = [];
    $sourceColumns = [];

    foreach ($columns as $column) {
        $quotedColumns[] = qi($column);
        $sourceColumns[] = 'i.' . qi($column);
    }

    $columnList = implode(', ', $quotedColumns);
    $sourceList = implode(', ', $sourceColumns);

    $joinParts = [];

    foreach ($keys as $key) {
        $joinParts[] =
            'c.' . qi($key) .
            ' <=> i.' . qi($key);
    }

    /*
     * Cocokkan juga transtype supaya record selain 03 tidak pernah
     * dianggap sebagai pasangan record Penjualan.
     */
    $joinParts[] =
        'c.' . $transtypeColumn .
        ' <=> i.' . $transtypeColumn;

    $join = implode(' AND ', $joinParts);

    /*
     * INSERT HANYA:
     * - tanggal yang dipilih
     * - transtype 03 (Penjualan)
     * - record yang belum tersedia di inventorycopy
     */
    $sql = "INSERT INTO " . qi($pair['copy']) . " ({$columnList})
            SELECT {$sourceList}
            FROM " . qi($pair['source']) . " i
            LEFT JOIN " . qi($pair['copy']) . " c
              ON {$join}
            WHERE i.{$dateColumn} >= ?
              AND i.{$dateColumn} < ?
              AND i.{$transtypeColumn} = ?
              AND c." . qi($keys[0]) . " IS NULL";

    $insertedRows = execute_sql(
        $db,
        $sql,
        'sss',
        [$start, $end, $transtype]
    );

    /*
     * Verifikasi juga hanya untuk transtype 03.
     */
    $verify = query_one(
        $db,
        "SELECT
            COUNT(*) AS source_rows,
            SUM(c." . qi($keys[0]) . " IS NULL) AS missing_rows
         FROM " . qi($pair['source']) . " i
         LEFT JOIN " . qi($pair['copy']) . " c
           ON {$join}
         WHERE i.{$dateColumn} >= ?
           AND i.{$dateColumn} < ?
           AND i.{$transtypeColumn} = ?",
        'sss',
        [$start, $end, $transtype]
    );

    $sourceRows = (int) ($verify['source_rows'] ?? 0);
    $missingRows = (int) ($verify['missing_rows'] ?? 0);

    if ($missingRows > 0) {
        throw new RuntimeException(
            'Proses belum lengkap. Masih ada ' .
            $missingRows .
            ' data inventory transtype ' .
            $transtype .
            ' tanggal ' .
            $selectedDate .
            ' yang belum masuk ke inventorycopy.'
        );
    }

    return [
        'date' => $selectedDate,
        'transtype' => $transtype,
        'source_rows' => $sourceRows,
        'inserted_rows' => $insertedRows,
        'already_exists' => max(
            0,
            $sourceRows - $insertedRows
        ),
        'missing_rows' => $missingRows,
    ];
}

/*
|--------------------------------------------------------------------------
| LOG
|--------------------------------------------------------------------------
*/
function log_inventory_action(array $payload): void
{
    $directory = __DIR__ . '/logs';

    if (!is_dir($directory)) {
        @mkdir($directory, 0750, true);
    }

    $encoded = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    if ($encoded !== false) {
        @file_put_contents(
            $directory . '/inventorycopy.log',
            $encoded . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}

/*
|--------------------------------------------------------------------------
| Load map.php
|--------------------------------------------------------------------------
*/
$mapFile = __DIR__ . '/map.php';

if (!is_file($mapFile)) {
    die('map.php tidak ditemukan.');
}

$mapReturn = require $mapFile;

$storeMap = [];

if (is_array($mapReturn)) {
    $storeMap = $mapReturn;
} elseif (isset($map) && is_array($map)) {
    $storeMap = $map;
} elseif (isset($stores) && is_array($stores)) {
    $storeMap = $stores;
}

if (!$storeMap) {
    die('Mapping koneksi dari map.php kosong.');
}

$dropdownStores = [];

foreach ($storeMap as $storeName => $storeConfig) {
    if (!is_array($storeConfig)) {
        continue;
    }

    $dropdownStores[(string) $storeName] = $storeConfig;
}

uksort($dropdownStores, 'strnatcasecmp');

/*
|--------------------------------------------------------------------------
| State
|--------------------------------------------------------------------------
*/
$action = (string) ($_POST['action'] ?? '');
$selectedStore = trim((string) ($_POST['store'] ?? ''));
$selectedDate = trim((string) ($_POST['inventory_date'] ?? ''));

$errorMessage = '';
$errorType = '';
$successResult = null;
$previewResult = null;

try {
    if ($action !== '') {
        $postedCsrf = (string) ($_POST['csrf_token'] ?? '');

        if (!hash_equals($csrfToken, $postedCsrf)) {
            throw new RuntimeException(
                'Token keamanan tidak valid. Muat ulang halaman.'
            );
        }

        if ($action === 'preview' || $action === 'process') {
            if (!isset($dropdownStores[$selectedStore])) {
                throw new RuntimeException(
                    'Toko belum dipilih atau tidak valid.'
                );
            }

            if ($selectedDate === '') {
                throw new RuntimeException(
                    'Tanggal inventory wajib dipilih terlebih dahulu.'
                );
            }

            if (!valid_date($selectedDate)) {
                throw new RuntimeException(
                    'Tanggal inventory tidak valid.'
                );
            }

            if ($selectedDate > date('Y-m-d')) {
                throw new RuntimeException(
                    'Tanggal inventory tidak boleh berada di masa depan.'
                );
            }

            $db = connect_store(
                $dropdownStores[$selectedStore]
            );

            try {
                $pair = inventory_pair_meta($db);

                if ($action === 'preview') {
                    $previewResult = inventory_preview(
                        $db,
                        $pair,
                        $selectedDate
                    );
                } else {
                    if ($pair['source'] !== 'inventory') {
                        throw new RuntimeException(
                            'Tabel sumber bukan inventory.'
                        );
                    }

                    if ($pair['copy'] !== 'inventorycopy') {
                        throw new RuntimeException(
                            'Tabel tujuan bukan inventorycopy.'
                        );
                    }

                    if ($pair['source'] === $pair['copy']) {
                        throw new RuntimeException(
                            'Tabel inventory dan inventorycopy tidak boleh sama.'
                        );
                    }

                    if (!$db->begin_transaction()) {
                        throw new RuntimeException(
                            'Gagal memulai transaksi: ' .
                            $db->error
                        );
                    }

                    try {
                        $lockName =
                            'inventorycopy_' .
                            substr(
                                hash(
                                    'sha256',
                                    $selectedStore
                                ),
                                0,
                                40
                            );

                        $lock = query_one(
                            $db,
                            'SELECT GET_LOCK(?, 10) AS locked',
                            's',
                            [$lockName]
                        );

                        if ((int) ($lock['locked'] ?? 0) !== 1) {
                            throw new RuntimeException(
                                'Proses inventory lain sedang berjalan untuk toko ini.'
                            );
                        }

                        try {
                            $successResult =
                                copy_inventory_by_date(
                                    $db,
                                    $pair,
                                    $selectedDate
                                );

                            if (!$db->commit()) {
                                throw new RuntimeException(
                                    'COMMIT gagal: ' .
                                    $db->error
                                );
                            }

                            /*
                             * AUTO REFRESH PREVIEW SETELAH COMMIT.
                             *
                             * Contoh:
                             * sebelum proses:
                             *   Data Inventory     = 915
                             *   Data Inventorycopy = 0
                             *
                             * sesudah proses:
                             *   Data Inventory     = 915
                             *   Data Inventorycopy = 915
                             *
                             * Tidak perlu klik "Cek Data" lagi.
                             */
                            try {
                                $previewResult = inventory_preview(
                                    $db,
                                    $pair,
                                    $selectedDate
                                );
                            } catch (Throwable $previewRefreshError) {
                                /*
                                 * Data sudah COMMIT, jadi error refresh preview
                                 * tidak boleh membuat proses copy dianggap gagal.
                                 */
                                $previewResult = null;

                                log_inventory_action([
                                    'time' => date('c'),
                                    'status' =>
                                        'INVENTORYCOPY_PREVIEW_REFRESH_FAILED',
                                    'store' =>
                                        $selectedStore,
                                    'date' =>
                                        $selectedDate,
                                    'error' =>
                                        $previewRefreshError->getMessage(),
                                ]);
                            }
                        } finally {
                            try {
                                query_one(
                                    $db,
                                    'SELECT RELEASE_LOCK(?) AS released',
                                    's',
                                    [$lockName]
                                );
                            } catch (Throwable $ignored) {
                            }
                        }

                        log_inventory_action([
                            'time' => date('c'),
                            'status' =>
                                'INVENTORYCOPY_SUCCESS',
                            'store' =>
                                $selectedStore,
                            'date' =>
                                $selectedDate,
                            'result' =>
                                $successResult,
                            'preview_after_process' =>
                                $previewResult,
                        ]);
                    } catch (Throwable $processError) {
                        @$db->rollback();

                        log_inventory_action([
                            'time' => date('c'),
                            'status' =>
                                'INVENTORYCOPY_FAILED',
                            'store' =>
                                $selectedStore,
                            'date' =>
                                $selectedDate,
                            'error' =>
                                $processError->getMessage(),
                        ]);

                        throw $processError;
                    }
                }
            } finally {
                $db->close();
            }
        } elseif ($action === 'reset') {
            header(
                'Location: ' .
                strtok($_SERVER['REQUEST_URI'], '?')
            );
            exit;
        } else {
            throw new RuntimeException(
                'Aksi tidak dikenali.'
            );
        }
    }
} catch (Throwable $error) {
    $message = $error->getMessage();

    if (strpos($message, 'TOKO_OFFLINE|') === 0) {
        $errorType = 'offline';
        $message = substr(
            $message,
            strlen('TOKO_OFFLINE|')
        );
    }

    $errorMessage = $message;
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Inventory Backup</title>

    <link rel="icon" type="image/png" href="img/srt2.png">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
        rel="stylesheet"
    >

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --blue: #1d4ed8;
            --blue-dark: #173b90;
            --bg: #f5f7fb;
            --border: #e5e7eb;
        }

        html,
        body {
            width: 100%;
            min-height: 100%;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background: var(--bg);
            color: #1f2937;
        }

        .page-wrap {
            width: 100%;
            max-width: none;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }

        .main-card {
            width: 100%;
            min-height: 100vh;
            background: #fff;
            border: 0;
            border-radius: 0;
            box-shadow: none;
            overflow: hidden;
        }

        .header {
            padding: 26px clamp(20px, 3vw, 52px);
            background: var(--blue);
            color: #fff;
        }

        .header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .btn-back-dashboard {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            background: rgba(255,255,255,.14);
            color: #fff;
            border: 1px solid rgba(255,255,255,.35);
            border-radius: 10px;
            padding: 9px 14px;
            font-weight: 600;
            transition: .2s ease;
        }

        .btn-back-dashboard:hover {
            background: #fff;
            color: var(--blue);
        }

        .header h1 {
            font-size: 24px;
            margin: 0;
            font-weight: 700;
        }

        .header p {
            margin: 7px 0 0;
            opacity: .9;
        }

        .body {
            width: 100%;
            padding: clamp(20px, 3vw, 52px);
        }

        @media (max-width: 767.98px) {
            .header {
                padding: 20px 16px;
            }

            .body {
                padding: 18px 14px 28px;
            }

            .btn-process,
            #btnPreview {
                width: 100%;
            }

            .btn-back-dashboard {
                width: 100%;
                justify-content: center;
            }
        }

        .form-label {
            font-weight: 600;
        }

        .required {
            color: #dc2626;
        }

        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            padding: 16px;
        }

        .result-card {
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            background: #fff;
        }

        .stat {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 18px;
            height: 100%;
        }

        .stat-label {
            color: #6b7280;
            font-size: 13px;
        }

        .stat-value {
            font-size: 27px;
            font-weight: 700;
            margin-top: 4px;
        }

        code {
            color: #1d4ed8;
        }

        .btn-process {
            min-width: 220px;
        }
    </style>
</head>

<body>

<div class="page-wrap">

    <div class="main-card">

        <div class="header">
            <div class="header-top">
                <h1>
                    <i class="fa-solid fa-database me-2"></i>
                    PROSES INVENTORY COPY
                </h1>

                <a href="dashboard.php" class="btn-back-dashboard">
                    <i class="fa-solid fa-arrow-left"></i>
                    Kembali
                </a>
            </div>

            <p>
                Backup inventory berdasarkan tanggal yang dipilih.
            </p>
        </div>

        <div class="body">

            <div class="info-box mb-4">
                <div class="fw-bold mb-1">
                    <i class="fa-solid fa-circle-info me-2"></i>
                    Proses Terpisah
                </div>

                <div>
                    Halaman ini khusus untuk proses
                    <code>inventory</code>
                    
                    <code>inventorycopy</code>.
                    Hanya <strong>transtype 03 (Penjualan)</strong> yang
                    ditampilkan dan diproses. Transtype lain diabaikan.
                </div>
            </div>

            <form
                method="post"
                id="inventoryForm"
                autocomplete="off"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    id="action"
                    value="process"
                >

                <div class="row g-4">

                    <div class="col-md-6">
                        <label
                            class="form-label"
                            for="store"
                        >
                            Toko
                            <span class="required">*</span>
                        </label>

                        <select
                            class="form-select form-select-lg"
                            id="store"
                            name="store"
                            required
                        >
                            <option value="">
                                -- Pilih Toko --
                            </option>

                            <?php foreach ($dropdownStores as $storeName => $storeConfig): ?>
                                <option
                                    value="<?= e($storeName) ?>"
                                    <?= $selectedStore === $storeName ? 'selected' : '' ?>
                                >
                                    <?= e($storeName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label
                            class="form-label"
                            for="inventory_date"
                        >
                            Tanggal Inventory
                            <span class="required">*</span>
                        </label>

                        <input
                            type="date"
                            class="form-control form-control-lg"
                            id="inventory_date"
                            name="inventory_date"
                            value="<?= e($selectedDate) ?>"
                            max="<?= e(date('Y-m-d')) ?>"
                            required
                        >

                        <div class="form-text">
                            Hanya data pada tanggal ini dengan
                            <strong>transtype 03 (Penjualan)</strong> yang akan
                            di-copy ke <code>inventorycopy</code>.
                        </div>
                    </div>

                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">

                    <button
                        type="button"
                        class="btn btn-outline-primary px-4"
                        id="btnPreview"
                    >
                        <i class="fa-solid fa-magnifying-glass me-2"></i>
                        Cek Data
                    </button>

                    <button
                        type="submit"
                        class="btn btn-primary btn-process px-4"
                        id="btnProcess"
                    >
                        <i class="fa-solid fa-copy me-2"></i>
                        Proses Inventory
                    </button>

                </div>

            </form>

            <?php if ($previewResult !== null): ?>

                <div class="result-card mt-4">

                    <h5 class="fw-bold mb-3">
                        Hasil Pengecekan
                        <span class="badge text-bg-primary ms-2">Transtype 03  Penjualan</span>
                    </h5>

                    <div class="row g-3">

                        <div class="col-md-3">
                            <div class="stat">
                                <div class="stat-label">
                                    Data Inventory
                                </div>

                                <div class="stat-value">
                                    <?= number_format(
                                        $previewResult['total_rows']
                                    ) ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="stat">
                                <div class="stat-label">
                                    Data Inventorycopy
                                </div>

                                <div class="stat-value">
                                    <?= number_format(
                                        $previewResult['copy_rows']
                                    ) ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="stat">
                                <div class="stat-label">
                                    Belum Ada di Copy
                                </div>

                                <div class="stat-value">
                                    <?= number_format(
                                        $previewResult['missing_rows']
                                    ) ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="stat">
                                <div class="stat-label">
                                    Tanggal
                                </div>

                                <div class="stat-value fs-5">
                                    <?= e($selectedDate) ?>
                                </div>
                            </div>
                        </div>

                    </div>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<script>
(function () {
    const form = document.getElementById('inventoryForm');
    const actionInput = document.getElementById('action');
    const btnPreview = document.getElementById('btnPreview');
    const btnProcess = document.getElementById('btnProcess');
    const store = document.getElementById('store');
    const dateInput = document.getElementById('inventory_date');

    function validateBasic() {
        if (!store.value) {
            Swal.fire({
                icon: 'warning',
                title: 'Toko Belum Dipilih',
                text: 'Silakan pilih toko terlebih dahulu.'
            });

            store.focus();
            return false;
        }

        if (!dateInput.value) {
            Swal.fire({
                icon: 'warning',
                title: 'Tanggal Belum Dipilih',
                text: 'Silakan pilih tanggal inventory terlebih dahulu.'
            });

            dateInput.focus();
            return false;
        }

        return true;
    }

    btnPreview.addEventListener('click', function () {
        if (!validateBasic()) {
            return;
        }

        actionInput.value = 'preview';

        Swal.fire({
            title: 'Mengecek Data',
            text: 'Sedang mengecek inventory pada tanggal yang dipilih.',
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: function () {
                Swal.showLoading();
            }
        });

        form.submit();
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (!validateBasic()) {
            return;
        }

        actionInput.value = 'process';

        const dateText = dateInput.value;

        Swal.fire({
            icon: 'question',
            title: 'Proses Inventory?',
            html:
                'Data <code>inventory</code> <strong>transtype 03 (Penjualan)</strong><br>' +
                'pada tanggal <strong>' + dateText + '</strong><br>' +
                'akan di-INSERT ke <code>inventorycopy</code>.',
            showCancelButton: true,
            confirmButtonText: 'Ya, Proses',
            cancelButtonText: 'Batal',
            reverseButtons: true
        }).then(function (result) {

            if (!result.isConfirmed) {
                return;
            }

            Swal.fire({
                title: 'Sedang Memproses',
                html:
                    'Inventory ? Inventorycopy<br>' +
                    '<strong>Transtype 03 (Penjualan)</strong><br>' +
                    '<strong>' + dateText + '</strong>',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: function () {
                    Swal.showLoading();
                }
            });

            form.submit();
        });
    });
})();
</script>

<?php if ($errorMessage !== ''): ?>
<script>
Swal.fire({
    icon: <?= js($errorType === 'offline' ? 'warning' : 'error') ?>,
    title: <?= js(
        $errorType === 'offline'
            ? 'Toko Offline'
            : 'Proses Dibatalkan'
    ) ?>,
    text: <?= js($errorMessage) ?>,
    confirmButtonText: 'Kembali',
    allowOutsideClick: false
});
</script>
<?php endif; ?>

<?php if ($successResult !== null): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Inventory Berhasil Diproses',
    html:
        'Tanggal: <strong><?= e($successResult['date']) ?></strong><br>' +
        'Transtype: <strong><?= e($successResult['transtype']) ?> (Penjualan)</strong><br><br>' +
        'Data inventory: <strong><?= number_format($successResult['source_rows']) ?></strong><br>' +
        'Berhasil di-copy: <strong><?= number_format($successResult['inserted_rows']) ?></strong><br>' +
        'Sudah ada sebelumnya: <strong><?= number_format($successResult['already_exists']) ?></strong>',
    confirmButtonText: 'OK'
});
</script>
<?php endif; ?>

</body>
</html>
