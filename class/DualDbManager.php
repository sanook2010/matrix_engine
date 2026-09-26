<?php
// =========================================================================
// class/DualDbManager.php - Robust Dual Database Engine (Parent Class)
//
// [FIX] เดิมไฟล์นี้ปิด class ที่บรรทัด 92 แล้วตามด้วย initTables / saveIndex
//       /lookupIndex วางค้างอยู่นอกเครื่องหมายปีกกา ทำให้ PHP Parse error
//       "unexpected token protected" ตอน runtime เก็บกวาดทั้งหมดเข้าในคลาส
// =========================================================================
class DualDbManager {
    protected $mysqlPdo;
    protected $sqlitePdo;
    protected $projectId;
    protected $currentUser;

    public function __construct($mysqlPdo, $sqliteDbPath, $projectId, $currentUser) {
        $this->mysqlPdo = $mysqlPdo;
        $this->projectId = (int)$projectId;
        $this->currentUser = $currentUser;

        $cleanSqlitePath = $this->normalizePath($sqliteDbPath);
        $this->initializeSqliteConnection($cleanSqlitePath);
        $this->initTables();
    }

    private function normalizePath($path) {
        if (empty($path)) {
            throw new InvalidArgumentException("SQLite database path cannot be empty.");
        }
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#(?<!:)/+#', '/', $path);
        $parts = explode('/', $path);
        $absolutes = [];
        foreach ($parts as $part) {
            if ($part === '.' || $part === '') continue;
            if ($part === '..') array_pop($absolutes);
            else $absolutes[] = $part;
        }
        $cleanedPath = implode('/', $absolutes);
        if (substr($path, 0, 1) === '/') {
            $cleanedPath = '/' . $cleanedPath;
        } elseif (!preg_match('/^[a-zA-Z]:/', $path)) {
            $cleanedPath = './' . $cleanedPath;
        }
        return $cleanedPath;
    }

    private function initializeSqliteConnection($sqliteDbPath) {
        try {
            $dir = dirname($sqliteDbPath);
            if (!empty($dir) && $dir !== '.' && $dir !== '..' && !is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $this->sqlitePdo = new PDO("sqlite:" . $sqliteDbPath);
            $this->sqlitePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->sqlitePdo->exec("PRAGMA journal_mode=WAL;");
        } catch (Exception $e) {
            throw new Exception("SQLar SQLite Kernel Init Failed: " . $e->getMessage());
        }
    }

    public function getMysqlPdo() { return $this->mysqlPdo; }
    public function getSqlitePdo() { return $this->sqlitePdo; }

    protected function initTables(): void {
        if ($this->mysqlPdo) {
            $this->mysqlPdo->exec("CREATE TABLE IF NOT EXISTS idx_small (
                item_key VARCHAR(255) PRIMARY KEY,
                size INT NOT NULL,
                md5 CHAR(32),
                access_count INT DEFAULT 1,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB");
        }
        if ($this->sqlitePdo) {
            $this->sqlitePdo->exec("CREATE TABLE IF NOT EXISTS idx_large (
                item_key TEXT PRIMARY KEY,
                size INTEGER NOT NULL,
                block_level INTEGER DEFAULT 0,
                tar_path TEXT,
                md5 TEXT,
                accessed_at DATETIME DEFAULT (datetime('now'))
            )");
        }
    }

    public function saveIndex(string $key, int $size, array $meta = []): void {
        if ($size <= 102400) {
            if (!$this->mysqlPdo) return;
            $stmt = $this->mysqlPdo->prepare("
                INSERT INTO idx_small (item_key, size, md5) VALUES (:k, :s, :m)
                ON DUPLICATE KEY UPDATE size=VALUES(size), md5=VALUES(md5), access_count=access_count+1
            ");
            $stmt->execute([':k' => $key, ':s' => $size, ':m' => $meta['md5'] ?? '']);
        } else {
            if (!$this->sqlitePdo) return;
            $stmt = $this->sqlitePdo->prepare("
                INSERT OR REPLACE INTO idx_large (item_key, size, block_level, tar_path, md5)
                VALUES (:k, :s, :l, :p, :m)
            ");
            $stmt->execute([
                ':k' => $key, ':s' => $size,
                ':l' => $meta['block_level'] ?? 0,
                ':p' => $meta['tar_path'] ?? '',
                ':m' => $meta['md5'] ?? ''
            ]);
        }
    }

    public function lookupIndex(string $key): ?array {
        if ($this->mysqlPdo) {
            $s = $this->mysqlPdo->prepare("SELECT size, md5 FROM idx_small WHERE item_key=?");
            $s->execute([$key]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) return ['target' => 'sqlar', 'size' => (int)$r['size'], 'md5' => $r['md5'] ?? ''];
        }
        if ($this->sqlitePdo) {
            $l = $this->sqlitePdo->prepare("SELECT size, block_level, tar_path, md5 FROM idx_large WHERE item_key=?");
            $l->execute([$key]);
            $r = $l->fetch(PDO::FETCH_ASSOC);
            if ($r) return $r + ['target' => 'tar'];
        }
        return null;
    }
}
