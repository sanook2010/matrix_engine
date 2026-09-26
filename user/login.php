<?php
ob_start(); // 1. บังคับเปิดบัฟเฟอร์ที่บรรทัดแรกสุด ห้ามมีช่องว่างก่อนแท็ก <?php

// login.php
session_start();
define('WEBROOT', $_SERVER['DOCUMENT_ROOT']);
require_once(WEBROOT.'/core/AutoStorageRouter.php');
if(!function_exists('db_connect')){
require_once(WEBROOT.'/core/database.php');
  $pdo = db_connect();
}
if (isset($_POST['username'])) {
    $username = trim($_POST['username']);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);

ob_start(); // บังคับเปิดเอาต์พุตบัฟเฟอร์ที่บรรทัดแรกสุดของไฟล์

// login.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('WEBROOT', $_SERVER['DOCUMENT_ROOT']);
require_once(WEBROOT.'/core/AutoStorageRouter.php');

if(!function_exists('db_connect')){
    require_once(WEBROOT.'/core/database.php');
}
$pdo = db_connect();

if (isset($_POST['username'])) {
    $username = trim($_POST['username']);
    $password = md5($_POST['password']); 

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND password = ?");
    $stmt->execute([$username, $password]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // ตรวจสอบเงื่อนไขความปลอดภัยของบัญชีแอดมิน
        if ($user['role'] === 'แอดมิน' && $user['username'] !== 'แอดมิน') {
            ob_end_clean();
            die("🛡️ ระบบความปลอดภัย: บัญชีแอดมินนี้ไม่ได้รับอนุญาตเนื่องจากชื่อผู้ใช้ไม่ถูกต้อง");
        }

        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role']; // บันทึกระดับสิทธิ์ลง Session ถูกต้อง
    }
        
    if (isset($_SESSION['username'])) {
        ob_end_clean(); 
        if(!@header("Location: ../dashboard.php", true, 307)){
            echo "<script>window.location.href = '../dashboard.php';</script>";
            exit();
        }
    } else {
        echo "<div class='form'>
              <h3>Incorrect Username/password.</h3><br/>
              <p class='link'>Click here to <a href='login.php'>Login</a> again.</p>
              </div>";
    }
} else {
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8"/>
    <title>Login</title>
    <style><?php include('style.css'); ?></style>
</head>
<body>
    <form class="form" method="post" name="login">
        <h1 class="login-title">Login</h1>
        <input type="text" class="login-input" name="username" placeholder="Username" autofocus="true" required />
        <input type="password" class="login-input" name="password" placeholder="Password" required />
        <input type="submit" value="Login" name="submit" class="login-button"/>
        <p class="link">Don't have an account? <a href="registration.php">Register Now</a></p>
    </form>
</body>
</html>
<?php 
} 
ob_end_flush(); 
    
    if ($user && password_verify($_POST['password'], $user['password'])) { // ✅ ใช้ password_verify
        $_SESSION['username'] = $user['username'];
        if(isset($user['role'])){
$_SESSION['role'] = $user['role'];
         }
        ob_end_clean();
        header("Location: ../dashboard.php");
        exit();
    } else {
        echo "<div class='form'><h3>Incorrect Username/password.</h3><br/><p class='link'>Click here to <a href='login.php'>Login</a> again.</p></div>";
    }
} else {
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8"/>
    <title>Login</title>
    <style><?php include('style.css'); ?></style>
</head>
<body>
    <form class="form" method="post" name="login">
        <h1 class="login-title">Login</h1>
        <input type="text" class="login-input" name="username" placeholder="Username" autofocus="true" required />
        <input type="password" class="login-input" name="password" placeholder="Password" required />
        <input type="submit" value="Login" name="submit" class="login-button"/>
        <p class="link">Don't have an account? <a href="registration.php">Register Now</a></p>
    </form>
</body>
</html>
<?php 
} 
ob_end_flush(); // 3. ปล่อยบัฟเฟอร์ออกไปเมื่อทำงานเสร็จสมบูรณ์
?>