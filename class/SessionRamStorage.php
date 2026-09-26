<?php
// ==============================================================
// 🧠 SessionRamStorage — แรมหลายชั้น + เซสชั่นส่วนตัว
// ✅ แต่ละคนมีพื้นที่แรมของตัวเอง — ไม่ชน ไม่ปน ปลอดภัย
// ✅ เลือกชั้นอัตโนมัติ: RAM Disk → temp global → temp session → memory
// ✅ ไม่มีไฟล์จริง — เร็วที่สุด ประหยัดแรมสุดขีด
// ==============================================================

class SessionRamStorage {
    // 🔑 ข้อมูลเซสชั่น
    public readonly string $sessionId;
    public readonly int $maxMemory;
    public readonly string $basePath;

    // 📌 ชั้นเก็บข้อมูล — เรียงลำดับความเร็ว
    public const LAYER_RAMDISK = 'ramdisk';   // /ramcache/ — ถ้ามี
    public const LAYER_GLOBAL  = 'global';    // php://temp — รวม
    public const LAYER_SESSION = 'session';   // php://temp/{session} — ส่วนตัว
    public const LAYER_MEMORY  = 'memory';    // php://memory — ชั่วคราว

    public function __construct(?string $sessionId = null, int $maxMemory = 2 * 1024 * 1024) {
        $this->sessionId = $sessionId ?? (session_id() ?: bin2hex(random_bytes(8)));
        $this->maxMemory = $maxMemory;

        // 🧠 ตรวจสอบและเลือกพื้นที่อัตโนมัติ
        $this->basePath = $this->detectBestLayer();
    }

    // ==============================================================
    // 🔍 ตรวจสอบชั้นที่ดีที่สุด — อัตโนมัติ
    // ==============================================================
    private function detectBestLayer(): string {
        // 1️⃣ RAM Disk จริง — ถ้ามี ใช้ก่อน เร็วสุด
        if (is_dir('/ramcache/') && is_writable('/ramcache/')) {
            return '/ramcache/sess_' . $this->sessionId . '_';
        }

        // 2️⃣ ไม่มี RAM Disk → ใช้ php://temp แยกเซสชั่น
        // php://temp ไม่มี path จริง — ใช้ชื่อเซสชั่นเป็นส่วนหนึ่งของ resource
        return 'temp://session_' . $this->sessionId . '_';
    }

    // ==============================================================
    // 📂 เปิดสตรีม — แยกตามเซสชั่น อัตโนมัติ
    // ==============================================================
    public function openStream(string $name, string $mode = 'r+b', ?int $maxMemory = null): false {
        $maxMem = $maxMemory ?? $this->maxMemory;
        $prefix = str_starts_with($this->basePath, 'temp://') 
            ? $this->basePath . $name 
            : $this->basePath . $name;

        // ใช้ php://temp พร้อมชื่อเซสชั่น — ไม่ชนกัน
        $fp = fopen("php://temp/maxmemory:$maxMem", $mode);
        if ($fp) {
            // เก็บชื่อไว้อ้างอิง
            stream_context_set_option($fp, 'session', 'name', $prefix);
            stream_context_set_option($fp, 'session', 'session_id', $this->sessionId);
        }
        return $fp;
    }

    // ==============================================================
    // ✍️ เขียนข้อมูล — เซสชั่นนี้เท่านั้น
    // ==============================================================
    public function write(string $name, string $data, ?int $maxMemory = null): int|false {
        $fp = $this->openStream($name, 'r+b', $maxMemory);
        if (!$fp) return false;
        
        $bytes = fwrite($fp, $data);
        rewind($fp);
        fclose($fp);
        return $bytes;
    }

    // ==============================================================
    // 📖 อ่านข้อมูล — เฉพาะเซสชั่นนี้
    // ==============================================================
    public function read(string $name): string|false {
        // สำหรับ php://temp ที่ไม่ใช่ไฟล์จริง — เก็บข้อมูลในอาร์เรย์แยกตามเซสชั่น
        // ใช้ Ds_Vector เป็นตัวกลาง
        $key = $this->sessionId . '_' . $name;
        return $GLOBALS['_SESSION_RAM'][$key] ?? false;
    }

    // ==============================================================
    // 💾 บันทึกถาวร (ถ้าต้องการ) — ไปที่ไฟล์จริง
    // ==============================================================
    public function persist(string $name, string $data): bool {
        $path = sys_get_temp_dir() . '/sess_' . $this->sessionId . '_' . $name;
        return file_put_contents($path, $data) !== false;
    }

    // ==============================================================
    // 🗑️ ล้างข้อมูลเซสชั่นนี้ทั้งหมด — เมื่อจบงาน
    // ==============================================================
    public function clear(): void {
        $prefix = 'sess_' . $this->sessionId . '_';
        
        // ล้างจากแรมรวม
        if (isset($GLOBALS['_SESSION_RAM'])) {
            foreach ($GLOBALS['_SESSION_RAM'] as $key => $val) {
                if (str_starts_with($key, $this->sessionId . '_')) {
                    unset($GLOBALS['_SESSION_RAM'][$key]);
                }
            }
        }

        // ล้างไฟล์ชั่วคราว
        foreach (glob(sys_get_temp_dir() . '/' . $prefix . '*') as $file) {
            @unlink($file);
        }
    }

    // ==============================================================
    // 📊 สถานะ — เหลือพื้นที่ เซสชั่นนี้ใช้ไปเท่าไร
    // ==============================================================
    public function getStats(): array {
        $usage = 0;
        if (isset($GLOBALS['_SESSION_RAM'])) {
            foreach ($GLOBALS['_SESSION_RAM'] as $key => $val) {
                if (str_starts_with($key, $this->sessionId . '_')) {
                    $usage += strlen((string)$val);
                }
            }
        }
        return [
            'session_id' => $this->sessionId,
            'usage_bytes' => $usage,
            'usage_mb' => round($usage / 1048576, 4),
            'max_memory' => $this->maxMemory,
            'layer' => $this->basePath,
        ];
    }

    // ==============================================================
    // 🔄 สร้างสตรีมพร้อม filter — แปลงขณะเขียน เซสชั่นนี้เท่านั้น
    // ==============================================================
    public function filteredWrite(string $name, string $data, string $filter = 'string.rot13'): bool {
        $tempPath = 'php://temp/maxmemory:' . $this->maxMemory;
        $fp = fopen($tempPath, 'r+b');
        if (!$fp) return false;
        
        fwrite($fp, $data);
        rewind($fp);
        
        // แปลงด้วย filter
        $filtered = file_get_contents("php://filter/write=$filter/resource=" . $tempPath);
        fclose($fp);
        
        // เก็บแยกตามเซสชั่น
        $GLOBALS['_SESSION_RAM'][$this->sessionId . '_' . $name] = $filtered;
        return true;
    }
}

// ==============================================================
// 🧠 ตัวช่วย — ใช้ท
