<?php
// =========================================================================
// class/SQLarProjectManager.php
//
// [FIX] เดิมไฟล์นี้ประกาศ class ProjectAuthManager ซ้ำกับไฟล์หลัก ทำให้
// api.php ที่ require_once ทั้งคู่ ฟ้อง fatal "Cannot declare class
// ProjectAuthManager" ตอน runtime แก้โดยย้าย SQLar เข้ามาแทนที่
// =========================================================================
declare(strict_types=1);

require_once __DIR__ . '/DualDbManager.php';

class SQLarProjectManager extends DualDbManager {

    /**
     * บันทึกไฟล์เข้า SQLar SQLite (gzcompress เพื่อให้ตรงกับที่
     * ProjectAuthManager::getFileFromStore อ่านออก)
     *
     * รูปแบบเดียวกับ ProjectAuthManager::saveFileToStore แต่ห่อด้วย
     * (key, volume) ตามสัญญา API ใน api.php
     */
    public function saveFileNode(string $name, $volume, bool $compress = true): bool {
        if (!$this->sqlitePdo) {
            throw new RuntimeException('SQLite PDO not initialised');
        }
        $payload = is_string($volume) ? $volume : (string)$volume;
        $data    = $compress ? gzcompress($payload, 9) : $payload;

        $stmt = $this->sqlitePdo->prepare("
            INSERT OR REPLACE INTO sqlar (name, mode, mtime, sz, data)
            VALUES (?, ?, ?, ?, ?)
        ");

        return $stmt->execute([
            $name,
            0100644,
            time(),
            strlen($payload),
            $data,
        ]);
    }

    /** อ่านไฟล์จาก SQLar กลับมา */
    public function getFileNode(string $name, bool $compressed = true): ?string {
        $stmt = $this->sqlitePdo->prepare("SELECT sz, data FROM sqlar WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return $compressed ? gzuncompress($row['data'], (int)$row['sz']) : $row['data'];
    }

    /** ลบไฟล์ออกจาก SQLar */
    public function deleteFileNode(string $name): bool {
        $stmt = $this->sqlitePdo->prepare("DELETE FROM sqlar WHERE name = ?");
        return $stmt->execute([$name]);
    }
}