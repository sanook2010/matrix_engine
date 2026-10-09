<?php
/**
 * ==============================================================================
 * 🚀 MATRIX CANVAS VIRTUAL HTTP SERVER KERNEL (loader.php)
 * [ENGINE CONTROL ALL + CH PERSISTENCE + ROUTING RULES INTEGRATED]
 * ==============================================================================
 */

// ✅ โหลดค่าจาก file_manifest.ini ก่อนอย่างอื่น
$manifestPath = __DIR__ . '/file_manifest.ini';
if (!file_exists($manifestPath)) {
    http_response_code(500);
    die("<h1>500 Server Error</h1><p>ไม่พบไฟล์ '" . htmlspecialchars($manifestPath) . "'</p>");
}
$manifest = parse_ini_file($manifestPath, true);

// ✅ กำหนดค่าให้ WebSite-PHP จาก Manifest
$_SERVER['HTTP_MOD_REWRITE'] = $manifest['HTTP_MOD_REWRITE'] ?? 'On';
$_SERVER['MOD_REWRITE'] = $manifest['MOD_REWRITE'] ?? 'On';

// ✅ กำหนดเส้นทางจาก Manifest
if (!defined('WEBROOT')) {
    define('WEBROOT', __DIR__);
}
$publicRoot = WEBROOT . ($manifest['PATHS']['PUBLIC_ROOT'] ?? '/public');
$coreRoot   = WEBROOT . ($manifest['PATHS']['CORE_ROOT'] ?? '/core');

// ✅ กำหนด BASE URL สำหรับเรียกใช้ในทุกไฟล์
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
$publicUrl = $baseUrl . '/' . trim($manifest['PATHS']['PUBLIC_ROOT'] ?? '/public', '/');
define('BASE_URL', $baseUrl);
define('PUBLIC_URL', $publicUrl);

// ✅ ฟังก์ชันสร้าง URL เส้นทางไฟล์ พร้อมรองรับการพ่วงค่า ch อัตโนมัติ
if (!function_exists('asset_url')) {
    function asset_url(string $filePath = '', string $ch = ''): string {
        $url = PUBLIC_URL . '/' . ltrim($filePath, '/');
        return $ch !== '' ? $url . '?ch=' . urlencode($ch) : $url;
    }
}

// ✅ ฟังก์ชันสร้าง URL หน้าเว็บ พร้อมรองรับ ch
if (!function_exists('url')) {
    function url(string $path = '', string $ch = ''): string {
        $url = BASE_URL . '/' . ltrim($path, '/');
        return $ch !== '' ? $url . '?ch=' . urlencode($ch) : $url;
    }
}

// ==============================================================================
// 📌 จัดการสถานะห้อง (ch), Cookie และ Session ป้องกันการหลุดเป็นยูสเซอร์เดี่ยว
// ==============================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_name('MATRIX_CORE_ENV');
    session_start();
}

$channelIndex = $_GET['ch'] ?? $_POST['ch'] ?? ($_COOKIE['matrix_active_ch'] ?? '1');
$channelIndex = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$channelIndex);
if ($channelIndex === '') {
    $channelIndex = '1';
}

setcookie('matrix_active_ch', $channelIndex, time() + (86400 * 30), '/', '', false, true);
$_SESSION['active_channel'] = $channelIndex;

// ✅ โหลดเอนจิ้นก่อนทุกอย่าง — จากเส้นทางใน Manifest
if (file_exists($coreRoot . '/.auto_prepend.php')) {
    require_once $coreRoot . '/.auto_prepend.php';
} elseif (file_exists($coreRoot . '/auto_prepend.php')) {
    require_once $coreRoot . '/auto_prepend.php';
}

// ==============================================================================
// 🌉 MATRIX TRANSPARENT ACCELERATOR & PROXY BRIDGE
// ==============================================================================
if (class_exists('ModuleManager', false)) {
    if (!isset($GLOBALS['__MATRIX_STREAM']) && ModuleManager::has('stream')) {
        $GLOBALS['__MATRIX_STREAM'] = ModuleManager::get('stream');
    }
    if (!isset($GLOBALS['__MATRIX_DB']) && ModuleManager::has('db')) {
        $GLOBALS['__MATRIX_DB'] = ModuleManager::get('db');
    }
}

if (!function_exists('matrix_resolve_file_content')) {
    function matrix_resolve_file_content(string $filepath): ?string {
        if (isset($GLOBALS['__MATRIX_STREAM']) && is_object($GLOBALS['__MATRIX_STREAM']) &&
            method_exists($GLOBALS['__MATRIX_STREAM'], 'read')) {
            $cleanPath = str_replace([WEBROOT, './', '\\'], ['', '', '/'], $filepath);
            $content = $GLOBALS['__MATRIX_STREAM']->read(ltrim($cleanPath, '/'));
            if ($content !== null) {
                return $content;
            }
        }
        return null;
    }
}

if (!function_exists('matrix_resolve_db_connect')) {
    function matrix_resolve_db_connect(string $type = 'sqlite', string $source = '') {
        if (isset($GLOBALS['__MATRIX_DB']) && is_object($GLOBALS['__MATRIX_DB'])) {
            $db = $GLOBALS['__MATRIX_DB'];
            $typeLower = strtolower($type);
            if ($typeLower === 'sqlite' && method_exists($db, 'connectSQLite')) {
                return $db->connectSQLite($source);
            } elseif ($typeLower === 'mysql' && method_exists($db, 'connectMySQL')) {
                return $db->connectMySQL('localhost', $source, '', '');
            } elseif ($typeLower === 'zip' && method_exists($db, 'connectZIP')) {
                return $db->connectZIP($source, 'default_pass');
            }
        }
        return null;
    }
}

// ✅ รับพาธจาก Request
$requestPath = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$filename = $_GET['filename'] ?? $requestPath;
$filename = ltrim((string)$filename, '/');

$realUserRoot = realpath($publicRoot);
if ($realUserRoot === false || !is_dir($realUserRoot)) {
    http_response_code(500);
    die("<h1>500 Server Error</h1><p>โฟลเดอร์ public ไม่พบหรือไม่ใช่ไดเรกทอรี</p>");
}

// ==============================================================================
// 🔀 คัดแยกเงื่อนไข Routing ตามที่คุณสรุปมาอย่างแม่นยำ
// ==============================================================================
$hasUpload   = isset($_FILES['file']);
$token       = $_POST['token'] ?? $_GET['token'] ?? '';
$username    = $_POST['username'] ?? $_GET['username'] ?? '';
$storageMode = $_POST['mode'] ?? $_GET['mode'] ?? '';

$streamMode     = $_GET['stream'] ?? $_POST['stream'] ?? '';
$actionParam    = $_GET['action'] ?? $_POST['action'] ?? '';
$reqExtension   = strtolower(pathinfo(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), PATHINFO_EXTENSION));

// 1️⃣ ถ้าระบุ username และโหมดจัดเก็บ -> สร้าง Session แล้วส่งไป block_chunk.php
if (!empty($username) && !empty($storageMode)) {
    $_SESSION['username'] = $username;
    $_SESSION['storage_mode'] = $storageMode;
    $_POST['ch'] = $channelIndex;
    $_SERVER['REQUEST_METHOD'] = 'POST'; // บังคับให้ block_chunk ทำงานต่อได้
    // ปล่อยไหลไปเข้าส่วนจัดการ Uploader ด้านล่าง
}

// 2️⃣ ถ้าระบุโทเค่น (Token) -> ถือเป็นไฟล์ชั่วคราว/ไฟล์สโตร์ของเว็บ -> ส่งไป block_chunk.php
elseif (!empty($token)) {
    $_POST['ch'] = $channelIndex;
    $_POST['token'] = $token;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    // ปล่อยไหลไปเข้าส่วนจัดการ Uploader ด้านล่าง
}

// 3️⃣ ถ้าส่งอัพโหลดมาเปล่าๆ (ไม่ระบุอะไรเลย) -> ไม่จัดเก็บ -> สร้าง Session แล้วส่งไป process.php (Matrix Stream)
elseif ($hasUpload && empty($token) && (empty($username) || empty($storageMode)) && $streamMode === '' && $actionParam !== 'stream') {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $_GET['ch'] = $channelIndex;
    $_SERVER['SCRIPT_FILENAME'] = WEBROOT . '/process.php';
    $_SERVER['SCRIPT_NAME']     = '/process.php';
    $_SERVER['REQUEST_URI']     = ($filename === '' || $filename === 'index') ? '/' : '/' . $filename;

    if (file_exists(WEBROOT . '/process.php')) {
        include WEBROOT . '/process.php';
    } else {
        http_response_code(404);
        echo "404 Not Found — process.php not found";
    }
    exit;
}

// แบบเดิม: เช็คสตรีมมิ่งโดยตรง
if ($streamMode !== '' || $actionParam === 'stream' || in_array($reqExtension, ['mp4', 'ts', 'm3u8'])) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $_GET['ch'] = $channelIndex;
    $_SERVER['SCRIPT_FILENAME'] = WEBROOT . '/process.php';
    $_SERVER['SCRIPT_NAME']     = '/process.php';
    $_SERVER['REQUEST_URI']     = ($filename === '' || $filename === 'index') ? '/' : '/' . $filename;

    if (file_exists(WEBROOT . '/process.php')) {
        include WEBROOT . '/process.php';
    } else {
        http_response_code(404);
        echo "404 Not Found — process.php not found";
    }
    exit;
}

// -----------------------------------------------------------------------------
// ⚡ BLOCK_CHUNK UPLOADER MODULE (รองรับ Ch และการจัดเก็บแยกตามห้อง)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasUpload) {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(0);

    $uploadName = $_POST['filename'] ?? $_FILES['file']['name'] ?? '';
    $uploadName = str_replace(['../', '..\\', '\\'], '', trim($uploadName));

    if (empty($uploadName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ไม่ระบุชื่อไฟล์ปลายทาง']);
        exit;
    }

    $fileData = file_get_contents($_FILES['file']['tmp_name']);
    if ($fileData === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'อ่านไฟล์ชั่วคราวล้มเหลว']);
        exit;
    }

    $totalLength = strlen($fileData);
    $matrixGrid = [];

    if (class_exists('AutoStorageRouter', false)) {
        global $storage;
        // แยกโฟลเดอร์จัดเก็บตาม channelIndex (ch) อย่างเด็ดขาด
        $storageRoot = WEBROOT . ($manifest['PATHS']['STORAGE_ROOT'] ?? '/storage') . '/ch_' . $channelIndex;
        $router = $storage ?? new AutoStorageRouter($storageRoot);

        $chunkSize = 1024;
        $currentOffset = 0;
        $chunksSaved = 0;

        while ($currentOffset < $totalLength) {
            $slice = substr($fileData, $currentOffset, $chunkSize);
            $chunkMd5 = md5($slice);
            $chunkKey = 'block_chunk_' . $chunkMd5 . '.dat';

            if (method_exists($router, 'store')) {
                $router->store($chunkKey, $slice, 'zip');
            }

            $matrixGrid[] = [
                'chunk_index' => $chunksSaved,
                'chunk_key'   => $chunkKey,
                'size'        => strlen($slice)
            ];

            $currentOffset += strlen($slice);
            $chunksSaved++;
        }

        if (method_exists($router, 'store')) {
            $router->store($uploadName, json_encode($matrixGrid), 'sqlar');
        }
    }

    try {
        if (function_exists('db_connect')) {
            $pdo = db_connect();
            $stmt = $pdo->prepare("INSERT INTO files (project_id, name, is_dir, size, last_edit, file_type, channel_index) 
                VALUES (?, ?, 0, ?, NOW(), ?, ?) 
                ON DUPLICATE KEY UPDATE size = VALUES(size), last_edit = NOW()");
            $fileType = $_FILES['file']['type'] ?? 'application/octet-stream';
            // ผูก project_id หรือช่อง ch เข้าฐานข้อมูล
            $stmt->execute([1, $uploadName, $totalLength, $fileType, $channelIndex]);
        }
    } catch (Exception $e) {
        error_log('DB Error: ' . $e->getMessage());
    }

    echo json_encode([
        'success'    => true,
        'message'    => "ไฟล์ถูกหั่นและบันทึกเป็น block_chunk สำเร็จ (Channel: {$channelIndex})",
        'channel'    => $channelIndex,
        'filename'   => $uploadName,
        'chunks'     => count($matrixGrid),
        'total_size' => $totalLength,
        'file_url'   => asset_url($uploadName, $channelIndex)
    ]);

    if (file_exists($coreRoot . '/.auto_append.php')) {
        require_once $coreRoot . '/.auto_append.php';
    }
    exit;
}

// -----------------------------------------------------------------------------
// 🔀 VIRTUAL HTTP ROUTER PROCESSOR
// -----------------------------------------------------------------------------
$safeFilename = str_replace(['../', '..\\', '\\', "\0"], '', (string)$filename);
$safeFilename = ltrim($safeFilename, '/');
$cleanPayload = trim(str_replace(BASE_URL, '', '/' . $safeFilename), '/');
$segments = ($cleanPayload === '') ? [] : explode('/', $cleanPayload);
$primarySegment = $segments[0] ?? '';
$targetFile = '';
$thdocsRoot = '';

if ($safeFilename === '' || $cleanPayload === 'index' || $cleanPayload === 'process') {
    $targetFile = WEBROOT . '/process.php';
} elseif ($primarySegment === 'thdocs') {
    array_shift($segments);
    $thdocsRoot = WEBROOT . '/' . trim($manifest['PATHS']['THDOCS'] ?? '/thdocs', '/');
    $targetFile = rtrim($thdocsRoot, '/') . '/' . (implode('/', $segments) ?: 'index.php');
} elseif ($primarySegment === 'public') {
    array_shift($segments);
    $targetFile = rtrim($publicRoot, '/') . '/' . (implode('/', $segments) ?: 'index.php');
} elseif ($primarySegment === 'parent' || $primarySegment === '..') {
    array_shift($segments);
    $targetFile = rtrim(dirname(WEBROOT), '/') . '/' . (implode('/', $segments) ?: 'index.php');
} else {
    $coreTarget = $coreRoot . '/' . $cleanPayload . '.php';
    if (file_exists($coreTarget)) {
        $targetFile = $coreTarget;
    } else {
        $targetFile = rtrim($publicRoot, '/') . '/' . $safeFilename;
        if (!file_exists($targetFile) || is_dir($targetFile)) {
            $targetFile = WEBROOT . '/process.php';
        }
    }
}

// ✅ ตรวจสอบความปลอดภัย Path Traversal
$realTarget = realpath($targetFile);
$realUserRootReal = realpath($publicRoot);
$realCoreRoot = realpath($coreRoot);
$realWebRoot = realpath(WEBROOT);
$realParentRoot = realpath(dirname(WEBROOT));

if ($thdocsRoot !== '') {
    $realThdocsRoot = realpath($thdocsRoot) ?: '';
} else {
    $realThdocsRoot = '';
}

$allowed = false;
if ($realTarget === false) {
    $allowed = false;
} elseif (strpos($realTarget, $realUserRootReal . DIRECTORY_SEPARATOR) === 0) {
    $allowed = true;
} elseif ($realTarget === $realWebRoot . '/process.php') {
    $allowed = true;
} elseif ($realCoreRoot && strpos($realTarget, $realCoreRoot . DIRECTORY_SEPARATOR) === 0) {
    $allowed = true;
} elseif ($realParentRoot && strpos($realTarget, $realParentRoot . DIRECTORY_SEPARATOR) === 0) {
    $allowed = true;
} elseif ($realThdocsRoot && strpos($realTarget, $realThdocsRoot . DIRECTORY_SEPARATOR) === 0) {
    $allowed = true;
}

if (!$allowed) {
    http_response_code(403);
    die("403 Forbidden — ไม่ได้รับอนุญาตให้เข้าถึงไฟล์นี้");
}

if (!file_exists($realTarget)) {
    http_response_code(404);
    die("404 Not Found — ไม่พบไฟล์เป้าหมาย");
}

$extension = strtolower(pathinfo($realTarget, PATHINFO_EXTENSION));

if ($extension === 'php') {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $MATRIX_ENGINE_SESSION = $_SESSION ?? [];
        session_write_close();
    }

    $pathInPublic = (strpos($realTarget, $realUserRootReal) === 0)
        ? substr($realTarget, strlen($realUserRootReal))
        : '/process.php';

    $_SERVER['SCRIPT_FILENAME'] = $realTarget;
    $_SERVER['SCRIPT_NAME'] = str_replace('\\', '/', $pathInPublic);
    $_SERVER['REQUEST_URI'] = ($filename === '' || $filename === 'index') ? '/' : '/' . $filename;

    include $realTarget;
} else {
    $mimeMap = $manifest['MIME_TYPES'] ?? [
        'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8', 'xml' => 'application/xml; charset=utf-8',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject', 'ico' => 'image/x-icon'
    ];

    $engineContent = matrix_resolve_file_content($realTarget);
    header("Content-Type: " . ($mimeMap[$extension] ?? 'application/octet-stream'));

    if ($engineContent !== null) {
        header("Content-Length: " . strlen($engineContent));
        header("Cache-Control: public, max-age=86400");
        echo $engineContent;
    } else {
        header("Content-: " . filesize($realTarget));
        header("Cache-Control: public, max-age=86400");
        readfile($realTarget);
    }
}

if (file_exists($coreRoot . '/.auto_append.php')) {
    require_once $coreRoot . '/.auto_append.php';
}
exit;
