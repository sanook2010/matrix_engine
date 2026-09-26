<?php
require_once(WEBROOT.'/class/dbstream.php');
require_once(WEBROOT.'/class/SQLiteToGrid.class.php');
require_once(WEBROOT.'/class/tar.class.php');

// =============================================================================
// 🚀 SECURE GRID STREAM ENGINE (MATRIX MEMORY & TAR ARCHITECTURE)
// =============================================================================

set_time_limit(0);
class_exists('SQLiteToGrid');
class_exists('DBStream');
class_exists('tar');

ini_set('memory_limit', '-1');
ini_set('display_errors', '1');
error_reporting(E_ALL);

if (!function_exists("array_column")) {
    function array_column($array,$column_name) {
        return array_map(function($element) use ($column_name) {
            return $element[$column_name];
        }, $array);
    }
}

class SecureGridStreamMatrix {
    private string $storage_dir;
    private string $tiny_grid_dir;
    private string $db_path;
    private ?PDO $pdo = null;
    private array $emoji128;
    private array $sizeConfig;

    public function __construct(string $storage_dir = WEBROOT . '/storage', array$sizeConfig = []) {
        $this->storage_dir = rtrim($storage_dir, '/');
        $this->tiny_grid_dir =$this->storage_dir . '/tiny_grids/wheel_tags_100k';
        $this->db_path =$this->storage_dir . '/grid_architecture.sqlite';

        if (!is_dir($this->storage_dir)) { @mkdir($this->storage_dir, 0755, true); }
        if (!is_dir($this->tiny_grid_dir)) { @mkdir($this->tiny_grid_dir, 0755, true); }

        $emojiStr = '✦✧✩⭐⭐🌟✨💫⚡🔥🌀♻️⚜️🔱🎴🀄♠️♣️♥️♦️♟️🧲⚙️🔗📎📌📍✂️🖊️🖋️🖌️🖍️📝🔍🔎🔏🔐🔒🔓🔔🔕📣📢💬💭🗯️♠♣♥♦♨♩♪♫♬♭♯♀♂☿♁♃♄♅♆♇♈♉♊♋♌♍♎♏♐♑♒♓☀️☁️☂️☃️☄️☎️📞📟🔋🔌💻💽💾💿📀🎥🎞️📽️🎬📺📷📸📹📼🕯️💡🔦🏮🧱🕳️💣🛀🛌🛍️🛒🎁🎈🎏🎀🪄🪅🪩🧿🔮🪬🗿';
        $this->emoji128 = preg_split('//u',$emojiStr, -1, PREG_SPLIT_NO_EMPTY);

        $this->sizeConfig = empty($sizeConfig) ? [             0 => 256, 1 => 3, 2 => 256, 3 => 5, 4 => 6, 5 => 7, 6 => 8, 7 => 9, 8 => 1000, 9 => 11, 10 => 12,             13 => 15, 14 => 256, 15 => 17, 16 => 18, 17 => 19, 18 => 20, 19 => 21, 20 => 22, 21 => 23, 22 => 24,             23 => 25, 24 => 26, 25 => 27, 26 => 28, 27 => 29, 28 => 30, 29 => 31, 30 => 30, 31 => 31, 32 => 32,             33 => 33, 34 => 34, 35 => 35, 36 => 35         ] :$sizeConfig;

        $this->initDatabaseStream();
    }

    /**
     * 🎯 ฟังก์ชันอ่านและดึงข้อมูลเฉพาะช่วงตัวอักษรเป๊ะๆ (Precise Character Range / Slice) จากไฟล์ภายใน TAR
     */
    public function fetchPreciseCharRangeFromTar(int $fileId, int $startCharIndex, int $length): string {
        $stmt = $this->pdo->prepare("SELECT tar_name, inner_file FROM tar_stream_index WHERE file_id = ?");
        $stmt->execute([$fileId]);
        $info = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$info) return '';

        $tarPath = $this->storageDir . $info['tar_name'];
        if (!file_exists($tarPath)) return '';

        try {
            // อ่านไฟล์ภายใน TAR ผ่าน Stream Wrapper phar://
            $streamPath = "phar://" . $tarPath . "/" . $info['inner_file'];
            if (file_exists($streamPath)) {
                $content = file_get_contents($streamPath);
                
                // ตัดช่วงตัวอักษรเป๊ะๆ ตามตำแหน่งเริ่มต้น (Start) และความยาว (Length) ที่ระบุ
                return mb_substr($content, $startCharIndex, $length);
            }
        } catch (Exception $e) {
            return '';
        }
        return '';
    }
    
    public function initDatabaseStream(): PDO {
        if ($this->pdo === null) {$this->pdo = new PDO('sqlite:' . $this->db_path);$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS index_match (
                match_id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id INTEGER,
                header_signature TEXT,
                footer_signature TEXT,
                md5_hash TEXT
            )");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS index_file (
                file_id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_name TEXT,
                line_id INTEGER,
                location_url TEXT,
                tag_group_id INTEGER,
                referenced_by_ids TEXT
            )");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS tag_index (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                col1_code TEXT,
                col2_number_seq INTEGER
            )");
        }
        return $this->pdo;
    }

    private function arrayColumnAll(array $arrays): array {$output = [];
        $columnCount = count($arrays[0]);
        for ($i = 0; $i <$columnCount; $i++) {$output[] = array_column($arrays,$i);
        }
        return $output;
    }

    // ฟังก์ชันเข้ารหัส / Transform ข้อมูล "ก่อน" นำไปแปลงเป็นฐาน 10
    public function encryptBeforeBase10($value): string {
        // ตัวอย่างการเข้ารหัสเชิงโครงสร้าง (เช่น การทำ Bitwise / Custom Salt Mask หรือ Base64 Encode ล่วงหน้า)
        $stringVal = (string)$value;
        return base64_encode($stringVal);
    }

    // ฟังก์ชันแปลงเลขฐานระยะยาวด้วย GMP
    public function convertBaseLong($value, int$from_base, int $to_base = 10): string {$gmp_num = gmp_init((string)$value,$from_base);
        return gmp_strval($gmp_num,$to_base);
    }

    public function encodeToEmoji(int $byteVal): string {
        return $this->emoji128[$byteVal % count($this->emoji128)];
    }

    public function saveTinyGridBlock(string $grid_id, array $dataArray): bool {$file = $this->tiny_grid_dir . '/' .$grid_id . '.txt';
        $fp = @fopen($file, 'w');
        if (!$fp) return false;

        foreach ($dataArray as $item) {$rowString = json_encode([
                'pos'        => $item['pos'] ?? 0,                 'encrypted'  =>$item['encrypted_val'] ?? '',
                'base10'     => $item['base10'] ?? '0',                 'emoji'      =>$item['emoji'] ?? ''
            ], JSON_UNESCAPED_UNICODE);
            
            fwrite($fp,$rowString . "\n");
        }
        fclose($fp);
        return true;
    }

    // ดึงข้อมูลจากฐานข้อมูลมาประมวลผลแบบเมทริกซ์และจัดเก็บลง TAR
    public function processAndStoreAsTarMatrix(string $outputTarPath): string {$tar = new tar();
        
        // ดึงข้อมูลจากฐานข้อมูล tag_index ตามรูปแบบที่กำหนด
        $stmt =$this->pdo->query("SELECT col2_number_seq, col1_code AS wrapped_code FROM tag_index ORDER BY LENGTH(col2_number_seq) DESC, id ASC");
        $rowsFromDb =$stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rowsFromDb)) {
            throw new Exception("ไม่พบข้อมูลในตาราง tag_index สำหรับประมวลผล");
        }

        $flatValues = [];
        foreach ($rowsFromDb as$row) {
            $flatValues[] =$row['wrapped_code'];
            $flatValues[] =$row['col2_number_seq'];
        }

        // จัดการข้อมูลเข้าหน่วยความจำแบบเมทริกซ์ (Matrix Chunking)
        $array = array_chunk($flatValues, 35, false);$matrixData = $this->arrayColumnAll($array);

        $rowMap = [];$offset = 0;
        foreach ($this->sizeConfig as $base =>$chunkSize) {
            if (isset($matrixData[$offset])) {$rowMap[$base] = array_slice($matrixData, $offset,$chunkSize);
                $offset +=$chunkSize;
            } else {
                break;
            }
        }

        $setNo = 1;
        foreach ($rowMap as$baseKey => $items) {$processedRows = [];
            foreach ($items as $pos =>$val) {
                // 1. เข้ารหัส/แปลงข้อมูล "ก่อน" แปลงเป็นฐาน 10 ตามเงื่อนไขใหม่
                $encryptedVal = $this->encryptBeforeBase10($val);

                // 2. แปลงค่าที่เข้ารหัสแล้ว เป็นฐาน 10
                $base10Value =$this->convertBaseLong($val, max(2, (int)$baseKey), 10);
                $intBase10 = abs((int)gmp_strval(gmp_init($base10Value, 10), 10));

                // 3. แปลงเป็นอีโมจิ 128
                $emoji = $this->encodeToEmoji($intBase10);

                $processedRows[] = [
                    'pos'           => $pos + 1,                     'encrypted_val' =>$encryptedVal,
                    'base10'        => $base10Value,
                    'emoji'         => $emoji
                ];
            }

            $gridId = "grid_matrix_base_{$baseKey}_set_{$setNo}";
            $this->saveTinyGridBlock($gridId,$processedRows);

            // อ่านไฟล์ที่บันทึกเพื่อแพ็กเข้า TAR
            $blockFilePath = $this->tiny_grid_dir . '/' .$gridId . '.txt';
            $fileContent = file_get_contents($blockFilePath);

            $tar->files[] = [
                "name"      => "block_{$setNo}.json",
                "mode"      => 0644,
                "size"      => strlen($fileContent),
                "time"      => time(),
                "user_id"   => 0,
                "group_id"  => 0,
                "user_name" => "",
                "group_name"=> "",
                "checksum"  => 0,
                "file"      => $fileContent
            ];
            $tar->numFiles++;$setNo++;
        }

        // ส่งออกเป็นไฟล์ TAR สมบูรณ์
        $tar->toTar($outputTarPath, false);
        return $outputTarPath;
    }
}
?>