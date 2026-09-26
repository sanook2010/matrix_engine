<?php
// autoload.php

// 1. กำหนด WEBROOT ให้ชี้ไปที่ /htdocs/ ของคุณโดยตรง
if (!defined('WEBROOT')) {
    define('WEBROOT', '/home/vol7_4/yzz.me/yzzme_42809897/xxy.yzz.me/htdocs');
}

// 2. ⚡ บังคับโหลด database.php ทันที
// โค้ดจะเช็กตำแหน่งยอดฮิตที่ไฟล์นี้น่าจะอยู่ ถ้าเจอที่ไหนจะโหลดขึ้นมาทันทีครับ
$dbLocations = [
    WEBROOT . '/database.php',
    WEBROOT . '/core/database.php',
    WEBROOT . '/class/database.php',
    WEBROOT . '/config/database.php',
    WEBROOT . '/class/function.php'
];

foreach ($dbLocations as $dbLocation) {
    if (file_exists($dbLocation)) {
        require_once($dbLocation);
        break; // เจอแล้วโหลดเลย และหยุดลูปทันที
    }
}

// 3. โหลด Router หลัก
$routerPath = WEBROOT . "/core/AutoStorageRouter.php";
if (file_exists($routerPath)) {
    require_once($routerPath);
}

// 4. โหลดไฟล์ทั้งหมดในโฟลเดอร์ class (ใช้ glob เพื่อความชัวร์บนโฮสต์ฟรี)
$classPath = WEBROOT . '/class/*.php';
$classFiles = glob($classPath);
if ($classFiles) {
    foreach ($classFiles as $file) {
        require_once($file);
    }
}

// 5. สแตนด์บายออโต้โหลดสำหรับโฟลเดอร์คลาสอื่นๆ ในอนาคต
$folders = [
    WEBROOT . '/class', 
    WEBROOT . '/core/database.php'
];

spl_autoload_register(function (string $className) use ($folders): void {foreach ($folders as $folder) {
    if (!is_dir($folder)) {
        continue;
    }

    $file = rtrim($folder, '/') . '/' . $className . '.php';
    if (file_exists($file)) {
        require_once($file);
        return;
    }
}
});

// 6. ฟังก์ชันสำหรับดักจับเนื้อหาไฟล์
function getIncludeContents($filename) {
    if (is_file($filename)) {
        ob_start();
        include $filename;
        $contents = ob_get_contents();
        ob_end_clean();
        return $contents;
    }
    return false;
}
