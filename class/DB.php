<?php 
// กำหนดโฟลเดอร์ชั่วคราวสำหรับแก้ปัญหา ZipArchive บน Shared Hosting
$localTmp = WEBROOT . '/tmp';
if (!is_dir($localTmp)) {
    @mkdir($localTmp, 0755, true);
}
ini_set('sys_temp_dir', $localTmp);
putenv('TMPDIR=' . $localTmp);
include_once(WEBROOT . "/class/function.php");
/**
 * 🤖 DatabaseZIP — JARVIS HYBRID ULTIMATE VERSION (รวมคลาส DB, MatrixStore, DatabaseZIP และคงการเรียกใช้ Loop กับ Ds_Vector)
 * ==============================================================
 * โค้ดชุดนี้ถูกออกแบบมาเพื่อเป็นสถาปัตยกรรมแบ็คเอนด์อเนกประสงค์ (Backend Engine) 
 * ที่ผสานการทำงานระหว่างการเก็บข้อมูลลงไฟล์ ZIP เข้ารหัส, ฐานข้อมูลเชิงสัมพันธ์ (SQLite/MySQL) 
 * และระบบจัดการโครงสร้างเมทริกซ์ประสิทธิภาพสูง พร้อมระบบป้องกันความปลอดภัยในตัว (.htaccess และสิทธิ์ไฟล์)
 */

// ==============================================================
// 1. MatrixTemplate (เทมเพลตกำหนดขนาดบล็อกมิติข้อมูล)
// ==============================================================
if (!defined('MATRIX_TPL')) define('MATRIX_TPL', true);
class MatrixTemplate {
    /** @var array กำหนดโครงสร้างบล็อกขนาดมิติต่างๆ สำหรับระบบประมวลผลเมทริกซ์ขั้นสูง */
    public const LAYERS = [
        'L3' => ['block' => 65536],
        'L2' => ['block' => 256],
        'L1' => ['block' => 16],
        'L0' => ['block' => 4],
    ];
}

// ==============================================================
// 2. MatrixStore (ระบบจัดการ แปลงร่าง และวิเคราะห์ข้อมูลเมทริกซ์/ตาราง)
// ==============================================================
if (!defined('MATRIX_STORE_V1')) define('MATRIX_STORE_V1', true);

class MatrixStore {
    private string $delimiter = "\x1F"; // อักขระกั้นคอลัมน์ (Unit Separator) สำหรับ Giti String
    private string $rowSep    = "\x1E"; // อักขระกั้นแถว (Record Separator) สำหรับ Giti String
    private array  $headers   = [];     // รายชื่อหัวตารางหรือคอลัมน์
    private array  $matrix   = [];     // ข้อมูลเมทริกซ์สองมิติภายใน
    private bool   $assocMode = false;  // โหมดอาเรย์แบบ Associative (มีคีย์ระบุชัดเจน)

    /**
     * เริ่มต้นใช้งาน MatrixStore พร้อมรับข้อมูลดิบและหัวตาราง (ถ้ามี)
     */
    public function __construct(array $data = [], ?array $headers = null) {
        if (!empty($data)) $this->fromArray($data, $headers);
    }

    /**
     * แปลงข้อมูลจาก PHP Array ให้อยู่ในรูปเมทริกซ์ภายใน
     */
    public function fromArray(array $data, ?array $headers = null): self {
        $this->assocMode = !isset($data[0]);
        $this->headers = $headers ?? $this->extractKeys($data);
        $this->matrix = [];
        foreach ($data as $key => $row) {
            $this->matrix[] = $this->flattenRow($row, $key);
        }
        return $this;
    }

    /**
     * โหลดข้อมูลจากข้อความรูปแบบ CSV หรือ Text ทั่วไป
     */
    public function fromString(string $csvLike, string $rowSep = "\n", string $colSep = ","): self {
        $lines = explode($rowSep, trim($csvLike));
        $this->headers = array_map('trim', explode($colSep, array_shift($lines)));
        $this->matrix = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $this->matrix[] = array_map('trim', explode($colSep, $line));
        }
        return $this;
    }

    /**
     * แปลงข้อมูลทั้งหมดเป็นสตริงรูปแบบพิเศษ (Giti String) สำหรับเก็บหรือส่งต่อแบบคอมแพค
     */
    public function toGitiString(): string {
        $head = implode($this->delimiter, $this->headers);
        $rows = [];
        foreach ($this->matrix as $row) $rows[] = implode($this->delimiter, $row);
        return $head . $this->rowSep . implode($this->rowSep, $rows);
    }

    /**
     * กู้คืนข้อมูลเมทริกซ์กลับมาจากรูปแบบ Giti String
     */
    public function fromGitiString(string $gitiStr): self {
        $parts = explode($this->rowSep, $gitiStr);
        $this->headers = explode($this->delimiter, array_shift($parts));
        $this->matrix = [];
        foreach ($parts as $rowStr) $this->matrix[] = explode($this->delimiter, $rowStr);
        return $this;
    }

    /**
     * ส่งออกข้อมูลในรูปแบบมาตรฐาน CSV พร้อมระบบครอบอัญประกาศกันอักขระพิเศษ
     */
    public function toCsv(): string {
        $head = implode(',', $this->headers);
        $rows = [];
        foreach ($this->matrix as $row) {
            $escaped = array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $row);
            $rows[] = implode(',', $escaped);
        }
        return $head . "\n" . implode("\n", $rows);
    }

    /**
     * แปลงเมทริกซ์กลับเป็น PHP Array ปกติ
     */
    public function toArray(): array {
        $result = [];
        foreach ($this->matrix as $row) {
            $assoc = [];
            foreach ($this->headers as $i => $key) $assoc[$key] = $row[$i] ?? null;
            $result[] = $assoc;
        }
        return $this->assocMode ? array_combine(array_keys($result), $result) : $result;
    }

    /**
     * แปลงข้อมูลเป็นตาราง HTML พร้อมแสดงผลทันที
     */
    public function toHtml(): string {
        $html = '<table border="1" cellpadding="6" cellspacing="0">';
        $html .= '<thead><tr><th>' . implode('</th><th>', $this->headers) . '</th></tr></thead><tbody>';
        foreach ($this->matrix as $row) {
            $html .= '<tr><td>' . implode('</td><td>', $row) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    /** ดึงข้อมูลเฉพาะแถวที่กำหนด */
    public function getRow(int $index): ?array { return $this->matrix[$index] ?? null; }
    
    /** ดึงข้อมูลทั้งคอลัมน์ (ระบุด้วยชื่อคอลัมน์หรือลำดับ index) */
    public function getColumn(string|int $col): array {
        $idx = is_int($col) ? $col : array_search($col, $this->headers);
        if ($idx === false) return [];
        return array_column($this->matrix, $idx);
    }

    /** ดึงค่าข้อมูลเฉพาะเจาะจง ณ ตำแหน่ง แถว และคอลัมน์ ที่ต้องการ */
    public function getCell(int $row, string|int $col): mixed {
        $idx = is_int($col) ? $col : array_search($col, $this->headers);
        return $this->matrix[$row][$idx] ?? null;
    }

    /** กำหนดค่าข้อมูลลงในเซลล์ที่ระบุ */
    public function setCell(int $row, string|int $col, mixed $value): void {
        $idx = is_int($col) ? $col : array_search($col, $this->headers);
        if (!isset($this->matrix[$row])) $this->matrix[$row] = array_fill(0, count($this->headers), null);
        $this->matrix[$row][$idx] = $value;
    }

    /** สลับแถวเป็นคอลัมน์ (Matrix Transpose) */
    public function transpose(): array {
        if (!function_exists('array_column_all')) require_once __DIR__ . '/ds_vector.php';
        return array_column_all($this->matrix);
    }

    /** แยกคีย์ทั้งหมดจากอาเรย์เพื่อใช้เป็นหัวตารางอัตโนมัติ */
    private function extractKeys(array $data): array {
        $keys = [];
        foreach ($data as $row) {
            if (!is_array($row)) continue;
            foreach (array_keys($row) as $k) if (!in_array($k, $keys)) $keys[] = $k;
        }
        return $keys;
    }

    /** จัดเรียงแถวข้อมูลให้อยู่ในรูปแบบแฟลตอาเรย์ตามหัวตาราง */
    private function flattenRow(mixed $row, string|int $key): array {
        if (!is_array($row)) return [$key, $row];
        $flat = [];
        foreach ($this->headers as $h) $flat[] = $row[$h] ?? null;
        return $flat;
    }

    /** ดึงสถิติต่างๆ ของเมทริกซ์ เช่น จำนวนแถว, คอลัมน์, และขนาด Giti String */
    public function getStats(): array {
        return [
            'rows' => count($this->matrix),
            'cols' => count($this->headers),
            'headers' => $this->headers,
            'size_giti' => strlen($this->toGitiString()),
        ];
    }
}

// ==============================================================
// 3. DB (Universal Database Driver รองรับ SQLite, MySQL, และ SQLite-in-ZIP)
// ==============================================================
class DB {
    private string $driver;
    private ?PDO $db = null;
    private array $config = [];
    private bool $connected = false;
    private ?string $tempSqliteFile = null;

    /** สร้างอัปสแตนซ์สำหรับ SQLite ทั่วไป */
    public static function sqlite(string $file = 'database.db'): self {
        return new self(['driver' => 'sqlite', 'file' => $file]);
    }

    /** สร้างอัปสแตนซ์สำหรับฐานข้อมูล SQLite ที่ถูกบีบอัดและเข้ารหัสลับอยู่ในไฟล์ ZIP */
    public static function sqliteZip(string $zipPath, string $password): self {
        return new self(['driver' => 'sqlite_zip', 'zip' => $zipPath, 'pass' => $password]);
    }

    /** สร้างอัปสแตนซ์สำหรับเชื่อมต่อ MySQL / MariaDB */
    public static function mysql(string $host, string $dbname, string $user, string $pass, int $port = 3306, string $charset = 'utf8mb4'): self {
        return new self([
            'driver' => 'mysql',
            'host' => $host,
            'dbname' => $dbname,
            'user' => $user,
            'pass' => $pass,
            'port' => $port,
            'charset' => $charset
        ]);
    }

    public function __construct(array $config) {
        $this->config = $config;
        $this->driver = $config['driver'] ?? 'sqlite';
    }

    /** เชื่อมต่อฐานข้อมูลตามไดรเวอร์ที่กำหนด */
    private function connect(): void {
        if ($this->connected && $this->db !== null) return;

        if ($this->driver === 'sqlite_zip') {
            $this->connectSQLiteZip();
        } elseif ($this->driver === 'sqlite') {
            $file = $this->config['file'] ?? 'database.db';
            $this->db = new PDO('sqlite:' . $file);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->connected = true;
        } elseif ($this->driver === 'mysql') {
            $dsn = "mysql:host={$this->config['host']};port={$this->config['port']};dbname={$this->config['dbname']};charset={$this->config['charset']}";
            $this->db = new PDO($dsn, $this->config['user'], $this->config['pass']);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->connected = true;
        }
    }

    /** ดึงไฟล์ฐานข้อมูล SQLite ออกมาจาก ZIP มาถอดรหัสเก็บไว้ใน Temp File เพื่อใช้งานชั่วคราว */
    private function connectSQLiteZip(): void {
        $zipPath = $this->config['zip'];
        $pass = $this->config['pass'];
        $dbFile = 'database.sqlite';
        $content = '';

        if (file_exists($zipPath)) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) === true) {
                $raw = $zip->getFromName($dbFile);
                $zip->close();
                if ($raw !== false && $raw !== '') {
                    $content = $this->decrypt($raw, $pass);
                }
            }
        }

        $localTmp = __DIR__ . '/tmp';
        if (!is_dir($localTmp)) {
            mkdir($localTmp, 0755, true);
        }

        $this->tempSqliteFile = tempnam($localTmp, 'sqz_');
        if ($content !== '') {
            file_put_contents($this->tempSqliteFile, $content);
        }

        $this->db = new PDO('sqlite:' . $this->tempSqliteFile);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->connected = true;
    }

    public function saveZip(): bool {
        if ($this->driver !== 'sqlite_zip' || !$this->connected || !$this->tempSqliteFile) return false;

        $this->db = null; // ปิดการเชื่อมต่อ PDO เพื่อปลดล็อกไฟล์

        if (!file_exists($this->tempSqliteFile)) return false;

        $content = file_get_contents($this->tempSqliteFile);
        $content = $this->encrypt($content, $this->config['pass']);

        $tmpZipFile = tempnam(sys_get_temp_dir(), 'dbzip_');
        if (file_exists($this->config['zip'])) {
            copy($this->config['zip'], $tmpZipFile);
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpZipFile, ZipArchive::CREATE) === true) {
            $zip->addFromString('database.sqlite', $content);
            $zip->setEncryptionName('database.sqlite', ZipArchive::EM_AES_256);
            $zip->close();
        }

        @mkdir(dirname($this->config['zip']), 0755, true);
        copy($tmpZipFile, $this->config['zip']);
        @unlink($tmpZipFile);

        @unlink($this->tempSqliteFile);
        $this->tempSqliteFile = null;
        $this->connected = false;

        return true;
    }

    /** ฟังก์ชันเข้ารหัสข้อมูลด้วย AES-256-CBC */
    private function encrypt(string $data, string $pass): string {
        $key = hash('sha256', $pass, true);
        $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        return $iv . openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    }

    /** ฟังก์ชันถอดรหัสข้อมูลด้วย AES-256-CBC */
    private function decrypt(string $data, string $pass): string {
        $key = hash('sha256', $pass, true);
        $ivLen = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $ivLen);
        return openssl_decrypt(substr($data, $ivLen), 'aes-256-cbc', $key, 0, $iv);
    }

    /** สร้างเงื่อนไข Where คิวรีอัตโนมัติพร้อมผูกพารามิเตอร์ป้องกัน SQL Injection */
    private function buildWhereClause(array $where, array &$params): string {
        if (empty($where)) return '';
        $cond = [];
        foreach ($where as $k => $v) {
            if (is_array($v)) {
                $cond[] = "`{$k}` {$v[0]} ?"; 
                $params[] = $v[1];
            } else {
                $cond[] = "`{$k}` = ?"; 
                $params[] = $v;
            }
        }
        return " WHERE " . implode(' AND ', $cond);
    }

    /** เพิ่มข้อมูล หรืออัปเดตทันทีหากเกิดความซ้ำซ้อนที่คีย์กำหนด (Upsert) */
    public function upsert(string $table, array $data, string|array $uniqueKey): int {
        $this->connect();
        $unique = (array)$uniqueKey;
        $keys = array_keys($data);
        $vals = array_values($data);

        $fields = '`' . implode('`, `', $keys) . '`';
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));

        if ($this->driver === 'mysql') {
            $updates = [];
            foreach ($keys as $k) {
                if (!in_array($k, $unique)) $updates[] = "`{$k}` = VALUES(`{$k}`)";
            }
            if (empty($updates)) $updates[] = "`{$keys[0]}` = VALUES(`{$keys[0]}`)";
            
            $sql = "INSERT INTO `{$table}` ({$fields}) VALUES ({$placeholders}) 
                    ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
        } else {
            $uniqueStr = '`' . implode('`, `', $unique) . '`';
            $updates = [];
            foreach ($keys as $k) {
                if (!in_array($k, $unique)) $updates[] = "`{$k}` = excluded.`{$k}`";
            }
            
            $sql = "INSERT INTO `{$table}` ({$fields}) VALUES ({$placeholders}) 
                    ON CONFLICT ({$uniqueStr}) DO UPDATE SET " . 
                    (implode(', ', $updates) ?: "`{$unique[0]}` = `{$unique[0]}`");
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($vals);
        return (int)$this->db->lastInsertId();
    }

    /** แทรกข้อมูลใหม่ลงในตาราง */
    public function insert(string $table, array $data): int {
        $this->connect();
        $fields = '`' . implode('`, `', array_keys($data)) . '`';
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO `{$table}` ({$fields}) VALUES ({$placeholders})";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($data));
        return (int)$this->db->lastInsertId();
    }

    /** ค้นหาข้อมูลจากตาราง พร้อมเงื่อนไข การเรียงลำดับ และจำกัดจำนวน */
    public function select(string $table, array $where = [], string $order = '', int $limit = 0): array {
        $this->connect();
        $params = [];
        $sql = "SELECT * FROM `{$table}`" . $this->buildWhereClause($where, $params);
        
        if ($order !== '') $sql .= " ORDER BY {$order}";
        if ($limit > 0) $sql .= " LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** อัปเดตข้อมูลตามเงื่อนไขที่กำหนด */
    public function update(string $table, array $data, array $where): int {
        $this->connect();
        $sets = [];
        $params = [];
        foreach ($data as $k => $v) {
            $sets[] = "`{$k}` = ?";
            $params[] = $v;
        }
        
        $sql = "UPDATE `{$table}` SET " . implode(', ', $sets);
        $sql .= $this->buildWhereClause($where, $params);
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** ลบข้อมูลตามเงื่อนไข */
    public function delete(string $table, array $where): int {
        $this->connect();
        $params = [];
        $sql = "DELETE FROM `{$table}`" . $this->buildWhereClause($where, $params);
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** นับจำนวนแถวข้อมูล */
    public function count(string $table, array $where = []): int {
        $this->connect();
        $params = [];
        $sql = "SELECT COUNT(*) FROM `{$table}`" . $this->buildWhereClause($where, $params);
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** รันคำสั่ง SQL อิสระพร้อมพารามิเตอร์ */
    public function query(string $sql, array $params = []): PDOStatement {
        $this->connect();
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** รันคำสั่ง SQL แบบ Exec ตรง */
    public function exec(string $sql): int {
        $this->connect();
        return $this->db->exec($sql);
    }

    /** ดีสตรัคเตอร์ ทำการบันทึกและแพ็กไฟล์ ZIP อัตโนมัติเมื่อสิ้นสุดการทำงานกรณีใช้ไดรเวอร์ sqlite_zip */
    public function __destruct() {
        if ($this->driver === 'sqlite_zip' && $this->connected) {
            $this->saveZip();
        }
    }
}

// ==============================================================
// 4. DatabaseZIP (ฐานข้อมูล JSON ปลอดภัยสูงในไฟล์ ZIP พร้อม SQLite State Manager)
// ==============================================================
class DatabaseZIP {
    private string $dbName;
    private string $baseSecret;
    private string $zipPath;
    private string $sqlitePath;
    private bool $locked = true;
    private array $cache = [];
    private array $indexes = [];
    private bool $indexDirty = false;
    private ?PDO $sqlite = null;

    /**
     * เริ่มต้นใช้งาน DatabaseZIP ระบุชื่อฐานข้อมูลและคีย์ลับหลัก
     */
    public function __construct(string $dbName, string $baseSecret) {
        $this->dbName = $dbName;
        $this->baseSecret = $baseSecret;
        cache_folder(__DIR__."/store");
        $this->zipPath = __DIR__ . "/store/.db_{$dbName}.zip";
        $this->sqlitePath = __DIR__ . "/store/.db_{$dbName}.state.sqlite";
        
        if (!file_exists($this->zipPath)) {
            $this->createEmptyDatabase();
        }
        
        $this->initSQLite();
        $this->locked = true;
        self::protectRoot();
    }

    /** เริ่มต้นสร้างและเชื่อมต่อ SQLite ภายใน สำหรับเก็บสถานะระบบ (State) และระบบล็อก */
    private function initSQLite(): void {
        try {
            $this->sqlite = new PDO("sqlite:" . $this->sqlitePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            $this->sqlite->exec("PRAGMA journal_mode = WAL;");
            
            $this->sqlite->exec("
                CREATE TABLE IF NOT EXISTS system_state (
                    key TEXT PRIMARY KEY,
                    value TEXT,
                    updated_at INTEGER
                );
                CREATE TABLE IF NOT EXISTS lock_status (
                    id INTEGER PRIMARY KEY CHECK (id = 1),
                    locked INTEGER DEFAULT 1,
                    locked_by TEXT,
                    locked_at INTEGER,
                    expires_at INTEGER
                );
                CREATE TABLE IF NOT EXISTS persistent_stream_state (
                    context_tag TEXT PRIMARY KEY,
                    last_offset INTEGER,
                    payload_blob TEXT,
                    status TEXT,
                    updated_at INTEGER
                );
                CREATE TABLE IF NOT EXISTS git_tasks (
                    task_code TEXT PRIMARY KEY,
                    task_type TEXT,
                    sequence_index INTEGER,
                    macro_set INTEGER,
                    macro_group INTEGER,
                    ip_mapped TEXT,
                    status TEXT DEFAULT 'PENDING',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS git_head (
                    id INTEGER PRIMARY KEY CHECK (id = 1),
                    current_sequence INTEGER
                );
                INSERT OR IGNORE INTO lock_status (id, locked) VALUES (1, 1);
                INSERT OR IGNORE INTO git_head (id, current_sequence) VALUES (1, 0);
            ");
        } catch (PDOException $e) {
            $this->sqlite = null;
        }
    }

    /** ดึงค่าสถานะระบบจากตาราง system_state */
    public function stateGet(string $key, $default = null) {
        if (!$this->sqlite) return $default;
        $stmt = $this->sqlite->prepare("SELECT value FROM system_state WHERE key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? json_decode($row['value'], true) : $default;
    }

    /** บันทึกค่าสถานะระบบลงในตาราง system_state */
    public function stateSet(string $key, $value): void {
        if (!$this->sqlite) return;
        $stmt = $this->sqlite->prepare("REPLACE INTO system_state (key, value, updated_at) VALUES (?, ?, ?)");
        $stmt->execute([$key, json_encode($value), time()]);
    }

    /** สร้างรหัสผ่านไดนามิกเปลี่ยนไปตามช่วงเวลา (Time-slot) และสภาพแวดล้อมเพื่อความปลอดภัยขั้นสุด */
    private function getCurrentPassword(): string {
        $timeSlot = date('YmdH');
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = __DIR__;
        return hash('sha256', $this->baseSecret . $timeSlot . $domain . $path);
    }

    /** ปลดล็อกฐานข้อมูลชั่วคราวเพื่อให้สามารถเขียนข้อมูลได้ */
    public function unlock(): bool {
        if ($this->isLockedByTime()) {
            throw new Exception("⏰ ล็อกตามเวลา — รหัสหมดอายุ กรุณารอชั่วโมงถัดไป");
        }
        $this->locked = false;
        if ($this->sqlite) {
            $stmt = $this->sqlite->prepare("UPDATE lock_status SET locked = 0, locked_at = ? WHERE id = 1");
            $stmt->execute([time()]);
        }
        return true;
    }

    /** ล็อกฐานข้อมูลกลับคืนเพื่อความปลอดภัย */
    public function relock(): void {
        $this->locked = true;
        $this->cache = [];
        if ($this->sqlite) {
            $stmt = $this->sqlite->prepare("UPDATE lock_status SET locked = 1, expires_at = ? WHERE id = 1");
            $stmt->execute([time() + 3600]);
        }
    }

    /** ตรวจสอบว่าหมดอายุการล็อกตามเวลาหรือไม่ */
    private function isLockedByTime(): bool {
        if (!$this->sqlite) return false;
        $stmt = $this->sqlite->query("SELECT expires_at FROM lock_status WHERE id = 1");
        $row = $stmt->fetch();
        return $row && $row['expires_at'] < time();
    }

    /** บล็อกการทำงานที่ต้องมีการเขียนข้อมูล โดยจะทำการปลดล็อกและล็อกกลับอัตโนมัติ (Closure Safe Write) */
    public function write(callable $action) {
        $wasLocked = $this->locked;
        try {
            if ($wasLocked) $this->unlock();
            $result = $action();
            $this->markIndexDirty();
            return $result;
        } finally {
            if ($wasLocked) $this->relock();
        }
    }

    /** ค้นหาข้อมูลตารางขั้นสูง รองรับเงื่อนไข, การเปรียบเทียบ, เรียงลำดับ, จำกัดจำนวน และแบ่งหน้า */
    public function select(string $table, array $where = [], array $options = []): array {
        $targetIds = null;
        foreach ($where as $key => $val) {
            if (!is_array($val)) {
                $ids = $this->findByIndex($table, $key, $val);
                if ($ids !== null) {
                    $targetIds = $ids;
                    break;
                }
            }
        }

        if ($targetIds !== null) {
            $all = $this->readTable($table);
            $data = array_filter($all, fn($row) => in_array($row['id'], $targetIds));
            $data = array_values(array_filter($data, fn($row) => $this->matchConditions($row, $where)));
        } else {
            $data = $this->readTable($table);
            if (!empty($where)) {
                $data = array_values(array_filter($data, fn($row) => $this->matchConditions($row, $where)));
            }
        }

        if (!empty($options['order'])) $this->sortResults($data, $options['order']);
        if (isset($options['limit'])) $data = array_slice($data, 0, (int)$options['limit']);
        if (isset($options['page']) && isset($options['perPage'])) {
            $offset = ($options['page'] - 1) * $options['perPage'];
            $data = array_slice($data, $offset, $options['perPage']);
        }
        if (!empty($options['column'])) return array_column($data, $options['column']);
        if (!empty($options['count'])) return ['total' => count($data)];

        return $data;
    }

    /** ตรวจสอบเงื่อนไขการค้นหาแถวข้อมูลแต่ละแถว */
    private function matchConditions(array $row, array $conditions): bool {
        foreach ($conditions as $key => $value) {
            if (is_array($value) && isset($value[0], $value[1])) {
                [$f, $op, $v] = $value;
                if (!$this->compare($row[$f] ?? null, $op, $v)) return false;
                continue;
            }
            if (($row[$key] ?? null) !== $value) return false;
        }
        return true;
    }

    /** ฟังก์ชันเปรียบเทียบข้อมูล รองรับเครื่องหมาย =, !=, >, <, LIKE, IN, BETWEEN */
    private function compare($fieldValue, string $op, $target): bool {
        $fieldValue = is_numeric($fieldValue) ? (float)$fieldValue : $fieldValue;
        $target = is_numeric($target) ? (float)$target : $target;
        switch ($op) {
            case '=':  return $fieldValue == $target;
            case '!=':
            case '<>': return $fieldValue != $target;
            case '>':  return $fieldValue > $target;
            case '>=': return $fieldValue >= $target;
            case '<':  return $fieldValue < $target;
            case '<=': return $fieldValue <= $target;
            case 'LIKE': 
                $pattern = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($target, '/')) . '$/i';
                return (bool)preg_match($pattern, (string)$fieldValue);
            case 'IN': return in_array($fieldValue, (array)$target);
            case 'NOT IN': return !in_array($fieldValue, (array)$target);
            case 'BETWEEN': 
                return is_array($target) && count($target)>=2 && 
                       $fieldValue >= $target[0] && $fieldValue <= $target[1];
            default: return $fieldValue == $target;
        }
    }

    /** เรียงลำดับผลลัพธ์ */
    private function sortResults(array &$data, $order): void {
        if (is_string($order)) $order = [[$order, 'ASC']];
        if (!is_array($order[0] ?? null)) $order = [$order];
        usort($data, function($a, $b) use ($order) {
            foreach ($order as $colDir) {
                [$col, $dir] = $colDir;
                $av = $a[$col] ?? '';
                $bv = $b[$col] ?? '';
                if ($av < $bv) return $dir === 'DESC' ? 1 : -1;
                if ($av > $bv) return $dir === 'DESC' ? -1 : 1;
            }
            return 0;
        });
    }

    /** สร้าง Index สำหรับคอลัมน์เพื่อให้ค้นหาข้อมูลได้รวดเร็วขึ้น */
    public function createIndex(string $table, string $column): void {
        $all = $this->readTable($table);
        $index = [];
        foreach ($all as $row) {
            $key = $row[$column] ?? '__NULL__';
            if (!isset($index[$key])) $index[$key] = [];
            $index[$key][] = $row['id'];
        }
        $this->indexes["{$table}:{$column}"] = $index;
        $this->saveIndex($table, $column, $index);
    }

    /** ค้นหาข้อมูลผ่าน Index ที่สร้างไว้ */
    private function findByIndex(string $table, string $column, $value): ?array {
        $indexKey = "{$table}:{$column}";
        if (!isset($this->indexes[$indexKey])) {
            $this->loadIndex($table, $column);
        }
        return $this->indexes[$indexKey][$value] ?? null;
    }

    /** โหลด Index จากไฟล์ ZIP */
    private function loadIndex(string $table, string $column): void {
        $zip = new ZipArchive();
        if ($zip->open($this->zipPath) === true) {
            $zip->setPassword($this->getCurrentPassword());
            $content = $zip->getFromName("index_{$table}_{$column}.json");
            $zip->close();
            if ($content) {
                $this->indexes["{$table}:{$column}"] = json_decode($content, true);
            }
        }
    }

    /** กำหนดให้ Index ต้องถูกสร้างหรืออัปเดตใหม่เมื่อมีการเปลี่ยนแปลงข้อมูล */
    private function markIndexDirty(): void {
        $this->indexDirty = true;
        $this->indexes = [];
    }

    /** เพิ่มข้อมูลลงตาราง (ต้องอยู่ในสถานะปลดล็อกผ่าน write() เท่านั้น) */
    public function insert(string $table, array $data): int {
        if ($this->locked) throw new Exception("🔐 ล็อกอยู่ — ใช้ write() ก่อน");
        return $this->doInsert($table, $data);
    }

    /** อัปเดตข้อมูลตารางผ่านเงื่อนไข Closure */
    public function update(string $table, array $data, callable $where): int {
        if ($this->locked) throw new Exception("🔐 ล็อกอยู่ — ใช้ write() ก่อน");
        return $this->doUpdate($table, $data, $where);
    }

    /** ลบข้อมูลตารางผ่านเงื่อนไข Closure */
    public function delete(string $table, callable $where): int {
        if ($this->locked) throw new Exception("🔐 ล็อกอยู่ — ใช้ write() ก่อน");
        return $this->doDelete($table, $where);
    }

    /** ป้องกันการเข้าถึงไฟล์โค้ดโดยตรงผ่านเบราว์เซอร์ พร้อมสร้างไฟล์ .htaccess ป้องกันไฟล์ฐานข้อมูล ZIP และ SQLite */
    public static function protectRoot(): void {
        if (basename($_SERVER['SCRIPT_NAME']) === basename(__FILE__)) {
            http_response_code(403);
            die("❌ Access Denied");
        }
        $ht = __DIR__ . '/.htaccess';
        if (!file_exists($ht)) {
            file_put_contents($ht, '<FilesMatch "\.(zip|sqlite)$">
Order Allow,Deny
Deny from all
</FilesMatch>');
        }
    }

    private function writeTable(string $table, array $data): void {
    $this->cache[$table] = $data;
    
    $localTmp = defined('WEBROOT') ? WEBROOT . '/tmp' : __DIR__ . '/../tmp';
    if (!is_dir($localTmp)) @mkdir($localTmp, 0755, true);
    
    // เปลี่ยนจาก tempnam มาเป็นสุ่มชื่อเอง
    $tmpZipFile = $localTmp . '/dbw_' . uniqid() . '_' . mt_rand(1000, 9999) . '.tmp';
    
    if (file_exists($this->zipPath)) {
        copy($this->zipPath, $tmpZipFile);
    }
    
    $zip = new ZipArchive();
    if ($zip->open($tmpZipFile, ZipArchive::CREATE) === true) {
        $zip->setPassword($this->getCurrentPassword());
        $zip->addFromString("{$table}.json", json_encode($data));
        $zip->close();
    }
    
    @mkdir(dirname($this->zipPath), 0755, true);
    copy($tmpZipFile, $this->zipPath);
    @unlink($tmpZipFile);
    chmod($this->zipPath, 0600);
}


    private function saveIndex(string $table, string $column, array $index): void {
    $localTmp = defined('WEBROOT') ? WEBROOT . '/tmp' : __DIR__ . '/../tmp';
    if (!is_dir($localTmp)) @mkdir($localTmp, 0755, true);
    
    // เปลี่ยนจาก tempnam มาเป็นสุ่มชื่อเอง
    $tmpZipFile = $localTmp . '/dbs_' . uniqid() . '_' . mt_rand(1000, 9999) . '.tmp';
    if (file_exists($this->zipPath)) {
        copy($this->zipPath, $tmpZipFile);
    }
    
    $zip = new ZipArchive();
    if ($zip->open($tmpZipFile, ZipArchive::CREATE) === true) {
        $zip->setPassword($this->getCurrentPassword());
        $zip->addFromString("index_{$table}_{$column}.json", json_encode($index));
        $zip->close();
    }
    
    copy($tmpZipFile, $this->zipPath);
    @unlink($tmpZipFile);
    chmod($this->zipPath, 0600);
}


    private function createEmptyDatabase(): void {
    // 1. กำหนดและสร้างโฟลเดอร์ชั่วคราวให้ชัวร์
    $localTmp = defined('WEBROOT') ? WEBROOT . '/tmp' : __DIR__ . '/../tmp';
    if (!is_dir($localTmp)) {
        @mkdir($localTmp, 0755, true);
    }

    // 2. ใช้การสุ่มชื่อไฟล์ตรงๆ แทนการพึ่งพาคำสั่ง tempnam ของเซิร์ฟเวอร์
    $tmpZipFile = $localTmp . '/dbc_' . uniqid() . '_' . mt_rand(1000, 9999) . '.tmp';

    $zip = new ZipArchive();
    // สั่งเปิดโดยบังคับสร้างไฟล์ขึ้นมาใหม่
    if ($zip->open($tmpZipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $zip->setPassword($this->getCurrentPassword());
        $zip->addFromString('structure.json', '{}');
        $zip->close();
    } else {
        throw new Exception("❌ ไม่สามารถสร้างไฟล์ ZIP ชั่วคราวในโฟลเดอร์โปรเจกต์ได้ กรุณาเช็กสิทธิ์โฟลเดอร์ /tmp");
    }
    
    @mkdir(dirname($this->zipPath), 0755, true);
    copy($tmpZipFile, $this->zipPath);
    @unlink($tmpZipFile);
    chmod($this->zipPath, 0600);
}


    /** อ่านข้อมูลตารางจากไฟล์ ZIP พร้อมแคชข้อมูลไว้ในหน่วยความจำชั่วคราว */
    private function readTable(string $table): array {
        if (isset($this->cache[$table])) return $this->cache[$table];
        $zip = new ZipArchive();
        if ($zip->open($this->zipPath) !== true) return [];
        $zip->setPassword($this->getCurrentPassword());
        $content = $zip->getFromName("{$table}.json");
        $zip->close();
        $data = $content ? json_decode($content, true) ?: [] : [];
        $this->cache[$table] = $data;
        return $data;
    }

    /** การกระทำจริง: เพิ่มแถวข้อมูลใหม่และสร้าง Auto-increment ID */
    private function doInsert(string $table, array $data): int {
        $all = $this->readTable($table);
        $newId = empty($all) ? 1 : max(array_column($all, 'id')) + 1;
        $data['id'] = $newId;
        $all[] = $data;
        $this->writeTable($table, $all);
        return $newId;
    }

    /** การกระทำจริง: อัปเดตข้อมูลตามเงื่อนไขฟังก์ชัน */
    private function doUpdate(string $table, array $data, callable $where): int {
        $all = $this->readTable($table);
        $count = 0;
        foreach ($all as &$row) {
            if ($where($row)) {
                $row = array_merge($row, $data);
                $count++;
            }
        }
        $this->writeTable($table, $all);
        return $count;
    }

    /** การกระทำจริง: ลบข้อมูลตามเงื่อนไขฟังก์ชัน */
    private function doDelete(string $table, callable $where): int {
        $all = $this->readTable($table);
        $new = []; $count = 0;
        foreach ($all as $row) {
            if ($where($row)) $count++;
            else $new[] = $row;
        }
        $this->writeTable($table, $new);
        return $count;
    }

    /** ตรวจสอบสถานะการล็อกปัจจุบัน */
    public function isLocked(): bool { 
        if ($this->sqlite) {
            $stmt = $this->sqlite->query("SELECT locked FROM lock_status WHERE id = 1");
            $row = $stmt->fetch();
            return (bool)($row['locked'] ?? $this->locked);
        }
        return $this->locked; 
    }

    /** ดึงอินสแตนซ์ SQLite ภายในระบบ State */
    public function getSQLite(): ?PDO { return $this->sqlite; }

    /** แสดงรายชื่อตารางทั้งหมดใน DatabaseZIP */
    public function listTables(): array {
        $zip = new ZipArchive();
        if ($zip->open($this->zipPath) !== true) return [];
        $zip->setPassword($this->getCurrentPassword());
        
        $tables = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (pathinfo($name, PATHINFO_EXTENSION) === 'json') {
                $table = substr($name, 0, -5); // ตัด .json
                if (!str_starts_with($table, 'index_')) {
                    $tables[] = $table;
                }
            }
        }
        $zip->close();
        return $tables;
    }
}
?>
