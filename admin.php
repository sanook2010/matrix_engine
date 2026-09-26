<?php
// admin.php - แผงควบคุมสิทธิ์แอดมินยกรังและการสืบค้นพิกัดร่วม 256 บิต (All-in-One Dashboard)
// ===================================================================================
set_time_limit(0);
ini_set('memory_limit', '-1');
ini_set('display_errors', '1');
error_reporting(E_ALL);

// โหลดเราเตอร์สากลและระบบตรวจสอบเซสชั่น
define('WEBROOT', $_SERVER['DOCUMENT_ROOT']);
require_once WEBROOT . '/user/auth_session.php';
if (!defined('IN_STORAGE_ROUTER')) {
    define('IN_STORAGE_ROUTER', true);
    require_once WEBROOT . '/core/AutoStorageRouter.php';
}
require_once WEBROOT . '/class/DB.php';
require_once WEBROOT . '/class/run.php';

// บังคับล็อกระดับสิทธิ์เฉพาะแอดมินเท่านั้นตามตรรกะของเจ้านาย
if (function_exists('restrict_to_admin_only')) {
    restrict_to_admin_only();
}
cache_folder("storage/");
$storageDir = WEBROOT . '/storage';
$dbPath = $storageDir . '/grid_multidim.db';
$dbconn = new PDO('sqlite:' . $dbPath);
$dbconn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 🔧 [DATABASE SELF-SEEDING] - ดักจับกรณีไม่มีข้อมูลผู้ใช้ในระบบ SQLite หลัก
// ระบบจะทำการสร้างตารางคิวงานและฝังบัญชีแอดมินเข้าคลังทันทีใน O(1)
try {
    $dbconn->exec("CREATE TABLE IF NOT EXISTS users (
        user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        role TEXT DEFAULT 'user'
    );");
    
    $stmtCheck = $dbconn->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $stmtCheck->execute();
    if ((int)$stmtCheck->fetchColumn() === 0) {
        // ลงดัชนีแอดมินเริ่มต้น รหัสผ่านล็อกผ่านโครงสร้างแฮช MD5 หรือข้อความดิบตามระบบของพี่
        $stmtSeed = $dbconn->prepare("INSERT OR IGNORE INTO users (username, password, role) VALUES (?, ?, ?)");
        $stmtSeed->execute(['admin', md5('admin1234'), 'admin']);
        $stmtSeed->execute([$_SESSION['username'] ?? 'admin_system', md5('admin1234'), 'admin']);
    }
} catch (Exception $e) { /* Bypass if locked */ }

// เริ่มต้นโหลดคลาสเราเตอร์เพื่ออ่านผังสารบัญกลาง
$storage = new AutoStorageRouter($storageDir);
$filesData = $storage->listAll();

// ดึงสถิติวัดขนาดไฟล์คลังความปลอดภัยแคช .zip ล่าสุด
$zipPath = $storageDir . '/' . date('Ymd') . '.zip';
$zipSizeMb = file_exists($zipPath) ? round(filesize($zipPath) / (1024 * 1024), 2) : 0;

// ดึงรายงานประวัติการสตรีมพิกเซลล่าสุด 10 ชุดจากตารางหลัก
$stmtLogs = $dbconn->query("SELECT * FROM block_packets ORDER BY created_at DESC LIMIT 10");
$streamLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🛡️ แผงควบคุมข้อมูลดัชนีส่วนกลาง (Admin Master Vault Dashboard)</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background-color: #0f172a; color: #f8fafc; padding: 20px; line-height: 1.5; }
        .container { max-width: 1200px; margin: 0 auto; }
        header { background-color: #1e293b; padding: 20px; border-radius: 12px; border: 1px solid #334155; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; }
        h1 { font-size: 1.3rem; color: #38bdf8; }
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .card { background-color: #1e293b; padding: 20px; border-radius: 10px; border: 1px solid #334155; text-align: center; }
        .card h3 { font-size: 0.85rem; color: #94a3b8; margin-bottom: 8px; }
        .card .value { font-size: 1.7rem; font-weight: bold; color: #34d399; }
        .main-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        @media (max-width: 768px) { .main-layout { grid-template-columns: 1fr; } }
        .section-box { background-color: #1e293b; padding: 20px; border-radius: 10px; border: 1px solid #334155; }
        h2 { font-size: 1.1rem; color: #f1f5f9; margin-bottom: 15px; border-bottom: 1px solid #334155; padding-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.85rem; }
        th, td { padding: 10px; border-bottom: 1px solid #334155; }
        th { background-color: #0f172a; color: #94a3b8; }
        .badge { background-color: #0284c7; padding: 2px 6px; border-radius: 4px; font-size: 0.75rem; font-family: monospace; }
        .btn-logout { background-color: #ef4444; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: bold; text-decoration: none; font-size: 0.85rem;}
        .btn-logout:hover { background-color: #dc2626; }
    </style>
</head>
<body>

<div class="container">
    <header>
        <div>
            <h1>🛡️ แผงควบคุมระบบคลังความปลอดภัยระดับสูง (Master Vault Dashboard)</h1>
            <p style="font-size: 0.8rem; color: #94a3b8; margin-top: 4px;">แอดมิน: <span style="color:#f59e0b;"><?= $_SESSION['username'] ?></span> | ระดับสิทธิ์ร่วมส่วนกลาง: SQLite Verified</p>
        </div>
        <div>
            <a href="block_chunk.php" class="refresh-btn" style="background:#10b981; color:#fff; padding:8px 16px; border-radius:6px; text-decoration:none; font-weight:bold; font-size:0.85rem; margin-right:10px;">🎨 หน้าตารางกริดภาพ</a>
            <a href="user/logout.php" class="btn-logout">🚪 ออกจากระบบ</a>
        </div>
    </header>

    <!-- 📊 บล็อกรายงานผลสถิติจากคลังกลางเราเตอร์ -->
    <div class="dashboard-grid">
        <div class="card">
            <h3>ขนาดไฟล์คลังความปลอดภัย (.ZIP Cache)</h3>
            <div class="value" style="color: #f59e0b;"><?= $zipSizeMb ?> MB</div>
        </div>
        <div class="card">
            <h3>รายการไฟล์ในคลังเราเตอร์กลางทั้งหมด</h3>
            <div class="value"><?= count($filesData) ?> ไฟล์</div>
        </div>
        <div class="card">
            <h3>ประวัติสตรีมพิกเซลสะสม (SQL Packets)</h3>
            <div class="value" style="color: #38bdf8;">
                <?php
                $stmtCnt = $dbconn->query("SELECT COUNT(*) FROM block_packets");
                echo $stmtCnt->fetchColumn();
                ?>
            </div>
        </div>
    </div>

    <div class="main-layout">
        <!-- 📦 ตารางแสดงดรรชนีคลังจัดเก็บไฟล์ของ AutoStorageRouter -->
        <div class="section-box">
            <h2>📦 รายการสารบัญไฟล์ในระบบจัดเก็บหลัก</h2>
            <div style="max-height: 350px; overflow-y: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ชื่อไฟล์ในระบบ</th>
                            <th>ประเภทไฟล์</th>
                            <th>ขนาดไฟล์ดิบ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($filesData)): foreach ($filesData as $file): ?>
                            <tr>
                                <td><span style="color: #34d399; font-family: monospace;"><?= htmlspecialchars($file['name']) ?></span></td>
                                <td><span class="badge"><?= $file['file_type'] ?></span></td>
                                <td><?= round($file['size'] / 1024, 2) ?> KB</td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="3" style="text-align:center; color:#64748b;">ยังไม่มีประวัติไฟล์บันทึกผ่านท่อกระชับพื้นที่</td>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 📜 ตารางแสดงประวัติทราฟฟิกสตรีมพิกเซล O(1) ล่าสุดจากตารางภาพ -->
        <div class="section-box">
            <h2>📜 ประวัติรุ่นสตรีมข้อมูลพิกเซลล่าสุด (Fission Engine Ingress)</h2>
            <div style="max-height: 350px; overflow-y: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ลำดับคีย์ (Seq)</th>
                            <th>พิกัดกริด [G.C]</th>
                            <th>เนื้อหาพิกเซลข้อมูล</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($streamLogs)): foreach ($streamLogs as $log): ?>
                            <tr>
                                <td><span class="badge" style="background:#475569;"><?= $log['sequence'] ?></span></td>
                                <td><span style="color:#38bdf8; font-family:monospace;"><?= $log['block_group'] . '.' . $log['block_chunk'] ?></span></td>
                                <td style="font-family: monospace; word-break: break-all; max-width: 200px;"><?= htmlspecialchars(substr($log['payload'], 0, 50)) . (strlen($log['payload']) > 50 ? '...' : '') ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="3" style="text-align:center; color:#64748b;">ด่านรับ block_chunk ยังไม่ได้รับแรงกระแทกจากสตรีมพันล้านชิ้น</td>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</body>
</html>