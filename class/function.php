<?php
 // ==============================================================
 // ⚙️ ค่าคงที่ระบบ
 // ==============================================================
 //define('TEMP_MAX_MEMORY', 2 * 1024 * 1024); // 2MB — ประหยัดแรม
 //$GLOBALS["maxmemory"] = TEMP_MAX_MEMORY;
 $GLOBALS["sessionId"] = session_id() ?: bin2hex(random_bytes(8));
 //กำหนดให้เป็นอินสแตนซ์ของ PDO เช่น $dbconn = new PDO(...);
 $SQLite_con = SQLite_connect();
 if(isset($GLOBALS['conn'])){
     $GLOBALS['conn'] = db_connect();
 }
/**
function db_query($sql, $params = array(), $single = true)
{
    try {
        $stmt = $GLOBALS['conn']->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $success = $stmt->execute($params);
        if (!$success) {
            return null;
        }
        if ($stmt->columnCount() === 0) {
            $affected_rows = $stmt->rowCount();
            $trimmed_sql = strtoupper(trim($sql));
            $is_insert = (strpos($trimmed_sql, 'INSERT') === 0);

            if ($is_insert && $affected_rows > 0) {
                return $GLOBALS['conn']->lastInsertId();
            }
            return $affected_rows;
        }
        if ($single) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null; 
        } else {
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        return null;
    }
}
/**
// สร้างตารางสารบัญแผนที่ไฟล์
db_query_sqlite("CREATE TABLE IF NOT EXISTS file_maps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_tag TEXT UNIQUE,    // คำค้นหาลัด หรือรหัสอ้างอิง เช่น 'user_logs_jan'
    file_name TEXT,          // ชื่อไฟล์จริงในเครื่อง
    full_path TEXT,          // พาธเต็มพิกัดในเครื่อง เช่น '/var/www/uploads/logs/'
    file_size INTEGER        // ขนาดไฟล์เอาไว้เช็ค
)");

// เพิ่มดัชนี (INDEX) ที่คอลัมน์คำค้นหาลัด เพื่อให้ค้นหาเจอใน 0.001 วินาที
db_query_sqlite("CREATE INDEX IF NOT EXISTS idx_tag ON file_maps(file_tag)");

function show_mapped_file($file_tag) {
	global $SQLite_con;
	SQLite_query("CREATE INDEX IF NOT EXISTS idx_tag ON file_maps(file_tag)");
    $map = SQLite_query("SELECT full_path, file_name FROM file_maps WHERE file_tag = ?", [$file_tag]);
    if (!$map) {
        echo "ไม่พบไฟล์นี้ในระบบแผนที่ข้อมูล";
        return;
    }
    $target_file = $map['full_path'] . $map['file_name'];
    fetch_stream($target_file); 
}
**/
function SQLite_connect($db="database.db"){
try {
    $db_file = __DIR__ . '/'.$db; 
    $dbconn_sqlite = new PDO("sqlite:" . $db_file);
    $dbconn_sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT); 
} catch (PDOException $e) {
    die("เชื่อมต่อฐานข้อมูล SQLite ล้มเหลว: " . $e->getMessage());
}
 return $dbconn_sqlite;
}

function SQLite_query($sql, $params = array(), $single = true)
{
	global $SQLite_con;
    try {
        $stmt = $SQLite_con->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $success = $stmt->execute($params);
        if (!$success) {
            return null;
        }
        if ($stmt->columnCount() === 0) {
            $affected_rows = $stmt->rowCount();
            $trimmed_sql = strtoupper(trim($sql));
            $is_insert = (strpos($trimmed_sql, 'INSERT') === 0);

            if ($is_insert && $affected_rows > 0) {
                return $SQLite_con->lastInsertId();
            }
            return $affected_rows;
        }
        if ($single) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null; 
        } else {
            return $stmt->fetchAll(PDO::FETCH_ASSOC); 
        }

    } catch (Exception $e) {
        return null;
    }
}

/**
 * โหมดสแตนด์บายดัมพ์แรมล้างแผง: สะสมแถวข้อมูลดิบเรียงหนึ่งบนแรมโดยไม่สนชื่อเซ็ต
 * เมื่อข้อมูลรวมหนาแน่นถึงขีดจำกัด ให้ดัมพ์ลง SQLite รวดเดียวเพื่อรีเซ็ตแรมเป็นศูนย์
 **/
 /**
function standby_global_dump_pipeline(array $singlePacket, int $globalRamLimit = 10000, int $setFlushThreshold = 56000, string $saveDir = __DIR__ . '/storage')
{
    global $SQLite_con; // เรียกพอร์ตเชื่อมต่อ SQLite ดั้งเดิมในเว็บคุณ
    
    // 💡 1. ตัวแปร Static บนแรม คอยจดจำแบบแถวเรียงหนึ่ง (Flat Rows Buffer) และตัวนับรวม
    static $globalRamQueue = [];
    static $setTotalCounters = [];

    $setName = $singlePacket['Block_Set'] ?? ($singlePacket['block_set'] ?? 'DEFAULT_SET');
    $id      = $singlePacket['id'] ?? '0';
    $seq     = $singlePacket['Sequence'] ?? ($singlePacket['sequence'] ?? '0');
    $grp     = $singlePacket['Group'] ?? ($singlePacket['block_group'] ?? '0');
    $chk     = $singlePacket['Chunk'] ?? ($singlePacket['block_chunk'] ?? '0');

    // เตรียมระบบสร้างตารางและดัชนี (รันเพียงครั้งเดียว)
    if (empty($setTotalCounters)) {
        SQLite_query("CREATE TABLE IF NOT EXISTS global_dump_pixels (
            block_set TEXT, id TEXT, sequence TEXT, block_group TEXT, block_chunk TEXT
        )");
        SQLite_query("CREATE INDEX IF NOT EXISTS idx_dump_set ON global_dump_pixels(block_set);");
    }

    // เจาะจงตัวนับสะสมรวม (ของเก่าในตาราง + ของใหม่ในแรม) ของเซ็ตที่ไหลเข้ามาในรอบนี้
    if (!isset($setTotalCounters[$setName])) {
        $checkCount = SQLite_query("SELECT COUNT(*) as cnt FROM global_dump_pixels WHERE block_set = ?", [$setName], true);
        $setTotalCounters[$setName] = $checkCount ? (int)$checkCount['cnt'] : 0;
    }

    // ผลักข้อมูลเดี่ยวที่ไหลเข้ามาลงตู้แรมจำแบบแถวตรงดิบๆ (Flat Array) กินแรมน้อยมากเพราะไม่มีการแตก Key ซับซ้อน
    $globalRamQueue[] = [
        'set' => $setName, 'id' => $id, 'seq' => $seq, 'grp' => $grp, 'chk' => $chk
    ];
    $setTotalCounters[$setName]++;

    // ⚡ [จุดระเบิดที่ 1: ดัมพ์ข้อมูลล้างแผงเมื่อข้อมูลรวมในแรมหนาแน่นถึงเกณฑ์ที่กำหนด]
    // ไม่สนใจว่าส่งมาสลับ Set มั่วขนาดไหน ถ้าจำนวนแถวรวมในแรมสะสมจนครบ (เช่น 10,000 แถว) สั่งดัมพ์เทลง SQLite ทันที!
    if (count($globalRamQueue) >= $globalRamLimit) {
        
        // ใช้ระบบ Transaction ความเร็วสูง ย้ายข้อมูลจากแรมลงฐานข้อมูลชั่วคราวใน WAL Mode รวดเดียว
        if ($SQLite_con) { $SQLite_con->beginTransaction(); }
        try {
            foreach ($globalRamQueue as $rowItem) {
                SQLite_query(
                    "INSERT INTO global_dump_pixels (block_set, id, sequence, block_group, block_chunk) VALUES (?, ?, ?, ?, ?)",
                    [$rowItem['set'], $rowItem['id'], $rowItem['seq'], $rowItem['grp'], $rowItem['chk']]
                );
            }
            if ($SQLite_con) { $SQLite_con->commit(); }
        } catch (Exception $e) {
            if ($SQLite_con && $SQLite_con->inTransaction()) { $SQLite_con->rollBack(); }
        }
        
        // 💥 ล้างแผงหน่วยความจำแรม คืนพื้นที่ว่างให้เซิร์ฟเวอร์กลับเป็นศูนย์ (Reset RAM Queue)
        $globalRamQueue = [];
    }

    // 💡 [จุดระเบิดที่ 2: สั่งมัดรวมใช้งานเมื่อตัวนับรวมของเซ็ตใดเซ็ตหนึ่งสะสมพลังพิกเซลครบ 56K จริงๆ]
    if ($setTotalCounters[$setName] >= $setFlushThreshold) {
        
        // ก่อนทำการคอมไพล์ ให้เคลียร์เทข้อมูลที่ยังค้างคาอยู่ในแรมก้อนปัจจุบันลง SQLite ให้หมดก่อนเพื่อให้ข้อมูลครบถ้วน
        if (!empty($globalRamQueue)) {
            if ($SQLite_con) { $SQLite_con->beginTransaction(); }
            foreach ($globalRamQueue as $rowItem) {
                SQLite_query("INSERT INTO global_dump_pixels (block_set, id, sequence, block_group, block_chunk) VALUES (?, ?, ?, ?, ?)", [$rowItem['set'], $rowItem['id'], $rowItem['seq'], $rowItem['grp'], $rowItem['chk']]);
            }
            if ($SQLite_con) { $SQLite_con->commit(); }
            $globalRamQueue = []; // ล้างแรมเป็นศูนย์
        }

        // 🔍 ดึงข้อมูลกลับมารวมใช้งาน: อ่านคิวรี่เอาพิกเซลเฉพาะของ Set ที่ครบ 56K ขึ้นมาจากตาราง SQLite 
        $rows = SQLite_query("SELECT id, sequence, block_set, block_group, block_chunk FROM global_dump_pixels WHERE block_set = ?", [$setName], false);
        
        $mergedStreamString = '';
        if (is_array($rows)) {
            foreach ($rows as $row) {
                // หลอมรวมสายสตริงคั่นด้วยสัญลักษณ์ Pipe '|' ส่งต่อไปที่ 3 ขุนพลหลักดั้งเดิมของคุณ
                $mergedStreamString .= "{$row['id']}|{$row['sequence']}|{$row['block_set']}|{$row['block_group']}|{$row['block_chunk']}\n";
            }
        }

        // ดีดสายข้อมูลวิ่งชาร์จตรงเข้าลูปเกียร์ 5 มิติ O(N^5) ตัวดั้งเดิมในเว็บคุณทันทีเพื่อเรนเดอร์ภาพ PNG
        $payloadData = ['str' => $mergedStreamString];
        run::initLog(true, __DIR__ . "/telemetry_global_flush_{$setName}.log");
        $mapper = new MatrixPixelMapper();

        run::execute($payloadData, '0.0.0.0', "GLOBAL_FLUSH_{$setName}", function($virtualKey, $volume) use ($mapper) {
            $mapper->collect($volume);
        });

        // คอมไพล์เซฟรูปภาพ PNG 100% Lossless
        if (!is_dir($saveDir)) { @mkdir($saveDir, 0777, true); }
        $targetImagePath = $saveDir . "/matrix_canvas_set_{$setName}.png";
        $mapper->compileToImage($targetImagePath);
        
        run::close();

        // บันทึกพิกัดพาธไฟล์ลงสู่ระบบสารบัญแผนที่ไฟล์ดั้งเดิม (File Maps) ของเดิมที่มีฟังก์ชัน show_mapped_file()
        SQLite_query(
            "INSERT OR REPLACE INTO file_maps (file_tag, file_name, full_path, file_size) VALUES (?, ?, ?, ?)",
            [$setName, "matrix_canvas_set_{$setName}.png", __DIR__ . "/storage/", filesize($targetImagePath)]
        );

        // 💥 สลายคิวงานของ Set นี้ออกจากตาราง SQLite และลบตัวนับสะสมทิ้งเกลี้ยงระบบ ส่วนของเซ็ตอื่นที่ยังไม่ครบ 56K ก็ยังนอนพักนิ่งๆ ใน SQLite รอเวลาทำงานต่ออย่างปลอดภัย
        SQLite_query("DELETE FROM global_dump_pixels WHERE block_set = ?", [$setName]);
        unset($setTotalCounters[$setName]);

        return [
            "status" => "flushed_to_png",
            "set_name" => $setName,
            "image_path" => $targetImagePath,
            "message" => "ข้อมูลสะสมรวมร่างครบ 56K ขับเคลื่อนแกนหลักคอมไพล์ลงสารบัญแผนที่ภาพเรียบร้อย"
        ];
    }

    // รายงานสถานะระหว่างการสะสมแถวเรียงหนึ่งบนแรมสแตนด์บาย
    return [
        "status" => "global_staging",
        "set_name" => $setName,
        "current_ram_rows" => count($globalRamQueue),
        "total_set_accumulated" => $setTotalCounters[$setName],
        "message" => "สะสมแถวข้อมูลลงแรมจำตรงแบบ Flat Rows สำเร็จ (คิวสะสมในแรมปัจจุบัน: " . count($globalRamQueue) . "/{$globalRamLimit})"
    ];
}
**/
function json_response($data, $status_code = 200) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status_code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function input_get($key, $default = null) {
    $value = isset($_POST[$key]) ? $_POST[$key] : (isset($_GET[$key]) ? $_GET[$key] : $default);
    if ($value === null) return $default;
    if (is_array($value)) {
        return array_map(function($v) { return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8'); }, $value);
    }
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

/**
function fetch_stream($url, $options = []) {
    $method      = isset($options['method']) ? strtoupper($options['method']) : 'GET';
    $custom_hdrs = isset($options['headers']) ? $options['headers'] : [];
    $return_mode = isset($options['return']) ? (bool)$options['return'] : false;
    $body_data   = isset($options['body']) ? $options['body'] : null;

    $cookie_file_path = __DIR__ . '/my_session_cookies.txt'; 
    $cookies = [];
    if (file_exists($cookie_file_path)) {
        $saved_data = file_get_contents($cookie_file_path);
        $cookies = json_decode($saved_data, true) ?? [];
    }
    $headers_dict = [];
    $headers_dict['User-Agent'] = 'PHP-Stream-Client/3.0';
    if (!empty($cookies)) {
        $cookie_pairs = [];
        foreach ($cookies as $name => $value) {
            $cookie_pairs[] = "{$name}={$value}";
        }
        $headers_dict['Cookie'] = implode('; ', $cookie_pairs);
    }
    $stream_content = null;
    if ($body_data !== null) {
        $has_file = false;
        if (is_array($body_data)) {
            foreach ($body_data as $key => $value) {
                if (is_string($value) && strpos($value, '@') === 0) {
                    $has_file = true;
                    break;
                }
            }
        }
        if ($has_file && $method === 'POST') {
            $boundary = '--------------------------' . microtime(true);
            $headers_dict['Content-Type'] = "multipart/form-data; boundary={$boundary}";
            $stream_content = fopen('php://temp', 'r+');
            
            foreach ($body_data as $key => $value) {
                if (is_string($value) && strpos($value, '@') === 0) {
                    // แกะพาธไฟล์จริงออกมาหลังเครื่องหมาย @
                    $file_path = substr($value, 1);
                    if (file_exists($file_path)) {
                        $file_name = basename($file_path);
                        $mime_type = mime_content_type($file_path) ?: 'application/octet-stream';
                        fwrite($stream_content, "--{$boundary}\r\n");
                        fwrite($stream_content, "Content-Disposition: form-data; name=\"{$key}\"; filename=\"{$file_name}\"\r\n");
                        fwrite($stream_content, "Content-Type: {$mime_type}\r\n\r\n");
                        $file_stream = new SplFileObject($file_path);
                        while (!$file_stream->feof()) {
                  echo $file_stream->fread(1024)."\r\n";
                        }
                        //fclose($file_stream);
                    }
                } else {
                    fwrite($stream_content, "--{$boundary}\r\n");
                    fwrite($stream_content, "Content-Disposition: form-data; name=\"{$key}\"\r\n\r\n");
                    fwrite($stream_content, "{$value}\r\n");
                }
            }
            fwrite($stream_content, "--{$boundary}--\r\n");
            rewind($stream_content);
        } else {
            if (is_array($body_data)) {
                $is_json_intent = false;
                foreach ($custom_hdrs as $h) {
                    if (stripos($h, 'application/json') !== false) { $is_json_intent = true; break; }
                }
                $body_data = $is_json_intent ? json_encode($body_data) : http_build_query($body_data);
                if (!$is_json_intent) $headers_dict['Content-Type'] = 'application/x-www-form-urlencoded';
            }
            $stream_content = $body_data;
        }
    }
    foreach ($custom_hdrs as $c_hdr) {
        $parts = explode(':', $c_hdr, 2);
        if (count($parts) === 2) { $headers_dict[trim($parts[0])] = trim($parts[1]); }
        else { $headers_dict[trim($c_hdr)] = ''; }
    }
    $header_string = "";
    foreach ($headers_dict as $k => $v) {
        $header_string .= ($v !== '') ? "{$k}: {$v}\r\n" : "{$k}\r\n";
    }
    $stream_opts = [
        'http' => [
            'method'           => $method,
            'header'           => $header_string,
            'content'          => $stream_content, // รองรับทั้งแบบ String และ Resource Stream ของไฟล์
            'follow_location'  => 1,
            'ignore_errors'    => true
        ]
    ];
    $context = stream_context_create($stream_opts);
    $stream = @fopen($url, 'rb', false, $context);
    if ($stream === false) {
        if (!$return_mode) echo "ไม่สามารถเปิดการเชื่อมต่อ Stream ได้";
        return null;
    }
    $response_body = '';
    while (!feof($stream)) {
        $line = fgets($stream);
        if ($line !== false) {
            if ($return_mode) { $response_body .= $line; } 
            else { echo $line; flush(); }
        }
    }
    $meta_data = stream_get_meta_data($stream);
    if (isset($meta_data['wrapper_data'])) {
        $headers = $meta_data['wrapper_data'];
        $has_new_cookies = false;
        foreach ($headers as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                $cookie_raw = trim(substr($header, 11));
                $cookie_parts = explode(';', $cookie_raw);
                $cookie_kv = explode('=', $cookie_parts[0], 2);
                if (count($cookie_kv) === 2) {
                    $cookies[trim($cookie_kv[0])] = trim($cookie_kv[1]);
                    $has_new_cookies = true;
                }
            }
        }
        if ($has_new_cookies) {
            file_put_contents($cookie_file_path, json_encode($cookies, JSON_PRETTY_PRINT));
        }
    }
    fclose($stream);
    if (is_resource($stream_content)) fclose($stream_content);
    if ($return_mode) {
        $decoded_data = json_decode($response_body, true);
        return ($decoded_data !== null) ? $decoded_data : $response_body;
    }
    return true;
}

function fetch_stream_to_temp_db($url, $file_tag, PDO $temp_db) {
    $stream = @fopen($url, 'rb');
    if ($stream === false) {
        echo "❌ ไม่สามารถเปิดสตรีมข้อมูลเพื่ออ่านได้\n";
        return false;
    }

    $memory_buffer = [];
    $grid_limit = 256 * 256; 

    $temp_db->exec("PRAGMA synchronous = OFF;");
    $temp_db->exec("PRAGMA journal_mode = MEMORY;");
    
    $temp_db->exec("CREATE TABLE IF NOT EXISTS temp_frame_pixels (
        file_tag TEXT, 
        frame_no INTEGER, 
        coord_id INTEGER, 
        color TEXT,
        PRIMARY KEY (file_tag, frame_no, coord_id)
    )");
    $temp_db->exec("CREATE INDEX IF NOT EXISTS idx_temp_coord ON temp_frame_pixels(file_tag, coord_id);");

    while (!feof($stream)) {
        $line = fgets($stream);
        if ($line !== false) {
            $line_content = trim($line);
            if (empty($line_content)) continue;

            $parts = explode('|', $line_content);
            if (count($parts) >= 5) {
                $frame_no = (int)trim($parts[0]);
                $x        = (int)trim($parts[1]);
                $y        = (int)trim($parts[2]);
                $z        = (int)trim($parts[3]);
                $color    = trim($parts[4]);

                $packed_coord = ($x << 16) | ($y << 8) | $z;

                $memory_buffer[] = [
                    'frame_no' => $frame_no,
                    'coord'    => $packed_coord, 
                    'color'    => $color
                ];
            }

            if (count($memory_buffer) >= $grid_limit) {
                $temp_db->beginTransaction();
                try {
                    $stmt = $temp_db->prepare("INSERT OR REPLACE INTO temp_frame_pixels (file_tag, frame_no, coord_id, color) VALUES (?, ?, ?, ?)");
                    foreach ($memory_buffer as $data) {
                        $stmt->execute([$file_tag, $data['frame_no'], $data['coord'], $data['color']]);
                    }
                    $temp_db->commit();
                } catch (Exception $e) {
                    $temp_db->rollBack();
                }
                $memory_buffer = []; 
            }
        }
    }

    if (!empty($memory_buffer)) {
        $temp_db->beginTransaction();
        try {
            $stmt = $temp_db->prepare("INSERT OR REPLACE INTO temp_frame_pixels (file_tag, frame_no, coord_id, color) VALUES (?, ?, ?, ?)");
            foreach ($memory_buffer as $data) {
                $stmt->execute([$file_tag, $data['frame_no'], $data['coord'], $data['color']]);
            }
            $temp_db->commit();
        } catch (Exception $e) {
            $temp_db->rollBack();
        }
        unset($memory_buffer);
    }

    fclose($stream);
    return true;
}
**/
 // ==============================================================
 // 🔢 baseconvert — แปลงเลข↔ฐาน รองรับฐาน 2–36, 52, 60, 62, 64, 128(อีโมจิ)
 //    คีย์ฐาน2 = สตริง '0'/'1' — CPU อ่านเป็นบิตได้ตรง ไม่ต้องแปลง
 // ==============================================================

function baseconvert($data, $base, $count = null) {
     $lines = @file("base.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
     $s = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
     $e = "✦✧✩⭐🌟✨💫⚡🔥🌀♻️⚜️🔱🎴🀄♠️♣️♥️♦️♟️🧲⚙️🔗📎📌📍✂️🖊️🖋️🖌️🖍️📝🔍🔎🔏🔐🔒🔓🔔🔕📣📢💬💭🗯️♠♣♥♦♨♩♪♫♬♭♯♀♂☿♁♃♄♅♆♇♈♉♊♋♌♍♎♏♐♑♒♓☀️☁️☂️☃️☄️☎️📞📟🔋🔌💻💽💾💿📀🎥🎞️📽️🎬📺📷📸📹📼🕯️💡🔦🏮🧱🕳️💣🛀🛌🛍️🛒🎁🎈🎏🎀🪄🪅🪩🧿🔮🪬🗿";
     $lines[35][] = array_column(array_chunk(str_split(substr($s,0,52)),2),0)[0];
     $lines[36][] = array_column(array_chunk(str_split(substr($s,0,62)),2),0)[0];
     $lines[37][] = array_column(array_chunk(str_split(substr($s,0,64)),2),0)[0];
     $lines[38][] = array_column(array_chunk(mb_str_split(mb_substr($e,0,128,"UTF-8"),1),1),0)[0];
     preg_match_all('/.{1,3}(?=(.{3})*$)/', $data, $m);
     $c = array_chunk(explode(",",str_replace(":",",",implode(",",$lines))),38,false);
     if ($count) return array_column($c, $base-2);
     foreach ($m[0] as $n) $r[] = $c[$base-2][bindec($n)];
     return implode("", $r??[]);
 }
/**
 // ==============================================================
 // 📦 array_column_all — ดึงทุกคอลัมน์ ใช้ลูปภายใน C ไม่ใช่ลูป PHP ยาว
 // ==============================================================
 /**
 function array_column_all(array $arrays): array {
     if (empty($arrays)) return [];
     $columnCount = 0;
     foreach ($arrays as $row) {
         $columnCount = max($columnCount, is_array($row) ? count($row) : 0);
     }
     $output = [];
     for ($i = 0; $i < $columnCount; $i++) {
         $column = [];
         foreach ($arrays as $row) {
             $column[] = is_array($row) ? ($row[$i] ?? null) : null;
         }
         $output[] = $column;
     }
     return $output;
 }
 **/
 // ==============================================================
 // 📦 dump_debug — ย่อ-ขยายมิติ: flat=แบน, line=บรรทัด, nested=โครงสร้าง
 // ==============================================================
 function dump_debug($input, $mode = "nested", $chunkSize = null) {
     switch ($mode) {
         case "flat":
             $result = [];
             array_walk_recursive($input, fn($v) => $result[] = $v);
             return $result;
         case "line":
             if (is_string($input) && file_exists($input)) {
                 return file($input, FILE_IGNORE_NEW_LINES);
             }
             return implode("\n", array_map(fn($r) => is_array($r) ? implode("\t", $r) : $r, (array)$input));
         case "nested":
         default:
             return $chunkSize !== null ? array_chunk((array)$input, $chunkSize) : (array)$input;
     }
 }
 // ==============================================================
 // 📦 pretty_array — จัดรูปแบบแสดงผล
 // ==============================================================
 function pretty_array($array, $level = 0) {
     $indent = str_repeat("  ", $level);
     $out = "[\n";
     foreach ($array as $key => $value) {
         $out .= $indent . "  " . var_export($key, true) . " => ";
         $out .= is_array($value) ? pretty_array($value, $level + 1) : var_export($value, true);
         $out .= ",\n";
     }
     return $out . $indent . "]";
 }
 // ==============================================================
 // 📦 array_blob — แปลงตรงๆ อาร์เรย์↔สตริง
 // ==============================================================
 function array_blob($data, int $bytesPerItem = 1, string $encoding = 'UTF-8') {
     if (is_array($data)) {
         $result = '';
         foreach ($data as $item) {
             if (is_int($item)) $result .= chr($item & 0xFF);
             elseif (is_string($item)) $result .= $item;
             else $result .= (string)$item;
         }
         return $result;
     }
     if (is_string($data)) {
         $result = [];
         $len = $encoding === 'UTF-8' ? mb_strlen($data, 'UTF-8') : strlen($data);
         if ($encoding === 'UTF-8') {
             for ($i = 0; $i < $len; $i++) {
                 $result[] = mb_substr($data, $i, 1, 'UTF-8');
             }
         } else {
             for ($i = 0; $i < $len; $i += $bytesPerItem) {
                 $result[] = substr($data, $i, $bytesPerItem);
             }
         }
         return $result;
     }
     return (string)$data;
 }
 // ==============================================================
 // 🎯 stream_matrix — เขียน→อ่าน→แสดงผล ครบในฟังก์ชันเดียว
 // ==============================================================
 function stream_matrix(array $matrix): void {
     $sessionId = $GLOBALS["sessionId"];
     $streamUri = "php://temp/maxmemory:" . TEMP_MAX_MEMORY . "/$sessionId";
     $fh = fopen($streamUri, 'w');
     if (!$fh) { echo "ไม่สามารถเปิดสตรีมเขียนได้\n"; return; }
     foreach ($matrix as $row) {
         $line = is_array($row) ? implode("\t", $row) : (string)$row;
         fwrite($fh, $line . "\n");
     }
     fclose($fh);
     $fh = fopen($streamUri, 'r');
     if (!$fh) { echo "ไม่สามารถเปิดสตรีมอ่านได้\n"; return; }
     $resultMatrix = [];
     while (($line = fgets($fh)) !== false) {
         $line = rtrim($line, "\n\r");
         if ($line === '') continue;
         $row = explode("\t", $line);
         $resultMatrix[] = count($row) === 1 ? $row[0] : $row;
     }
     fclose($fh);
     echo pretty_array($resultMatrix);
 }
function array_chuck($array,$num,$name){
    $return[$name]= array_chunk($array,$num,true);
    return $return;
}

 function matrix_chunk($input, string $mode = "nested", string $sessionId = "", int $batchSize = 1048576)
 {
     if ($input === null) return;
     if (is_array($input)) {
         if (empty($input)) return [];
         createGrid($input, $mode, $sessionId);
         return;
     }
     if ($input === '') return [];
     // ✅ ตัดแบทช์: 65536 × 16 = 1,048,576 ตัวต่อก้อน
     $batches = [];
     $length = mb_strlen($input, 'UTF-8');
     for ($offset = 0; $offset < $length; $offset += $batchSize) {
         $batchStr = mb_substr($input, $offset, $batchSize, 'UTF-8');
         $batches[] = processSingleBatch($batchStr, $mode);
     }
     // ✅ รวมผลลัพธ์
     $result = (count($batches) === 1) ? reset($batches) : $batches;
     stream_matrix($result);
     return $result;
 }
 /**
  * ประมวลผลแบทช์เดี่ยว
  */
 function processSingleBatch(string $batchStr, string $mode)
 {
     $array = mb_str_split($batchStr, 1, 'UTF-8');
     $total = count($array);
     $keys = [];
     for ($i = 0; $i < $total; $i++) {
         $keys[] = decbin($i);
     }
     $current = array_combine($keys, $array);
     $levelSizes = [4, 16, 256, 65536];
     $levelIndex = 0;
     while (count($current) > 1 && $levelIndex < count($levelSizes)) {
         $size = $levelSizes[$levelIndex];
         $chunks = array_chunk($current, $size, true);
         $groupKeys = [];
         foreach ($chunks as $gi => $_) {
             $groupKeys[] = decbin($gi);
         }
         $withKeys = array_combine($groupKeys, $chunks);
         $current = array_column_all($withKeys);
         $current = dump_debug($current, $mode);
         $levelIndex++;
     }
     // 🛡️ ป้องกันลูปไม่จบ — สูงสุด 100 รอบ
     $maxLoop = 100;
     $loopCount = 0;
     while (count($current) > 1 && $loopCount < $maxLoop) {
         $loopCount++;
         $size = 4;
         $chunks = array_chunk($current, $size, true);
         $groupKeys = [];
         foreach ($chunks as $gi => $_) {
             $groupKeys[] = decbin($gi);
         }
         $withKeys = array_combine($groupKeys, $chunks);
         $current = array_column_all($withKeys);
         $current = dump_debug($current, $mode);
     }
     if ($loopCount >= $maxLoop) {
         error_log("processSingleBatch: หยุดลูปเกิน $maxLoop รอบ — ข้อมูลอาจไม่สมบูรณ์");
     }
     return $current;
 }
 // ==============================================================
 // 🎯 createGrid — อาร์เรย์→เมทริกซ์ แบ่งทวีคูณ 4ⁿ O(log₄ N)
 // ==============================================================
 function createGrid($input, string $mode = "nested", string $sessionId = "") {
     if ($input === null) return;
     if (is_string($input)) {
         if ($input === '') return [];
         matrix_chunk($input, $mode, $sessionId);
         return;
     }
     if (!is_array($input) || empty($input)) return [];
     $total = count($input);
     $keys = [];
     for ($i = 0; $i < $total; $i++) {
         $keys[] = decbin($i);
     }
     $current = array_combine($keys, $input);
     $levelSizes = [4, 16, 256, 65536];
     $levelIndex = 0;
     while (count($current) > 1 && $levelIndex < count($levelSizes)) {
         $size = $levelSizes[$levelIndex];
         $chunks = array_chunk($current, $size, true);
         $groupKeys = [];
         foreach ($chunks as $gi => $_) {
             $groupKeys[] = decbin($gi);
         }
         $withKeys = array_combine($groupKeys, $chunks);
         $current = array_column_all($withKeys);
         $current = dump_debug($current, $mode);
         $levelIndex++;
     }
     while (count($current) > 1) {
         $size = 4;
         $chunks = array_chunk($current, $size, true);
         $groupKeys = [];
         foreach ($chunks as $gi => $_) {
             $groupKeys[] = decbin($gi);
         }
         $withKeys = array_combine($groupKeys, $chunks);
         $current = array_column_all($withKeys);
         $current = dump_debug($current, $mode);
     }
     stream_matrix($current);
 }



function mem2file($filename,$string){
    $matrix2D=[];
$fiveMBs = 5 * 1024 * 1024;
$file = fopen('php://temp/maxmemory:$fiveMBs', 	'rw'); 
while(feof($file)!==true) {
fputs($file, implode("\n",$string));
  echo " ";
 array_push($matrix2D,fgets($file));
  flush();
	}
file_put_contents("php://filter/write=string.rot13/resource=$filename", dump_debug($matrix2D,"flat"));
	fclose($file);
}

function route_after_loop(string $tableOrFile, array $matrixChunk, $nextAction) {
    global $storage;
    $m2D = [];
    $colCount = 5;
    for($j=0;$j<=count($matrixChunk);$j++){
    for($i=0;$i<=$colCount;$i++){
    $m2D = array_chunk(array_chunk($matrixChunk,20,true),$colCount,true);
    mem2file($tableOrFile,dump_debug($m2D,"flat"));
    srand((double)microtime()*1000000);
$matrix2D=[];
$file = new SplFileObject("php://filter/read=string.toupper|string.rot13/resource=$tableOrFile","r");
while (!$file->eof()) {
    array_push($matrix2D,explode(",",$file->fgets()));
    $i++;$j++;
        }
    }
}
    $perfectMatrix = array_column_all($matrix2D);
    if (is_callable($nextAction)) {
        $nextAction($perfectMatrix);
    }
     //$compiledString = implode("\n", $matrixChunk);
     //$storage->store($tableOrFile, $compiledString, 'tar');
    unset($matrixChunk, $matrix2D, $perfectMatrix);
}

/**
 * ⚡ save_bulk_stream — กางอาเรย์ยกรังเขียนฐานข้อมูลรัวๆ รอบเดียว ไม่วนอ่านทีละไลน์
 * [🔒 โหมดอิสระใน function.php รองรับแบทช์รายวันอนันต์ หมุนวนสเกลตามพื้นที่ดิสก์ ]
 */
function save_bulk_stream(string $table, array $perfectMatrix, string $uniqueKey = 'c0', ?PDO $pdo = null): bool
{
    global $db_instance;
    $db = $pdo ?? $db_instance;
    if (!$db || empty($perfectMatrix)) return false;

    // 🚀 ปรับโหมดความเร็วแสงสูงสุด (WAL Mode) ให้พอร์ตเชื่อมต่อทันที
    $db->exec("PRAGMA synchronous = OFF;");
    $db->exec("PRAGMA journal_mode = WAL;");

    // แกะจำนวนมิติตัวแปรคอลัมน์จากแถวแรกของ Perfect Matrix
    $firstRow = reset($perfectMatrix);
    $n = is_array($firstRow) ? count($firstRow) : 1;
    
    // ตั้งชื่อคอลัมน์อัตโนมัติ c0, c1, c2... ให้ตรงตามพิกัดบิต O(1) ของคุณ
    $colArr = range(0, $n - 1);
    array_walk($colArr, fn(&$v, $i) => $v = "c$i");
    $colList = '`' . implode('`, `', $colArr) . '`';
    
    $allValues = [];
    $placeholderRows = [];
    
    // กางอาเรย์ยกรังสะสมลง Flat Array บนแรมชั้นสองความเร็วแสง
    foreach ($perfectMatrix as $row) {
        if ($row === null) break; // 🛡️ เซฟตี้สูงสุด: พบ Null ตัดท่อและเบรกการทำงานทันที
        
        $vals = is_array($row) ? array_values($row) : [$row];
        if (count($vals) !== $n) continue; 
        
        $placeholderRows[] = '(' . implode(', ', array_fill(0, $n, '?')) . ')';
        foreach ($vals as $v) {
            $allValues[] = $v;
        }
    }

    if (empty($allValues)) return false;

    // ฝังกลไก Upsert (ON CONFLICT DO UPDATE) เพื่ออัปเดตและสลับก้อนข้อมูลเดิมอัตโนมัติ
    $updateFields = [];
    foreach ($colArr as $col) {
        if ($col !== $uniqueKey) {
            $updateFields[] = "`{$col}` = excluded.`{$col}`";
        }
    }
    $upsertClause = !empty($updateFields) ? " ON CONFLICT(`{$uniqueKey}`) DO UPDATE SET " . implode(', ', $updateFields) : "";

    // 💾 ดันก้อนสตรีมยิงรวดเดียวจบ ไม่เกิดเศษขยะค้างคา
    try {
        $db->beginTransaction();
        
        $sql = "INSERT INTO `{$table}` ({$colList}) VALUES " . implode(', ', $placeholderRows) . $upsertClause;
        $stmt = $db->prepare($sql);
        $stmt->execute($allValues);
        
        $db->commit();
        
        // 🔥 ทำลายเศษขยะแรมของแบทช์นี้ทิ้งทันทีเป็นศูนย์ ป้องกัน Memory Leak 100%
        unset($allValues, $placeholderRows, $sql, $updateFields);
        return true;
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return false;
    }
}

function cache_folder($folder){
    if (is_dir($folder)) {
        $old_umask = umask(0);
        chmod($folder, 0777);
        umask($old_umask);
        return true;
    } else {
        $dir = explode("/",$folder);
        if(is_array($dir)){
            $i=0;
            $build_folder = "";
            while($i < count($dir)){
                // ✅ แก้เงื่อนไขที่ผิด — ไม่ใช่ > 0 แต่เช็คว่าไม่ว่าง
                if(trim($dir[$i]) !== ""){
                    $build_folder.= "/".$dir[$i];
                    $old_umask = umask(0); 
                    if(!is_dir($build_folder)){
                        mkdir($build_folder, 0777);
                    }
                    umask($old_umask);
                }
                $i++;
            }
            return is_dir($folder);
        }else{
            $old_umask = umask(0); 
            if(!is_dir($folder)){
                mkdir($folder, 0777);
            }
            umask($old_umask);
            return is_dir($folder);
        }
    }
}
