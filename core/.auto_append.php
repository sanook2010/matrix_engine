<?php
/**
 * ==============================================================================
 * 🚀 MATRIX CANVAS BOOTSTRAP ENGINE - AUTO-APPEND PIPELINE (UNIFIED + STORAGE ROUTER)
 * [🔒 ระบบสำคัญล็อกตายตัว ซ่อนไฟล์ด้วยเครื่องหมายจุด . ] บันดันบิตลงดิสก์ dat และทำลายขยะแรมเป็นศูนย์
 * 🧭 เพิ่ม: AutoStorageRouter — แยกเก็บอัตโนมัติ ZIP แคช / SQLar / TAR
 * ==============================================================================
 */

error_reporting(E_ALL); ini_set('display_errors', '1'); set_time_limit(0); ini_set('memory_limit', '-1');
define('WEBROOT', $_SERVER['DOCUMENT_ROOT']);
// ✅ โหลดเราเตอร์ก่อน — เพิ่มบรรทัดเดียว
if (!defined('IN_STORAGE_ROUTER')) {
    define('IN_STORAGE_ROUTER', true);
    require_once WEBROOT . '/core/AutoStorageRouter.php';
}

if (!defined('HTML_STORAGE_DIR'))  define('HTML_STORAGE_DIR', WEBROOT . '/canvas_blocks');
if (!defined('TEMPLATE_URI_DIR'))  define('TEMPLATE_URI_DIR', WEBROOT . '/templates_uri');
if (!defined('TEMP_MAX_MEMORY'))   define('TEMP_MAX_MEMORY', 2 * 1024 * 1024);

$GLOBALS["maxmemory"] = TEMP_MAX_MEMORY; $GLOBALS["sessionId"] = $GLOBALS["sessionId"] ?? bin2hex(random_bytes(8));
$GLOBALS["db_instance"] = DB::sqlite(WEBROOT.'/database.db'); $GLOBALS["db_instance"]->connect();

// ✅ เริ่มต้นเราเตอร์ — เพิ่ม 1 บล็อก
$GLOBALS["storage"] = new AutoStorageRouter(WEBROOT . '/storage');

run::initLog(true, WEBROOT . '/canvas_blocks/stream_session_append.log');
init_recovery_tables();

/**
 * ⚡ save_row — บันทึกแล้วคืนค่าทันที ไม่ต้องอ่านกลับจาก DB
 * 
 * @param string $table ชื่อตาราง
 * @param string $data ข้อมูล คั่นด้วยช่องว่าง
 * @param string|null $columns ชื่อคอลัมน์ คั่นด้วยช่องว่าง (ถ้าไม่มี ใช้ c0,c1... อัตโนมัติ)
 * @param bool $withReturn true=คืนข้อมูลทันทีจากค่าที่ส่งเข้าไป / false=แค่บันทึก
 * @param PDO|null $pdo การเชื่อมต่อ DB
 * @return bool|array
 */
function save_row(string $table, string $data, ?string $columns = null, bool $withReturn = false, ?PDO $pdo = null): bool|array
{
    global $db_instance;
    $db = $pdo ?? $db_instance;
    
    // ✅ ขั้นที่ 1: แปลงข้อมูลทันทีจากสิ่งที่ส่งมา — ไม่ต้องแตะ DB
    $vals = array_values(array_filter(explode(' ', trim($data))));
    $n = count($vals);
    if ($n === 0) return false;
    
    // เตรียมชื่อคอลัมน์
    if ($columns !== null && trim($columns) !== '') {
        $colArr = array_values(array_filter(explode(' ', trim($columns))));
        if (count($colArr) !== $n) return false;
    } else {
        $colArr = range(0, $n - 1);
        array_walk($colArr, fn(&$v, $i) => $v = "c$i");
    }
    
    // ✅ สร้างข้อมูลคืนค่าทันที ก่อนจะไปติดต่อ DB เลย
    $ready = array_combine($colArr, $vals);
    
    // ✅ ขั้นที่ 2: บันทึก DB — ทำงานต่อ ไม่รอ
    if ($db) {
        try {
            $colList = '`' . implode('`, `', $colArr) . '`';
            $pholders = implode(', ', array_fill(0, $n, '?'));
            $stmt = $db->prepare("INSERT INTO `{$table}` ({$colList}) VALUES ({$pholders})");
            $stmt->execute($vals);
        } catch (PDOException $e) {
            return false;
        }
    }
    
    // ✅ คืนค่าทันที — มาจากสิ่งที่ส่งเข้ามา ไม่ได้อ่านจาก DB
    return $withReturn ? $ready : true;
}

class MicroToMacroGearEngine {
    private array $dimensions; public function __construct(array $dimensions = []) { $this->dimensions = $dimensions; }
    public function driveMacroCoordinates(int $sequenceIndex): array {
        $coords = []; $current = $sequenceIndex; foreach ($this->dimensions as $dimSize) { $coords[] = $current % $dimSize; $current = intdiv($current, $dimSize); }
        $macroSet = 17 + ($coords[2] % 20); $macroGroup = 17 + ($coords[1] % 20);
        return ['raw_sequence' => $sequenceIndex, 'macro_set' => $macroSet, 'macro_group' => $macroGroup, 'micro_chunk' => $coords[0], 'ip_mapped' => "192.168.{$macroGroup}.{$macroSet}"];
    }
}

class MicroGitTaskRegistry {
    private DB $db; public function __construct(DB $dbConnection) { $this->db = $dbConnection; }
    public function getHeadSequence(): int { $stmt = $this->db->query("SELECT current_sequence FROM git_head WHERE id = 1"); return (int) $stmt->fetchColumn(); }
    public function registerTask(int $sequence, array $macroState, string $typePrefix = 'H'): string {
        $taskCode = strtoupper($typePrefix) . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
        $this->db->upsert('git_tasks', ['task_code' => $taskCode, 'task_type' => $typePrefix, 'sequence_index' => $sequence, 'macro_set' => $macroState['macro_set'], 'macro_group' => $macroState['macro_group'], 'ip_mapped' => $macroState['ip_mapped'], 'status' => 'COMPLETED'], 'task_code');
        $this->db->update('git_head', ['current_sequence' => $sequence], ['id' => 1]);
        
        // ✅ บันทึก task ลง SQLar อัตโนมัติ — เพิ่ม 1 บรรทัด
        global $storage; $storage->store("task_{$taskCode}.json", json_encode($macroState), 'sqlar');
        
        return $taskCode;
    }
}

// ✅ ปรับ matrix_chunk_append ให้ส่งไปเก็บ — เพิ่มตรงนี้
function matrix_chunk_append(string $slice): void {
    global $storage, $contextTag;
    $key = ($contextTag ?? 'stream') . '_chunk_' . md5($slice) . '.dat';
    $storage->store($key, $slice, 'zip'); // ชิ้นสตรีม → ZIP แคช
}

function persistent_auto_append_pipeline(string $incomingPayload, string $contextTag = 'MAIN_STREAM_CONTEXT_APPEND', int $triggerBatchSize = 2): void {
    $db = $GLOBALS["db_instance"]; if (!$db) return;
    $states = $db->select('persistent_stream_state', ['context_tag' => $contextTag], '', 1); $state = $states[0] ?? null;
    $payloadToProcess = $incomingPayload; $currentOffset = 0;

    if ($state && $state['status'] === 'RUNNING') {
        $payloadToProcess = $state['payload_blob']; $currentOffset = (int)$state['last_offset'];
        echo "🔄 [AUTO-RESUME APPEND] กู้คืนสตรีมแอฟเพนด์ค้างท่อที่ตำแหน่งออฟเซ็ต: {$currentOffset}\n";
    } else {
        $db->upsert('persistent_stream_state', ['context_tag' => $contextTag, 'last_offset' => 0, 'payload_blob' => $incomingPayload, 'status' => 'RUNNING', 'updated_at' => time()], 'context_tag');
    }

    $totalLength = mb_strlen($payloadToProcess, 'UTF-8'); if ($currentOffset >= $totalLength) { $db->update('persistent_stream_state', ['status' => 'COMPLETED', 'updated_at' => time()], ['context_tag' => $contextTag]); return; }
    $chunkSize = 1024; $gearEngine = new MicroToMacroGearEngine(); $registry = new MicroGitTaskRegistry($db);

    // ✅ เก็บเพย์โหลดทั้งหมดลง TAR — เพิ่ม 1 บรรทัด
    global $storage; $storage->store("{$contextTag}_full_payload.dat", $incomingPayload, 'tar');

    while ($currentOffset < $totalLength) {
        $slice = mb_substr($payloadToProcess, $currentOffset, $chunkSize, 'UTF-8');
        run::init([$slice], function($item) use ($gearEngine, $registry, $triggerBatchSize, $contextTag) {
            matrix_chunk_append($item, $contextTag); 
            $currentIndex = $registry->getHeadSequence();
            for ($i = 0; $i < $triggerBatchSize; $i++) {
                $nextIndex = $currentIndex + $i; $macroState = $gearEngine->driveMacroCoordinates($nextIndex);
                $taskCode = $registry->registerTask($nextIndex, $macroState, 'H');
                echo "⚡ [TRIGGERED APPEND WORKER] Task [{$taskCode}] | Seq: {$nextIndex} | Set: {$macroState['macro_set']} | IP: {$macroState['ip_mapped']}\n";
            }
            return $item;
        });
        $currentOffset += mb_strlen($slice, 'UTF-8');
        $db->update('persistent_stream_state', ['last_offset' => $currentOffset, 'updated_at' => time()], ['context_tag' => $contextTag]);
        gc_collect_cycles();
    }
    $db->update('persistent_stream_state', ['status' => 'COMPLETED', 'updated_at' => time()], ['context_tag' => $contextTag]);
    echo "✅ [SUCCESS] สตรีมข้อมูลแบบ Append เสร็จสิ้นพร้อมทริกเกอร์ Worker ครบถ้วน\n";
}

header('Content-Type: text/plain; charset=utf-8');
echo "🚀 [START] ระบบ Matrix Canvas Auto-Append พร้อมรันผ่าน run.php และ DB class + Storage Router แล้ว...\n\n";
persistent_auto_append_pipeline("ทดสอบสตรีมข้อมูลแบบ Append ความเร็วสูงผสาน Worker Trigger และ run class...", 'MAIN_STREAM_CONTEXT_APPEND', 2);

// 💾 เคลียร์ Buffer สตรีมและ Pointer ทั้งหมดลงดิสก์
if (!empty($GLOBALS['STREAM_POINTERS'])) {
    foreach ($GLOBALS['STREAM_POINTERS'] as $fp) { if (is_resource($fp)) { fflush($fp); fclose($fp); } }
    $GLOBALS['STREAM_POINTERS'] = [];
}
run::close();
gc_collect_cycles();
