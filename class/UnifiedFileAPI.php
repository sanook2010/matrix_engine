<?php
// class/UnifiedFileAPI.php - (Fission Ingress & Output Pipeline for API.php Compatibility - FULL FIXED VERSION)
// =================================================================================

class UnifiedFileAPI {
    private $pdo;
    private $projectId;
    private $currentUser;
    private $projectDb;

    /**
     * Constructor: ผูกพารามิเตอร์คุมงานและเชื่อมดรรชนีความปลอดภัยร่วมกัน
     */
    public function __construct($pdo, $projectId, $currentUser, $projectDb = null) {
        $this->pdo = $pdo;
        $this->projectId = (int)$projectId;
        $this->currentUser = $currentUser;
        $this->projectDb = $projectDb;
    }

    // =========================================================================
    // ⚙️ [4 เมธอดขับเคลื่อนคำสั่ง MySQL PDO Access Driver ของพี่]
    // =========================================================================
    public function select_one($query, array $params = []) {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) { return null; }
    }

    public function select_all($query, array $params = []) {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) { return []; }
    }

    public function update_table($query, array $params = []) {
        try {
            $stmt = $this->pdo->prepare($query);
            return $stmt->execute($params);
        } catch (Exception $e) { return false; }
    }

    public function delete_table($query, array $params = []) {
        try {
            $stmt = $this->pdo->prepare($query);
            return $stmt->execute($params);
        } catch (Exception $e) { return false; }
    }

    // =========================================================================
    // 📥 1. ขาบันทึก/แก้ไขไฟล์ (API SAVE): หลอมประจุ Fission เข้าโรงเก็บอัจฉริยะ
    // =========================================================================
    public function apiSave($filename, $content, $is_dir = false, string $customFileType = null) {
        global $storage;
        $router = $storage ?? new AutoStorageRouter(WEBROOT . '/storage', $this->pdo, $this->projectId, $this->currentUser);

        if ($content === null) { $content = ''; }
        if (!empty($content) && is_string($content) && !$is_dir) {
            if (base64_encode(base64_decode($content, true)) === $content) {
                $content = base64_decode($content);
            }
        }

        // 🚀 รันผ่าน Fission Engine บนแรมจำลองความเร็วแสง O(1) พิกัด 256 บิตตายตัว
        $fissionBatchData = [
            'sequence'    => mt_rand(100000, 999999),
            'block_set'   => 0,
            'block_group' => 0,
            'block_chunk' => 0,
            'payload'     => $content,
            'created_at'  => time()
        ];

        $fissionEngine = new FissionMatrixEngine($fissionBatchData);
        $perfectMatrix = $fissionEngine->matrixGrid;

        if (empty($perfectMatrix)) { return ['success' => false, 'message' => 'Fission Matrix Error']; }

        // มัดรวมรหัสพิกัดพิกเซลชุดสะอาด ยิงตูมเดียวส่งเข้าคลังจัดเก็บของเราเตอร์สากล
        $finalPayloadData = json_encode($perfectMatrix, JSON_UNESCAPED_UNICODE);
        $routerResult = $router->store($filename, $finalPayloadData, 'auto');

        if ($routerResult['success']) {
            $this->syncMetadataLogs($filename, strlen($content), $is_dir, $customFileType);
            $fissionEngine->flushRemainingEvents(); // ล้างคิว Pointer
            unset($fissionEngine, $perfectMatrix);
            gc_collect_cycles(); // ทำลายเศษขยะแรมเป็นศูนย์
            return ['success' => true];
        }
        return ['success' => false];
    }

    public function apiUpload(array $customFields = []) {
        $filename = $_POST['filename'] ?? $_FILES['file']['name'] ?? '';
        $textContent = $_POST['text_content'] ?? null;
        if (empty($filename)) { return ['success' => false, 'message' => 'ไม่ระบุชื่อไฟล์ปลายทาง']; }

        $contentToProcess = '';
        if ($textContent !== null) {
            $contentToProcess = $textContent;
        } elseif (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $contentToProcess = file_get_contents($_FILES['file']['tmp_name']);
        } else {
            return ['success' => false, 'message' => 'ไม่พบข้อมูล Payload'];
        }

        $fileType = $customFields['file_type'] ?? null;
        return $this->apiSave($filename, $contentToProcess, false, $fileType);
    }

    // =========================================================================
    // 📖 2. ขาเรียกอ่านไฟล์ย้อนกลับ (API READ): ดึงพิกัดเราเตอร์มาแกะสกัดคืนรูปให้ตรงกัน
    // =========================================================================
    public function apiReadDecompressed($filename) {
        global $storage;
        if (function_exists('cache_folder')) {
            @cache_folder("../storage");
        }
        $router = $storage ?? new AutoStorageRouter(WEBROOT . '/storage', $this->pdo, $this->projectId, $this->currentUser);

        // 🚀 เจาะดึงชุดรหัสผ่านดิสก์อัจฉริยะ O(1) ของเราเตอร์ส่วนกลาง
        $encodedMatrixString = $router->retrieve($filename);
        if ($encodedMatrixString === null) return null;

        // แกะชุดรหัสโครงสร้าง Array ที่ Fission Engine เคยมัดรวบเอาไว้ตอนขาส่ง
        $matrixData = json_decode($encodedMatrixString, true);
        $originalFileContent = '';

        if (is_array($matrixData)) {
            // วนรอบดึงก้อนเนื้อหาพิกเซลย่อย (payload) คืนรูปกลับมาต่อกันเป็นสายสตริงของไฟล์ดั้งเดิม 100%
            foreach ($matrixData as $row) {
                $originalFileContent .= $row['payload'] ?? '';
            }
        } else {
            // แผนสำรองกรณีเป็นไฟล์ระบบขนาดสั้นดั้งเดิมที่ไม่ได้ผ่านท่อ Fission
            $originalFileContent = $encodedMatrixString;
        }

        // ดักแกะดีคอมเพรสสายสตรีมเพื่อความเข้ากันได้กับระบบประมวลผลไฟล์ SQLar
        if ($decompressed = @gzdecode($originalFileContent)) { $originalFileContent = $decompressed; } 
        elseif ($decompressed = @gzinflate($originalFileContent)) { $originalFileContent = $decompressed; }

        // คืนร่างอาร์เรย์โครงสร้างเนื้อสะอาดให้ตรงล็อกกับที่สวิตช์ของ api.php สั่ง VIEW ค้นหา
        return [
            'success' => true,
            'name'    => $filename,
            'is_dir'  => 0,
            'content' => $originalFileContent // เนื้อไฟล์ความละเอียดดั้งเดิมพร้อมส่งครอบ Base64
        ];
    }

    // =========================================================================
    // 🔧 STRUCTURAL & DIRECTORY MODULES - (ระบบคุมสารบัญไฟล์ดั้งเดิมของเจ้านาย)
    // =========================================================================
    public function apiList() {
        $stmt = $this->pdo->prepare("SELECT name, is_dir, size, last_edit, file_type FROM files WHERE project_id = ? ORDER BY is_dir DESC, name ASC");
        $stmt->execute([$this->projectId]);
        return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
    }

    public function apiCreateFolder(string $path) {
        $folderPath = rtrim(trim($path), '/') . '/';
        return $this->apiSave($folderPath, '', true, 'inode/directory');
    }

    public function apiExtractZip(string $filename, string $targetFolder = '') {
        global $storage;
        $router = $storage ?? new AutoStorageRouter(WEBROOT . '/storage', $this->pdo, $this->projectId, $this->currentUser);
        $encodedMatrixString = $router->retrieve($filename);
        if (!$encodedMatrixString) {
            return ['success' => false, 'message' => 'ไม่พบไฟล์ ZIP ที่ระบุ'];
        }

        $matrixData = json_decode($encodedMatrixString, true);
        $rawZipContent = '';
        if (is_array($matrixData)) {
            foreach ($matrixData as $row) {
                $rawZipContent .= $row['payload'] ?? '';
            }
        } else {
            $rawZipContent = $encodedMatrixString;
        }

        if (empty($rawZipContent)) {
            return ['success' => false, 'message' => 'เนื้อหาไฟล์ ZIP ว่างเปล่า'];
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'fission_zip_');
        file_put_contents($tmpZip, $rawZipContent);

        $zip = new ZipArchive();
        $extractedCount = 0;
        if ($zip->open($tmpZip) === TRUE) {
            $baseTarget = trim($targetFolder, '/');
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                $contentStream = $zip->getFromIndex($i);
                $destPath = empty($baseTarget) ? $entryName : $baseTarget . '/' . $entryName;
                $isDir = str_ends_with($entryName, '/');
                $this->apiSave($destPath, $isDir ? '' : $contentStream, $isDir);
                $extractedCount++;
            }
            $zip->close();
        } else {
            @unlink($tmpZip);
            return ['success' => false, 'message' => 'ไม่สามารถเปิดไฟล์ ZIP Archive ได้'];
        }
        @unlink($tmpZip);

        return [
            'success' => true, 
            'message' => "แตกไฟล์เรียบร้อย จำนวน {$extractedCount} รายการ",
            'extracted_count' => $extractedCount
        ];
    }

    public function apiDelete($filename) {
        global $storage;
        $router = $storage ?? new AutoStorageRouter(WEBROOT . '/storage', $this->pdo, $this->projectId, $this->currentUser);
        $res = $router->delete($filename, 'auto');
        if ($res['success']) {
            $stmt = $this->pdo->prepare("DELETE FROM files WHERE project_id = ? AND (name = ? OR name LIKE ?)");
            $stmt->execute([$this->projectId, $filename, $filename . '/%']);
        }
        return ['success' => $res['success']];
    }

    public function apiCascadeStructure($mode, $src, $dest) {
        global $storage;
        $router = $storage ?? new AutoStorageRouter(WEBROOT . '/storage', $this->pdo, $this->projectId, $this->currentUser);
        if ($mode === 'move' || $mode === 'rename') { return ['success' => $router->rename($src, $dest, 'auto')['success']]; }
        if ($mode === 'copy') { return ['success' => $router->copy($src, $dest, 'auto')['success']]; }
        return ['success' => false];
    }

    private function syncMetadataLogs($filename, int $size, bool $is_dir, string $customFileType = null) {
        $fileType = $customFileType ?? ($is_dir ? 'inode/directory' : 'application/octet-stream');
        try {
            $stmt = $this->pdo->prepare("INSERT INTO files (project_id, name, is_dir, size, last_edit, file_type) VALUES (?, ?, ?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE size = ?, last_edit = NOW(), file_type = COALESCE(?, file_type)");
            $stmt->execute([$this->projectId, $filename, ($is_dir ? 1 : 0), $size, $fileType, $size, $customFileType]);
        } catch (Exception $e) { /* Bypass */ }
    }
}
?>
