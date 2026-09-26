<?php
// class/ProjectAuthManager.php

class ProjectAuthManager {
    private $pdo;    // อ็อบเจกต์ PDO ของ MySQL ทั่วไป
    private $sqlite; // อ็อบเจกต์ PDO ของ SQLite (ดึงมาจากระบบ State Manager)

    public function __construct() {
        // 1. เชื่อมต่อ MySQL ด้วยฟังก์ชันดั้งเดิมของคุณ (ได้อ็อบเจกต์ PDO โดยตรง)
        $this->pdo = db_connect(); 

        // 2. ดึงการเชื่อมต่อ SQLite ผ่านคลาส DatabaseZIP ที่คุณมีอยู่ในระบบ
        // ระบบจะสร้างไฟล์ .db_project_store.state.sqlite ไว้ในโฟลเดอร์ store ให้อัตโนมัติ
        $dbZip = new DatabaseZIP('project_store', 'your_secure_secret_key');
        $this->sqlite = $dbZip->getSQLite();
    }

    // ใช้ MySQL = ค้นหาตรวจสอบความถูกต้องของ Token
    public function verifyToken(string $token): ?array {
        if (!$this->pdo) return null;
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE token = ? LIMIT 1");
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    // ใช้ SQLite SQLar = บีบอัดและเก็บไฟล์ข้อมูลลงตาราง sqlar
    public function saveFileToStore(string $name, string $data): bool {
        if (!$this->sqlite) return false;
        
        // ตรวจสอบและสร้างตาราง sqlar หากยังไม่มีในฐานข้อมูล SQLite
        $this->sqlite->exec("
            CREATE TABLE IF NOT EXISTS sqlar (
                name TEXT PRIMARY KEY,
                mode INTEGER,
                mtime INTEGER,
                sz INTEGER,
                data BLOB
            )
        ");

        $gzData = gzcompress($data, 9); // SQLar มาตรฐาน บีบอัดระดับสูงสุดก่อนเก็บ
        $stmt = $this->sqlite->prepare("
            INSERT OR REPLACE INTO sqlar (name, mode, mtime, sz, data)
            VALUES (?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            $name,
            0100644,       // file mode
            time(),        // mtime
            strlen($data), // original size
            $gzData        // compressed blob
        ]);
    }

    // อ่านไฟล์จากตาราง sqlar ใน SQLite ออกมาคลายบีบอัด
    public function getFileFromStore(string $name): ?string {
        if (!$this->sqlite) return null;
        
        $stmt = $this->sqlite->prepare("SELECT sz, data FROM sqlar WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        if (!$row) return null;
        
        return gzuncompress($row['data'], $row['sz']);
    }
}
