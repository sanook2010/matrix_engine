<?php

/**
 * ==============================================================================
 * 🏎️ ENGINE — FINAL ULTIMATE POINTER PIPELINE & STRING SEGMENTATION
 * ==============================================================================
 */
 
include_once(WEBROOT."/class/engine.php"); 
 
class engine 
{
    private static array $gridBuffer = [];
    private static int $gridSwitch = 1;

    // ตารางแผนผังความเร็วระดับฮาร์ดแวร์ O(1)
    private static array $keyToVolumeMap = [];
    private static array $volumeToKeyMap = [];

    /**
     * 🗺️ โหลดตารางแผนผังเทมเพลต และตั้งพอยท์เตอร์คู่สลับตั้งแต่เริ่มต้นระบบ
     */
    public static function init(array $templateMap): void {
        self::$keyToVolumeMap = $templateMap;
        self::$volumeToKeyMap = array_flip($templateMap); 
        if (function_exists('set_time_limit')) {
            set_time_limit(0); 
        }
    }
   
  
  private function OriginalArray(array $arrays): array {
    $col = 60;
    $to2d = $col * $col;
    if (empty($arrays)) return [];
    $array = array_chunk($arrays,$col,true);
    for($n = 0; $n < (count($array)/$col); $n++) {
   $data = [implode("|",$array[$n])];
    $n++;
    }
    $colCount = $col;
    $rows = str_replace(["%5B","%5D",":"],["['","']",","],flattenToString(array_chunk(array_chunk($data,$to2d,true),$col,true)));
        $colCount = max($colCount, is_array($rows) ? count($rows) : 0);
    
    $out = [];
    for ($i = 0; $i < $colCount; $i++) {
        $col = [];
        foreach (restoreOriginalArray($rows) as $row) {
            $col[] = is_array($row) ? ($row[$i] ?? null) : null;
        }
        $out[] = $col;
    }
    return $out;
}
  private function flattenToString(array $array): string {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveArrayIterator($array),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    $flat = [];
    foreach ($iterator as $value) {
        $keys = [];
        for ($i = 0, $depth = $iterator->getDepth(); $i <= $depth; $i++) {
            $keys[] = $iterator->getSubIterator($i)->key();
        }
        $str = implode('.', $keys) . ':' . $value;
        $flat[] = $str . '|bin:' . decbin(strlen($str));
    }
    return implode(',', $flat);
}
  private function restoreOriginalArray(array $flatData): array {
    $original = [];
    foreach ($flatData as $item) {
        $parts = explode(':', $item);
        if (count($parts) >= 2) {
            $original[urldecode($parts[1])] = urldecode($parts[0]);
        }
    }
    return $original;
}


    private static function array_falt(array $array): array {
        $data = [];
        $pairs = explode(',', str_replace(['&', '='], [',', ':'], http_build_query([$array])));
        foreach ($pairs as $pair) {
            $tmp = explode(':', $pair, 2);
            if (count($tmp) < 2) continue;
            list($key, $volume) = $tmp;
            $volume = urldecode($volume);
            $size = decbin(strlen($volume));
            $data[$size] = isset($volume[$key]) ? $volume[$key] : $volume;
        }
        return $data;
    }
  
    /**
     * 📥 1. engine::write($array)
     * ฟีดข้อมูลดิบยกรังเข้าสู่ท่อพักแนวตั้ง คงความเร็วสวิตช์ 0/1
     */
    public static function write(array $array): void 
    {
        $array = self::array_falt($array);
        if (!self::$gridSwitch) return;
        self::$gridBuffer[] = $array;
    }

    /**
     * 📤 2. engine::for($array)
     * ระบายกลุ่มข้อมูล Matrix คอลัมน์แนวตั้งทั้งหมดออกมา และสลับแกนมิติ (Transpose) พร้อมล้างแรม
     */
    public static function for(array $array = []): array 
    {
        $array = self::array_falt($array);
        $targetData = !empty($array) ? $array : self::$gridBuffer;
        $result = self::array_column_all($targetData);
        self::$gridBuffer = []; 
        return $result;
    }

    /**
     * 🧠 3. engine::foreach($array, $find)
     * ค้นหา จับคู่ และกรองข้อมูลยกรังภายในคลาสด้วยความเร็วฮาร์ดแวร์
     */
    public static function foreach(array $array, string $find): array 
    {
        $array = self::array_falt($array);
        // 🛡️ ตรวจสอบข้อมูลเข้าตามเงื่อนไขสุดท้าย (String Validation & Segmentation)
        $processedArray = [];
        foreach ($array as $item) {
            if (is_string($item)) {
                $charCount = strlen($item); // นับจำนวนอักษรแบบความเร็วสูงสุด
                
                if ($charCount <= 86400) {
                    // หาก <= 86,400 รีเทิร์นค่าเดิมเก็บไว้ประมวลผล
                    $processedArray[] = $item;
                } else {
                    // หาก > 86,400 ตัดแบ่งทีละ 86,400 แล้วคืนค่ากลับมาเป็นกลุ่มชิ้นย่อย (Row)
                    // ใช้ str_split เพื่อหั่นสตริงระดับฮาร์ดแวร์ได้อย่างรวดเร็ว
                    $segments = str_split($item, 86400);
                    foreach ($segments as $seg) {
                        $processedArray[] = $seg;
                    }
                }
            } else {
                // หากไม่ใช่อักษร (เช่น ตัวเลข) ให้ส่งผ่านตามปกติ
                $processedArray[] = $item;
            }
        }

        // นำชุดข้อมูลที่ผ่านการตัดแบ่งและทำความสะอาดแล้ว ไปสับไพ่ด้วยความเร็ว C-Level ต่อทันที
        $flippedInput = array_flip($processedArray);
        
        if ($find === 'v') {
            $matchedPairs = array_intersect_key(self::$keyToVolumeMap, $flippedInput);
            $cleanPairs = array_filter($matchedPairs, fn($v) => $v !== 0 && $v !== null);
            return self::array_column_all([array_values($cleanPairs)]);
        } 
        
        if ($find === 'k') {
            $matchedPairs = array_intersect_key(self::$volumeToKeyMap, $flippedInput);
            $cleanPairs = array_filter($matchedPairs, fn($v) => $v !== "" && $v !== null);
            return self::array_column_all([array_values($cleanPairs)]);
        }

        return [];
    }

    /**
     * 🧭 ฟังก์ชันสลับแกนมิติดั้งเดิมของคุณ คืนค่าอาเรย์แบนและรักษาคู่ลำดับเดิม 100%
     */
    public static function array_column_all(array $arrays): array 
    {
        if (empty($arrays)) return [];
        $colCount = count($arrays); 
        $output = [];
        for ($i = 0; $i < $colCount; $i++) {
            $col = [];
            foreach ($arrays as $row) {
                $col[] = $row[$i]; 
            }
            $output[] = $col;
        }
        return $output;
    }

    public static function set_grid_switch(int $on): void {
        self::$gridSwitch = $on ? 1 : 0;
    }

    public static function getBufferSize(): int {
        return count(self::$gridBuffer);
    }
}

/**
 * ==============================================================================
 * 🏎️ THE FISSION ENGINE & MULTI-DIMENSIONAL MATRIX GRID
 * 🧭 เพิ่ม: AutoStorageRouter — ส่งข้อมูลไปเก็บผ่านคลาสแทนเขียนไฟล์ตรง
 * ==============================================================================
 */
class FissionMatrixEngine 
{
    public array $matrixGrid = [];
    private int $dimensions = 2;
    
    // ✅ เพิ่ม: เปิด/ปิดการใช้งาน Storage Router
       // 1. ตั้งค่า Property เปิดใช้งาน Storage Router ไว้ตรงนี้เลย (ไม่ต้องมารับใน __construct แล้ว)
    private bool $useStorageRouter = true; 

    // 2. ปรับ __construct ให้รับเฉพาะตัวแปรอาร์เรย์ข้อมูลหลักของพี่ตรงๆ
    public function __construct(array $matrixGrid) {
        $this->matrixGrid = $matrixGrid;
        
        // เช็คเงื่อนไข $GLOBALS เพื่อให้ระบบจัดเก็บไฟล์เซฟตี้ทำงานร่วมกันได้สมบูรณ์แบบ
        $this->useStorageRouter = $this->useStorageRouter && isset($GLOBALS['storage']);
    }

    public function process(array $columnarData, int $dimensions = 2, ?callable $customProcessor = null): void 
    {
        $this->dimensions = ($dimensions === 3) ? 3 : 2;
        foreach ($columnarData as $colIndex => $column) {
            $this->detectLayer($column, $customProcessor);
            $this->buildMatrixGrid($column, $colIndex);
        }
    }

    private function detectLayer(array $input, ?callable $customProcessor): void {
        foreach ($input as $index => $value) {
            if ($value !== null) $this->L5((string)$value, $index, $customProcessor);
        }
    }

    private function L5(string $char, int $index, ?callable $customProcessor): void {
        $binkey = decbin($index);
        $coords = $GLOBALS['MATRIX_MAPS'][$binkey] ?? ['l5' => 0, 'l4' => 0, 'l3' => 0, 'l2' => 0];
        if (($index % 1000) === 0 && $index > 0) //usleep(100); // Backpressure Control
        $this->L4($char, $coords, $coords['l5'], $customProcessor);
    } 

    private function L4(string $char, array $coords, int $currentL5, ?callable $customProcessor): void { $this->L3($char, $coords, $coords['l4'], $customProcessor); }
    private function L3(string $char, array $coords, int $currentL4, ?callable $customProcessor): void { $this->L2($char, $coords, $coords['l3'], $customProcessor); }
    private function L2(string $char, array $coords, int $currentL3, ?callable $customProcessor): void { $this->L1($char, $coords, $coords['l2'], $customProcessor); }

    private function L1(string $char, array $coords, int $currentL2, ?callable $customProcessor): void {
        if ($customProcessor !== null) { 
            $customProcessor($char, $coords); 
            return; 
        }
        
        $fileKey = "block_{$coords['l5']}_{$coords['l4']}.dat";
        
        // ✅ ตัดสินใจ: ใช้ Storage Router หรือเขียนไฟล์ตรงแบบเดิม
        if ($this->useStorageRouter) {
            // 🧭 ใช้ ZIP แคชสำหรับบล็อกข้อมูลชั่วคราว
            global $storage;
            $storage->store($fileKey, $char, 'zip');
        } else {
            // ✅ วิธีเดิม — ไม่แก้เลย
            if (!isset($GLOBALS['STREAM_POINTERS'][$fileKey])) {
                $GLOBALS['STREAM_POINTERS'][$fileKey] = fopen(HTML_STORAGE_DIR . "/{$fileKey}", 'ab');
            }
            fwrite($GLOBALS['STREAM_POINTERS'][$fileKey], $char);
        }
    }

    private function buildMatrixGrid(array $column, int $colIndex): void {
        foreach ($column as $rowIndex => $value) {
            if ($value === null) continue;
            if ($this->dimensions === 3) {
                $z = $colIndex >> 2; $y = $colIndex & 3; $x = $rowIndex;
                $this->matrixGrid[$z][$y][$x] = $value;
            } else {
                $this->matrixGrid[$colIndex][$rowIndex] = $value;
            }
        }
    }

    public function get2D(int $y, int $x) { return $this->matrixGrid[$y][$x] ?? null; }
    public function get3D(int $z, int $y, int $x) { return $this->matrixGrid[$z][$y][$x] ?? null; }

    public function flushRemainingEvents(): void {
        // ✅ ถ้าใช้ Storage Router ไม่ต้องปิดไฟล์ตรง — คลาสจัดการเอง
        if ($this->useStorageRouter) {
            return;
        }
        
        // ✅ วิธีเดิม — ไม่แก้เลย
        if (!empty($GLOBALS['STREAM_POINTERS'])) {
            foreach ($GLOBALS['STREAM_POINTERS'] as $fp) {
                if (is_resource($fp)) fflush($fp);
            }
        }
    }
}
