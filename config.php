<?php
declare(strict_types=1);
// config.php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$endpoint = "http://xxy.yzz.io/api.php"; 

$GLOBALS["host"] = 'sql204.yzz.me';
$GLOBALS["db"] = 'yzzme_42809897_DB';
$GLOBALS["user"] = 'yzzme_42809897';
$GLOBALS["pass"] = 'VbGP5U10XRDy';
    
$token = 'tok_guest_095a4f3a85cdfa1b73ed57712ca225a3'; 

// ตั้งค่าเขตเวลาให้ตรงกันทั่วระบบ
date_default_timezone_set('Asia/Bangkok');


