<?php
class UserAuth {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
    }

    public function login($username, $password) {
        if (empty($username) || empty($password)) { return [ 'success' => false, 'message' => 'กรุณากรอกชื่อผู้ใช้งานและรหัสผ่าน' ]; }
        try {
            $stmt = $this->pdo->prepare("SELECT username, password FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['username'] = $user['username'];
                return [ 'success' => true, 'message' => 'เข้าสู่ระบบสำเร็จ', 'username' => $user['username'] ];
            }
            return [ 'success' => false, 'message' => 'ชื่อผู้ใช้งานหรือรหัสผ่านไม่ถูกต้อง' ];
        } catch (Exception $e) { return [ 'success' => false, 'message' => 'เกิดข้อผิดพลาดของระบบ: ' . $e->getMessage() ]; }
    }

    public function checkSession() {
        if (isset($_SESSION['username']) && !empty($_SESSION['username'])) { return $_SESSION['username']; }
        return 'guest'; 
    }

    public function logout() {
        unset($_SESSION['username']);
        if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
        return [ 'success' => true, 'message' => 'ออกจากระบบเรียบร้อยแล้ว' ];
    }
}
?>
