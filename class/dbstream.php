<?php

class DBStream {
    private $_pdo;
    private $_ps;
    private $_rowId = 0;
    private $_streamKey;
    private $_tableName = 'data'; // ค่าเริ่มต้นของตาราง

    function stream_open($path, $mode, $options, &$opath)
    {
        $url = parse_url($path);
        $this->_streamKey = ($url['host'] ?? '') . ($url['path'] ?? '') . ($url['query'] ?? ''); 
        
        // จัดการ Path สำหรับ SQLite (ส่วนแรกคือชื่อไฟล์ฐานข้อมูล)
        $pathParts = explode('/', trim($url['path'] ?? '', '/'));
        $dbFile = $pathParts[0] ?? 'database.db';
        
        // เติมสกุล .db ให้โดยอัตโนมัติหากไม่ได้ระบุ
        if (!str_ends_with($dbFile, '.db') && !str_ends_with($dbFile, '.sqlite')) {
            $dbFile .= '.db';
        }
        
        // ถ้ามีการระบุซับโฟลเดอร์หรือชื่อตารางต่อท้าย นำมาใช้เป็นชื่อตาราง
        if (isset($pathParts[1]) && !empty($pathParts[1])) {
            $this->_tableName = preg_replace('/[^a-zA-Z0-9_]/', '_', implode('_', array_slice($pathParts, 1)));
        }

        // รองรับการส่งชื่อตารางผ่าน Query String เช่น ?table=custom_table
        if (isset($url['query'])) {
            parse_str($url['query'], $queryArr);
            if (!empty($queryArr['table'])) {
                $this->_tableName = preg_replace('/[^a-zA-Z0-9_]/', '_', $queryArr['table']);
            }
        }

        try {
            // เชื่อมต่อฐานข้อมูล SQLite ผ่าน PDO
            $this->_pdo = new PDO("sqlite:{$dbFile}", null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            
            // กรองชื่อตารางให้ปลอดภัย
            $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $this->_tableName);
            
            // 📌 1. สร้างตารางตามชื่อที่ระบุใน SQLite
            $this->_pdo->exec("
                CREATE TABLE IF NOT EXISTS \"{$safeTable}\" (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    data TEXT,
                    created_at DATETIME
                );
            ");

            // 📌 2. สร้างตารางเก็บสถานะการอ่าน
            $this->_pdo->exec("
                CREATE TABLE IF NOT EXISTS stream_states (
                    stream_key TEXT PRIMARY KEY,
                    last_read_id INTEGER NOT NULL DEFAULT 0
                );
            ");

        } catch(PDOException $e) { 
            return false; 
        }

        $cleanMode = substr($mode, 0, 1);
        switch ($cleanMode){
            case 'w' : 
            case 'a' :
                // ใช้ datetime('now') แทน NOW() ของ MySQL
                $this->_ps = $this->_pdo->prepare("INSERT INTO \"{$safeTable}\" (data, created_at) VALUES (?, datetime('now'))");
                break;
                
            case 'r' : 
                $stmt = $this->_pdo->prepare('SELECT last_read_id FROM stream_states WHERE stream_key = ?');
                $stmt->execute([$this->_streamKey]);
                $savedRow = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $this->_rowId = $savedRow ? (int)$savedRow['last_read_id'] : 0;

                $this->_ps = $this->_pdo->prepare("SELECT id, data FROM \"{$safeTable}\" WHERE id > ? LIMIT 1");
                break;
            default  : 
                return false;
        }
        return true;
    }

    function stream_read($count)
    {
         $this->_ps->execute([$this->_rowId]);
         if($this->_ps->rowCount() == 0) return false;
         
         $this->_ps->bindColumn(1, $this->_rowId);
         $this->_ps->bindColumn(2, $ret);
         $this->_ps->fetch();

         // ใช้ ON CONFLICT แทน ON DUPLICATE KEY UPDATE สำหรับ SQLite
         $stmt = $this->_pdo->prepare('
             INSERT INTO stream_states (stream_key, last_read_id) 
             VALUES (?, ?) 
             ON CONFLICT(stream_key) DO UPDATE SET last_read_id = ?
         ');
         $stmt->execute([$this->_streamKey, $this->_rowId, $this->_rowId]);

         return $ret;
    }

    function stream_write($data) { 
        $this->_ps->execute([$data]); 
        return strlen($data); 
    }

    function stream_tell() { 
        return $this->_rowId; 
    }

    function stream_eof() { 
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $this->_tableName);
        $stmt = $this->_pdo->prepare("SELECT COUNT(*) FROM \"{$safeTable}\" WHERE id > ?");
        $stmt->execute([$this->_rowId]);
        return $stmt->fetchColumn() == 0; 
    }

    function stream_seek($offset, $step) {
        return false;
    }

    public static function register($protocol = 'db') {
        if (!in_array($protocol, stream_get_wrappers())) {
            return stream_register_wrapper($protocol, __CLASS__);
        }
        return true;
    }
}

// ลงทะเบียน Wrapper
DBStream::register('db');

// 🚀 ตัวอย่างการใช้งาน SQLite Stream Wrapper
// รูปแบบที่ 1: db://database_filename/sub_folder/table_name
// $fp = fopen('db://my_shop.db/products/log_data', 'w');

// รูปแบบที่ 2: ใช้ Query String ระบุชื่อตาราง
// $fp = fopen('db://my_database.db?table=custom_logs', 'r');
