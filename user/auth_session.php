<?php
// auth_session.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ตรวจสอบว่าผู้ใช้งานเข้าสู่ระบบมาแล้วหรือยัง
if (!isset($_SESSION["username"])) {
    header("Location: /user/login.php");
    exit();
}

// ฟังก์ชันจำกัดสิทธิ์เฉพาะแอดมินตัวจริงเท่านั้น
function restrict_to_admin_only() {
    if (!isset($_SESSION["role"]) || $_SESSION["role"] !== 'แอดมิน') {
        echo "<script>alert('🛡️ ปฏิเสธการเข้าถึง: บริเวณนี้เฉพาะผู้ดูแลระบบที่มีสิทธิ์ (แอดมิน) เท่านั้น');</script>";
        echo "<div style='text-align:center; margin-top:50px;'><h3>Access Denied</h3><p><a href='dashboard.php'>กลับสู่หน้าผู้ใช้งานหลัก</a></p></div>";
        exit();
    }
}
?>
