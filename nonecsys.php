<?php
declare(strict_types=1);

/*
 * FIX MAPPING INVENTORY - 2026-08-18
 * sales.salesid == inventorycopy.transid == inventory.transid
 * Cek SalesID selalu mencari inventorycopy.transid, BUKAN inventorycopy.salesid.
 */

/*
|--------------------------------------------------------------------------
| Penanganan error VPS
|--------------------------------------------------------------------------
| Error fatal disimpan ke file nonecsys_php_error.log agar HTTP 500 dapat
| dilacak tanpa menampilkan detail sensitif kepada pengguna.
*/
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', __DIR__ . '/nonecsys_php_error.log');

register_shutdown_function(function () {
    $error = error_get_last();

    if (!$error) {
        return;
    }

    $fatalTypes = array(
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR
    );

    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    $message = '[' . date('c') . '] '
        . $error['message']
        . ' di '
        . $error['file']
        . ':'
        . $error['line']
        . PHP_EOL;

    @file_put_contents(
        __DIR__ . '/nonecsys_php_error.log',
        $message,
        FILE_APPEND | LOCK_EX
    );
});

if (!extension_loaded('mysqli')) {
    http_response_code(500);
    die('Extension mysqli belum aktif di server.');
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
session_start();
date_default_timezone_set('Asia/Jakarta');

/*
|--------------------------------------------------------------------------
| Mode tanpa login
|--------------------------------------------------------------------------
| Session hanya dipakai untuk CSRF dan menyimpan tahapan proses, bukan
| untuk autentikasi/login.
*/
$operatorName = 'Tanpa Login';

mysqli_report(MYSQLI_REPORT_OFF);

if (empty($_SESSION['sales_step_csrf'])) {
    try {
        $_SESSION['sales_step_csrf'] = bin2hex(random_bytes(32));
    } catch (Throwable $tokenError) {
        $_SESSION['sales_step_csrf'] = hash(
            'sha256',
            uniqid((string) mt_rand(), true)
        );
    }
}
$csrfToken = (string) $_SESSION['sales_step_csrf'];

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
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    ) ?: 'null';
}

function base64url_encode_string(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function base64url_decode_string(string $value): string
{
    $padding = strlen($value) % 4;

    if ($padding !== 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);

    if ($decoded === false) {
        throw new RuntimeException('Data pilihan baris tidak valid.');
    }

    return $decoded;
}

/*
 * Nilai key pada checkbox ditandatangani dengan CSRF token agar tidak dapat
 * diedit manual untuk menghapus baris lain yang tidak ditampilkan.
 */
function conflict_key_token(
    array $keyValues,
    array $keyColumns,
    string $secret
): string {
    $ordered = [];

    foreach ($keyColumns as $keyColumn) {
        if (!array_key_exists($keyColumn, $keyValues)) {
            throw new RuntimeException('Nilai key konflik tidak lengkap.');
        }

        $ordered[$keyColumn] = $keyValues[$keyColumn];
    }

    $json = json_encode(
        $ordered,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new RuntimeException('Gagal membuat token pilihan baris.');
    }

    $payload = base64url_encode_string($json);
    $signature = hash_hmac('sha256', $payload, $secret);

    return $payload . '.' . $signature;
}

function parse_conflict_key_token(
    string $token,
    array $keyColumns,
    string $secret
): array {
    $parts = explode('.', $token, 2);

    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        throw new RuntimeException('Format pilihan baris tidak valid.');
    }

    [$payload, $signature] = $parts;
    $expectedSignature = hash_hmac('sha256', $payload, $secret);

    if (!hash_equals($expectedSignature, $signature)) {
        throw new RuntimeException('Pilihan baris telah berubah atau tidak valid.');
    }

    $decoded = json_decode(base64url_decode_string($payload), true);

    if (!is_array($decoded) || count($decoded) !== count($keyColumns)) {
        throw new RuntimeException('Isi pilihan baris tidak valid.');
    }

    $ordered = [];

    foreach ($keyColumns as $keyColumn) {
        if (!array_key_exists($keyColumn, $decoded)) {
            throw new RuntimeException('Key pilihan baris tidak lengkap.');
        }

        $value = $decoded[$keyColumn];

        if ($value !== null && !is_scalar($value)) {
            throw new RuntimeException('Nilai key pilihan baris tidak valid.');
        }

        $ordered[$keyColumn] = $value;
    }

    return $ordered;
}

function conflict_key_fingerprint(array $keyValues, array $keyColumns): string
{
    $ordered = [];

    foreach ($keyColumns as $keyColumn) {
        $ordered[$keyColumn] = $keyValues[$keyColumn] ?? null;
    }

    return hash(
        'sha256',
        json_encode(
            $ordered,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: ''
    );
}

function qi(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function bind_params(mysqli_stmt $stmt, string $types, array &$params): void
{
    $refs = array();
    $refs[] = &$types;

    foreach ($params as &$value) {
        $refs[] = &$value;
    }
    unset($value);

    if (!call_user_func_array(array($stmt, 'bind_param'), $refs)) {
        throw new RuntimeException('Gagal memasang parameter query.');
    }
}

/*
 * Mengambil hasil prepared statement tanpa mysqli_stmt::get_result().
 * Cara ini tetap berjalan pada server yang tidak memakai mysqlnd.
 */
function stmt_fetch_all_assoc(mysqli_stmt $stmt): array
{
    $metadata = $stmt->result_metadata();

    if (!$metadata) {
        return array();
    }

    $fields = array();
    $row = array();
    $bindValues = array();

    while ($field = $metadata->fetch_field()) {
        $fieldName = (string) $field->name;
        $fields[] = $fieldName;
        $row[$fieldName] = null;
        $bindValues[] = &$row[$fieldName];
    }

    if ($bindValues) {
        if (!call_user_func_array(array($stmt, 'bind_result'), $bindValues)) {
            $metadata->free();
            throw new RuntimeException('Gagal membaca hasil query.');
        }
    }

    $rows = array();

    while ($stmt->fetch()) {
        $current = array();

        foreach ($fields as $fieldName) {
            $current[$fieldName] = $row[$fieldName];
        }

        $rows[] = $current;
    }

    $metadata->free();

    return $rows;
}

function query_one(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Gagal menyiapkan query: ' . $db->error);
    }

    try {
        if ($types !== '') {
            bind_params($stmt, $types, $params);
        }

        if (!$stmt->execute()) {
            throw new RuntimeException('Query gagal: ' . $stmt->error);
        }

        $rows = stmt_fetch_all_assoc($stmt);

        return isset($rows[0]) ? $rows[0] : array();
    } finally {
        $stmt->close();
    }
}

function query_all(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Gagal menyiapkan query: ' . $db->error);
    }

    try {
        if ($types !== '') {
            bind_params($stmt, $types, $params);
        }

        if (!$stmt->execute()) {
            throw new RuntimeException('Query gagal: ' . $stmt->error);
        }

        return stmt_fetch_all_assoc($stmt);
    } finally {
        $stmt->close();
    }
}

function execute_sql(mysqli $db, string $sql, string $types = '', array $params = []): int
{
    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Gagal menyiapkan query: ' . $db->error);
    }

    try {
        if ($types !== '') {
            bind_params($stmt, $types, $params);
        }

        if (!$stmt->execute()) {
            throw new RuntimeException('Query gagal: ' . $stmt->error);
        }

        return $stmt->affected_rows;
    } finally {
        $stmt->close();
    }
}

function valid_date(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed instanceof DateTime && $parsed->format('Y-m-d') === $date;
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

    /*
     * Cek port lebih dulu agar toko offline tidak menunggu RTO MySQL.
     * Jika fsockopen dinonaktifkan server, proses langsung lanjut ke mysqli.
     */
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
                'TOKO_OFFLINE|Toko sedang offline atau jaringan database tidak dapat dijangkau.'
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

    $connected = @mysqli_real_connect(
        $db,
        $cfg['host'],
        $cfg['user'],
        $cfg['pass'],
        $cfg['db'],
        $port
    );

    if (!$connected) {
        $connectCode = (int) $db->connect_errno;

        if (in_array($connectCode, array(2002, 2003, 2005, 2006), true)) {
            throw new RuntimeException(
                'TOKO_OFFLINE|Toko sedang offline atau jaringan database tidak dapat dijangkau.'
            );
        }

        throw new RuntimeException(
            'Koneksi database ditolak. Periksa user, password, nama database, dan hak akses pada map.php.'
        );
    }

    /*
     * Database lama kadang belum mendukung utf8mb4.
     * Charset tidak dijadikan alasan untuk menggagalkan maintenance.
     */
    if (!@mysqli_set_charset($db, 'utf8mb4')) {
        if (!@mysqli_set_charset($db, 'utf8')) {
            @mysqli_query($db, 'SET NAMES utf8');
        }
    }

    @$db->query('SET SESSION innodb_lock_wait_timeout = 180');
    @$db->query('SET SESSION lock_wait_timeout = 180');

    return $db;
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

function detect_table(mysqli $db, array $names, string $label): string
{
    foreach ($names as $name) {
        if (table_exists($db, $name)) {
            return $name;
        }
    }

    throw new RuntimeException(
        'Tabel ' . $label . ' tidak ditemukan. Dicari: ' . implode(', ', $names)
    );
}

function table_engine(mysqli $db, string $table): string
{
    $row = query_one(
        $db,
        "SELECT ENGINE
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1",
        's',
        [$table]
    );

    return strtoupper((string) ($row['ENGINE'] ?? ''));
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

function column_names(array $columns): array
{
    $names = array();

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

        $sourceName = strtolower((string) ($sourceColumn['COLUMN_NAME'] ?? ''));
        $copyName   = strtolower((string) ($copyColumn['COLUMN_NAME'] ?? ''));

        if ($sourceName !== $copyName) {
            throw new RuntimeException(
                "Nama field {$source} dan {$copy} berbeda pada urutan ke-"
                . ($index + 1)
                . ": {$sourceName} != {$copyName}"
            );
        }

        $sourceDataType = strtolower((string) ($sourceColumn['DATA_TYPE'] ?? ''));
        $copyDataType   = strtolower((string) ($copyColumn['DATA_TYPE'] ?? ''));

        $sourceColumnType = strtolower((string) ($sourceColumn['COLUMN_TYPE'] ?? ''));
        $copyColumnType   = strtolower((string) ($copyColumn['COLUMN_TYPE'] ?? ''));

        /*
         * Aturan kompatibilitas:
         *
         * 1. COLUMN_TYPE sama persis -> aman.
         * 2. TIMESTAMP pada tabel utama -> DATETIME pada tabel copy -> aman.
         *    DATETIME mampu menyimpan nilai tanggal/waktu TIMESTAMP tanpa
         *    menjalankan fungsi konversi khusus.
         *
         * Arah sebaliknya tidak otomatis diizinkan karena rentang TIMESTAMP
         * lebih terbatas daripada DATETIME.
         */
        $typeCompatible =
            $sourceColumnType === $copyColumnType
            || (
                $sourceDataType === 'timestamp'
                && $copyDataType === 'datetime'
            );

        if (!$typeCompatible) {
            throw new RuntimeException(
                "Tipe field {$source} dan {$copy} tidak kompatibel pada urutan ke-"
                . ($index + 1)
                . ". {$source}: "
                . (string) ($sourceColumn['COLUMN_NAME'] ?? '-')
                . ' '
                . (string) ($sourceColumn['COLUMN_TYPE'] ?? '-')
                . "; {$copy}: "
                . (string) ($copyColumn['COLUMN_NAME'] ?? '-')
                . ' '
                . (string) ($copyColumn['COLUMN_TYPE'] ?? '-')
            );
        }

        /*
         * Copy boleh lebih longgar, tetapi tidak boleh lebih ketat terhadap NULL.
         * Jika sumber bisa NULL sedangkan copy NOT NULL, insert berpotensi gagal.
         */
        $sourceNullable = strtoupper((string) ($sourceColumn['IS_NULLABLE'] ?? 'NO'));
        $copyNullable   = strtoupper((string) ($copyColumn['IS_NULLABLE'] ?? 'NO'));

        if ($sourceNullable === 'YES' && $copyNullable !== 'YES') {
            throw new RuntimeException(
                "Field {$copy}."
                . (string) ($copyColumn['COLUMN_NAME'] ?? '-')
                . " tidak menerima NULL, sedangkan field sumber dapat berisi NULL."
            );
        }
    }
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
        $indexes[$indexName][(int) $row['SEQ_IN_INDEX']] = (string) $row['COLUMN_NAME'];
    }

    if (!$indexes) {
        return [];
    }

    $selected = null;

    foreach ($indexes as $indexName => $indexColumns) {
        $selected = $indexName;
        break;
    }

    if ($selected === null) {
        return array();
    }

    ksort($indexes[$selected]);

    return array_values($indexes[$selected]);
}

function trigger_count(mysqli $db, string $table, string $event): int
{
    $row = query_one(
        $db,
        "SELECT COUNT(*) AS jumlah
         FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE()
           AND EVENT_OBJECT_TABLE = ?
           AND EVENT_MANIPULATION = ?",
        'ss',
        [$table, strtoupper($event)]
    );

    return (int) ($row['jumlah'] ?? 0);
}

function detect_date_column(array $columnNames, string $table): string
{
    foreach (['transdate', 'salesdate'] as $candidate) {
        if (in_array($candidate, $columnNames, true)) {
            return $candidate;
        }
    }

    throw new RuntimeException(
        "Field tanggal transdate/salesdate tidak ditemukan pada {$table}."
    );
}

function candidate_condition(string $alias, string $dateColumn): string
{
    return "{$alias}." . qi($dateColumn) . " < ?
        AND {$alias}.`salesid` IS NOT NULL
        AND LEFT({$alias}.`salesid`, CHAR_LENGTH(?)) <> ?";
}

function protected_condition(string $alias, string $dateColumn): string
{
    return "{$alias}." . qi($dateColumn) . " < ?
        AND {$alias}.`salesid` IS NOT NULL
        AND LEFT({$alias}.`salesid`, CHAR_LENGTH(?)) = ?";
}

function key_join(string $leftAlias, string $rightAlias, array $keys): string
{
    $parts = [];

    foreach ($keys as $key) {
        $quoted = qi($key);
        $parts[] = "{$leftAlias}.{$quoted} <=> {$rightAlias}.{$quoted}";
    }

    return implode(' AND ', $parts);
}

function all_fields_equal(string $leftAlias, string $rightAlias, array $columns): string
{
    $parts = [];

    foreach ($columns as $column) {
        $quoted = qi($column);
        $parts[] = "{$leftAlias}.{$quoted} <=> {$rightAlias}.{$quoted}";
    }

    return implode(' AND ', $parts);
}

function numeric_summary_fields(array $columnNames): array
{
    $qtyColumn = null;
    $amountColumn = null;

    foreach (['salesqty', 'qty', 'quantity'] as $candidate) {
        if (in_array($candidate, $columnNames, true)) {
            $qtyColumn = $candidate;
            break;
        }
    }

    foreach (
        ['netamount', 'paymentamount', 'amount', 'debit', 'credit']
        as $candidate
    ) {
        if (in_array($candidate, $columnNames, true)) {
            $amountColumn = $candidate;
            break;
        }
    }

    return [$qtyColumn, $amountColumn];
}

function inspect_table_pair(
    mysqli $db,
    string $label,
    string $source,
    string $copy,
    string $cutoffDate,
    string $prefix,
    int $previewLimit = 1000
): array {
    $sourceColumns = table_columns($db, $source);
    $copyColumns   = table_columns($db, $copy);

    assert_same_structure($source, $sourceColumns, $copy, $copyColumns);

    $names = column_names($sourceColumns);

    if (!in_array('salesid', $names, true)) {
        throw new RuntimeException("Field salesid tidak ditemukan pada {$source}.");
    }

    $dateColumn = detect_date_column($names, $source);
    $keys = unique_key_columns($db, $source);

    if (!$keys) {
        throw new RuntimeException(
            "{$source} tidak memiliki PRIMARY KEY atau UNIQUE KEY."
        );
    }

    $copyKeys = unique_key_columns($db, $copy);

    if ($keys !== $copyKeys) {
        throw new RuntimeException(
            "Key unik {$source} dan {$copy} tidak sama."
        );
    }

    $insertable = insert_columns($sourceColumns);
    $cutoff = $cutoffDate . ' 00:00:00';
    $condition = candidate_condition('s', $dateColumn);
    $protected = protected_condition('s', $dateColumn);
    $join = key_join('c', 's', $keys);
    $equal = all_fields_equal('c', 's', $insertable);

    [$qtyColumn, $amountColumn] = numeric_summary_fields($names);

    $summaryFields = [
        'COUNT(*) AS candidate_rows',
        'COUNT(DISTINCT s.`salesid`) AS candidate_sales',
        'MIN(s.' . qi($dateColumn) . ') AS min_date',
        'MAX(s.' . qi($dateColumn) . ') AS max_date',
    ];

    $summaryFields[] = $qtyColumn
        ? 'COALESCE(SUM(s.' . qi($qtyColumn) . '), 0) AS total_qty'
        : '0 AS total_qty';

    $summaryFields[] = $amountColumn
        ? 'COALESCE(SUM(s.' . qi($amountColumn) . '), 0) AS total_amount'
        : '0 AS total_amount';

    $summary = query_one(
        $db,
        "SELECT " . implode(', ', $summaryFields) . "
         FROM " . qi($source) . " s
         WHERE {$condition}",
        'sss',
        [$cutoff, $prefix, $prefix]
    );

    $protectedSummary = query_one(
        $db,
        "SELECT
            COUNT(*) AS protected_rows,
            COUNT(DISTINCT s.`salesid`) AS protected_sales
         FROM " . qi($source) . " s
         WHERE {$protected}",
        'sss',
        [$cutoff, $prefix, $prefix]
    );

    $backupState = query_one(
        $db,
        "SELECT
            SUM(c." . qi($keys[0]) . " IS NULL) AS missing_rows,
            SUM(c." . qi($keys[0]) . " IS NOT NULL AND ({$equal})) AS exact_rows,
            SUM(c." . qi($keys[0]) . " IS NOT NULL AND NOT ({$equal})) AS conflict_rows
         FROM " . qi($source) . " s
         LEFT JOIN " . qi($copy) . " c
            ON {$join}
         WHERE {$condition}",
        'sss',
        [$cutoff, $prefix, $prefix]
    );

    $orderParts = ['s.' . qi($dateColumn) . ' DESC'];

    foreach ($keys as $key) {
        $orderParts[] = 's.' . qi($key) . ' DESC';
    }

    $previewRows = query_all(
        $db,
        "SELECT s.*
         FROM " . qi($source) . " s
         WHERE {$condition}
         ORDER BY " . implode(', ', $orderParts) . "
         LIMIT " . max(1, min($previewLimit, 1000)),
        'sss',
        [$cutoff, $prefix, $prefix]
    );

    /*
     * Ambil data dengan PRIMARY/UNIQUE KEY yang sama, tetapi isi field berbeda.
     * Hasil disusun menjadi pasangan source dan copy agar mudah dibandingkan
     * langsung pada halaman pengecekan.
     */
    $conflictSelectFields = [];

    foreach ($names as $fieldName) {
        $conflictSelectFields[] =
            's.' . qi($fieldName) . ' AS ' . qi('source__' . $fieldName);
        $conflictSelectFields[] =
            'c.' . qi($fieldName) . ' AS ' . qi('copy__' . $fieldName);
    }

    $conflictPreviewLimit = max(1, min($previewLimit, 1000));
    $conflictRawRows = query_all(
        $db,
        "SELECT " . implode(', ', $conflictSelectFields) . "
         FROM " . qi($source) . " s
         INNER JOIN " . qi($copy) . " c
            ON {$join}
         WHERE {$condition}
           AND NOT ({$equal})
         ORDER BY " . implode(', ', $orderParts) . "
         LIMIT {$conflictPreviewLimit}",
        'sss',
        [$cutoff, $prefix, $prefix]
    );

    $conflictPreviewRows = [];

    foreach ($conflictRawRows as $rawRow) {
        $sourceRow = [];
        $copyRow = [];
        $differentFields = [];
        $keyValues = [];

        foreach ($names as $fieldName) {
            $sourceValue = $rawRow['source__' . $fieldName] ?? null;
            $copyValue = $rawRow['copy__' . $fieldName] ?? null;

            $sourceRow[$fieldName] = $sourceValue;
            $copyRow[$fieldName] = $copyValue;

            if ($sourceValue !== $copyValue) {
                $differentFields[] = $fieldName;
            }
        }

        foreach ($keys as $keyName) {
            $keyValues[$keyName] = $sourceRow[$keyName] ?? null;
        }

        $conflictPreviewRows[] = [
            'key_values'       => $keyValues,
            'different_fields' => $differentFields,
            'source'           => $sourceRow,
            'copy'             => $copyRow,
        ];
    }

    $candidateRows = (int) ($summary['candidate_rows'] ?? 0);
    $firstKey = $keys[0];

    $keyAggregate = query_one(
        $db,
        "SELECT
            MIN(CAST(s." . qi($firstKey) . " AS CHAR)) AS min_key,
            MAX(CAST(s." . qi($firstKey) . " AS CHAR)) AS max_key
         FROM " . qi($source) . " s
         WHERE {$condition}",
        'sss',
        [$cutoff, $prefix, $prefix]
    );

    $result = [
        'label'          => $label,
        'source'         => $source,
        'copy'           => $copy,
        'date_column'    => $dateColumn,
        'source_columns' => $sourceColumns,
        'copy_columns'   => $copyColumns,
        'field_names'    => $names,
        'insert_columns' => $insertable,
        'key_columns'    => $keys,
        'source_engine'  => table_engine($db, $source),
        'copy_engine'    => table_engine($db, $copy),

        'candidate_rows'  => $candidateRows,
        'candidate_sales' => (int) ($summary['candidate_sales'] ?? 0),
        'protected_rows'  => (int) ($protectedSummary['protected_rows'] ?? 0),
        'protected_sales' => (int) ($protectedSummary['protected_sales'] ?? 0),
        'missing_rows'    => (int) ($backupState['missing_rows'] ?? 0),
        'exact_rows'      => (int) ($backupState['exact_rows'] ?? 0),
        'conflict_rows'   => (int) ($backupState['conflict_rows'] ?? 0),
        'min_date'        => (string) ($summary['min_date'] ?? ''),
        'max_date'        => (string) ($summary['max_date'] ?? ''),
        'total_qty'       => (float) ($summary['total_qty'] ?? 0),
        'total_amount'    => (float) ($summary['total_amount'] ?? 0),
        'min_key'         => (string) ($keyAggregate['min_key'] ?? ''),
        'max_key'         => (string) ($keyAggregate['max_key'] ?? ''),

        'preview_rows'            => $previewRows,
        'preview_limit'           => $previewLimit,
        'conflict_preview_rows'   => $conflictPreviewRows,
        'conflict_preview_limit'  => $conflictPreviewLimit,

        'copy_insert_triggers'   => trigger_count($db, $copy, 'INSERT'),
        'source_delete_triggers' => trigger_count($db, $source, 'DELETE'),
    ];

    $result['signature'] = table_pair_signature($result);

    return $result;
}

function table_pair_signature(array $pair): string
{
    $signatureData = [
        'source'          => $pair['source'],
        'copy'            => $pair['copy'],
        'date_column'     => $pair['date_column'],
        'key_columns'     => $pair['key_columns'],
        'field_names'     => $pair['field_names'],
        'candidate_rows'  => $pair['candidate_rows'],
        'candidate_sales' => $pair['candidate_sales'],
        'protected_rows'  => $pair['protected_rows'],
        'missing_rows'    => $pair['missing_rows'],
        'exact_rows'      => $pair['exact_rows'],
        'conflict_rows'   => $pair['conflict_rows'],
        'min_date'        => $pair['min_date'],
        'max_date'        => $pair['max_date'],
        'min_key'         => $pair['min_key'],
        'max_key'         => $pair['max_key'],
        'total_qty'       => round((float) $pair['total_qty'], 6),
        'total_amount'    => round((float) $pair['total_amount'], 6),
    ];

    return hash(
        'sha256',
        json_encode(
            $signatureData,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: ''
    );
}

function pair_blockers(array $pair, bool $forDelete = false): array
{
    $reasons = [];

    if ($pair['source_engine'] !== 'INNODB' || $pair['copy_engine'] !== 'INNODB') {
        $reasons[] = "{$pair['source']} dan {$pair['copy']} wajib menggunakan InnoDB.";
    }

    if ($pair['key_columns'] === []) {
        $reasons[] = "{$pair['source']} tidak memiliki key unik.";
    }

    if ((int) $pair['copy_insert_triggers'] > 0) {
        $reasons[] = "{$pair['copy']} memiliki trigger INSERT.";
    }

    if ($forDelete && (int) $pair['source_delete_triggers'] > 0) {
        $reasons[] = "{$pair['source']} memiliki trigger DELETE.";
    }

    if ($forDelete && (int) $pair['missing_rows'] > 0) {
        $reasons[] = "{$pair['source']}: masih ada data yang belum masuk ke {$pair['copy']}.";
    }

    return $reasons;
}

function insert_missing_rows(
    mysqli $db,
    array $pair,
    string $cutoffDate,
    string $prefix
): int {
    $cutoff = $cutoffDate . ' 00:00:00';
    $columns = $pair['insert_columns'];
    $keys = $pair['key_columns'];

    $quotedColumns = array();
    $sourceColumns = array();

    foreach ($columns as $column) {
        $quotedColumns[] = qi($column);
        $sourceColumns[] = 's.' . qi($column);
    }

    $columnList = implode(', ', $quotedColumns);
    $sourceList = implode(', ', $sourceColumns);

    $join = key_join('c', 's', $keys);
    $condition = candidate_condition('s', $pair['date_column']);

    /*
     * Baris dengan PRIMARY/UNIQUE KEY yang sudah ada tidak ikut SELECT.
     * Karena itu data copy tidak di-overwrite dan duplikat otomatis dilewati.
     */
    $sql = "INSERT INTO " . qi($pair['copy']) . " ({$columnList})
            SELECT {$sourceList}
            FROM " . qi($pair['source']) . " s
            LEFT JOIN " . qi($pair['copy']) . " c
                ON {$join}
            WHERE {$condition}
              AND c." . qi($keys[0]) . " IS NULL";

    return execute_sql(
        $db,
        $sql,
        'sss',
        [$cutoff, $prefix, $prefix]
    );
}

function verify_backup(
    mysqli $db,
    array $pair,
    string $cutoffDate,
    string $prefix
): array {
    $cutoff = $cutoffDate . ' 00:00:00';
    $keys = $pair['key_columns'];
    $columns = $pair['insert_columns'];

    $join = key_join('c', 's', $keys);
    $equal = all_fields_equal('c', 's', $columns);
    $condition = candidate_condition('s', $pair['date_column']);

    $row = query_one(
        $db,
        "SELECT
            COUNT(*) AS source_rows,
            SUM(c." . qi($keys[0]) . " IS NULL) AS missing_rows,
            SUM(c." . qi($keys[0]) . " IS NOT NULL AND ({$equal})) AS exact_rows,
            SUM(c." . qi($keys[0]) . " IS NOT NULL AND NOT ({$equal})) AS conflict_rows
         FROM " . qi($pair['source']) . " s
         LEFT JOIN " . qi($pair['copy']) . " c
            ON {$join}
         WHERE {$condition}",
        'sss',
        [$cutoff, $prefix, $prefix]
    );

    return [
        'source_rows'   => (int) ($row['source_rows'] ?? 0),
        'missing_rows'  => (int) ($row['missing_rows'] ?? 0),
        'exact_rows'    => (int) ($row['exact_rows'] ?? 0),
        'conflict_rows' => (int) ($row['conflict_rows'] ?? 0),
    ];
}

function verification_complete(array $verify, int $candidateRows): bool
{
    return $verify['source_rows'] === $candidateRows
        && $verify['missing_rows'] === 0
        && ($verify['exact_rows'] + $verify['conflict_rows']) === $candidateRows;
}

function delete_verified_rows(
    mysqli $db,
    array $pair,
    string $cutoffDate,
    string $prefix
): int {
    $cutoff = $cutoffDate . ' 00:00:00';
    $keys = $pair['key_columns'];
    $columns = $pair['insert_columns'];

    $join = key_join('c', 's', $keys);
    $equal = all_fields_equal('c', 's', $columns);
    $condition = candidate_condition('s', $pair['date_column']);

    /*
     * Hanya baris yang identik dengan copy yang dihapus.
     * Key sama dengan isi berbeda tetap berada di tabel utama (dilewati).
     */
    $sql = "DELETE s
            FROM " . qi($pair['source']) . " s
            INNER JOIN " . qi($pair['copy']) . " c
                ON {$join}
               AND ({$equal})
            WHERE {$condition}";

    return execute_sql(
        $db,
        $sql,
        'sss',
        [$cutoff, $prefix, $prefix]
    );
}

/*
 * Menghapus hanya baris konflik yang dipilih dari tabel utama `sales`.
 * Tabel `salescopy`, `salesdetail`, dan `salespayments` tidak disentuh.
 */
function delete_selected_sales_conflicts(
    mysqli $db,
    array $pair,
    string $cutoffDate,
    string $prefix,
    array $selectedKeyRows
): int {
    if (strtolower((string) ($pair['source'] ?? '')) !== 'sales') {
        throw new RuntimeException('Delete manual hanya diizinkan untuk tabel sales.');
    }

    if (strtolower((string) ($pair['copy'] ?? '')) !== 'salescopy') {
        throw new RuntimeException('Pasangan tabel salescopy tidak valid.');
    }

    $keys = $pair['key_columns'];

    if (!$keys) {
        throw new RuntimeException('Tabel sales tidak memiliki key unik.');
    }

    $cutoff = $cutoffDate . ' 00:00:00';
    $join = key_join('c', 's', $keys);
    $equal = all_fields_equal('c', 's', $pair['insert_columns']);
    $condition = candidate_condition('s', $pair['date_column']);
    $deletedTotal = 0;

    foreach ($selectedKeyRows as $keyValues) {
        $keyConditions = [];
        $params = [$cutoff, $prefix, $prefix];
        $types = 'sss';

        foreach ($keys as $keyColumn) {
            if (!array_key_exists($keyColumn, $keyValues)) {
                throw new RuntimeException('Nilai key baris sales tidak lengkap.');
            }

            $keyConditions[] = 's.' . qi($keyColumn) . ' <=> ?';
            $params[] = $keyValues[$keyColumn];
            $types .= 's';
        }

        $sql = "DELETE s
                FROM " . qi($pair['source']) . " s
                INNER JOIN " . qi($pair['copy']) . " c
                    ON {$join}
                WHERE {$condition}
                  AND NOT ({$equal})
                  AND " . implode(' AND ', $keyConditions);

        $affected = execute_sql($db, $sql, $types, $params);

        if ($affected !== 1) {
            throw new RuntimeException(
                'Salah satu baris sales tidak lagi cocok dengan data konflik. Delete dibatalkan.'
            );
        }

        $deletedTotal += $affected;
    }

    return $deletedTotal;
}

function candidate_count(
    mysqli $db,
    array $pair,
    string $cutoffDate,
    string $prefix
): int {
    $cutoff = $cutoffDate . ' 00:00:00';

    $row = query_one(
        $db,
        "SELECT COUNT(*) AS jumlah
         FROM " . qi($pair['source']) . " s
         WHERE " . candidate_condition('s', $pair['date_column']),
        'sss',
        [$cutoff, $prefix, $prefix]
    );

    return (int) ($row['jumlah'] ?? 0);
}


function find_column_name(array $columnNames, array $candidates): string
{
    $lookup = [];

    foreach ($columnNames as $columnName) {
        $lookup[strtolower((string) $columnName)] = (string) $columnName;
    }

    foreach ($candidates as $candidate) {
        $candidate = strtolower((string) $candidate);

        if (isset($lookup[$candidate])) {
            return $lookup[$candidate];
        }
    }

    return '';
}

function inventory_pair_meta(mysqli $db): array
{
    $inventory = detect_table($db, ['inventory'], 'inventory');
    $inventoryCopy = detect_table($db, ['inventorycopy'], 'inventorycopy');

    $inventoryColumns = table_columns($db, $inventory);
    $copyColumns = table_columns($db, $inventoryCopy);

    assert_same_structure(
        $inventory,
        $inventoryColumns,
        $inventoryCopy,
        $copyColumns
    );

    $fieldNames = column_names($inventoryColumns);
    $copyFieldNames = column_names($copyColumns);

    /*
     * Mapping referensi:
     * sales.salesid = inventory.transid = inventorycopy.transid
     * Nilainya sama, hanya nama field pada tabel inventory berbeda.
     */
    $transIdColumn = find_column_name($fieldNames, ['transid']);
    $copyTransIdColumn = find_column_name($copyFieldNames, ['transid']);

    if ($transIdColumn === '' || $copyTransIdColumn === '') {
        throw new RuntimeException(
            'Field transid wajib tersedia pada inventory dan inventorycopy.'
        );
    }

    if (strtolower($transIdColumn) !== strtolower($copyTransIdColumn)) {
        throw new RuntimeException(
            'Nama field transid pada inventory dan inventorycopy tidak sama.'
        );
    }

    $keys = unique_key_columns($db, $inventory);
    $copyKeys = unique_key_columns($db, $inventoryCopy);

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

    return [
        'source'                 => $inventory,
        'copy'                   => $inventoryCopy,
        'source_columns'         => $inventoryColumns,
        'copy_columns'           => $copyColumns,
        'field_names'            => $fieldNames,
        'insert_columns'         => insert_columns($inventoryColumns),
        'key_columns'            => $keys,
        'transid_column'         => $transIdColumn,
        'source_engine'          => table_engine($db, $inventory),
        'copy_engine'            => table_engine($db, $inventoryCopy),
        'source_insert_triggers' => trigger_count($db, $inventory, 'INSERT'),
    ];
}

function inventory_salesid_preview(
    mysqli $db,
    array $pair,
    string $salesId,
    int $previewLimit = 1000
): array {
    $salesId = trim($salesId);

    if ($salesId === '') {
        throw new RuntimeException('SalesID referensi inventory tidak boleh kosong.');
    }

    $previewLimit = max(1, min($previewLimit, 5000));
    $transIdColumn = $pair['transid_column'];

    $countRow = query_one(
        $db,
        "SELECT COUNT(*) AS jumlah
         FROM " . qi($pair['copy']) . " c
         WHERE c." . qi($transIdColumn) . " = ?",
        's',
        [$salesId]
    );

    $copyRowCount = (int) ($countRow['jumlah'] ?? 0);

    if ($copyRowCount > $previewLimit) {
        throw new RuntimeException(
            'SalesID ' . $salesId . ' memiliki ' . $copyRowCount
            . ' baris pada inventorycopy. Batas preview adalah ' . $previewLimit
            . ' baris agar data yang diproses selalu sama dengan data yang ditampilkan.'
        );
    }

    $keys = $pair['key_columns'];
    $columns = $pair['insert_columns'];
    $join = key_join('i', 'c', $keys);
    $equal = all_fields_equal('i', 'c', $columns);
    $firstKey = $keys[0];

    $selectFields = [];

    foreach ($pair['field_names'] as $fieldName) {
        $selectFields[] =
            'c.' . qi($fieldName) . ' AS ' . qi('copy__' . $fieldName);
    }

    $selectFields[] =
        "CASE
            WHEN i." . qi($firstKey) . " IS NULL THEN 'missing'
            WHEN ({$equal}) THEN 'exact'
            ELSE 'conflict'
         END AS " . qi('__inventory_status');

    $orderParts = [];

    foreach ($keys as $key) {
        $orderParts[] = 'c.' . qi($key) . ' ASC';
    }

    if (!$orderParts) {
        $orderParts[] = 'c.' . qi($transIdColumn) . ' ASC';
    }

    $rawRows = query_all(
        $db,
        "SELECT " . implode(', ', $selectFields) . "
         FROM " . qi($pair['copy']) . " c
         LEFT JOIN " . qi($pair['source']) . " i
           ON {$join}
         WHERE c." . qi($transIdColumn) . " = ?
         ORDER BY " . implode(', ', $orderParts),
        's',
        [$salesId]
    );

    $rows = [];
    $missingRows = 0;
    $exactRows = 0;
    $conflictRows = 0;

    foreach ($rawRows as $rawRow) {
        $data = [];

        foreach ($pair['field_names'] as $fieldName) {
            $data[$fieldName] = $rawRow['copy__' . $fieldName] ?? null;
        }

        $status = (string) ($rawRow['__inventory_status'] ?? 'conflict');

        if ($status === 'missing') {
            $missingRows++;
        } elseif ($status === 'exact') {
            $exactRows++;
        } else {
            $status = 'conflict';
            $conflictRows++;
        }

        $rows[] = [
            'status' => $status,
            'data'   => $data,
        ];
    }

    $signature = hash(
        'sha256',
        serialize([
            'salesid' => $salesId,
            'rows'    => $rows,
        ])
    );

    return [
        'salesid'        => $salesId,
        'source'         => $pair['source'],
        'copy'           => $pair['copy'],
        'field_names'    => $pair['field_names'],
        'key_columns'    => $keys,
        'copy_rows'      => count($rows),
        'missing_rows'   => $missingRows,
        'exact_rows'     => $exactRows,
        'conflict_rows'  => $conflictRows,
        'rows'           => $rows,
        'preview_limit'  => $previewLimit,
        'signature'      => $signature,
    ];
}

function process_inventory_salesid_from_copy(
    mysqli $db,
    array $pair,
    string $salesId,
    string $expectedSignature
): array {
    if ($pair['source_engine'] !== 'INNODB' || $pair['copy_engine'] !== 'INNODB') {
        throw new RuntimeException(
            'Tabel inventory dan inventorycopy wajib menggunakan InnoDB.'
        );
    }

    if ((int) $pair['source_insert_triggers'] > 0) {
        throw new RuntimeException(
            'Tabel inventory memiliki trigger INSERT. Proses berdasarkan SalesID/transid dibatalkan.'
        );
    }

    $before = inventory_salesid_preview($db, $pair, $salesId, 1000);

    if ($expectedSignature === '' || !hash_equals($expectedSignature, $before['signature'])) {
        throw new RuntimeException(
            'Data inventorycopy berubah setelah pengecekan. Tekan Cek SalesID ulang sebelum Proses.'
        );
    }

    if ((int) $before['copy_rows'] === 0) {
        throw new RuntimeException(
            'Tidak ada data inventorycopy dengan transid yang sama dengan SalesID ' . $salesId . '.'
        );
    }

    if ((int) $before['conflict_rows'] > 0) {
        throw new RuntimeException(
            'Ada key inventory yang sama tetapi isi berbeda. Proses dibatalkan agar tidak overwrite data inventory.'
        );
    }

    $columns = $pair['insert_columns'];
    $keys = $pair['key_columns'];
    $quotedColumns = [];
    $copyColumns = [];

    foreach ($columns as $column) {
        $quotedColumns[] = qi($column);
        $copyColumns[] = 'c.' . qi($column);
    }

    $columnList = implode(', ', $quotedColumns);
    $copyList = implode(', ', $copyColumns);
    $join = key_join('i', 'c', $keys);

    $insertSql = "INSERT INTO " . qi($pair['source']) . " ({$columnList})
                  SELECT {$copyList}
                  FROM " . qi($pair['copy']) . " c
                  LEFT JOIN " . qi($pair['source']) . " i
                    ON {$join}
                  WHERE c." . qi($pair['transid_column']) . " = ?
                    AND i." . qi($keys[0]) . " IS NULL";

    $insertedRows = execute_sql($db, $insertSql, 's', [$salesId]);
    $after = inventory_salesid_preview($db, $pair, $salesId, 1000);

    if ((int) $after['missing_rows'] > 0) {
        throw new RuntimeException(
            'Proses inventory belum lengkap. Masih ada '
            . (int) $after['missing_rows']
            . ' baris inventorycopy yang belum masuk ke inventory.'
        );
    }

    if ((int) $after['conflict_rows'] > 0) {
        throw new RuntimeException(
            'Setelah proses masih ditemukan key sama dengan isi berbeda pada inventory.'
        );
    }

    return [
        'salesid'        => $salesId,
        'copy_rows'      => (int) $before['copy_rows'],
        'requested_rows' => (int) $before['missing_rows'],
        'already_exact'  => (int) $before['exact_rows'],
        'inserted_rows'  => $insertedRows,
        'completed_at'   => date('Y-m-d H:i:s'),
        'preview_after'  => $after,
    ];
}

function resolve_sales_conflict_salesid(
    array $salesPreview,
    string $token,
    string $secret
): string {
    $keyValues = parse_conflict_key_token(
        trim($token),
        $salesPreview['key_columns'],
        $secret
    );

    $fingerprint = conflict_key_fingerprint(
        $keyValues,
        $salesPreview['key_columns']
    );

    foreach ($salesPreview['conflict_preview_rows'] as $conflictRow) {
        $rowFingerprint = conflict_key_fingerprint(
            $conflictRow['key_values'],
            $salesPreview['key_columns']
        );

        if (!hash_equals($rowFingerprint, $fingerprint)) {
            continue;
        }

        $copySalesId = trim((string) ($conflictRow['copy']['salesid'] ?? ''));
        $sourceSalesId = trim((string) ($conflictRow['source']['salesid'] ?? ''));
        $salesId = $copySalesId !== '' ? $copySalesId : $sourceSalesId;

        if ($salesId === '') {
            throw new RuntimeException(
                'SalesID pada data konflik kosong sehingga inventorycopy tidak dapat dicek.'
            );
        }

        return $salesId;
    }

    throw new RuntimeException(
        'Data konflik SalesID tidak ditemukan atau sudah berubah. Jalankan Cek Sales ulang.'
    );
}

function log_action(array $payload): void
{
    $directory = __DIR__ . '/logs';

    if (!is_dir($directory)) {
        @mkdir($directory, 0750, true);
    }

    $encoded = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($encoded !== false) {
        @file_put_contents(
            $directory . '/sales_step_by_step.log',
            $encoded . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}

/*
|--------------------------------------------------------------------------
| map.php dan mapsales.php
|--------------------------------------------------------------------------
*/
$mapFile = __DIR__ . '/map.php';
$mapSalesFile = __DIR__ . '/mapsales.php';

if (!is_file($mapFile)) {
    die('map.php tidak ditemukan.');
}

if (!is_file($mapSalesFile)) {
    die('mapsales.php tidak ditemukan.');
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

$mapSalesReturn = require $mapSalesFile;
$salesMap = [];

if (is_array($mapSalesReturn)) {
    $salesMap = $mapSalesReturn;
} elseif (isset($mapsales) && is_array($mapsales)) {
    $salesMap = $mapsales;
} elseif (isset($mapSales) && is_array($mapSales)) {
    $salesMap = $mapSales;
} elseif (isset($salesPrefixMap) && is_array($salesPrefixMap)) {
    $salesMap = $salesPrefixMap;
}

if (!$storeMap) {
    die('Mapping koneksi dari map.php kosong.');
}

if (!$salesMap) {
    die('Mapping prefix dari mapsales.php kosong.');
}

$dropdownStores = [];

foreach ($storeMap as $storeName => $storeConfig) {
    if (!is_array($storeConfig)) {
        continue;
    }

    $storeName = (string) $storeName;

    if (!array_key_exists($storeName, $salesMap)) {
        continue;
    }

    $dropdownStores[$storeName] = [
        'config' => $storeConfig,
        'prefix' => strtoupper(trim((string) $salesMap[$storeName])),
    ];
}

uksort($dropdownStores, 'strnatcasecmp');

/*
|--------------------------------------------------------------------------
| State dan aksi
|--------------------------------------------------------------------------
*/
$state = $_SESSION['sales_step_state'] ?? [];

/* State versi lama dihentikan agar alur inventory baru tidak tercampur. */
if ($state && (int) ($state['workflow_version'] ?? 0) !== 6) {
    unset($_SESSION['sales_step_state']);
    $state = [];
}

$action = isset($_POST['check_salesid_key'])
    ? 'check_inventory_salesid'
    : (string) ($_POST['action'] ?? '');

$selectedStore = trim((string) ($_POST['store'] ?? ($state['store'] ?? '')));
$cutoffDate = trim((string) ($_POST['cutoff_date'] ?? ($state['cutoff_date'] ?? '')));
$prefix = '';

$detailPreview = null;
$paymentPreview = null;
$salesPreview = null;
$backupResult = $state['backup_result'] ?? null;
$pendingDeleteResult = isset($state['delete_result']) && is_array($state['delete_result'])
    ? $state['delete_result']
    : null;
$deleteResult = !empty($state['sales_cleanup_done'])
    && isset($state['delete_result'])
    && is_array($state['delete_result'])
        ? $state['delete_result']
        : null;
$inventorySalesPreview = null;
$inventorySalesPreviewChanged = false;
$inventorySalesResult = isset($state['inventory_salesid_result'])
    && is_array($state['inventory_salesid_result'])
        ? $state['inventory_salesid_result']
        : null;
$errorMessage = '';
$errorType = '';
$successMessage = '';

try {
    if ($action !== '') {
        $postedCsrf = (string) ($_POST['csrf_token'] ?? '');

        if (!hash_equals($csrfToken, $postedCsrf)) {
            throw new RuntimeException('Token keamanan tidak valid. Muat ulang halaman.');
        }

        if ($action === 'reset') {
            unset($_SESSION['sales_step_state']);
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }

        /*
         * User memilih selesai setelah SalesID inventory sudah identik.
         * State maintenance dibersihkan lalu kembali ke halaman utama/dashboard.
         */
        if ($action === 'finish') {
            $finishState = isset($_SESSION['sales_step_state'])
                && is_array($_SESSION['sales_step_state'])
                    ? $_SESSION['sales_step_state']
                    : [];

            log_action([
                'time'        => date('c'),
                'status'      => 'USER_FINISH_TO_DASHBOARD',
                'user'        => $operatorName,
                'store'       => (string) ($finishState['store'] ?? $selectedStore),
                'cutoff_date' => (string) ($finishState['cutoff_date'] ?? $cutoffDate),
                'salesid'     => (string) ($finishState['inventory_checked_salesid'] ?? ''),
            ]);

            unset($_SESSION['sales_step_state']);
            header('Location: ../dashboard.php');
            exit;
        }

        if (!isset($dropdownStores[$selectedStore])) {
            throw new RuntimeException('Toko belum dipilih atau tidak valid.');
        }

        $prefix = $dropdownStores[$selectedStore]['prefix'];

        if ($prefix === '') {
            throw new RuntimeException(
                'Prefix salesid toko ini masih kosong di mapsales.php.'
            );
        }

        if (!valid_date($cutoffDate)) {
            throw new RuntimeException('Tanggal batas tidak valid.');
        }

        if ($cutoffDate > date('Y-m-d')) {
            throw new RuntimeException('Tanggal batas tidak boleh berada di masa depan.');
        }

        $db = connect_store($dropdownStores[$selectedStore]['config']);

        try {
            $tablePairs = [
                'detail' => [
                    'label'  => 'Sales Detail',
                    'source' => detect_table($db, ['salesdetail'], 'salesdetail'),
                    'copy'   => detect_table($db, ['salesdetailcopy'], 'salesdetailcopy'),
                ],
                'payment' => [
                    'label'  => 'Sales Payment',
                    'source' => detect_table(
                        $db,
                        ['salespayments', 'salespayment'],
                        'salespayments'
                    ),
                    'copy'   => detect_table(
                        $db,
                        ['salespaymentscopy', 'salespaymentcopy'],
                        'salespaymentscopy'
                    ),
                ],
                'sales' => [
                    'label'  => 'Sales',
                    'source' => detect_table($db, ['sales'], 'sales'),
                    'copy'   => detect_table($db, ['salescopy'], 'salescopy'),
                ],
            ];

            $inspectPair = function (string $key, int $limit = 1000) use (
                $db,
                $tablePairs,
                $cutoffDate,
                $prefix
            ): array {
                $config = $tablePairs[$key];

                return inspect_table_pair(
                    $db,
                    $config['label'],
                    $config['source'],
                    $config['copy'],
                    $cutoffDate,
                    $prefix,
                    $limit
                );
            };

            if ($action === 'check_inventory_salesid') {
                if (
                    !$state
                    || ($state['store'] ?? '') !== $selectedStore
                    || ($state['cutoff_date'] ?? '') !== $cutoffDate
                    || ($state['prefix'] ?? '') !== $prefix
                    || empty($state['sales_ready'])
                ) {
                    throw new RuntimeException(
                        'Cek sales terlebih dahulu sebelum Cek SalesID inventory.'
                    );
                }

                $salesPreview = $inspectPair('sales', 1000);

                if (!hash_equals(
                    (string) ($state['sales_signature'] ?? ''),
                    (string) $salesPreview['signature']
                )) {
                    throw new RuntimeException(
                        'Data sales berubah. Jalankan Cek Sales ulang sebelum Cek SalesID.'
                    );
                }

                $conflictToken = trim((string) ($_POST['check_salesid_key'] ?? ''));

                if ($conflictToken === '') {
                    throw new RuntimeException('Data konflik SalesID tidak valid.');
                }

                $salesId = resolve_sales_conflict_salesid(
                    $salesPreview,
                    $conflictToken,
                    $csrfToken
                );

                $inventoryPair = inventory_pair_meta($db);
                $inventorySalesPreview = inventory_salesid_preview(
                    $db,
                    $inventoryPair,
                    $salesId,
                    1000
                );

                $state['inventory_checked_salesid'] = $salesId;
                $state['inventory_checked_conflict_token'] = $conflictToken;
                $state['inventory_salesid_signature'] = $inventorySalesPreview['signature'];
                $state['inventory_salesid_result'] = null;
                $state['created_at'] = time();
                $_SESSION['sales_step_state'] = $state;
                $inventorySalesResult = null;

                log_action([
                    'time'        => date('c'),
                    'status'      => 'INVENTORY_SALESID_CHECK',
                    'user'        => $operatorName,
                    'store'       => $selectedStore,
                    'cutoff_date' => $cutoffDate,
                    'salesid'     => $salesId,
                    'copy_rows'   => (int) $inventorySalesPreview['copy_rows'],
                    'missing_rows'=> (int) $inventorySalesPreview['missing_rows'],
                    'exact_rows'  => (int) $inventorySalesPreview['exact_rows'],
                    'conflict_rows' => (int) $inventorySalesPreview['conflict_rows'],
                ]);

                if ((int) $inventorySalesPreview['copy_rows'] === 0) {
                    $successMessage =
                        'SalesID ' . $salesId . ' sudah dicek, tetapi tidak ditemukan di inventorycopy.';
                } else {
                    $successMessage =
                        'SalesID ' . $salesId . ' ditemukan di inventorycopy. '
                        . 'Periksa data yang ditampilkan sebelum menekan Proses.';
                }
            } elseif ($action === 'process_inventory_salesid') {
                if (
                    !$state
                    || ($state['store'] ?? '') !== $selectedStore
                    || ($state['cutoff_date'] ?? '') !== $cutoffDate
                    || ($state['prefix'] ?? '') !== $prefix
                    || empty($state['sales_ready'])
                ) {
                    throw new RuntimeException(
                        'Cek SalesID inventory terlebih dahulu sebelum Proses.'
                    );
                }

                $salesId = trim((string) ($_POST['inventory_salesid'] ?? ''));
                $checkedSalesId = trim((string) ($state['inventory_checked_salesid'] ?? ''));
                $expectedSignature = (string) ($state['inventory_salesid_signature'] ?? '');

                if ($salesId === '' || $checkedSalesId === '' || $salesId !== $checkedSalesId) {
                    throw new RuntimeException(
                        'SalesID yang akan diproses tidak sama dengan hasil Cek SalesID terakhir.'
                    );
                }

                if ($expectedSignature === '') {
                    throw new RuntimeException(
                        'Signature hasil Cek SalesID tidak tersedia. Cek SalesID ulang.'
                    );
                }

                $inventoryPair = inventory_pair_meta($db);
                $lockName = 'inventory_salesid_' . substr(
                    hash('sha256', $selectedStore . '|' . $salesId),
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
                        'Proses inventory SalesID lain sedang berjalan untuk data ini.'
                    );
                }

                $transactionStarted = false;

                try {
                    if (!$db->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE')) {
                        throw new RuntimeException(
                            'Gagal mengatur isolation level inventory: ' . $db->error
                        );
                    }

                    if (!$db->begin_transaction()) {
                        throw new RuntimeException(
                            'Gagal memulai transaksi inventory: ' . $db->error
                        );
                    }
                    $transactionStarted = true;

                    $processResult = process_inventory_salesid_from_copy(
                        $db,
                        $inventoryPair,
                        $salesId,
                        $expectedSignature
                    );

                    if (!$db->commit()) {
                        throw new RuntimeException(
                            'COMMIT proses inventory berdasarkan SalesID gagal: ' . $db->error
                        );
                    }
                    $transactionStarted = false;

                    $inventorySalesPreview = $processResult['preview_after'];
                    unset($processResult['preview_after']);
                    $inventorySalesResult = $processResult;

                    $state['inventory_checked_salesid'] = $salesId;
                    $state['inventory_salesid_signature'] = $inventorySalesPreview['signature'];
                    $state['inventory_salesid_result'] = $inventorySalesResult;
                    $state['created_at'] = time();
                    $_SESSION['sales_step_state'] = $state;

                    /* Tetap tampilkan daftar konflik sales setelah proses inventory. */
                    $salesPreview = $inspectPair('sales', 1000);

                    log_action([
                        'time'        => date('c'),
                        'status'      => 'INVENTORY_SALESID_PROCESS_SUCCESS',
                        'user'        => $operatorName,
                        'store'       => $selectedStore,
                        'cutoff_date' => $cutoffDate,
                        'salesid'     => $salesId,
                        'result'      => $inventorySalesResult,
                    ]);

                    $successMessage =
                        'Proses SalesID ' . $salesId . ' selesai. '
                        . number_format(
                            (int) ($inventorySalesResult['inserted_rows'] ?? 0),
                            0,
                            ',',
                            '.'
                        )
                        . ' row berhasil di-insert dari inventorycopy ke inventory.';
                } catch (Throwable $inventoryError) {
                    if ($transactionStarted) {
                        @$db->rollback();
                    }

                    log_action([
                        'time'        => date('c'),
                        'status'      => 'INVENTORY_SALESID_PROCESS_FAILED',
                        'user'        => $operatorName,
                        'store'       => $selectedStore,
                        'cutoff_date' => $cutoffDate,
                        'salesid'     => $salesId,
                        'error'       => $inventoryError->getMessage(),
                    ]);

                    throw $inventoryError;
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
            } elseif ($action === 'delete_sales_conflicts') {
                if (
                    !$state
                    || ($state['store'] ?? '') !== $selectedStore
                    || ($state['cutoff_date'] ?? '') !== $cutoffDate
                    || ($state['prefix'] ?? '') !== $prefix
                    || empty($state['detail_ready'])
                    || empty($state['payment_ready'])
                    || empty($state['sales_ready'])
                ) {
                    throw new RuntimeException(
                        'Cek salesdetail, salespayments, dan sales terlebih dahulu.'
                    );
                }

                $postedTokens = $_POST['sales_conflict_keys'] ?? [];

                if (!is_array($postedTokens)) {
                    throw new RuntimeException('Pilihan data sales tidak valid.');
                }

                $postedTokens = array_values(array_unique(array_filter(
                    array_map('strval', $postedTokens),
                    static function (string $value): bool {
                        return trim($value) !== '';
                    }
                )));

                if (!$postedTokens) {
                    throw new RuntimeException('Centang minimal satu data sales yang akan dihapus.');
                }

                if (count($postedTokens) > 1000) {
                    throw new RuntimeException('Maksimal 1.000 data sales dalam satu proses delete.');
                }

                $lockName = 'sales_conflict_delete_' . substr(
                    hash('sha256', $selectedStore),
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
                        'Proses maintenance lain sedang berjalan untuk toko ini.'
                    );
                }

                $transactionStarted = false;

                try {
                    if (!$db->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE')) {
                        throw new RuntimeException(
                            'Gagal mengatur isolation level: ' . $db->error
                        );
                    }

                    if (!$db->begin_transaction()) {
                        throw new RuntimeException(
                            'Gagal memulai transaksi delete sales: ' . $db->error
                        );
                    }
                    $transactionStarted = true;

                    $detailPreview = $inspectPair('detail', 100);
                    $paymentPreview = $inspectPair('payment', 100);
                    $salesPreview = $inspectPair('sales', 1000);

                    $signatureChecks = [
                        'salesdetail' => [
                            (string) ($state['detail_signature'] ?? ''),
                            $detailPreview['signature'],
                        ],
                        'salespayments' => [
                            (string) ($state['payment_signature'] ?? ''),
                            $paymentPreview['signature'],
                        ],
                        'sales' => [
                            (string) ($state['sales_signature'] ?? ''),
                            $salesPreview['signature'],
                        ],
                    ];

                    foreach ($signatureChecks as $tableLabel => $signatures) {
                        if (!hash_equals($signatures[0], $signatures[1])) {
                            throw new RuntimeException(
                                "Data {$tableLabel} berubah. Lakukan pengecekan ulang."
                            );
                        }
                    }

                    if (
                        $salesPreview['source_engine'] !== 'INNODB'
                        || $salesPreview['copy_engine'] !== 'INNODB'
                    ) {
                        throw new RuntimeException(
                            'Tabel sales dan salescopy wajib menggunakan InnoDB.'
                        );
                    }

                    if ((int) $salesPreview['source_delete_triggers'] > 0) {
                        throw new RuntimeException(
                            'Tabel sales memiliki trigger DELETE. Delete manual dibatalkan.'
                        );
                    }

                    $allowedRows = [];

                    foreach ($salesPreview['conflict_preview_rows'] as $conflictRow) {
                        $fingerprint = conflict_key_fingerprint(
                            $conflictRow['key_values'],
                            $salesPreview['key_columns']
                        );

                        $allowedRows[$fingerprint] = [
                            'key_values' => $conflictRow['key_values'],
                        ];
                    }

                    $selectedKeyRows = [];

                    foreach ($postedTokens as $postedToken) {
                        $keyValues = parse_conflict_key_token(
                            trim($postedToken),
                            $salesPreview['key_columns'],
                            $csrfToken
                        );
                        $fingerprint = conflict_key_fingerprint(
                            $keyValues,
                            $salesPreview['key_columns']
                        );

                        if (!isset($allowedRows[$fingerprint])) {
                            throw new RuntimeException(
                                'Data yang dipilih tidak ditemukan pada daftar konflik terbaru.'
                            );
                        }

                        $selectedKeyRows[$fingerprint] =
                            $allowedRows[$fingerprint]['key_values'];
                    }

                    $deletedSalesConflicts = delete_selected_sales_conflicts(
                        $db,
                        $salesPreview,
                        $cutoffDate,
                        $prefix,
                        array_values($selectedKeyRows)
                    );

                    if (!$db->commit()) {
                        throw new RuntimeException(
                            'COMMIT delete data konflik sales gagal: ' . $db->error
                        );
                    }
                    $transactionStarted = false;

                    unset(
                        $state['inventory_checked_salesid'],
                        $state['inventory_checked_conflict_token'],
                        $state['inventory_salesid_signature'],
                        $state['inventory_salesid_result']
                    );
                    $inventorySalesPreview = null;
                    $inventorySalesResult = null;

                    $salesPreview = $inspectPair('sales', 1000);
                    $isCleanupFlow = !empty($state['cleanup_pending'])
                        && isset($state['delete_result'])
                        && is_array($state['delete_result']);

                    if ($isCleanupFlow) {
                        $pendingDeleteResult = $state['delete_result'];
                        $pendingDeleteResult['sales_deleted'] =
                            (int) ($pendingDeleteResult['sales_deleted'] ?? 0)
                            + $deletedSalesConflicts;
                        $pendingDeleteResult['manual_sales_deleted'] =
                            (int) ($pendingDeleteResult['manual_sales_deleted'] ?? 0)
                            + $deletedSalesConflicts;
                        $pendingDeleteResult['sales_remaining'] =
                            (int) ($salesPreview['candidate_rows'] ?? 0);

                        /*
                         * Tahap penyelesaian manual hanya berlaku untuk konflik
                         * tabel sales. Konflik detail/payment tetap dilaporkan
                         * sebagai data dilewati, sesuai perilaku maintenance lama.
                         */
                        $remainingCleanupRows =
                            (int) ($pendingDeleteResult['sales_remaining'] ?? 0);

                        if ($remainingCleanupRows === 0) {
                            $deleteResult = $pendingDeleteResult;
                            $state['cleanup_pending'] = false;
                            $state['sales_cleanup_done'] = true;
                            $state['delete_result'] = $pendingDeleteResult;
                            $state['backup_ready'] = false;
                            $state['backup_result'] = null;
                            $state['created_at'] = time();

                            $_SESSION['sales_step_state'] = $state;
                            $backupResult = null;
                            $pendingDeleteResult = null;
                            $detailPreview = null;
                            $paymentPreview = null;
                            $salesPreview = null;

                            log_action([
                                'time'        => date('c'),
                                'status'      => 'SALES_CLEANUP_COMPLETE',
                                'user'        => $operatorName,
                                'store'       => $selectedStore,
                                'cutoff_date' => $cutoffDate,
                                'prefix_kept' => $prefix,
                                'result'      => $deleteResult,
                            ]);

                            $successMessage =
                                'Semua Data Key Sama pada sales sudah diselesaikan. '
                                . 'Data salescopy tetap dipertahankan. Maintenance selesai.';
                        } else {
                            $state['sales_ready'] = true;
                            $state['sales_signature'] = $salesPreview['signature'];
                            $state['backup_ready'] = false;
                            $state['backup_result'] = null;
                            $state['cleanup_pending'] = true;
                            $state['delete_result'] = $pendingDeleteResult;
                            $state['created_at'] = time();
                            $_SESSION['sales_step_state'] = $state;
                            $backupResult = null;

                            $successMessage = number_format(
                                $deletedSalesConflicts,
                                0,
                                ',',
                                '.'
                            ) . ' data konflik sales berhasil dihapus. Masih ada '
                                . number_format(
                                    (int) ($pendingDeleteResult['sales_remaining'] ?? 0),
                                    0,
                                    ',',
                                    '.'
                                )
                                . ' Data Key Sama yang harus diselesaikan.';
                        }
                    } else {
                        $state['sales_ready'] = true;
                        $state['sales_signature'] = $salesPreview['signature'];
                        $state['backup_ready'] = false;
                        $state['backup_result'] = null;
                        $state['created_at'] = time();
                        $_SESSION['sales_step_state'] = $state;
                        $backupResult = null;

                        $successMessage = number_format(
                            $deletedSalesConflicts,
                            0,
                            ',',
                            '.'
                        ) . ' data konflik dari tabel sales berhasil dihapus. Tabel salescopy tidak diubah.';
                    }

                    log_action([
                        'time'        => date('c'),
                        'status'      => 'DELETE_SALES_CONFLICT_SUCCESS',
                        'user'        => $operatorName,
                        'store'       => $selectedStore,
                        'cutoff_date' => $cutoffDate,
                        'prefix_kept' => $prefix,
                        'deleted'     => $deletedSalesConflicts,
                        'remaining'   => is_array($salesPreview)
                            ? (int) ($salesPreview['candidate_rows'] ?? 0)
                            : 0,
                        'cleanup_flow'=> $isCleanupFlow,
                        'keys'        => array_values($selectedKeyRows),
                    ]);
                } catch (Throwable $manualDeleteError) {
                    if ($transactionStarted) {
                        @$db->rollback();
                    }

                    log_action([
                        'time'        => date('c'),
                        'status'      => 'DELETE_SALES_CONFLICT_FAILED',
                        'user'        => $operatorName,
                        'store'       => $selectedStore,
                        'cutoff_date' => $cutoffDate,
                        'prefix_kept' => $prefix,
                        'error'       => $manualDeleteError->getMessage(),
                    ]);

                    throw $manualDeleteError;
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
            } elseif ($action === 'check_detail') {
                $detailPreview = $inspectPair('detail');

                $_SESSION['sales_step_state'] = [
                    'workflow_version' => 6,
                    'store'             => $selectedStore,
                    'cutoff_date'       => $cutoffDate,
                    'prefix'            => $prefix,
                    'created_at'        => time(),
                    'detail_ready'      => true,
                    'detail_signature'  => $detailPreview['signature'],
                    'payment_ready'     => false,
                    'sales_ready'       => false,
                    'backup_ready'      => false,
                    'backup_result'     => null,
                ];

                $state = $_SESSION['sales_step_state'];
                $backupResult = null;
            } elseif ($action === 'check_payment') {
                if (
                    !$state
                    || ($state['store'] ?? '') !== $selectedStore
                    || ($state['cutoff_date'] ?? '') !== $cutoffDate
                    || ($state['prefix'] ?? '') !== $prefix
                    || empty($state['detail_ready'])
                ) {
                    throw new RuntimeException('Cek salesdetail terlebih dahulu.');
                }

                $detailPreview = $inspectPair('detail');

                if (!hash_equals(
                    (string) ($state['detail_signature'] ?? ''),
                    $detailPreview['signature']
                )) {
                    throw new RuntimeException(
                        'Data salesdetail berubah. Jalankan Cek Salesdetail ulang.'
                    );
                }

                $paymentPreview = $inspectPair('payment');
                $state['payment_ready'] = true;
                $state['payment_signature'] = $paymentPreview['signature'];
                $state['sales_ready'] = false;
                $state['backup_ready'] = false;
                $state['backup_result'] = null;
                $state['created_at'] = time();

                $_SESSION['sales_step_state'] = $state;
                $backupResult = null;
            } elseif ($action === 'check_sales') {
                if (
                    !$state
                    || ($state['store'] ?? '') !== $selectedStore
                    || ($state['cutoff_date'] ?? '') !== $cutoffDate
                    || ($state['prefix'] ?? '') !== $prefix
                    || empty($state['detail_ready'])
                    || empty($state['payment_ready'])
                ) {
                    throw new RuntimeException(
                        'Cek salesdetail dan salespayments terlebih dahulu.'
                    );
                }

                $detailPreview = $inspectPair('detail');
                $paymentPreview = $inspectPair('payment');

                if (!hash_equals(
                    (string) ($state['detail_signature'] ?? ''),
                    $detailPreview['signature']
                )) {
                    throw new RuntimeException('Data salesdetail berubah. Mulai cek ulang.');
                }

                if (!hash_equals(
                    (string) ($state['payment_signature'] ?? ''),
                    $paymentPreview['signature']
                )) {
                    throw new RuntimeException('Data salespayments berubah. Mulai cek ulang.');
                }

                $salesPreview = $inspectPair('sales');
                $state['sales_ready'] = true;
                $state['sales_signature'] = $salesPreview['signature'];
                $state['backup_ready'] = false;
                $state['backup_result'] = null;
                $state['created_at'] = time();

                $_SESSION['sales_step_state'] = $state;
                $backupResult = null;
            } elseif ($action === 'backup') {
                if (
                    !$state
                    || ($state['store'] ?? '') !== $selectedStore
                    || ($state['cutoff_date'] ?? '') !== $cutoffDate
                    || ($state['prefix'] ?? '') !== $prefix
                    || empty($state['detail_ready'])
                    || empty($state['payment_ready'])
                    || empty($state['sales_ready'])
                ) {
                    throw new RuntimeException(
                        'Cek salesdetail, salespayments, dan sales terlebih dahulu.'
                    );
                }

                if ((int) ($state['created_at'] ?? 0) < time() - 1800) {
                    throw new RuntimeException(
                        'Hasil pengecekan sudah lebih dari 30 menit. Mulai cek ulang.'
                    );
                }

                $detailPreview = $inspectPair('detail');
                $paymentPreview = $inspectPair('payment');
                $salesPreview = $inspectPair('sales');

                $signatureChecks = [
                    'salesdetail' => [
                        (string) ($state['detail_signature'] ?? ''),
                        $detailPreview['signature'],
                    ],
                    'salespayments' => [
                        (string) ($state['payment_signature'] ?? ''),
                        $paymentPreview['signature'],
                    ],
                    'sales' => [
                        (string) ($state['sales_signature'] ?? ''),
                        $salesPreview['signature'],
                    ],
                ];

                foreach ($signatureChecks as $tableLabel => $signatures) {
                    if (!hash_equals($signatures[0], $signatures[1])) {
                        throw new RuntimeException(
                            "Data {$tableLabel} berubah. Lakukan pengecekan ulang."
                        );
                    }
                }

                $blockers = array_merge(
                    pair_blockers($detailPreview, false),
                    pair_blockers($paymentPreview, false),
                    pair_blockers($salesPreview, false)
                );

                if ($blockers) {
                    throw new RuntimeException(implode(' ', $blockers));
                }

                $lockName = 'sales_backup_' . substr(
                    hash('sha256', $selectedStore),
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
                        'Proses backup lain sedang berjalan untuk toko ini.'
                    );
                }

                $transactionStarted = false;

                try {
                    if (!$db->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE')) {
                        throw new RuntimeException(
                            'Gagal mengatur isolation level: ' . $db->error
                        );
                    }

                    if (!$db->begin_transaction()) {
                        throw new RuntimeException(
                            'Gagal memulai transaksi: ' . $db->error
                        );
                    }
                    $transactionStarted = true;

                    $orderedPairs = [
                        'detail'  => $detailPreview,
                        'payment' => $paymentPreview,
                        'sales'   => $salesPreview,
                    ];
                    $insertedRows = [];
                    $verification = [];

                    foreach ($orderedPairs as $key => $pair) {
                        $insertedRows[$key] = insert_missing_rows(
                            $db,
                            $pair,
                            $cutoffDate,
                            $prefix
                        );

                        $verification[$key] = verify_backup(
                            $db,
                            $pair,
                            $cutoffDate,
                            $prefix
                        );

                        if (!verification_complete(
                            $verification[$key],
                            $pair['candidate_rows']
                        )) {
                            throw new RuntimeException(
                                "Verifikasi {$pair['copy']} gagal. Seluruh backup dibatalkan."
                            );
                        }
                    }

                    if (!$db->commit()) {
                        throw new RuntimeException('COMMIT backup gagal: ' . $db->error);
                    }
                    $transactionStarted = false;

                    $backupResult = ['completed_at' => date('Y-m-d H:i:s')];

                    foreach ($orderedPairs as $key => $pair) {
                        $verify = $verification[$key];
                        $inserted = $insertedRows[$key];

                        $backupResult[$key . '_source'] = $pair['source'];
                        $backupResult[$key . '_copy'] = $pair['copy'];
                        $backupResult[$key . '_total'] = $pair['candidate_rows'];
                        $backupResult[$key . '_inserted'] = $inserted;
                        $backupResult[$key . '_exact'] = $verify['exact_rows'];
                        $backupResult[$key . '_already'] = max(
                            0,
                            $verify['exact_rows'] - $inserted
                        );
                        $backupResult[$key . '_skipped'] = $verify['conflict_rows'];
                    }

                    $state['backup_ready'] = true;
                    $state['backup_result'] = $backupResult;
                    $state['backup_at'] = time();

                    $_SESSION['sales_step_state'] = $state;

                    log_action([
                        'time'        => date('c'),
                        'status'      => 'BACKUP_SUCCESS',
                        'user'        => $operatorName,
                        'store'       => $selectedStore,
                        'cutoff_date' => $cutoffDate,
                        'prefix_kept' => $prefix,
                        'result'      => $backupResult,
                    ]);

                    $successMessage =
                        'Backup tiga tabel berhasil. Primary/unique key yang sudah ada dilewati tanpa overwrite.';
                } catch (Throwable $backupError) {
                    if ($transactionStarted) {
                        @$db->rollback();
                    }

                    log_action([
                        'time'        => date('c'),
                        'status'      => 'BACKUP_FAILED',
                        'user'        => $operatorName,
                        'store'       => $selectedStore,
                        'cutoff_date' => $cutoffDate,
                        'prefix_kept' => $prefix,
                        'error'       => $backupError->getMessage(),
                    ]);

                    throw $backupError;
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
            } elseif ($action === 'delete') {
                if (
                    !$state
                    || ($state['store'] ?? '') !== $selectedStore
                    || ($state['cutoff_date'] ?? '') !== $cutoffDate
                    || ($state['prefix'] ?? '') !== $prefix
                    || empty($state['backup_ready'])
                    || empty($state['backup_result'])
                ) {
                    throw new RuntimeException(
                        'Backup tiga tabel belum berhasil. Delete ditolak.'
                    );
                }

                $confirmation = strtoupper(
                    trim((string) ($_POST['confirmation'] ?? ''))
                );

                $expected = 'DELETE '
                    . (int) ($state['backup_result']['detail_exact'] ?? -1)
                    . '-'
                    . (int) ($state['backup_result']['payment_exact'] ?? -1)
                    . '-'
                    . (int) ($state['backup_result']['sales_exact'] ?? -1);

                if ($confirmation !== $expected) {
                    throw new RuntimeException(
                        'Teks konfirmasi salah. Ketik persis: ' . $expected
                    );
                }

                $detailPreview = $inspectPair('detail');
                $paymentPreview = $inspectPair('payment');
                $salesPreview = $inspectPair('sales');

                $orderedPairs = [
                    'detail'  => $detailPreview,
                    'payment' => $paymentPreview,
                    'sales'   => $salesPreview,
                ];

                /*
                 * Pastikan jumlah yang akan dihapus masih sama dengan hasil backup.
                 * Perubahan data setelah backup wajib melalui pengecekan ulang.
                 */
                foreach ($orderedPairs as $key => $pair) {
                    $expectedTotal = (int) ($state['backup_result'][$key . '_total'] ?? -1);
                    $expectedExact = (int) ($state['backup_result'][$key . '_exact'] ?? -1);
                    $expectedSkipped = (int) ($state['backup_result'][$key . '_skipped'] ?? -1);

                    if (
                        $pair['candidate_rows'] !== $expectedTotal
                        || $pair['exact_rows'] !== $expectedExact
                        || $pair['conflict_rows'] !== $expectedSkipped
                        || $pair['missing_rows'] !== 0
                    ) {
                        throw new RuntimeException(
                            "Data {$pair['source']} berubah setelah backup. Mulai proses dari awal."
                        );
                    }
                }

                $blockers = [];

                foreach ($orderedPairs as $pair) {
                    $blockers = array_merge($blockers, pair_blockers($pair, true));
                }

                if ($blockers) {
                    throw new RuntimeException(implode(' ', $blockers));
                }

                $lockName = 'sales_delete_' . substr(
                    hash('sha256', $selectedStore),
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
                        'Proses maintenance lain sedang berjalan.'
                    );
                }

                $transactionStarted = false;

                try {
                    if (!$db->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE')) {
                        throw new RuntimeException(
                            'Gagal mengatur isolation level: ' . $db->error
                        );
                    }

                    if (!$db->begin_transaction()) {
                        throw new RuntimeException(
                            'Gagal memulai transaksi delete: ' . $db->error
                        );
                    }
                    $transactionStarted = true;

                    $verifyBeforeDelete = [];
                    $deletedRows = [];
                    $remainingRows = [];

                    foreach ($orderedPairs as $key => $pair) {
                        $verifyBeforeDelete[$key] = verify_backup(
                            $db,
                            $pair,
                            $cutoffDate,
                            $prefix
                        );

                        if (!verification_complete(
                            $verifyBeforeDelete[$key],
                            $pair['candidate_rows']
                        )) {
                            throw new RuntimeException(
                                "{$pair['copy']} tidak lengkap. Delete dibatalkan."
                            );
                        }

                        $deletedRows[$key] = delete_verified_rows(
                            $db,
                            $pair,
                            $cutoffDate,
                            $prefix
                        );

                        if ($deletedRows[$key] !== $verifyBeforeDelete[$key]['exact_rows']) {
                            throw new RuntimeException(
                                "Jumlah delete {$pair['source']} tidak sesuai."
                            );
                        }

                        $remainingRows[$key] = candidate_count(
                            $db,
                            $pair,
                            $cutoffDate,
                            $prefix
                        );

                        if ($remainingRows[$key] !== $verifyBeforeDelete[$key]['conflict_rows']) {
                            throw new RuntimeException(
                                "Sisa kandidat {$pair['source']} tidak sesuai jumlah key yang dilewati."
                            );
                        }
                    }

                    if (!$db->commit()) {
                        throw new RuntimeException('COMMIT delete gagal: ' . $db->error);
                    }
                    $transactionStarted = false;

                    $pendingDeleteResult = [
                        'detail_deleted'       => $deletedRows['detail'],
                        'payment_deleted'      => $deletedRows['payment'],
                        'sales_deleted'        => $deletedRows['sales'],
                        'manual_sales_deleted' => 0,
                        'detail_remaining'     => $remainingRows['detail'],
                        'payment_remaining'    => $remainingRows['payment'],
                        'sales_remaining'      => $remainingRows['sales'],
                    ];

                    log_action([
                        'time'        => date('c'),
                        'status'      => 'DELETE_IDENTICAL_SUCCESS',
                        'user'        => $operatorName,
                        'store'       => $selectedStore,
                        'cutoff_date' => $cutoffDate,
                        'prefix_kept' => $prefix,
                        'result'      => $pendingDeleteResult,
                    ]);

                    /*
                     * Maintenance hanya ditahan bila masih ada konflik pada
                     * tabel sales, karena daftar Data Key Sama dan tombol
                     * delete manual memang khusus sales.
                     */
                    $remainingTotal = (int) $remainingRows['sales'];

                    if ($remainingTotal === 0) {
                        $deleteResult = $pendingDeleteResult;
                        $state['sales_cleanup_done'] = true;
                        $state['cleanup_pending'] = false;
                        $state['delete_result'] = $pendingDeleteResult;
                        $state['backup_ready'] = false;
                        $state['backup_result'] = null;
                        unset(
                            $state['inventory_checked_salesid'],
                            $state['inventory_checked_conflict_token'],
                            $state['inventory_salesid_signature'],
                            $state['inventory_salesid_result']
                        );
                        $state['created_at'] = time();
                        $_SESSION['sales_step_state'] = $state;

                        $backupResult = null;
                        $pendingDeleteResult = null;
                        $detailPreview = null;
                        $paymentPreview = null;
                        $salesPreview = null;
                        $inventorySalesPreview = null;
                        $inventorySalesResult = null;
                        $successMessage =
                            'Delete data identik selesai dan tidak ada Data Key Sama yang tersisa. Maintenance selesai.';
                    } else {
                        /*
                         * Setelah delete data identik, lakukan inspeksi ulang.
                         * State dipertahankan agar daftar konflik sales langsung
                         * muncul kembali dan dapat diselesaikan sebelum status
                         * Maintenance Selesai ditampilkan.
                         */
                        $detailPreview = $inspectPair('detail', 100);
                        $paymentPreview = $inspectPair('payment', 100);
                        $salesPreview = $inspectPair('sales', 1000);

                        $state = [
                            'workflow_version'  => 6,
                            'store'             => $selectedStore,
                            'cutoff_date'       => $cutoffDate,
                            'prefix'            => $prefix,
                            'created_at'        => time(),
                            'detail_ready'      => true,
                            'detail_signature'  => $detailPreview['signature'],
                            'payment_ready'     => true,
                            'payment_signature' => $paymentPreview['signature'],
                            'sales_ready'       => true,
                            'sales_signature'   => $salesPreview['signature'],
                            'backup_ready'      => false,
                            'backup_result'     => null,
                            'cleanup_pending'   => true,
                            'delete_result'     => $pendingDeleteResult,
                        ];

                        $_SESSION['sales_step_state'] = $state;
                        $backupResult = null;
                        $deleteResult = null;

                        if ((int) $remainingRows['sales'] > 0) {
                            $successMessage =
                                'Delete data identik selesai. Masih ada '
                                . number_format((int) $remainingRows['sales'], 0, ',', '.')
                                . ' Data Key Sama pada sales. Selesaikan daftar tersebut terlebih dahulu.';
                        } else {
                            $successMessage =
                                'Delete data identik selesai, tetapi masih ada data konflik pada salesdetail atau salespayments.';
                        }
                    }
                } catch (Throwable $deleteError) {
                    if ($transactionStarted) {
                        @$db->rollback();
                    }

                    log_action([
                        'time'        => date('c'),
                        'status'      => 'DELETE_FAILED',
                        'user'        => $operatorName,
                        'store'       => $selectedStore,
                        'cutoff_date' => $cutoffDate,
                        'prefix_kept' => $prefix,
                        'error'       => $deleteError->getMessage(),
                    ]);

                    throw $deleteError;
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
            } else {
                throw new RuntimeException('Aksi tidak dikenali.');
            }
        } finally {
            $db->close();
        }
    } elseif ($state) {
        $selectedStore = (string) ($state['store'] ?? '');
        $cutoffDate = (string) ($state['cutoff_date'] ?? '');

        if (isset($dropdownStores[$selectedStore])) {
            $prefix = $dropdownStores[$selectedStore]['prefix'];
            $db = connect_store($dropdownStores[$selectedStore]['config']);

            try {
                $tablePairs = [
                    'detail' => [
                        'label'  => 'Sales Detail',
                        'source' => detect_table($db, ['salesdetail'], 'salesdetail'),
                        'copy'   => detect_table($db, ['salesdetailcopy'], 'salesdetailcopy'),
                    ],
                    'payment' => [
                        'label'  => 'Sales Payment',
                        'source' => detect_table(
                            $db,
                            ['salespayments', 'salespayment'],
                            'salespayments'
                        ),
                        'copy'   => detect_table(
                            $db,
                            ['salespaymentscopy', 'salespaymentcopy'],
                            'salespaymentscopy'
                        ),
                    ],
                    'sales' => [
                        'label'  => 'Sales',
                        'source' => detect_table($db, ['sales'], 'sales'),
                        'copy'   => detect_table($db, ['salescopy'], 'salescopy'),
                    ],
                ];

                if (!empty($state['detail_ready']) && empty($state['sales_cleanup_done'])) {
                    $pair = $tablePairs['detail'];
                    $detailPreview = inspect_table_pair(
                        $db,
                        $pair['label'],
                        $pair['source'],
                        $pair['copy'],
                        $cutoffDate,
                        $prefix,
                        100
                    );
                }

                if (!empty($state['payment_ready']) && empty($state['sales_cleanup_done'])) {
                    $pair = $tablePairs['payment'];
                    $paymentPreview = inspect_table_pair(
                        $db,
                        $pair['label'],
                        $pair['source'],
                        $pair['copy'],
                        $cutoffDate,
                        $prefix,
                        100
                    );
                }

                if (!empty($state['sales_ready']) && empty($state['sales_cleanup_done'])) {
                    $pair = $tablePairs['sales'];
                    $salesPreview = inspect_table_pair(
                        $db,
                        $pair['label'],
                        $pair['source'],
                        $pair['copy'],
                        $cutoffDate,
                        $prefix,
                        100
                    );
                }

                $checkedInventorySalesId = trim((string) (
                    $state['inventory_checked_salesid'] ?? ''
                ));

                if (
                    $checkedInventorySalesId !== ''
                    && empty($state['sales_cleanup_done'])
                ) {
                    $inventoryPair = inventory_pair_meta($db);
                    $inventorySalesPreview = inventory_salesid_preview(
                        $db,
                        $inventoryPair,
                        $checkedInventorySalesId,
                        1000
                    );

                    $expectedInventorySignature = (string) (
                        $state['inventory_salesid_signature'] ?? ''
                    );

                    $inventorySalesPreviewChanged =
                        $expectedInventorySignature === ''
                        || !hash_equals(
                            $expectedInventorySignature,
                            (string) $inventorySalesPreview['signature']
                        );
                }
            } finally {
                $db->close();
            }
        }
    }
} catch (Throwable $error) {
    $errorMessage = $error->getMessage();

    if (strpos($errorMessage, 'TOKO_OFFLINE|') === 0) {
        $errorType = 'offline';
        $errorMessage = substr($errorMessage, strlen('TOKO_OFFLINE|'));
    } else {
        $errorType = 'error';
    }

    log_action([
        'time'   => date('c'),
        'status' => $errorType === 'offline' ? 'STORE_OFFLINE' : 'PAGE_ERROR',
        'user'   => $operatorName,
        'store'  => $selectedStore,
        'error'  => $errorMessage,
    ]);
}

$selectedPrefix = $selectedStore !== '' && isset($dropdownStores[$selectedStore])
    ? $dropdownStores[$selectedStore]['prefix']
    : '';

$detailReady = !empty($state['detail_ready']);
$paymentReady = !empty($state['payment_ready']);
$salesReady = !empty($state['sales_ready']);
$backupReady = !empty($state['backup_ready']) && is_array($backupResult);
$cleanupPending = !empty($state['cleanup_pending'])
    && is_array($pendingDeleteResult);
$salesCleanupDone = !empty($state['sales_cleanup_done'])
    && is_array($deleteResult);
$inventoryCheckedSalesId = trim((string) ($state['inventory_checked_salesid'] ?? ''));
$inventoryCheckedConflictToken = trim((string) ($state['inventory_checked_conflict_token'] ?? ''));
$maintenanceComplete = $salesCleanupDone;

$step1Done = $detailReady || $cleanupPending || $salesCleanupDone || $maintenanceComplete;
$step2Done = $paymentReady || $cleanupPending || $salesCleanupDone || $maintenanceComplete;
$step3Done = $salesReady || $cleanupPending || $salesCleanupDone || $maintenanceComplete;
$step4Done = $backupReady || $cleanupPending || $salesCleanupDone || $maintenanceComplete;
$step5Done = $salesCleanupDone || $maintenanceComplete;
$step5Active = !$step5Done && ($cleanupPending || $backupReady);

$previewStages = $salesCleanupDone
    ? []
    : ($cleanupPending
    ? [
        [
            'key' => 'sales',
            'number' => 5,
            'preview' => $salesPreview,
            'next_action' => '',
            'next_label' => '',
            'show_next' => false,
        ],
    ]
    : [
        [
            'key' => 'detail',
            'number' => 1,
            'preview' => $detailPreview,
            'next_action' => 'check_payment',
            'next_label' => '2. Cek Salespayments',
            'show_next' => $detailReady && !$paymentReady,
        ],
        [
            'key' => 'payment',
            'number' => 2,
            'preview' => $paymentPreview,
            'next_action' => 'check_sales',
            'next_label' => '3. Cek Sales',
            'show_next' => $paymentReady && !$salesReady,
        ],
        [
            'key' => 'sales',
            'number' => 3,
            'preview' => $salesPreview,
            'next_action' => '',
            'next_label' => '',
            'show_next' => false,
        ],
    ]);

$backupBlockers = [];

foreach ([$detailPreview, $paymentPreview, $salesPreview] as $preview) {
    if (is_array($preview)) {
        $backupBlockers = array_merge(
            $backupBlockers,
            pair_blockers($preview, false)
        );
    }
}

$deleteConfirmText = $backupReady
    ? 'DELETE '
        . (int) ($backupResult['detail_exact'] ?? 0)
        . '-'
        . (int) ($backupResult['payment_exact'] ?? 0)
        . '-'
        . (int) ($backupResult['sales_exact'] ?? 0)
    : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales & Inventory Synchronization - Delete Sales V3</title>

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
            --blue-soft: #eff6ff;
            --border: #dbe5f2;
            --text: #172033;
            --muted: #64748b;
            --green: #15803d;
            --red: #c62828;
            --amber: #b45309;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            color: var(--text);
            background:
                radial-gradient(
                    circle at 8% 0%,
                    rgba(29, 78, 216, .12),
                    transparent 28%
                ),
                linear-gradient(180deg, #f8fbff 0%, #edf3fa 100%);
            font-family:
                Inter,
                ui-sans-serif,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }

        .shell {
            width: min(1480px, calc(100% - 28px));
            margin: 22px auto 48px;
        }

        .hero {
            padding: 24px;
            color: #fff;
            border-radius: 22px;
            background: linear-gradient(135deg, var(--blue-dark), #2563eb);
            box-shadow: 0 18px 42px rgba(30, 64, 175, .22);
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(25px, 4vw, 35px);
            font-weight: 850;
            letter-spacing: -.025em;
        }

        .hero-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .dashboard-link {
            flex: 0 0 auto;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 15px;
            color: #173b90;
            border: 1px solid rgba(255, 255, 255, .78);
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 8px 20px rgba(15, 23, 42, .16);
            font-size: 13px;
            font-weight: 850;
            text-decoration: none;
            transition: transform .18s ease, box-shadow .18s ease;
        }

        .dashboard-link:hover {
            color: #173b90;
            transform: translateY(-2px);
            box-shadow: 0 12px 25px rgba(15, 23, 42, .22);
        }

        .roadmap {
            position: relative;
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 18px;
            margin-top: 30px;
            padding: 6px 0 2px;
        }

        .roadmap::before {
            content: "";
            position: absolute;
            z-index: 0;
            top: 42px;
            left: 9%;
            right: 9%;
            height: 5px;
            border-radius: 999px;
            background: linear-gradient(
                90deg,
                rgba(255, 255, 255, .30),
                rgba(147, 197, 253, .95),
                rgba(255, 255, 255, .30)
            );
            box-shadow: 0 0 18px rgba(191, 219, 254, .50);
        }

        .roadmap-step {
            position: relative;
            z-index: 1;
            min-width: 0;
            min-height: 156px;
            padding: 74px 16px 16px;
            overflow: hidden;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, .22);
            border-radius: 20px;
            background: linear-gradient(
                145deg,
                rgba(255, 255, 255, .16),
                rgba(255, 255, 255, .07)
            );
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, .14),
                0 12px 30px rgba(15, 23, 42, .12);
            backdrop-filter: blur(8px);
            transition: transform .22s ease, background .22s ease, box-shadow .22s ease;
        }

        .roadmap-step::after {
            content: "";
            position: absolute;
            right: -36px;
            bottom: -54px;
            width: 118px;
            height: 118px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .07);
        }

        .roadmap-step.pending {
            opacity: .68;
        }

        .roadmap-step.active {
            color: #12377f;
            border-color: rgba(255, 255, 255, .90);
            background: linear-gradient(145deg, #ffffff 0%, #e8f1ff 100%);
            box-shadow:
                0 18px 38px rgba(15, 23, 42, .24),
                0 0 0 3px rgba(191, 219, 254, .35);
            transform: translateY(-7px);
        }

        .roadmap-step.done {
            border-color: rgba(187, 247, 208, .75);
            background: linear-gradient(145deg, rgba(22, 163, 74, .72), rgba(21, 128, 61, .42));
            box-shadow: 0 15px 31px rgba(20, 83, 45, .22);
        }

        .roadmap-node {
            position: absolute;
            top: 9px;
            left: 50%;
            width: 66px;
            height: 66px;
            display: grid;
            place-items: center;
            border: 5px solid rgba(219, 234, 254, .92);
            border-radius: 50%;
            color: #1d4ed8;
            background: linear-gradient(145deg, #ffffff, #dbeafe);
            box-shadow:
                0 10px 24px rgba(15, 23, 42, .25),
                inset 0 1px 0 rgba(255, 255, 255, .95);
            transform: translateX(-50%);
        }

        .roadmap-node > i {
            font-size: 24px;
        }

        .roadmap-step.done .roadmap-node {
            color: #fff;
            border-color: rgba(220, 252, 231, .90);
            background: linear-gradient(145deg, #22c55e, #15803d);
        }

        .roadmap-step.active .roadmap-node {
            animation: roadmap-pulse 1.7s infinite;
        }

        .roadmap-index {
            position: absolute;
            top: -8px;
            right: -8px;
            min-width: 25px;
            height: 25px;
            display: grid;
            place-items: center;
            padding: 0 5px;
            border: 2px solid #fff;
            border-radius: 999px;
            color: #fff;
            background: #0f2f78;
            font-size: 11px;
            font-weight: 900;
            box-shadow: 0 4px 10px rgba(15, 23, 42, .25);
        }

        .roadmap-step.done .roadmap-index {
            background: #166534;
        }

        .roadmap-step strong {
            position: relative;
            z-index: 1;
            display: block;
            font-size: 14px;
            line-height: 1.28;
        }

        .roadmap-status {
            position: relative;
            z-index: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-width: 84px;
            margin-top: 10px;
            padding: 5px 10px;
            border: 1px solid rgba(255, 255, 255, .24);
            border-radius: 999px;
            color: rgba(255, 255, 255, .82);
            background: rgba(15, 23, 42, .12);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .roadmap-step.active .roadmap-status {
            color: #1d4ed8;
            border-color: #bfdbfe;
            background: #eff6ff;
        }

        .roadmap-step.done .roadmap-status {
            color: #ecfdf5;
            border-color: rgba(220, 252, 231, .38);
            background: rgba(20, 83, 45, .24);
        }

        @keyframes roadmap-pulse {
            0%, 100% {
                box-shadow:
                    0 10px 24px rgba(15, 23, 42, .25),
                    0 0 0 0 rgba(255, 255, 255, .46);
            }
            50% {
                box-shadow:
                    0 10px 24px rgba(15, 23, 42, .25),
                    0 0 0 11px rgba(255, 255, 255, 0);
            }
        }

        .panel {
            margin-top: 18px;
            padding: 22px;
            border: 1px solid var(--border);
            border-radius: 19px;
            background: rgba(255, 255, 255, .96);
            box-shadow: 0 13px 32px rgba(15, 23, 42, .07);
        }

        .panel-title {
            margin: 0 0 17px;
            font-size: 19px;
            font-weight: 850;
        }

        .form-label {
            font-size: 13px;
            font-weight: 750;
            color: #334155;
        }

        .form-select,
        .form-control {
            min-height: 48px;
            border-radius: 12px;
            border-color: #cdd9e7;
        }

        .form-select:focus,
        .form-control:focus {
            border-color: #5b8def;
            box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .12);
        }

        .prefix-display {
            min-height: 48px;
            display: flex;
            align-items: center;
            padding: 0 14px;
            color: #1e40af;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            background: var(--blue-soft);
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-weight: 900;
        }

        .btn {
            min-height: 47px;
            border-radius: 12px;
            font-weight: 850;
        }

        .btn-primary {
            border: 0;
            background: linear-gradient(135deg, var(--blue-dark), var(--blue));
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 10px;
        }

        .summary {
            min-height: 94px;
            padding: 13px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: #fff;
        }

        .summary span {
            display: block;
            margin-bottom: 7px;
            color: var(--muted);
            font-size: 10px;
            font-weight: 850;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .summary strong {
            display: block;
            font-size: 21px;
            line-height: 1.1;
            overflow-wrap: anywhere;
        }

        .summary small {
            display: block;
            margin-top: 6px;
            color: var(--muted);
        }

        .summary.red {
            border-color: #fecaca;
            background: #fff7f7;
        }

        .summary.green {
            border-color: #bbf7d0;
            background: #f0fdf4;
        }

        .summary.amber {
            border-color: #fed7aa;
            background: #fffaf2;
        }

        .structure-box {
            margin-top: 14px;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 14px;
        }

        .structure-header {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
        }

        .structure-header code {
            color: #1d4ed8;
        }

        .data-wrap {
            max-height: 520px;
            overflow: auto;
            scrollbar-width: thin;
        }

        .data-table {
            width: max-content;
            min-width: 100%;
            margin: 0;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 12px;
        }

        .data-table th,
        .data-table td {
            max-width: 320px;
            padding: 9px 11px;
            border-right: 1px solid #e8eef6;
            border-bottom: 1px solid #e8eef6;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .data-table th {
            position: sticky;
            top: 0;
            z-index: 2;
            color: #1e3a8a;
            background: #eff6ff;
            font-weight: 850;
        }

        .data-table tr:nth-child(even) td {
            background: #fbfdff;
        }

        .conflict-table .origin-cell {
            position: sticky;
            left: 0;
            z-index: 1;
            min-width: 94px;
            font-weight: 900;
            text-align: center;
        }

        .conflict-table th.origin-cell {
            z-index: 4;
        }


        .conflict-table.with-actions .select-cell,
        .conflict-table.with-actions .trash-cell {
            position: sticky;
            z-index: 3;
            min-width: 50px;
            width: 50px;
            max-width: 50px;
            padding: 7px;
            text-align: center;
        }

        .conflict-table.with-actions .select-cell {
            left: 0;
        }

        .conflict-table.with-actions .trash-cell {
            left: 50px;
        }

        .conflict-table.with-actions .origin-cell {
            left: 100px;
        }

        .conflict-table.with-actions th.select-cell,
        .conflict-table.with-actions th.trash-cell {
            z-index: 5;
            background: #eaf2ff;
        }

        .conflict-table.with-actions .source-row .select-cell,
        .conflict-table.with-actions .source-row .trash-cell {
            background: #fff1f2 !important;
        }

        .conflict-table.with-actions .copy-row .select-cell,
        .conflict-table.with-actions .copy-row .trash-cell {
            background: #eff6ff !important;
        }

        .sales-delete-version {
            display: inline-flex;
            align-items: center;
            margin-left: 8px;
            padding: 3px 8px;
            border: 1px solid #86efac;
            border-radius: 999px;
            color: #166534;
            background: #dcfce7;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .04em;
            vertical-align: middle;
        }

        .sales-conflict-checkbox,
        .sales-conflict-select-all {
            width: 17px;
            height: 17px;
            margin: 0;
            cursor: pointer;
            accent-color: #dc2626;
        }

        .conflict-row-delete {
            width: 31px;
            height: 31px;
            display: inline-grid;
            place-items: center;
            padding: 0;
            color: #b91c1c;
            border: 1px solid #fecaca;
            border-radius: 7px;
            background: #fff;
            cursor: pointer;
            transition: background .15s ease, color .15s ease, transform .15s ease;
        }

        .conflict-row-delete:hover {
            color: #fff;
            background: #dc2626;
            transform: translateY(-1px);
        }

        .conflict-delete-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 11px 14px;
            border-bottom: 1px solid #fecaca;
            background: #fff7f7;
        }

        .conflict-delete-toolbar small {
            color: #991b1b;
            font-weight: 750;
        }

        .conflict-delete-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 38px;
            padding: 8px 13px;
            color: #fff;
            border: 0;
            border-radius: 9px;
            background: #dc2626;
            font-size: 12px;
            font-weight: 850;
            cursor: pointer;
        }

        .conflict-delete-button:disabled {
            cursor: not-allowed;
            opacity: .48;
        }

        .conflict-table .source-row .origin-cell {
            color: #991b1b;
            background: #fee2e2;
        }

        .conflict-table .copy-row .origin-cell {
            color: #1e3a8a;
            background: #dbeafe;
        }

        .conflict-table .diff-cell {
            color: #7c2d12;
            background: #ffedd5 !important;
            font-weight: 800;
            box-shadow: inset 0 0 0 1px #fdba74;
        }

        .conflict-table .same-cell {
            color: #475569;
        }

        .conflict-table .conflict-separator td {
            height: 9px;
            padding: 0;
            border: 0;
            background: #e2e8f0 !important;
        }

        .conflict-note {
            padding: 12px 14px;
            color: #92400e;
            border-bottom: 1px solid #fed7aa;
            background: #fff7ed;
            font-size: 12px;
        }

        .conflict-key {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-left: 6px;
        }

        .conflict-key code {
            color: #7c2d12;
            border: 1px solid #fed7aa;
            border-radius: 6px;
            background: #fff;
            padding: 2px 6px;
        }

        .empty-state {
            padding: 30px;
            color: var(--muted);
            text-align: center;
        }

        .status-box {
            margin-top: 14px;
            padding: 15px;
            border-radius: 13px;
        }

        .status-box.safe {
            color: #14532d;
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
        }

        .status-box.warning {
            color: #7c2d12;
            border: 1px solid #fed7aa;
            background: #fff7ed;
        }

        .action-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 16px;
        }

        .danger-zone {
            padding: 18px;
            border: 1px solid #fecaca;
            border-radius: 15px;
            background: #fff7f7;
        }

        .danger-zone h3 {
            margin: 0 0 8px;
            color: #991b1b;
            font-size: 18px;
            font-weight: 850;
        }

        .confirm-code {
            display: inline-block;
            padding: 5px 9px;
            color: #991b1b;
            border-radius: 8px;
            background: #fee2e2;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-weight: 900;
        }

        @media (max-width: 1100px) {
            .summary-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 980px) {
            .roadmap {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .roadmap::before {
                display: none;
            }
        }

        @media (max-width: 620px) {
            .shell {
                width: min(100% - 18px, 1480px);
                margin-top: 10px;
            }

            .hero,
            .panel {
                padding: 17px;
                border-radius: 16px;
            }

            .hero-head {
                align-items: stretch;
                flex-direction: column;
            }

            .dashboard-link {
                width: 100%;
            }

            .roadmap,
            .summary-grid {
                grid-template-columns: 1fr;
            }

            .roadmap-step {
                min-height: 88px;
                padding: 18px 16px 18px 82px;
                text-align: left;
            }

            .roadmap-node {
                top: 50%;
                left: 17px;
                transform: translateY(-50%);
            }

            .roadmap-step.active {
                transform: none;
            }

            .action-row {
                display: grid;
            }
        }
    </style>
</head>
<body>
<div class="shell">
    <section class="hero">
        <div class="hero-head">
            <h1><i class="fa-solid fa-arrows-rotate me-2" aria-hidden="true"></i>Sales Synchronization Roadmap</h1>
            <a class="dashboard-link" href="../dashboard.php">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                Kembali ke Dashboard
            </a>
        </div>
        <div class="roadmap" aria-label="Roadmap sinkronisasi data">
            <div class="roadmap-step <?= $step1Done ? 'done' : 'active' ?>">
                <div class="roadmap-node">
                    <i class="fa-solid <?= $step1Done ? 'fa-check' : 'fa-list-check' ?>" aria-hidden="true"></i>
                    <span class="roadmap-index">1</span>
                </div>
                <strong>Cek Salesdetail</strong>
                <span class="roadmap-status">
                    <i class="fa-solid <?= $step1Done ? 'fa-circle-check' : 'fa-spinner' ?>" aria-hidden="true"></i>
                    <?= $step1Done ? 'Selesai' : 'Aktif' ?>
                </span>
            </div>

            <div class="roadmap-step <?= $step2Done ? 'done' : ($step1Done ? 'active' : 'pending') ?>">
                <div class="roadmap-node">
                    <i class="fa-solid <?= $step2Done ? 'fa-check' : 'fa-credit-card' ?>" aria-hidden="true"></i>
                    <span class="roadmap-index">2</span>
                </div>
                <strong>Cek Salespayments</strong>
                <span class="roadmap-status">
                    <i class="fa-solid <?= $step2Done ? 'fa-circle-check' : ($step1Done ? 'fa-spinner' : 'fa-clock') ?>" aria-hidden="true"></i>
                    <?= $step2Done ? 'Selesai' : ($step1Done ? 'Aktif' : 'Menunggu') ?>
                </span>
            </div>

            <div class="roadmap-step <?= $step3Done ? 'done' : ($step2Done ? 'active' : 'pending') ?>">
                <div class="roadmap-node">
                    <i class="fa-solid <?= $step3Done ? 'fa-check' : 'fa-receipt' ?>" aria-hidden="true"></i>
                    <span class="roadmap-index">3</span>
                </div>
                <strong>Cek Sales</strong>
                <span class="roadmap-status">
                    <i class="fa-solid <?= $step3Done ? 'fa-circle-check' : ($step2Done ? 'fa-spinner' : 'fa-clock') ?>" aria-hidden="true"></i>
                    <?= $step3Done ? 'Selesai' : ($step2Done ? 'Aktif' : 'Menunggu') ?>
                </span>
            </div>

            <div class="roadmap-step <?= $step4Done ? 'done' : ($step3Done ? 'active' : 'pending') ?>">
                <div class="roadmap-node">
                    <i class="fa-solid <?= $step4Done ? 'fa-check' : 'fa-database' ?>" aria-hidden="true"></i>
                    <span class="roadmap-index">4</span>
                </div>
                <strong>Sinkronisasi Backup</strong>
                <span class="roadmap-status">
                    <i class="fa-solid <?= $step4Done ? 'fa-circle-check' : ($step3Done ? 'fa-spinner' : 'fa-clock') ?>" aria-hidden="true"></i>
                    <?= $step4Done ? 'Selesai' : ($step3Done ? 'Aktif' : 'Menunggu') ?>
                </span>
            </div>

            <div class="roadmap-step <?= $step5Done ? 'done' : ($step5Active ? 'active' : 'pending') ?>">
                <div class="roadmap-node">
                    <i class="fa-solid <?= $step5Done ? 'fa-check' : 'fa-shield-halved' ?>" aria-hidden="true"></i>
                    <span class="roadmap-index">5</span>
                </div>
                <strong>Delete Terverifikasi</strong>
                <span class="roadmap-status">
                    <i class="fa-solid <?= $step5Done ? 'fa-circle-check' : ($step5Active ? 'fa-spinner' : 'fa-clock') ?>" aria-hidden="true"></i>
                    <?= $step5Done ? 'Selesai' : ($cleanupPending ? 'Selesaikan Konflik' : ($backupReady ? 'Aktif' : 'Menunggu')) ?>
                </span>
            </div>

        </div>
    </section>

    <section class="panel">
        <h2 class="panel-title">Pilih Toko dan Batas Tanggal</h2>

        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="check_detail">

            <div class="row g-3">
                <div class="col-lg-5">
                    <label class="form-label" for="store">Toko</label>
                    <select
                        class="form-select"
                        id="store"
                        name="store"
                        <?= $detailReady ? 'disabled' : '' ?>
                        required
                    >
                        <option value="">- Pilih toko -</option>
                        <?php foreach ($dropdownStores as $storeName => $storeData): ?>
                            <?php $disabled = $storeData['prefix'] === ''; ?>
                            <option
                                value="<?= e($storeName) ?>"
                                data-prefix="<?= e($storeData['prefix']) ?>"
                                <?= $selectedStore === $storeName ? 'selected' : '' ?>
                                <?= $disabled ? 'disabled' : '' ?>
                            >
                                <?= e($storeName) ?>
                                <?= $disabled ? ' - prefix belum diisi' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php if ($detailReady): ?>
                        <input type="hidden" name="store" value="<?= e($selectedStore) ?>">
                    <?php endif; ?>

                </div>

                <div class="col-lg-3">
                    <label class="form-label">Prefix yang Dipertahankan</label>
                    <div class="prefix-display" id="prefixDisplay">
                        <?= e($selectedPrefix !== '' ? $selectedPrefix . '%' : '-') ?>
                    </div>
                </div>

                <div class="col-lg-4">
                    <label class="form-label" for="cutoff_date">Proses Data Sebelum Tanggal</label>
                    <input
                        class="form-control"
                        type="date"
                        id="cutoff_date"
                        name="cutoff_date"
                        value="<?= e($cutoffDate) ?>"
                        max="<?= e(date('Y-m-d')) ?>"
                        <?= $detailReady ? 'readonly' : '' ?>
                        required
                    >
                </div>

                <?php if (!$detailReady): ?>
                    <div class="col-12 d-grid d-md-flex justify-content-md-end">
                        <button type="submit" class="btn btn-primary px-4">1. Cek Salesdetail</button>
                    </div>
                <?php else: ?>
                    <div class="col-12 d-flex justify-content-end">
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            onclick="document.getElementById('resetForm').submit()"
                        >
                            Ganti Toko / Mulai Ulang
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </form>

        <form method="post" id="resetForm" class="d-none">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="reset">
        </form>
    </section>

    <?php if ($errorMessage !== ''): ?>
        <section class="panel">
            <div class="alert <?= $errorType === 'offline' ? 'alert-warning' : 'alert-danger' ?> mb-0">
                <strong><?= $errorType === 'offline' ? 'Toko Offline:' : 'Proses dibatalkan:' ?></strong><br>
                <?= e($errorMessage) ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($cleanupPending && is_array($pendingDeleteResult)): ?>
        <section class="panel">
            <div class="alert alert-warning mb-0">
                <h4 class="alert-heading fw-bold">
                    Delete Identik Selesai - Maintenance Belum Selesai
                </h4>
                <p class="mb-2">
                    Masih ada
                    <strong><?= number_format((int) ($pendingDeleteResult['sales_remaining'] ?? 0), 0, ',', '.') ?></strong>
                    Data Key Sama pada tabel <code>sales</code>.
                    Hapus baris UTAMA yang dipilih sampai jumlahnya menjadi 0.
                </p>
                <div class="small">
                    Salesdetail terhapus:
                    <strong><?= number_format((int) ($pendingDeleteResult['detail_deleted'] ?? 0), 0, ',', '.') ?></strong>
                    &nbsp;|&nbsp;
                    Salespayments terhapus:
                    <strong><?= number_format((int) ($pendingDeleteResult['payment_deleted'] ?? 0), 0, ',', '.') ?></strong>
                    &nbsp;|&nbsp;
                    Sales terhapus:
                    <strong><?= number_format((int) ($pendingDeleteResult['sales_deleted'] ?? 0), 0, ',', '.') ?></strong>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php foreach ($previewStages as $stage): ?>
        <?php $preview = $stage['preview']; ?>
        <?php if (is_array($preview)): ?>
            <?php $stageBlockers = pair_blockers($preview, false); ?>
            <section class="panel">
                <h2 class="panel-title">
                    Tahap <?= (int) $stage['number'] ?> - <?= e($preview['source']) ?>
                    <i class="fa-solid fa-arrow-right-long inline-arrow" aria-hidden="true"></i>
                    <?= e($preview['copy']) ?>
                </h2>

                <div class="summary-grid">
                    <div class="summary red">
                        <span>Kandidat</span>
                        <strong><?= number_format($preview['candidate_rows'], 0, ',', '.') ?></strong>
                        <small><?= number_format($preview['candidate_sales'], 0, ',', '.') ?> salesid unik</small>
                    </div>
                    <div class="summary green">
                        <span>Prefix Dipertahankan</span>
                        <strong><?= number_format($preview['protected_rows'], 0, ',', '.') ?></strong>
                        <small><?= e($prefix) ?>%</small>
                    </div>
                    <div class="summary">
                        <span>Belum Masuk Copy</span>
                        <strong><?= number_format($preview['missing_rows'], 0, ',', '.') ?></strong>
                        <small>Akan di-insert</small>
                    </div>
                    <div class="summary green">
                        <span>Sudah Identik</span>
                        <strong><?= number_format($preview['exact_rows'], 0, ',', '.') ?></strong>
                        <small>Tidak dibuat duplikat</small>
                    </div>
                    <div class="summary amber">
                        <span>Key Sama Dilewati</span>
                        <strong><?= number_format($preview['conflict_rows'], 0, ',', '.') ?></strong>
                        <small>Tidak overwrite dan tidak dihapus</small>
                    </div>
                    <div class="summary">
                        <span>Rentang <?= e($preview['date_column']) ?></span>
                        <strong style="font-size:14px"><?= e($preview['min_date'] ?: '-') ?></strong>
                        <small>s.d. <?= e($preview['max_date'] ?: '-') ?></small>
                    </div>
                </div>

                <div class="structure-box">
                    <div class="structure-header">
                        <strong>Validasi Struktur</strong>
                        <code>
                            Key: <?= e(implode(', ', $preview['key_columns'])) ?> |
                            <?= e($preview['source_engine']) ?>
                            <i class="fa-solid fa-arrow-right-long inline-arrow" aria-hidden="true"></i>
                            <?= e($preview['copy_engine']) ?>
                        </code>
                    </div>
                    <div class="p-3 small">
                        <?= count($preview['field_names']) ?> field cocok dan urutannya sama.
                        Kolom tanggal filter: <code><?= e($preview['date_column']) ?></code>.
                    </div>
                </div>

                <div class="structure-box">
                    <div class="structure-header">
                        <strong>Preview Data <?= e($preview['source']) ?></strong>
                        <code>Maksimal <?= number_format($preview['preview_limit'], 0, ',', '.') ?> baris</code>
                    </div>

                    <?php if ($preview['preview_rows']): ?>
                        <div class="data-wrap">
                            <table class="data-table">
                                <thead>
                                <tr>
                                    <?php foreach ($preview['field_names'] as $field): ?>
                                        <th><?= e($field) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($preview['preview_rows'] as $row): ?>
                                    <tr>
                                        <?php foreach ($preview['field_names'] as $field): ?>
                                            <td title="<?= e($row[$field] ?? '') ?>">
                                                <?= array_key_exists($field, $row) && $row[$field] === null
                                                    ? '<em>NULL</em>'
                                                    : e($row[$field] ?? '') ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">Tidak ada data kandidat.</div>
                    <?php endif; ?>
                </div>

                <?php if ((int) $preview['conflict_rows'] > 0): ?>
                    <?php
                    /*
                     * Kontrol hapus harus tampil setiap sumber preview adalah tabel sales.
                     * Tidak bergantung pada key tahap agar tetap muncul pada konfigurasi
                     * tampilan/loop yang berbeda di server.
                     */
                    $salesConflictDeleteEnabled =
                        strtolower(trim((string) $preview['source'])) === 'sales';
                    ?>
                    <div class="structure-box">
                        <div class="structure-header">
                            <strong>
                                Data Key Sama - Isi Berbeda
                                <?php if ($salesConflictDeleteEnabled): ?>
                                    <span class="sales-delete-version">DELETE SALES AKTIF</span>
                                <?php endif; ?>
                            </strong>
                            <code>
                                Ditampilkan <?= number_format(count($preview['conflict_preview_rows']), 0, ',', '.') ?>
                                dari <?= number_format($preview['conflict_rows'], 0, ',', '.') ?> pasangan
                            </code>
                        </div>

                        <div class="conflict-note">
                            Baris <strong>UTAMA</strong> berasal dari tabel
                            <code><?= e($preview['source']) ?></code>, sedangkan baris
                            <strong>COPY</strong> berasal dari tabel
                            <code><?= e($preview['copy']) ?></code>.
                            Field berwarna oranye adalah field yang nilainya berbeda.
                            <?php if ($salesConflictDeleteEnabled): ?>
                                Gunakan tombol <strong>Cek SalesID</strong> untuk mencari SalesID tersebut
                                di <code>inventorycopy</code> dan menampilkan data sebelum diproses.
                                Centang baris UTAMA lalu tekan ikon sampah untuk menghapus
                                <strong>hanya dari tabel sales</strong>. Data salescopy tidak diubah.
                            <?php endif; ?>
                        </div>

                        <?php if ($preview['conflict_preview_rows']): ?>
                            <?php if ($salesConflictDeleteEnabled): ?>
                                <form
                                    method="post"
                                    class="sales-conflict-delete-form"
                                    autocomplete="off"
                                >
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_sales_conflicts">
                                    <input type="hidden" name="store" value="<?= e($selectedStore) ?>">
                                    <input type="hidden" name="cutoff_date" value="<?= e($cutoffDate) ?>">

                                    <div class="conflict-delete-toolbar">
                                        <small>
                                            <span class="selected-conflict-count">0</span>
                                            data sales dipilih
                                        </small>
                                        <button
                                            type="submit"
                                            class="conflict-delete-button"
                                            disabled
                                        >
                                            <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                                            Hapus Data Sales Dipilih
                                        </button>
                                    </div>

                                    <div class="data-wrap">
                                        <table class="data-table conflict-table with-actions">
                                            <thead>
                                            <tr>
                                                <th class="select-cell">
                                                    <input
                                                        type="checkbox"
                                                        class="sales-conflict-select-all"
                                                        title="Pilih semua data sales"
                                                        aria-label="Pilih semua data sales"
                                                    >
                                                </th>
                                                <th class="trash-cell">Hapus</th>
                                                <th style="min-width:140px">Inventory</th>
                                                <th class="origin-cell">Asal Data</th>
                                                <?php foreach ($preview['field_names'] as $field): ?>
                                                    <th><?= e($field) ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($preview['conflict_preview_rows'] as $conflictIndex => $conflictRow): ?>
                                                <?php
                                                $differentLookup = array_fill_keys(
                                                    $conflictRow['different_fields'],
                                                    true
                                                );
                                                $conflictCheckboxId =
                                                    'salesConflict_' . (int) $conflictIndex;
                                                $conflictToken = conflict_key_token(
                                                    $conflictRow['key_values'],
                                                    $preview['key_columns'],
                                                    $csrfToken
                                                );
                                                $conflictSalesId = trim((string) (
                                                    $conflictRow['copy']['salesid']
                                                        ?? $conflictRow['source']['salesid']
                                                        ?? ''
                                                ));
                                                ?>
                                                <tr class="source-row">
                                                    <td class="select-cell">
                                                        <input
                                                            type="checkbox"
                                                            id="<?= e($conflictCheckboxId) ?>"
                                                            class="sales-conflict-checkbox"
                                                            name="sales_conflict_keys[]"
                                                            value="<?= e($conflictToken) ?>"
                                                            aria-label="Pilih data sales baris <?= (int) $conflictIndex + 1 ?>"
                                                        >
                                                    </td>
                                                    <td class="trash-cell">
                                                        <button
                                                            type="button"
                                                            class="conflict-row-delete"
                                                            data-checkbox-id="<?= e($conflictCheckboxId) ?>"
                                                            title="Hapus baris ini dari tabel sales"
                                                            aria-label="Hapus baris ini dari tabel sales"
                                                        >
                                                            <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                                                        </button>
                                                    </td>
                                                    <td>
                                                        <button
                                                            type="submit"
                                                            name="check_salesid_key"
                                                            value="<?= e($conflictToken) ?>"
                                                            class="btn btn-sm btn-primary check-salesid-button"
                                                            title="Cek SalesID <?= e($conflictSalesId) ?> di inventorycopy"
                                                        >
                                                            <i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i>
                                                            Cek SalesID
                                                        </button>
                                                        <?php if ($conflictSalesId !== ''): ?>
                                                            <div class="small mt-1"><code><?= e($conflictSalesId) ?></code></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="origin-cell">UTAMA</td>
                                                    <?php foreach ($preview['field_names'] as $field): ?>
                                                        <?php
                                                        $sourceValue = $conflictRow['source'][$field] ?? null;
                                                        $isDifferent = isset($differentLookup[$field]);
                                                        ?>
                                                        <td
                                                            class="<?= $isDifferent ? 'diff-cell' : 'same-cell' ?>"
                                                            title="<?= e($sourceValue ?? '') ?>"
                                                        >
                                                            <?= $sourceValue === null
                                                                ? '<em>NULL</em>'
                                                                : e($sourceValue) ?>
                                                        </td>
                                                    <?php endforeach; ?>
                                                </tr>
                                                <tr class="copy-row">
                                                    <td class="select-cell"></td>
                                                    <td class="trash-cell"></td>
                                                    <td></td>
                                                    <td class="origin-cell">COPY</td>
                                                    <?php foreach ($preview['field_names'] as $field): ?>
                                                        <?php
                                                        $copyValue = $conflictRow['copy'][$field] ?? null;
                                                        $isDifferent = isset($differentLookup[$field]);
                                                        ?>
                                                        <td
                                                            class="<?= $isDifferent ? 'diff-cell' : 'same-cell' ?>"
                                                            title="<?= e($copyValue ?? '') ?>"
                                                        >
                                                            <?= $copyValue === null
                                                                ? '<em>NULL</em>'
                                                                : e($copyValue) ?>
                                                        </td>
                                                    <?php endforeach; ?>
                                                </tr>
                                                <?php if ($conflictIndex < count($preview['conflict_preview_rows']) - 1): ?>
                                                    <tr class="conflict-separator">
                                                        <td colspan="<?= count($preview['field_names']) + 4 ?>"></td>
                                                    </tr>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="data-wrap">
                                    <table class="data-table conflict-table">
                                        <thead>
                                        <tr>
                                            <th class="origin-cell">Asal Data</th>
                                            <?php foreach ($preview['field_names'] as $field): ?>
                                                <th><?= e($field) ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($preview['conflict_preview_rows'] as $conflictIndex => $conflictRow): ?>
                                            <?php
                                            $differentLookup = array_fill_keys(
                                                $conflictRow['different_fields'],
                                                true
                                            );
                                            ?>
                                            <tr class="source-row">
                                                <td class="origin-cell">UTAMA</td>
                                                <?php foreach ($preview['field_names'] as $field): ?>
                                                    <?php
                                                    $sourceValue = $conflictRow['source'][$field] ?? null;
                                                    $isDifferent = isset($differentLookup[$field]);
                                                    ?>
                                                    <td
                                                        class="<?= $isDifferent ? 'diff-cell' : 'same-cell' ?>"
                                                        title="<?= e($sourceValue ?? '') ?>"
                                                    >
                                                        <?= $sourceValue === null
                                                            ? '<em>NULL</em>'
                                                            : e($sourceValue) ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <tr class="copy-row">
                                                <td class="origin-cell">COPY</td>
                                                <?php foreach ($preview['field_names'] as $field): ?>
                                                    <?php
                                                    $copyValue = $conflictRow['copy'][$field] ?? null;
                                                    $isDifferent = isset($differentLookup[$field]);
                                                    ?>
                                                    <td
                                                        class="<?= $isDifferent ? 'diff-cell' : 'same-cell' ?>"
                                                        title="<?= e($copyValue ?? '') ?>"
                                                    >
                                                        <?= $copyValue === null
                                                            ? '<em>NULL</em>'
                                                            : e($copyValue) ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <?php if ($conflictIndex < count($preview['conflict_preview_rows']) - 1): ?>
                                                <tr class="conflict-separator">
                                                    <td colspan="<?= count($preview['field_names']) + 1 ?>"></td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                Jumlah key sama terdeteksi, tetapi data preview tidak tersedia.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="status-box <?= ($stageBlockers || $cleanupPending) ? 'warning' : 'safe' ?>">
                    <?php if ($stageBlockers): ?>
                        <strong>Belum dapat dilanjutkan:</strong>
                        <?= e(implode(' ', $stageBlockers)) ?>
                    <?php elseif ($cleanupPending): ?>
                        <strong>Maintenance belum selesai.</strong>
                        Selesaikan seluruh Data Key Sama pada tabel sales sampai jumlahnya 0.
                    <?php else: ?>
                        <strong>Siap dilanjutkan.</strong>
                    <?php endif; ?>
                </div>

                <?php if ($stage['show_next']): ?>
                    <div class="action-row">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="<?= e($stage['next_action']) ?>">
                            <input type="hidden" name="store" value="<?= e($selectedStore) ?>">
                            <input type="hidden" name="cutoff_date" value="<?= e($cutoffDate) ?>">
                            <button type="submit" class="btn btn-primary px-4" <?= $stageBlockers ? 'disabled' : '' ?>>
                                <?= e($stage['next_label']) ?>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($salesReady && !$backupReady && !$cleanupPending && !$salesCleanupDone): ?>
        <section class="panel">
            <h2 class="panel-title">Tahap 4 - Backup Tiga Tabel</h2>

            <?php if ($backupBlockers): ?>
                <div class="alert alert-warning">
                    <strong>Backup belum dapat dijalankan.</strong><br>
                    <?= e(implode(' ', $backupBlockers)) ?>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    Urutan proses: salesdetail, salespayments, lalu sales. Semua dijalankan dalam satu transaksi.
                    Key yang sudah ada di copy dilewati tanpa mengubah data copy.
                </div>
                <div class="action-row">
                    <form method="post" id="backupForm">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="backup">
                        <input type="hidden" name="store" value="<?= e($selectedStore) ?>">
                        <input type="hidden" name="cutoff_date" value="<?= e($cutoffDate) ?>">
                        <button type="submit" class="btn btn-success px-4">4. Backup Tiga Tabel</button>
                    </form>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($backupReady && !$cleanupPending && !$salesCleanupDone): ?>
        <section class="panel">
            <h2 class="panel-title">Tahap 4 - Hasil Backup</h2>
            <div class="alert alert-success">
                <strong>Backup selesai.</strong>
                Duplikat/key yang sudah ada dilewati. Belum ada data tabel utama yang dihapus.
            </div>

            <div class="row g-3">
                <?php foreach ([
                    ['key' => 'detail', 'title' => 'Salesdetail'],
                    ['key' => 'payment', 'title' => 'Salespayments'],
                    ['key' => 'sales', 'title' => 'Sales'],
                ] as $resultItem): ?>
                    <?php $resultKey = $resultItem['key']; ?>
                    <div class="col-lg-4">
                        <div class="structure-box mt-0 h-100">
                            <div class="structure-header">
                                <strong><?= e($resultItem['title']) ?></strong>
                                <code>
                                    <?= e($backupResult[$resultKey . '_source']) ?>
                                    <i class="fa-solid fa-arrow-right-long inline-arrow" aria-hidden="true"></i>
                                    <?= e($backupResult[$resultKey . '_copy']) ?>
                                </code>
                            </div>
                            <table class="table mb-0">
                                <tr><th>Total kandidat</th><td><?= number_format($backupResult[$resultKey . '_total'], 0, ',', '.') ?></td></tr>
                                <tr><th>Insert baru</th><td><?= number_format($backupResult[$resultKey . '_inserted'], 0, ',', '.') ?></td></tr>
                                <tr><th>Sudah identik sebelumnya</th><td><?= number_format($backupResult[$resultKey . '_already'], 0, ',', '.') ?></td></tr>
                                <tr><th>Identik dan dapat dihapus</th><td><?= number_format($backupResult[$resultKey . '_exact'], 0, ',', '.') ?></td></tr>
                                <tr><th>Key sama, dilewati</th><td><?= number_format($backupResult[$resultKey . '_skipped'], 0, ',', '.') ?></td></tr>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel">
            <div class="danger-zone">
                <h3>Tahap 5 - Delete Data yang Terverifikasi Identik</h3>
                <p>
                    Sistem menghapus detail dan payment terlebih dahulu, lalu header sales.
                    Baris dengan key sama tetapi isi berbeda tetap berada di tabel utama.
                </p>
                <p>Ketik persis: <span class="confirm-code"><?= e($deleteConfirmText) ?></span></p>

                <form method="post" id="deleteForm" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="store" value="<?= e($selectedStore) ?>">
                    <input type="hidden" name="cutoff_date" value="<?= e($cutoffDate) ?>">

                    <div class="row g-3 align-items-end">
                        <div class="col-lg-7">
                            <label class="form-label" for="confirmation">Konfirmasi Delete</label>
                            <input
                                class="form-control text-uppercase"
                                type="text"
                                id="confirmation"
                                name="confirmation"
                                placeholder="<?= e($deleteConfirmText) ?>"
                                required
                            >
                        </div>
                        <div class="col-lg-5 d-grid">
                            <button type="submit" class="btn btn-danger">Delete Data Identik</button>
                        </div>
                    </div>
                </form>
            </div>
        </section>
    <?php endif; ?>

    <?php if (is_array($deleteResult)): ?>
        <section class="panel">
            <div class="alert alert-success mb-0">
                <h4 class="alert-heading fw-bold">Maintenance Selesai</h4>
                <div>Salesdetail terhapus: <strong><?= number_format($deleteResult['detail_deleted'], 0, ',', '.') ?></strong></div>
                <div>Salespayments terhapus: <strong><?= number_format($deleteResult['payment_deleted'], 0, ',', '.') ?></strong></div>
                <div>Sales terhapus: <strong><?= number_format($deleteResult['sales_deleted'], 0, ',', '.') ?></strong></div>
                <?php if (isset($deleteResult['manual_sales_deleted'])): ?>
                    <div>Sales konflik/manual terhapus: <strong><?= number_format((int) $deleteResult['manual_sales_deleted'], 0, ',', '.') ?></strong></div>
                <?php endif; ?>
                <hr>
                <div>Salesdetail dilewati/tersisa: <strong><?= number_format($deleteResult['detail_remaining'], 0, ',', '.') ?></strong></div>
                <div>Salespayments dilewati/tersisa: <strong><?= number_format($deleteResult['payment_remaining'], 0, ',', '.') ?></strong></div>
                <div>Sales dilewati/tersisa: <strong><?= number_format($deleteResult['sales_remaining'], 0, ',', '.') ?></strong></div>
                <?php if ($salesCleanupDone): ?>
                    <hr>
                    <div>
                        Data pada <code>salescopy</code> tetap dipertahankan.
                        Tidak ada lagi proses backup <code>inventory</code> ke <code>inventorycopy</code>.
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (is_array($inventorySalesPreview)): ?>
        <?php
        $inventoryPreviewSalesId = (string) ($inventorySalesPreview['salesid'] ?? '');
        $inventoryResultMatches = is_array($inventorySalesResult)
            && (string) ($inventorySalesResult['salesid'] ?? '') === $inventoryPreviewSalesId;
        ?>
        <section class="panel" id="inventorySalesIdPanel">
            <h2 class="panel-title">
                Cek SalesID ? TransID di Inventorycopy - <code><?= e($inventoryPreviewSalesId) ?></code>
            </h2>

            <div class="alert alert-info">
                Data di bawah ini berasal dari <code>inventorycopy</code> dengan
                <code>transid = <?= e($inventoryPreviewSalesId) ?></code> karena nilai <code>sales.salesid</code> sama dengan <code>inventorycopy.transid</code>.
                Tombol <strong>Proses</strong> hanya melakukan insert
                <code>inventorycopy ? inventory</code> untuk row yang key-nya belum ada.
                Tidak ada backup <code>inventory ? inventorycopy</code> dan tidak ada overwrite.
            </div>

            <?php if ($inventorySalesPreviewChanged): ?>
                <div class="alert alert-warning">
                    Data inventorycopy berubah setelah pengecekan terakhir.
                    Tekan <strong>Cek SalesID</strong> lagi dari daftar Data Key Sama sebelum Proses.
                </div>
            <?php endif; ?>

            <?php if ($inventoryResultMatches): ?>
                <div class="alert alert-success">
                    <strong>Proses terakhir berhasil.</strong>
                    <?= number_format((int) ($inventorySalesResult['inserted_rows'] ?? 0), 0, ',', '.') ?> row di-insert ke inventory,
                    <?= number_format((int) ($inventorySalesResult['already_exact'] ?? 0), 0, ',', '.') ?> row sebelumnya sudah identik.
                </div>
            <?php endif; ?>

            <div class="summary-grid">
                <div class="summary">
                    <span>Row Inventorycopy</span>
                    <strong><?= number_format((int) $inventorySalesPreview['copy_rows'], 0, ',', '.') ?></strong>
                    <small>TransID = SalesID <?= e($inventoryPreviewSalesId) ?></small>
                </div>
                <div class="summary red">
                    <span>Siap Insert</span>
                    <strong><?= number_format((int) $inventorySalesPreview['missing_rows'], 0, ',', '.') ?></strong>
                    <small>Belum ada di inventory</small>
                </div>
                <div class="summary green">
                    <span>Sudah Identik</span>
                    <strong><?= number_format((int) $inventorySalesPreview['exact_rows'], 0, ',', '.') ?></strong>
                    <small>Tidak di-insert ulang</small>
                </div>
                <div class="summary amber">
                    <span>Key Konflik</span>
                    <strong><?= number_format((int) $inventorySalesPreview['conflict_rows'], 0, ',', '.') ?></strong>
                    <small>Key sama, isi berbeda</small>
                </div>
            </div>

            <?php if ($inventorySalesPreview['rows']): ?>
                <div class="structure-box">
                    <div class="structure-header">
                        <strong>Data yang Akan Menjadi Dasar Proses</strong>
                        <code><?= e($inventorySalesPreview['copy']) ?> ? <?= e($inventorySalesPreview['source']) ?></code>
                    </div>
                    <div class="data-wrap">
                        <table class="data-table">
                            <thead>
                            <tr>
                                <th>Status</th>
                                <?php foreach ($inventorySalesPreview['field_names'] as $field): ?>
                                    <th><?= e($field) ?></th>
                                <?php endforeach; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($inventorySalesPreview['rows'] as $inventoryRow): ?>
                                <?php
                                $inventoryStatus = (string) ($inventoryRow['status'] ?? 'conflict');
                                $inventoryStatusLabel = $inventoryStatus === 'missing'
                                    ? 'SIAP INSERT'
                                    : ($inventoryStatus === 'exact' ? 'SUDAH IDENTIK' : 'KEY KONFLIK');
                                $inventoryStatusClass = $inventoryStatus === 'missing'
                                    ? 'text-bg-primary'
                                    : ($inventoryStatus === 'exact' ? 'text-bg-success' : 'text-bg-warning');
                                ?>
                                <tr>
                                    <td><span class="badge <?= e($inventoryStatusClass) ?>"><?= e($inventoryStatusLabel) ?></span></td>
                                    <?php foreach ($inventorySalesPreview['field_names'] as $field): ?>
                                        <?php $value = $inventoryRow['data'][$field] ?? null; ?>
                                        <td title="<?= e($value ?? '') ?>">
                                            <?= $value === null ? '<em>NULL</em>' : e($value) ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-warning mb-0">
                    SalesID <strong><?= e($inventoryPreviewSalesId) ?></strong> tidak ditemukan sebagai <code>transid</code> pada <code>inventorycopy</code>.
                    Tidak ada data yang dapat diproses ke inventory.
                </div>
            <?php endif; ?>

            <?php if ((int) $inventorySalesPreview['conflict_rows'] > 0): ?>
                <div class="alert alert-danger mt-3 mb-0">
                    Proses diblokir karena ada key yang sudah ada di inventory tetapi isinya berbeda.
                    Sistem tidak akan overwrite data inventory.
                </div>
            <?php elseif ((int) $inventorySalesPreview['missing_rows'] > 0): ?>
                <div class="action-row">
                    <form method="post" id="inventorySalesProcessForm">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="process_inventory_salesid">
                        <input type="hidden" name="store" value="<?= e($selectedStore) ?>">
                        <input type="hidden" name="cutoff_date" value="<?= e($cutoffDate) ?>">
                        <input type="hidden" name="inventory_salesid" value="<?= e($inventoryPreviewSalesId) ?>">
                        <button
                            type="submit"
                            class="btn btn-success px-4"
                            <?= $inventorySalesPreviewChanged ? 'disabled' : '' ?>
                        >
                            <i class="fa-solid fa-play me-2" aria-hidden="true"></i>
                            Proses <?= number_format((int) $inventorySalesPreview['missing_rows'], 0, ',', '.') ?> Row
                        </button>
                    </form>
                </div>
            <?php elseif ((int) $inventorySalesPreview['copy_rows'] > 0): ?>
                <div class="alert alert-success mt-3">
                    <strong>Inventory sudah identik.</strong><br>
                    Semua data dengan TransID yang sama dengan SalesID
                    <code><?= e($inventoryPreviewSalesId) ?></code>
                    sudah identik di <code>inventory</code>.
                    Tidak ada row baru yang perlu di-insert.
                </div>

                <div class="status-box safe">
                    <strong>Pilih langkah berikutnya:</strong><br>
                    1. Hapus baris <code>sales</code> duplikat yang tadi dipilih, atau<br>
                    2. Selesai dan kembali ke halaman utama tanpa menghapus baris tersebut.
                </div>

                <div class="action-row">
                    <?php if ($inventoryCheckedConflictToken !== ''): ?>
                        <form method="post" id="inventoryIdenticalDeleteForm" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_sales_conflicts">
                            <input type="hidden" name="store" value="<?= e($selectedStore) ?>">
                            <input type="hidden" name="cutoff_date" value="<?= e($cutoffDate) ?>">
                            <input
                                type="hidden"
                                name="sales_conflict_keys[]"
                                value="<?= e($inventoryCheckedConflictToken) ?>"
                            >
                            <input
                                type="hidden"
                                name="inventory_salesid_for_delete"
                                value="<?= e($inventoryPreviewSalesId) ?>"
                            >
                            <button type="submit" class="btn btn-danger px-4">
                                <i class="fa-solid fa-trash-can me-2" aria-hidden="true"></i>
                                Hapus SalesID Duplikat Ini
                            </button>
                        </form>
                    <?php else: ?>
                        <button type="button" class="btn btn-danger px-4" disabled>
                            <i class="fa-solid fa-trash-can me-2" aria-hidden="true"></i>
                            Pilihan SalesID Tidak Tersedia
                        </button>
                    <?php endif; ?>

                    <form method="post" id="finishMaintenanceForm">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="finish">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fa-solid fa-circle-check me-2" aria-hidden="true"></i>
                            Selesai &amp; Kembali ke Halaman Utama
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

</div>

<script>
(function () {
    const storeSelect = document.getElementById('store');
    const prefixDisplay = document.getElementById('prefixDisplay');

    function updatePrefix() {
        if (!storeSelect || !prefixDisplay) {
            return;
        }

        const selected = storeSelect.options[storeSelect.selectedIndex];
        const prefix = selected ? String(selected.dataset.prefix || '') : '';
        prefixDisplay.textContent = prefix ? prefix + '%' : '-';
    }

    if (storeSelect) {
        storeSelect.addEventListener('change', updatePrefix);
        updatePrefix();
    }

    const backupForm = document.getElementById('backupForm');

    if (backupForm) {
        backupForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const form = this;

            Swal.fire({
                icon: 'question',
                title: 'Backup Tiga Tabel?',
                html:
                    '<strong>1. salesdetail ke salesdetailcopy</strong><br>' +
                    '<strong>2. salespayments ke salespaymentscopy</strong><br>' +
                    '<strong>3. sales ke salescopy</strong>',
                showCancelButton: true,
                confirmButtonText: 'Ya, Jalankan Backup',
                cancelButtonText: 'Batal',
                reverseButtons: true,
                allowOutsideClick: false
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                Swal.fire({
                    title: 'Sedang Backup',
                    text: 'Jangan tutup halaman.',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: function () {
                        Swal.showLoading();
                        form.submit();
                    }
                });
            });
        });
    }

    document.querySelectorAll('.sales-conflict-delete-form').forEach(function (form) {
        const checkboxes = Array.from(
            form.querySelectorAll('.sales-conflict-checkbox')
        );
        const selectAll = form.querySelector('.sales-conflict-select-all');
        const countLabel = form.querySelector('.selected-conflict-count');
        const submitButton = form.querySelector('.conflict-delete-button');
        const rowDeleteButtons = form.querySelectorAll('.conflict-row-delete');

        function selectedCount() {
            return checkboxes.filter(function (checkbox) {
                return checkbox.checked;
            }).length;
        }

        function refreshSelection() {
            const count = selectedCount();

            if (countLabel) {
                countLabel.textContent = String(count);
            }

            if (submitButton) {
                submitButton.disabled = count === 0;
            }

            if (selectAll) {
                selectAll.checked = checkboxes.length > 0 && count === checkboxes.length;
                selectAll.indeterminate = count > 0 && count < checkboxes.length;
            }
        }

        function confirmAndSubmit() {
            const count = selectedCount();

            if (count < 1) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Belum Ada Data Dipilih',
                    text: 'Centang minimal satu baris UTAMA dari tabel sales.'
                });
                return;
            }

            Swal.fire({
                icon: 'warning',
                title: 'Hapus Data Sales?',
                html:
                    '<strong>' + count + ' baris</strong> akan dihapus hanya dari ' +
                    '<code>sales</code>.<br>' +
                    'Data pada <code>salescopy</code>, <code>salesdetail</code>, dan ' +
                    '<code>salespayments</code> tidak diubah.',
                showCancelButton: true,
                confirmButtonText: '<i class="fa-solid fa-trash-can"></i> Ya, Hapus',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#dc2626',
                reverseButtons: true,
                allowOutsideClick: false
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                Swal.fire({
                    title: 'Sedang Menghapus Data Sales',
                    text: 'Jangan tutup halaman.',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: function () {
                        Swal.showLoading();
                        form.submit();
                    }
                });
            });
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checkboxes.forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
                refreshSelection();
            });
        }

        checkboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', refreshSelection);
        });

        rowDeleteButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                const checkboxId = button.getAttribute('data-checkbox-id');
                const target = checkboxId ? document.getElementById(checkboxId) : null;

                checkboxes.forEach(function (checkbox) {
                    checkbox.checked = false;
                });

                if (target) {
                    target.checked = true;
                }

                refreshSelection();
                confirmAndSubmit();
            });
        });

        form.addEventListener('submit', function (event) {
            const submitter = event.submitter || null;

            if (submitter && submitter.classList.contains('check-salesid-button')) {
                return;
            }

            event.preventDefault();
            confirmAndSubmit();
        });

        refreshSelection();
    });

    const deleteForm = document.getElementById('deleteForm');

    if (deleteForm) {
        deleteForm.addEventListener('submit', function (event) {
            event.preventDefault();

            const expected = <?= js($deleteConfirmText) ?>;
            const actual = String(
                document.getElementById('confirmation').value || ''
            ).trim().toUpperCase();

            if (actual !== expected) {
                Swal.fire({
                    icon: 'error',
                    title: 'Konfirmasi Salah',
                    text: 'Ketik persis: ' + expected
                });
                return;
            }

            const form = this;

            Swal.fire({
                icon: 'warning',
                title: 'Delete Data Identik?',
                html:
                    'Prefix <strong><?= e($prefix) ?>%</strong> tetap dipertahankan.<br>' +
                    'Key sama dengan isi berbeda akan dilewati dan tetap berada di tabel utama.',
                showCancelButton: true,
                confirmButtonText: 'Ya, Delete',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#c62828',
                reverseButtons: true,
                allowOutsideClick: false
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                Swal.fire({
                    title: 'Sedang Delete',
                    text: 'Jangan tutup halaman.',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: function () {
                        Swal.showLoading();
                        form.submit();
                    }
                });
            });
        });
    }


    const inventorySalesProcessForm = document.getElementById('inventorySalesProcessForm');

    if (inventorySalesProcessForm) {
        inventorySalesProcessForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const form = this;
            const salesIdInput = form.querySelector('[name="inventory_salesid"]');
            const salesId = salesIdInput ? String(salesIdInput.value || '').trim() : '';

            Swal.fire({
                icon: 'question',
                title: 'Proses Inventory SalesID?',
                html:
                    'Cari <code>inventorycopy.transid</code> yang nilainya sama dengan SalesID, lalu insert row yang belum ada ke <code>inventory</code> ' +
                    'untuk SalesID <strong>' + salesId.replace(/[&<>"']/g, function (char) {
                        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[char];
                    }) + '</strong>.<br>' +
                    '<strong>Tidak ada backup inventory ke inventorycopy dan tidak ada overwrite.</strong>',
                showCancelButton: true,
                confirmButtonText: 'Ya, Proses',
                cancelButtonText: 'Batal',
                reverseButtons: true,
                allowOutsideClick: false
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                Swal.fire({
                    title: 'Sedang Memproses Inventory',
                    text: 'Data inventorycopy sedang di-insert ke inventory berdasarkan transid yang nilainya sama dengan SalesID.',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: function () {
                        Swal.showLoading();
                        form.submit();
                    }
                });
            });
        });
    }

    const inventoryIdenticalDeleteForm = document.getElementById('inventoryIdenticalDeleteForm');

    if (inventoryIdenticalDeleteForm) {
        inventoryIdenticalDeleteForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const form = this;
            const salesIdInput = form.querySelector('[name="inventory_salesid_for_delete"]');
            const salesId = salesIdInput ? String(salesIdInput.value || '').trim() : '';
            const safeSalesId = salesId.replace(/[&<>"']/g, function (char) {
                return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[char];
            });

            Swal.fire({
                icon: 'warning',
                title: 'Hapus SalesID Duplikat Ini?',
                html:
                    'Inventory untuk SalesID <strong>' + safeSalesId + '</strong> sudah identik.<br>' +
                    'Sistem akan menghapus <strong>hanya baris UTAMA pada tabel sales</strong> ' +
                    'yang tadi dipilih.<br>' +
                    '<code>salescopy</code>, <code>salesdetail</code>, <code>salespayments</code>, ' +
                    '<code>inventory</code>, dan <code>inventorycopy</code> tidak dihapus.',
                showCancelButton: true,
                confirmButtonText: '<i class="fa-solid fa-trash-can"></i> Ya, Hapus SalesID',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#dc2626',
                reverseButtons: true,
                allowOutsideClick: false
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                Swal.fire({
                    title: 'Sedang Menghapus SalesID',
                    text: 'Jangan tutup halaman.',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: function () {
                        Swal.showLoading();
                        form.submit();
                    }
                });
            });
        });
    }

    const finishMaintenanceForm = document.getElementById('finishMaintenanceForm');

    if (finishMaintenanceForm) {
        finishMaintenanceForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const form = this;

            Swal.fire({
                icon: 'question',
                title: 'Selesai?',
                text: 'Proses saat ini akan ditutup dan Anda akan kembali ke halaman utama.',
                showCancelButton: true,
                confirmButtonText: 'Ya, Selesai',
                cancelButtonText: 'Batal',
                reverseButtons: true,
                allowOutsideClick: false
            }).then(function (result) {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    }

    <?php if ($errorMessage !== ''): ?>
    Swal.fire({
        icon: <?= js($errorType === 'offline' ? 'warning' : 'error') ?>,
        title: <?= js($errorType === 'offline' ? 'Toko Offline' : 'Proses Dibatalkan') ?>,
        text: <?= js($errorMessage) ?>,
        confirmButtonText: 'Kembali',
        allowOutsideClick: false
    });
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
    Swal.fire({
        icon: 'success',
        title: 'Berhasil',
        text: <?= js($successMessage) ?>
    });
    <?php endif; ?>
})();
</script>
</body>
</html>
