<?PHP
// =====================================================================
// 🚀 MAIN STORAGE ROUTER CLASS
// =====================================================================
class AutoStorageRouter
{
    private ?ZipManager            $zipManager   = null;
    private ?UnifiedFileAPI        $sqlarAPI      = null;
    private ?SecureGridStreamMatrix $tarManager  = null;
    private string $rootDir;
    private static int $projectId = 1;
    private static string $currentUser = 'system';
    private static ?PDO $mysqlPdo = null;
    private array $warmMemoryMap = [];
    private array $runtimeMemo   = []; // 🛡️ หน่วยความจำพักสภาวะกันดึงซ้ำ
    
    public function __construct(string $rootDir = WEBROOT . '/storage', ?PDO $mysqlPdo = null)
    {
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        self::$mysqlPdo = $mysqlPdo;
        @mkdir($this->rootDir, 0755, true);
        $this->cleanExpiredCache();
    }

    public function resolveOrFetch(string $key, callable $missingHandler) {
        // 1. เช็ก Local Storage / Mesh (0-connection)
        $cached = $this->retrieve($key);
        if ($cached !== null) {
            return UniversalArchiveDecoder::resolvePayload($cached);
        }

        // 2. เช็ก Runtime Memo (กันซ้ำใน Request เดียวกัน)
        if (!empty($this->runtimeMemo[$key])) {
            return $this->runtimeMemo[$key];
        }

        // 3. เงื่อนไขบังคับ: ถ้ายังไม่มีข้อมูลจริง ค่อยวิ่งเข้า missingHandler (Targeted Fetch)
        $payload = $missingHandler($key);
        if (empty($payload)) {
            return null;
        }

        // ลงบันทึกทั้ง Runtime Memo และ Storage Mesh
        $this->runtimeMemo[$key] = UniversalArchiveDecoder::resolvePayload($payload);
        $this->store($key, $payload, 'fission');

        return $this->runtimeMemo[$key];
    }

    /**
     * 🛡️ WARM INDEX GUARD (เกลี่ยพิกัดเข้า map เฉพาะเมื่อยังว่าง)
     */
    public function mountWarmIndexIfNeeded(array $lightweightIndex): void {
        if (empty($this->warmMemoryMap)) {
            $this->warmMemoryMap = $lightweightIndex;
        }
    }

    public function resolveLocation(string $key): ?string {
        return $this->warmMemoryMap[$key] ?? null;
    }

    // =================================================================
    // 📥 บันทึก / อ่าน
    // =================================================================
    public function store(string $key, string $data, string $mode = 'auto'): array
    {
        $size = strlen($data);
        $target = match(true) {
            $mode === 'zip'    => 'zip',
            $mode === 'sqlar'  => 'sqlar',
            $mode === 'tar'    => 'tar',
            default            => $size <= SIZE_THRESHOLD ? 'sqlar' : 'tar'
        };
        return match ($target) {
            'zip'    => $this->viaZipStore($key, $data),
            'sqlar'  => $this->viaSQLarStore($key, $data),
            'tar'    => $this->viaTarStore($key, $data),
        };
    }

    public function retrieve(string $key, string $prefer = 'auto'): ?string
    {
        if ($prefer === 'auto' || $prefer === 'zip') {
            $c = $this->getFromZip($key);    if ($c !== null) return $c;
        }
        if ($prefer === 'auto' || $prefer === 'sqlar') {
            $s = $this->getFromSQLar($key);  if ($s !== null) return $s;
        }
        return null;
    }

    public function retrieveByIndex(string $key): ?string
    {
        $info = $this->getInfo($key);
        if (!$info) return null;
        return match ($info['storage_type']) {
            'sqlar' => $this->getFromSQLar($key),
            'zip'   => $this->getFromZip($info['reference_id'] ?? $key),
            default => null
        };
    }

    // =================================================================
    // ✏️ แก้ไข
    // =================================================================
    public function update(string $key, string $newData, string $mode = 'auto'): array
    {
        return $this->store($key, $newData, $mode);
    }

    // =================================================================
    // 📋 คัดลอก
    // =================================================================
    public function copy(string $srcKey, string $destKey, string $mode = 'auto'): array
    {
        $content = $this->retrieve($srcKey, $mode);
        if ($content === null) {
            return ['success' => false, 'error' => 'ไม่พบไฟล์ต้นฉบับ'];
        }
        return $this->store($destKey, $content, $mode);
    }

    // =================================================================
    // 🔄 ย้าย / เปลี่ยนชื่อ
    // =================================================================
    public function rename(string $oldKey, string $newKey, string $mode = 'auto'): array
    {
        if ($mode === 'auto' || $mode === 'zip') {
            $res = $this->renameInZip($oldKey, $newKey);
            if ($res['success']) {
                $info = $this->getInfo($oldKey);
                $this->deleteIndex($oldKey);
                $this->updateIndex($newKey, 'zip', [
                    'size' => $info['size'] ?? 0,
                    'reference_id' => $newKey,
                    'file_type' => $info['file_type'] ?? 'application/octet-stream',
                ]);
                if ($mode === 'zip') return $res;
            }
        }
        if ($mode === 'auto' || $mode === 'sqlar') {
            $this->initSQLar();
            $oldInfo = $this->getInfo($oldKey);
            $r = $this->sqlarAPI->apiCascadeStructure('move', $oldKey, $newKey);
            if ($r['success']) {
                $this->deleteIndex($oldKey);
                $this->updateIndex($newKey, 'sqlar', [
                    'size' => $oldInfo['size'] ?? 0,
                    'file_type' => $oldInfo['file_type'] ?? 'application/octet-stream',
                    'is_dir' => $oldInfo['is_dir'] ?? 0,
                ]);
            }
            return $r;
        }
        return ['success' => false, 'error' => 'ไม่พบไฟล์'];
    }

    // =================================================================
    // 🗑️ ลบ
    // =================================================================
    public function delete(string $key, string $mode = 'auto'): array
    {
        $results = [];
        $found = false;
        
        if ($mode === 'auto' || $mode === 'zip') {
            $r = $this->deleteFromZip($key); $results['zip'] = $r;
            if ($r['success']) $found = true;
        }
        if ($mode === 'auto' || $mode === 'sqlar') {
            $r = $this->deleteFromSQLar($key); $results['sqlar'] = $r;
            if ($r['success']) $found = true;
        }
        return ['success' => $found, 'details' => $results];
    }

    // =================================================================
    // 🔍 ดัชนีกลาง — DBzip (MySQL ตาราง files)
    // =================================================================
    public function search(string $query = '', string $storageType = ''): array
    {
        $this->initSQLar();
        $params = [];
        $sql = "SELECT * FROM files WHERE project_id = ?";
        $params[] = self::$projectId;

        if ($query !== '') {
            $sql .= " AND name LIKE ?";
            $params[] = "%$query%";
        }
        if ($storageType !== '') {
            $sql .= " AND storage_type = ?";
            $params[] = $storageType;
        }
        $sql .= " ORDER BY is_dir DESC, name ASC";
        return $this->sqlarAPI->select_all($sql, $params);
    }

    public function exists(string $key, string $storageType = ''): string|false
    {
        $this->initSQLar();
        $sql = "SELECT storage_type FROM files WHERE project_id = ? AND name = ?";
        $params = [self::$projectId, $key];
        if ($storageType !== '') {
            $sql .= " AND storage_type = ?";
            $params[] = $storageType;
        }
        $row = $this->sqlarAPI->select_one($sql, $params);
        return $row ? $row['storage_type'] : false;
    }

    public function getInfo(string $key): ?array
    {
        $this->initSQLar();
        $sql = "SELECT * FROM files WHERE project_id = ? AND name = ? LIMIT 1";
        return $this->sqlarAPI->select_one($sql, [self::$projectId, $key]);
    }

    public function listAll(string $storageType = ''): array
    {
        return $this->search('', $storageType);
    }

    // =================================================================
    // 🔧 ดัชนีภายใน — อัปเดตเฉพาะเมื่อสำเร็จ
    // =================================================================
    private function updateIndex(string $key, string $storageType, array $meta = []): void
    {
        $this->initSQLar();
        $existing = $this->exists($key);
        
        $cols = [
            'project_id'   => self::$projectId,
            'name'         => $key,
            'is_dir'       => $meta['is_dir'] ?? 0,
            'size'         => $meta['size'] ?? 0,
            'last_edit'    => date('Y-m-d H:i:s'),
            'file_type'    => $meta['file_type'] ?? 'application/octet-stream',
            'storage_type' => $storageType,
            'reference_id' => $meta['reference_id'] ?? null,
            'tar_name'     => $meta['tar_name'] ?? null,
            'inner_path'   => $meta['inner_path'] ?? null,
        ];

        if ($existing) {
            $this->sqlarAPI->update_table(
                "UPDATE files SET is_dir=?, size=?, last_edit=?, file_type=?, storage_type=?, reference_id=?, tar_name=?, inner_path=? WHERE project_id=? AND name=?",
                [
                    $cols['is_dir'], $cols['size'], $cols['last_edit'], $cols['file_type'],
                    $cols['storage_type'], $cols['reference_id'], $cols['tar_name'], $cols['inner_path'],
                    $cols['project_id'], $cols['name']
                ]
            );
        } else {
            $this->sqlarAPI->update_table("INSERT INTO files (project_id,name,is_dir,size,last_edit,file_type,storage_type,reference_id,tar_name,inner_path) VALUES (?,?,?,?,?,?,?,?,?,?)",
array_values($cols)
);
}
}
private function deleteIndex(string $key): bool
{
return (bool)$this->sqlarAPI->update_table(
"DELETE FROM files WHERE project_id = ? AND name = ?",
[self::$projectId, $key]
);
}
// =================================================================
// ⚡ ZIP — เรียก ZipManager โดยตรง
// =================================================================
private function viaZipStore(string $key, string $data): array
{
$this->initZip();
$zipFile = $this->rootDir . '/' . date('Ymd') . '.zip';
if (!$this->zipManager->open($zipFile)) {
return ['success' => false, 'error' => 'เปิดไฟล์ ZIP ไม่สำเร็จ'];
}
$result = $this->zipManager->addFromString($key, $data);
$this->zipManager->close();
if ($result !== false) {
$this->updateIndex($key, 'zip', [
'size' => strlen($data),
'reference_id' => $key,
'file_type' => $this->detectFileType($key, $data),
]);
}
return ['success' => $result !== false, 'via' => 'zip', 'path' => $zipFile];
}
private function getFromZip(string $key): ?string
{
$this->initZip();
$zipFile = $this->rootDir . '/' . date('Ymd') . '.zip';
if (!$this->zipManager->open($zipFile)) return null;
$content = $this->zipManager->getFromName($key);
$this->zipManager->close();
return $content ?: null;
}
private function renameInZip(string $oldKey, string $newKey): array
{
$this->initZip();
$zipFile = $this->rootDir . '/' . date('Ymd') . '.zip';
if (!$this->zipManager->open($zipFile)) {
return ['success' => false, 'error' => 'เปิดไม่สำเร็จ'];
}
$content = $this->zipManager->getFromName($oldKey);
if ($content === false) {
$this->zipManager->close();
return ['success' => false, 'error' => 'ไม่พบไฟล์เดิม'];
}
$del = $this->zipManager->deleteName($oldKey);
$add = $this->zipManager->addFromString($newKey, $content);
$this->zipManager->close();
return ['success' => $del && $add];
}
private function deleteFromZip(string $key): array
{
$this->initZip();
$zipFile = $this->rootDir . '/' . date('Ymd') . '.zip';
if (!$this->zipManager->open($zipFile)) {
return ['success' => false];
}
$ok = $this->zipManager->deleteName($key);
$this->zipManager->close();
if ($ok !== false) {
$this->deleteIndex($key);
}
return ['success' => $ok !== false];
}
private function findInZip(string $key): bool
{
$this->initZip();
$zipFile = $this->rootDir . '/' . date('Ymd') . '.zip';
if (!$this->zipManager->open($zipFile)) return false;
$found = $this->zipManager->locateName($key);
$this->zipManager->close();
return !empty($found);
}
public function cleanExpiredCache(): void
{
$this->initZip();
$zipFile = $this->rootDir . '/' . date('Ymd') . '.zip';
if ($this->zipManager->open($zipFile)) {
$this->zipManager->cleanExpiredCache(CACHE_MAX_AGE);
$this->zipManager->close();
}
}
// =================================================================
// 📄 SQLar — เรียก UnifiedFileAPI
// =================================================================
private function viaSQLarStore(string $key, string $data): array
{
$this->initSQLar();
$result = $this->sqlarAPI->apiSave($key, $data);
if ($result['success']) {
$this->updateIndex($key, 'sqlar', [
'size' => strlen($data),
'file_type' => $this->detectFileType($key, $data),
]);
}
return $result;
}
private function getFromSQLar(string $key): ?string
{
$this->initSQLar();
$node = $this->sqlarAPI->apiReadDecompressed($key);
return $node['success'] ? ($node['content'] ?? null) : null;
}
private function deleteFromSQLar(string $key): array
{
$this->initSQLar();
$result = $this->sqlarAPI->apiDelete($key);
if ($result['success']) {
$this->deleteIndex($key);
}
return $result;
}
 //=================================================================
// 🗄️ TAR — เรียก SecureGridStreamMatrix
// =================================================================
private function viaTarStore(string $key, string $data): array
{
$this->initTar();
$outputTar = $this->rootDir . '/' . $key . '.tar';
$resultPath = $this->tarManager->processAndStoreAsTarMatrix($outputTar);
$ok = !empty($resultPath) && file_exists($resultPath);
if ($ok) {
$this->updateIndex($key, 'tar', [
'size' => filesize($resultPath),
'tar_name' => basename($resultPath),
]);
}
return ['success' => $ok, 'via' => 'tar', 'path' => $resultPath];
}
// =================================================================
// 🛠️ เครื่องมือช่วย
// =================================================================
private function detectFileType(string $key, string $data): string
{
$ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
$mimeMap = [
'css' => 'text/css',
'js' => 'application/javascript',
'json' => 'application/json',
'svg' => 'image/svg+xml',
'xml' => 'application/xml',
'html' => 'text/html',
'php' => 'application/x-httpd-php',
'txt' => 'text/plain',
];
return $mimeMap[$ext] ?? 'application/octet-stream';
}
// =================================================================
// 🔧 INITIALIZERS
// =================================================================
private function initZip(): void {
if ($this->zipManager === null) {
$this->zipManager = new ZipManager();
}
}
private function initSQLar(): void {
if ($this->sqlarAPI === null) {
global $pdo;
$this->sqlarAPI = new UnifiedFileAPI($pdo, self::$projectId, self::$currentUser);
}
}
private function initTar(): void {
if ($this->tarManager === null) {
$this->tarManager = new SecureGridStreamMatrix($this->rootDir);
}
}
} // ✅ ปิดคลาสที่บรรทัดนี้หลังจากฟังก์ชันทั้งหมดเสร็จสิ้น

