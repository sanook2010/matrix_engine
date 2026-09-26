<?php
// =========================================================================
// api.php - [CENTRALIZED ROUTER - PERFORMANCE TELEMETRY CLUSTER]
// =========================================================================
ini_set('display_errors', 0);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
 
if (!defined('WEBROOT')) {
    define('WEBROOT', $_SERVER['DOCUMENT_ROOT']);
}
require_once WEBROOT . "/autoload.php";

// ✅ แก้: เชื่อมต่อฐานข้อมูลก่อน
$pdo = null;
if (!class_exists('database')){
require_once WEBROOT . "/core/database.php";
}

// ฟังก์ชันช่วยดึงค่า input
$INPUT = function($key, $default = '') {
    return $_POST[$key] ?? $_GET[$key] ?? $default;
};

$LOCAL_ENV = [];
$LOCAL_ENV['action'] = $INPUT('action');
$LOCAL_ENV['filename'] = $INPUT('filename');

// รองรับ JSON Payload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $INPUT('payload') !== null) {
    $rawPayload = $INPUT('payload') ?? '';
    
    @file_put_contents(WEBROOT . '/cache/payload_debug.log', 
        date('Y-m-d H:i:s') . " RAW: " . substr($rawPayload, 0, 1000) . "\n\n", FILE_APPEND);
    
    if (strpos($rawPayload, '%') !== false) {
        $rawPayload = urldecode($rawPayload);
    }
    $decodedJson = json_decode($rawPayload, true);
    if (is_array($decodedJson)) { 
        $_POST = array_merge($_POST, $decodedJson); 
        $LOCAL_ENV['action'] = $decodedJson['action'] ?? $LOCAL_ENV['action'];
        $LOCAL_ENV['filename'] = $decodedJson['filename'] ?? $decodedJson['fileName'] ?? $LOCAL_ENV['filename'];
        
        @file_put_contents(WEBROOT . '/cache/payload_debug.log', 
            "DECODED ACTION: " . $LOCAL_ENV['action'] . " | Filename: " . $LOCAL_ENV['filename'] . "\n", FILE_APPEND);
    } else {
        @file_put_contents(WEBROOT . '/cache/payload_debug.log', "JSON DECODE FAILED\n", FILE_APPEND);
    }
}

// ✅ สร้างโฟลเดอร์ cache ถ้ายังไม่มี
if (!is_dir(WEBROOT . '/cache')) {
    @mkdir(WEBROOT . '/cache', 0755, true);
}

$logFilePath = WEBROOT . '/cache/project_telemetry.log';
if (class_exists('run')) {
    run::initLog(true, $logFilePath);
}

// ✅ แก้: ส่ง $pdo แทน $con
$authGuard = new ProjectAuthManager($pdo);
$userAuth = new UserAuth($pdo); 

$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$tokenFromHeader = '';
if (!empty($authHeader) && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $tokenFromHeader = $matches[1] ?? ''; 
} else {
    $tokenFromHeader = $INPUT('token') ?? '';
}

$authResult = null;
if (class_exists('run') && method_exists($authGuard, 'authenticateViaToken')) {
    $authResult = run::init($authGuard->authenticateViaToken($tokenFromHeader));
}
$projectDb = ''; 
$projectId = 0;
$currentUser = 'guest';

if ($authResult && isset($authResult['success']) && $authResult['success'] === true) {
    $projectId = (int)($authResult['id'] ?? $authResult['project_id'] ?? 0);
    $currentUser = $authResult['username'] ?? '';
    $projectDb = stripslashes($authResult['project_db'] ?? '') ?? ''; 
} else {
    $sessionUser = null;
    if (method_exists($userAuth, 'checkSession')) {
        $sessionUser = $userAuth->checkSession();
    }
    if ($sessionUser !== 'guest' && $sessionUser !== null) {
        $currentUser = $sessionUser;
        $projectId = $INPUT('project_id') ? (int)$INPUT('project_id') : 0; 
    }
}

if (empty($LOCAL_ENV['action'])) { $LOCAL_ENV['action'] = 'file_list'; }

$fileEngine = null;
if (class_exists('UnifiedFileAPI')) {
    $fileEngine = new UnifiedFileAPI($pdo, $projectId, $currentUser, $projectDb);
}

$response = ['success' => false, 'message' => 'ไม่พบคำสั่งควบคุมที่ระบบระบุไว้'];

// =========================================================================
// Switch Router Action Processing
// =========================================================================
switch ($LOCAL_ENV['action']) {
    case 'project':
        if (class_exists('run')) run::close();
        $response = ['success' => true, 'data' => $authResult];
        exit;
        
    case 'file_list':
        if (!$fileEngine) break;
        $search = $INPUT('search') ?? '';
        $sql = "SELECT * FROM `files` WHERE project_id = :project_id";
        $params = [':project_id' => $projectId];
        if (!empty($search)) {
            $sql .= " AND name LIKE :search";
            $params[':search'] = '%' . $search . '%';
        }
        $sql .= " ORDER BY `last_edit` ASC";
        $filesData = $fileEngine->select_all($sql, $params);
        $response = ['success' => true, 'data' => $filesData];
        break;
        
    case 'view':
        if (!$fileEngine) break;
        if (empty($LOCAL_ENV['filename'])) {
            header('HTTP/1.1 400 Bad Request');
            die(json_encode(['success' => false, 'message' => 'ไม่ระบุชื่อไฟล์'], JSON_UNESCAPED_UNICODE));
        }
        $node = $fileEngine->apiReadDecompressed($LOCAL_ENV['filename']);
        if (!$node || (isset($node['is_dir']) && $node['is_dir'])) {
            header('HTTP/1.1 404 Not Found');
            if (class_exists('run')) run::close();
            die(json_encode(['success' => false, 'message' => 'not found ' . $LOCAL_ENV['filename'] . ' Cloud Store'], JSON_UNESCAPED_UNICODE));
        }
        $dbFile = $fileEngine->select_one(
            "SELECT is_dir, size, last_edit, file_type FROM files WHERE project_id = ? AND name = ? LIMIT 1", 
            [$projectId, $LOCAL_ENV['filename']]
        );
        $fileContent = $node['content'] ?? $node['data'] ?? '';
        $response = [
            'success'   => true,
            'filename'  => $LOCAL_ENV['filename'],
            'metadata'  => $dbFile ?: [
                'is_dir'    => 0,
                'size'      => strlen($fileContent),
                'last_edit' => date('Y-m-d H:i:s'),
                'file_type' => 'text/plain'
            ],
            'content'   => base64_encode($fileContent)
        ];
        break;
        
    case 'file_save':
        $filename = $INPUT('filename') ?? $INPUT('path') ?? '';
        $contentBase64 = $INPUT('content') ?? '';
        $logPath = WEBROOT . '/cache/project_telemetry.log';
        @file_put_contents($logPath, date('Y-m-d H:i:s') . " [FILE_SAVE] Received: " . $filename . " | Size: " . strlen($contentBase64) . "\n", FILE_APPEND);
        
        if (empty($filename) || empty($contentBase64)) {
            $response = ['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน'];
            break;
        }
        $content = base64_decode($contentBase64, true);
        if ($content === false) {
            @file_put_contents($logPath, date('Y-m-d H:i:s') . " [FILE_SAVE] Base64 decode failed\n", FILE_APPEND);
            $response = ['success' => false, 'message' => 'Base64 decode ล้มเหลว'];
            break;
        }
        $fullPath = WEBROOT . '/public/' . ltrim($filename, '/');
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (file_put_contents($fullPath, $content) !== false) {
            @file_put_contents($logPath, date('Y-m-d H:i:s') . " [FILE_SAVE] ✅ Saved: " . $filename . "\n", FILE_APPEND);
            $response = ['success' => true, 'message' => 'บันทึกไฟล์สำเร็จ: ' . $filename];
        } else {
            @file_put_contents($logPath, date('Y-m-d H:i:s') . " [FILE_SAVE] ❌ Failed to write: " . $fullPath . "\n", FILE_APPEND);
            $response = ['success' => false, 'message' => 'บันทึกไฟล์ล้มเหลว'];
        }
        break;
        
    case 'file_upload':
    case 'file_edit':
        if (!$fileEngine) break;
        $customFields = [
            'file_type' => $INPUT('file_type') ?? null,
            'size'      => $INPUT('size') ? (int)$INPUT('size') : null
        ];
        $resulte = $fileEngine->apiUpload($customFields);
        $response = ['success' => true, 'data' => $resulte];
        break;
        
    case 'folder_create':
        if (!$fileEngine) break;
        $path = $INPUT('path') ?? '';
        $resulte = $fileEngine->apiCreateFolder($path);
        $response = ['success' => true, 'data' => $resulte];
        break;
        
    case 'delete':
    case 'file_delete':
    case 'delete_file':
        if (!$fileEngine) break;
        $resulte = $fileEngine->apiDelete($LOCAL_ENV['filename']);
        $response = ['success' => true, 'data' => $resulte];
        break;
        
    case 'copy':
    case 'move':
    case 'rename':
        if (!$fileEngine) break;
        $source = $INPUT('source') ?? $LOCAL_ENV['filename'] ?? '';
        $target = $INPUT('target') ?? $INPUT('new_name') ?? '';
        $mode = ($LOCAL_ENV['action'] === 'copy') ? 'copy' : 'move';
        $resulte = $fileEngine->apiCascadeStructure($mode, $source, $target);
        $response = ['success' => true, 'data' => $resulte];
        break;
        
    case 'file_extract_zip':
        if (!$fileEngine) break;
        $targetFolder = $INPUT('target_folder') ?? '';
        $resulte = $fileEngine->apiExtractZip($LOCAL_ENV['filename'], $targetFolder);
        $response = ['success' => true, 'data' => $resulte];
        break;
        
    case 'sync':
    case 'sqlar_sync':
        if (!$fileEngine || !$projectDb) break;
        $sqliteDbPath = WEBROOT . "/" . ltrim($projectDb, '/'); 
        $sqlarManager = new SQLarProjectManager($pdo, $sqliteDbPath, $projectId, $currentUser);
        $sqliteBlob = $INPUT('sqlite_blob') ?? null;
        if (!$sqliteBlob) {
            $response = ['success' => false, 'message' => 'ไม่พบข้อมูล Binary'];
        } else {
            $data_array = ['str' => $sqliteBlob];
            if (class_exists('run') && method_exists($sqlarManager, 'saveFileNode')) {
                run::execute($data_array, '0.0.0.0', 'SQLarProjectManager::syncFromSQLite', 
                    function($virtualKey, $volume) use ($sqlarManager) {
                        $sqlarManager->saveFileNode($virtualKey, $volume, false);
                    });
            }
            $response = ['success' => true, 'message' => 'ซิงค์ข้อมูลแตกพิกัดเรียบร้อย'];
        }
        break;
        
    case 'user_login':
        if (!$userAuth) break;
        $resulte = $userAuth->login($INPUT('username') ?? '', $INPUT('password') ?? '');
        $response = ['success' => true, 'data' => $resulte];
        break;
}

if (class_exists('run')) run::close();
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>