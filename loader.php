<?php
/**
 * ==============================================================================
 * 🧭 MATRIX CANVAS LOADER — แกนนำทางสากล
 * ==============================================================================
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('WEBROOT')) {
    define('WEBROOT', $_SERVER['DOCUMENT_ROOT']);
}

// โหลดค่าจาก .user.ini
$userIniPath = WEBROOT . '/.user.ini';
$sysConfig = [];
if (file_exists($userIniPath)) {
    $sysConfig = parse_ini_file($userIniPath, true);
}

$GLOBALS['SYSTEM_CONFIG'] = $sysConfig;
$GLOBALS['host'] = $sysConfig['database']['host'] ?? 'localhost';
$GLOBALS['db']   = $sysConfig['database']['dbname'] ?? 'database';
$GLOBALS['user'] = $sysConfig['database']['user'] ?? 'root';
$GLOBALS['pass'] = $sysConfig['database']['password'] ?? '';

if (!defined('HTML_STORAGE_DIR')) {
    define('HTML_STORAGE_DIR', WEBROOT . ($sysConfig['storage']['canvas_blocks'] ?? '/canvas_blocks'));
}

// ออโต้โหลดคลาส
spl_autoload_register(function (string $className) {
    $map = [
        'ZipManager'              => WEBROOT . '/class/ZipManager.php',
        'UnifiedFileAPI'              => WEBROOT . '/class/UnifiedFileAPI.php',
        'SecureGridStreamMatrix'  => WEBROOT . '/class/main_data.php',
        'ProjectAuthManager'      => WEBROOT . '/class/ProjectAuthManager.php',
        'SQLarProjectManager'     => WEBROOT . '/class/SQLarProjectManager.php',
        'DualDbManager'           => WEBROOT . '/class/DualDbManager.php',
        'VariableStreams'         => WEBROOT . '/class/VariableStreams.php',
        'FastMemorySlotManager'   => WEBROOT . '/class/FastMemorySlotManager.php',
        'SessionRamStorage'       => WEBROOT . '/class/SessionRamStorage.php',
        'DBStream'                => WEBROOT . '/class/dbstream.php',
        'UserAuth'                => WEBROOT . '/class/UserAuth.php',
        'run'                     => WEBROOT . '/class/run.php'
    ];

    if (isset($map[$className]) && file_exists($map[$className])) {
        require_once $map[$className];
        return;
    }

    $file = WEBROOT . '/class/' . $className . '.php';
    if (file_exists($file)) {
        include_once $file;
    }
});

// โหลดฟังก์ชันเชื่อมต่อ DB
if (!function_exists('db_connect')) {
    $dbFile = WEBROOT . '/core/database.php';
    if (file_exists($dbFile)) {
        require_once $dbFile;
    } else {
        // ถ้าไม่มีไฟล์ database.php ให้ประกาศฟังก์ชันตรงนี้เป็นสำรอง
        if (!function_exists('db_connect')) {
            function db_connect() {
                static $pdo = null;
                if ($pdo !== null) return $pdo;
                
                try {
                    $host = $GLOBALS['host'] ?? 'localhost';
                    $db   = $GLOBALS['db'] ?? '';
                    $user = $GLOBALS['user'] ?? 'root';
                    $pass = $GLOBALS['pass'] ?? '';
                    
                    $pdo = new PDO(
                        "mysql:host=$host;dbname=$db;charset=utf8mb4",
                        $user,
                        $pass,
                        [
                            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES   => false,
                        ]
                    );
                    return $pdo;
                } catch (PDOException $e) {
                    die('❌ DB Connect Error: ' . $e->getMessage());
                }
            }
        }
    }
}
/**
// ลงทะเบียนโปรโตคอล db://
if (!in_array('db', stream_get_wrappers(), true)) {
    if (class_exists('DBStream')) {
        stream_wrapper_register('db', 'DBStream');
    }
}
**/
// เปิด Log
if (class_exists('run')) {
    if (!is_dir(HTML_STORAGE_DIR)) {
        @mkdir(HTML_STORAGE_DIR, 0777, true);
    }
    run::initLog(true, HTML_STORAGE_DIR . '/stream_session_prepend.log');
}
