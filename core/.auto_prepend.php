<?php
/**
 * ==============================================================================
 * 🚀 MATRIX CANVAS BOOTSTRAP ENGINE - MASTER AUTO-PREPEND PIPELINE (HIDDEN CORE)
 * [🔒 ระบบสำคัญล็อกตายตัว ซ่อนไฟล์ด้วยเครื่องหมายจุด . ] บันทึกงานค้าง, เคลียร์ขยะ, รันต่ออัตโนมัติ
 * ==============================================================================
 */

/**
 * 🔄 [UNIVERSAL AUTOLOADER CHANNEL]
 * 💡 ดักจับและเรียกโหลดคลาสอัตโนมัติจากโฟลเดอร์ class/ (รวมถึงคลาส DB, run, loop) 
 * ต่อไปถ้าเจ้านายจะเสริมคลาสอะไรใหม่ๆ โยนลงโฟลเดอร์ได้เลย ระบบจะดึงไปทำงานเองโดยไม่แตะต้องไฟล์หลักนี้
 */
 
define('WEBROOT', $_SERVER['DOCUMENT_ROOT']);
spl_autoload_register(function (string $className) {
    $classDir = WEBROOT . '/class/';
    $file = $classDir . $className . '.php';
    if (file_exists($file)) {
        include_once $file;
    }
});

// เริ่มต้นระบบสตาร์ทแอปพลิเคชันเดิมดั้งเดิม (ถ้ามี)
if (file_exists(WEBROOT . "/start.php")) { include_once(WEBROOT . "/start.php"); }

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(0);
ini_set('memory_limit', '-1');

// ============================================================================
// 📊 GLOBAL CONFIGURATION & DATABASE SETUP (งานสำคัญดั้งเดิม ห้ามแตะต้อง)
// ============================================================================
if (!defined('HTML_STORAGE_DIR'))  define('HTML_STORAGE_DIR', WEBROOT . '/canvas_blocks');
if (!defined('TEMPLATE_URI_DIR'))  define('TEMPLATE_URI_DIR', WEBROOT . '/templates_uri');
if (!defined('TEMP_MAX_MEMORY'))   define('TEMP_MAX_MEMORY', 2 * 1024 * 1024);

$GLOBALS["maxmemory"] = TEMP_MAX_MEMORY;
$GLOBALS["sessionId"] = $GLOBALS["sessionId"] ?? bin2hex(random_bytes(8));

// 🗄️ สตาร์ทอินสแตนซ์ฐานข้อมูลผ่านคลาส DB (SQLite สำหรับจัดเก็บคลังสถานะงานค้างฉุกเฉิน)

include_once(WEBROOT . "/AutoStorageRouter.php");
$GLOBALS["db_instance"] = DB::sqlite(WEBROOT.'database.db');
$GLOBALS["db_instance"]->connect();

// สตาร์ท Log ผ่านคลาส run
run::initLog(true, WEBROOT . '/canvas_blocks/stream_session_prepend.log');

if (!is_dir(HTML_STORAGE_DIR)) @mkdir(HTML_STORAGE_DIR, 0777, true);
if (!is_dir(TEMPLATE_URI_DIR)) @mkdir(TEMPLATE_URI_DIR, 0777, true);
init_recovery_tables();

    global $storage;
 $pdo = db_connect();
 $db = new DatabaseZIP(WEBROOT.'/database.db','L3all2525.');
 
// === 🔧 สร้างตาราง — ไม่ใช้ DEFAULT CURRENT_TIMESTAMP แล้ว ===
 $pdo->exec("CREATE TABLE IF NOT EXISTS `job_queue` (
     `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
     `job_type` VARCHAR(50) NOT NULL DEFAULT 'general',
     `payload` TEXT NULL,
     `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
     `started_at` DATETIME NULL,
     `completed_at` DATETIME NULL,
     `created_at` DATETIME NOT NULL,
     INDEX `idx_status` (`status`)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 echo '✅ ตารางพร้อมใช้งาน\n';
 
 // === เพิ่มงานเข้าคิว ===
 function addJobToQueue(PDO $pdo, string $jobType, ?string $payload = null): int
 {
     $stmt = $pdo->prepare("INSERT INTO `job_queue` (`job_type`, `payload`, `status`, `created_at`) 
         VALUES (:job_type, :payload, 'pending', :created_at)");
     
     $stmt->execute([
         ':job_type' => $jobType,
         ':payload' => $payload,
         ':created_at' => date('Y-m-d H:i:s') // ✅ ส่งเวลาจาก PHP แทน
     ]);
     
     return (int)$pdo->lastInsertId();
 }
 // === อ่านงานถัดไป มาทำงาน ===
 function getNextJob(PDO $pdo): ?array
 {
     $pdo->beginTransaction();
     
     $stmt = $pdo->prepare("SELECT * FROM `job_queue` 
         WHERE `status` = 'pending' 
         ORDER BY `id` ASC 
         LIMIT 1 
         FOR UPDATE");
     
     $stmt->execute();
     $job = $stmt->fetch(PDO::FETCH_ASSOC);
     
     if (!$job) {
         $pdo->commit();
         return null;
     }
     
     // ทำเครื่องหมายว่ากำลังทำงาน
     $upd = $pdo->prepare("UPDATE `job_queue` 
         SET `status` = 'processing', `started_at` = :started_at 
         WHERE `id` = :id");
     
     $upd->execute([
         ':started_at' => date('Y-m-d H:i:s'),
         ':id' => $job['id']
     ]);
     
     $pdo->commit();
     
     return $job;
 }
 // === อัปเดตสถานะงานเมื่อเสร็จ ===
 function markJobDone(PDO $pdo, int $jobId, bool $success): void
 {
     $stmt = $pdo->prepare("UPDATE `job_queue` 
         SET `status` = :status, `completed_at` = :completed_at 
         WHERE `id` = :id");
     
     $stmt->execute([
         ':status' => $success ? 'completed' : 'failed',
         ':completed_at' => date('Y-m-d H:i:s'),
         ':id' => $jobId
     ]);
 }
 // === ตัวอย่างการใช้งาน ===
 // เพิ่มงาน
 $jobId = addJobToQueue($pdo, 'send_email', 'to:admin@catcf.qzz.io');
 echo "✅ เพิ่มงานที่ ID: $jobId\n";
 // ดึงงานมาทำ
 $job = getNextJob($pdo);
 if ($job) {
     echo "⚙️ กำลังทำงาน ID: {$job['id']}\n";
     // ที่นี่ใส่โค้ดทำงานของคุณ...
     $success = true; // จำลองว่าสำเร็จ
     
     markJobDone($pdo, (int)$job['id'], $success);
     echo "✅ งานเสร็จสิ้น\n";
 } else {
     echo "📭 ไม่มีงานในคิว\n";
 }
 
/**
 * ⚡ SERVER-SIDE TRAFFIC STORAGE & API
 */
function recordMinuteTraffic() {
    $file = __DIR__ . '/.traffic_minutes.json';
    $currentMinute = date('Y-m-d H:i');
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    if (!is_array($data)) $data = [];
    if (!isset($data[$currentMinute])) { $data[$currentMinute] = 0; }
    $data[$currentMinute]++;
    $data = array_slice($data, -60, 60, true);
    @file_put_contents($file, json_encode($data), LOCK_EX);
}
recordMinuteTraffic();

if (isset($_GET['action']) && $_GET['action'] === 'get_chart') {
    header('Content-Type: application/json; charset=utf-8');
    $file = __DIR__ . '/.traffic_minutes.json';
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    echo json_encode(['labels' => array_keys($data), 'values' => array_values($data)]);
    exit;
}

// ⚙️ GEAR ENGINE & MICRO GIT TASK REGISTRY
class MicroToMacroGearEngine {
    private array $dimensions;
    public function __construct(array $dimensions =) { $this->dimensions = $dimensions; }
    public function driveMacroCoordinates(int $sequenceIndex): array {
        $coords = []; $current = $sequenceIndex;
        foreach ($this->dimensions as $dimSize) { $coords[] = $current % $dimSize; $current = intdiv($current, $dimSize); }
        
        // ⚡ ดึงตารางบิตความเร็วแสง O(1) ผ่านฟังก์ชันดั้งเดิม map_table ในคลาส DB
        $binL5 = map_table('bin', $coords[2] % 256);
        $macroSet   = 17 + (bindec($binL5) % 20); 
        $macroGroup = 17 + ($coords[1] % 20); 
        return [
            'raw_sequence' => $sequenceIndex, 'macro_set' => $macroSet, 'macro_group' => $macroGroup,
            'micro_chunk'  => $coords[0], 'ip_mapped' => "192.168.{$macroGroup}.{$macroSet}"
        ];
    }
}

class MicroGitTaskRegistry {
    private DB $db;
    public function __construct(DB $dbConnection) { $this->db = $dbConnection; }
    public function getHeadSequence(): int {
        $stmt = $this->db->query("SELECT current_sequence FROM git_head WHERE id = 1");
        return (int) $stmt->fetchColumn();
    }
    public function registerTask(int $sequence, array $macroState, string $typePrefix = 'P'): string {
        $taskCode = strtoupper($typePrefix) . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
        $this->db->upsert('git_tasks', [
            'task_code' => $taskCode, 'task_type' => $typePrefix, 'sequence_index' => $sequence,
            'macro_set' => $macroState['macro_set'], 'macro_group' => $macroState['macro_group'],
            'ip_mapped' => $macroState['ip_mapped'], 'status' => 'COMPLETED'
        ], 'task_code');
        $this->db->update('git_head', ['current_sequence' => $sequence], ['id' => 1]);
        return $taskCode;
    }
}

function init_recovery_tables() {
    $db = $GLOBALS["db_instance"];
    $db->exec("CREATE TABLE IF NOT EXISTS persistent_stream_state (context_tag TEXT PRIMARY KEY, last_offset INTEGER, payload_blob TEXT, status TEXT, updated_at INTEGER)");
    $db->exec("CREATE TABLE IF NOT EXISTS git_tasks (task_code TEXT PRIMARY KEY, task_type TEXT, sequence_index INTEGER, macro_set INTEGER, macro_group INTEGER, ip_mapped TEXT, status TEXT DEFAULT 'PENDING', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS git_head (id INTEGER PRIMARY KEY CHECK (id = 1), current_sequence INTEGER)");
    $db->upsert('git_head', ['id' => 1, 'current_sequence' => 0], 'id');
}

// ============================================================================
// ⚡ PERSISTENT AUTO PREPEND PIPELINE WITH RUN ENGINE & TRIGGERS (งานสำคัญกู้ภัยค้างท่อ)
// ============================================================================
function matrix_chunk_prepend(string $slice): void {}

function persistent_auto_prepend_pipeline(string $incomingPayload, string $contextTag = 'STREAM_PERSISTENT_PREPEND', int $triggerBatchSize = 2): void {
    $db = $GLOBALS["db_instance"]; if (!$db) return;
    $states = $db->select('persistent_stream_state', ['context_tag' => $contextTag], '', 1);
    $state = $states[0] ?? null;
    
    $payloadToProcess = $incomingPayload; $totalLength = mb_strlen($payloadToProcess, 'UTF-8'); $currentOffset = $totalLength; 

    if ($state && $state['status'] === 'RUNNING') {
        $payloadToProcess = $state['payload_blob']; $currentOffset = (int)$state['last_offset'];
        echo "🔄 [AUTO-RESUME PREPEND] กู้คืนสตรีมพรีเพนด์ค้างท่อที่ตำแหน่งออฟเซ็ต: {$currentOffset}\n";
    } else {
        $db->upsert('persistent_stream_state', [
            'context_tag' => $contextTag, 'last_offset' => $totalLength, 'payload_blob' => $incomingPayload,
            'status' => 'RUNNING', 'updated_at' => time()
        ], 'context_tag');
    }

    if ($currentOffset <= 0) { $db->update('persistent_stream_state', ['status' => 'COMPLETED', 'updated_at' => time()], ['context_tag' => $contextTag]); return; }
    $chunkSize = 1024; $gearEngine = new MicroToMacroGearEngine(); $registry = new MicroGitTaskRegistry($db);

    while ($currentOffset > 0) {
        $takeLength = min($chunkSize, $currentOffset); $currentOffset -= $takeLength;
        $slice = mb_substr($payloadToProcess, $currentOffset, $takeLength, 'UTF-8');
        
        run::init([$slice], function($item) use ($gearEngine, $registry, $triggerBatchSize) {
            matrix_chunk_prepend($item); $currentIndex = $registry->getHeadSequence();
            for ($i = 1; $i <= $triggerBatchSize; $i++) {
                $nextIndex = $currentIndex + $i; $macroState = $gearEngine->driveMacroCoordinates($nextIndex);
                $taskCode = $registry->registerTask($nextIndex, $macroState, 'P');
                echo "⚡ [TRIGGERED PREPEND WORKER] Task [{$taskCode}] | Seq: {$nextIndex} | Set: {$macroState['macro_set']} | IP: {$macroState['ip_mapped']}\n";
            }
            return $item;
        });

        $db->update('persistent_stream_state', ['last_offset' => $currentOffset, 'updated_at' => time()], ['context_tag' => $contextTag]);
        
        // ♻️ เคลียร์ความเครียดขยะอัตโนมัติรอบลูป คืนหน่วยความจำ
        gc_collect_cycles();
    }

    $db->update('persistent_stream_state', ['status' => 'COMPLETED', 'updated_at' => time()], ['context_tag' => $contextTag]);
    echo "✅ [SUCCESS] สตรีมข้อมูลแบบ Prepend เสร็จสิ้นพร้อมทริกเกอร์ Worker ครบถ้วน\n";
}

header('Content-Type: text/plain; charset=utf-8');
echo "🚀 [START] ระบบ Matrix Canvas Auto-Prepend พร้อมรันผ่าน run.php และ DB class แล้ว...\n\n";
persistent_auto_prepend_pipeline("ทดสอบสตรีมข้อมูลแบบ Prepend ความเร็วสูงผสาน Worker Trigger และ run class...", 'MAIN_STREAM_CONTEXT_PREPEND', 2);
