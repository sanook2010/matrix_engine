<?php

class FastMemorySlotManager 
{
    private string $storageDir;
    private int $defaultTtl;

    public function __construct(string $customDir = '', int $defaultTtl = 5) 
    {
        // ใช้ sys_get_temp_dir() ซึ่งบนลินุกซ์มักถูกเมาท์ไว้ใน RAM (tmpfs) เพื่อความเร็วสูงสุด
        $baseDir = $customDir !== '' ? $customDir : sys_get_temp_dir() . '/fast_matrix_slots';
        $this->storageDir = rtrim($baseDir, '/\\');
        $this->defaultTtl = $defaultTtl;

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0777, true);
        }
    }

    /**
     * จองหรืออัปเดตข้อมูลลงในสล็อตหน่วยความจำพร้อมกำหนดเวลาหมดอายุ (TTL)
     */
    public function reserve(string $slotKey, array $data, ?int $ttl = null): bool 
    {
        $filePath = $this->getSlotFilePath($slotKey);
        $expireTime = time() + ($ttl ?? $this->defaultTtl);

        $payload = [
            'expires_at' => $expireTime,
            'data'       => $data,
            'updated_at' => microtime(true)
        ];

        // ใช้ LOCK_EX เพื่อป้องกันการเขียนชนกันในจังหวะที่มีความถี่สูง
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        $result = file_put_contents($filePath, $json, LOCK_EX);
        return $result !== false;
    }

    /**
     * ดึงข้อมูลจากสล็อต หากหมดอายุแล้วจะทำลายทิ้งและคืนค่า null ทันที (Auto-Cancellation)
     */
    public function fetch(string $slotKey): ?array 
    {
        $filePath = $this->getSlotFilePath($slotKey);

        if (!file_exists($filePath)) {
            return null;
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $payload = json_decode($content, true);
        if (!is_array($payload) || !isset($payload['expires_at'], $payload['data'])) {
            @unlink($filePath);
            return null;
        }

        // ตรวจสอบเงื่อนไขหมดอายุ (Timeout / Expired check)
        if (time() > $payload['expires_at']) {
            @unlink($filePath); // ยกเลิกและเคลียร์สล็อตทิ้งทันที
            return null;
        }

        return $payload['data'];
    }

    /**
     * ลบ/ยกเลิกสล็อตทันทีเมื่อกระบวนการทำงานเสร็จสมบูรณ์หรือรถเต็มคันแล้วออกตัว
     */
    public function release(string $slotKey): bool 
    {
        $filePath = $this->getSlotFilePath($slotKey);
        if (file_exists($filePath)) {
            return @unlink($filePath);
        }
        return true;
    }

    /**
     * ฟังก์ชันกวาดล้างสล็อตที่ตกค้างและหมดอายุทั้งหมดในโฟลเดอร์เพื่อเคลียร์พื้นที่
     */
    public function gc(): int 
    {
        $count = 0;
        $files = glob($this->storageDir . '/slot_*.json');
        
        if ($files === false) {
            return 0;
        }

        $now = time();
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content !== false) {
                $payload = json_decode($content, true);
                if (is_array($payload) && isset($payload['expires_at']) && $now > $payload['expires_at']) {
                    @unlink($file);
                    $count++;
                }
            }
        }

        return $count;
    }

    private function getSlotFilePath(string $slotKey): string 
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $slotKey);
        return $this->storageDir . '/slot_' . $safeKey . '.json';
    }
}
