<?php
// =========================================================================
// class/run.php - Telemetry Core, Filter Streams & Safe Include Template Engine
// แทนที่ eval ด้วย getIncludeContents และรองรับ 100,000+ รอบ
// =========================================================================

class run {
    private static $debug = false;
    private static $logStream = null;
    private static $isCleared = false; 
    private static $cachedTable = null;
    private static $cachedLayer = null;

    public static function initLog($debug = false, $logFilePath = null) {
        self::$debug = $debug;
        if (self::$debug && $logFilePath && !self::$isCleared) {
            self::$logStream = @fopen($logFilePath, 'w');
            self::$isCleared = true;
            if (self::$logStream) {
                @stream_set_blocking(self::$logStream, false);
                @fwrite(self::$logStream, "=== [SESSION TRACE] === " . date('Y-m-d H:i:s') . PHP_EOL);
            }
        }
    }

    // แทนที่ eval() ด้วยระบบ include ภายใต้ Output Buffering (ปลอดภัยและรวดเร็วกว่า)
    public static function getIncludeContents($filename) {
        if (is_file($filename)) {
            ob_start();
            include $filename;
            $contents = ob_get_contents();
            ob_end_clean();
            return $contents;
        }
        return false;
    }

    // ตัวจัดการสตรีมฟิลเตอร์สำหรับประมวลผลข้อมูลระดับสูง
    public static function filterstream($string, $targetFile = 'test.png') {
        $output = '';
        if (file_exists($targetFile)) {
            @file_put_contents("php://filter/write=string.rot13/resource=" . $targetFile, $string);
            $file = new SplFileObject("php://filter/read=string.toupper|string.rot13/resource=" . $targetFile);
            while (!$file->eof()) {
                $output .= $file->fgets();
            }
        }
        return $output;
    }

    public static function init($responseResult, callable $callback = null) {
        if ($responseResult === null) return null;

        if ($callback === null) {
            $callback = function($item, $key) {
                if ($item === null) return null;
                if (is_array($item) && isset($item['success']) && $item['success'] === true && isset($item['content'])) {
                    $item['raw_content'] = @base64_decode($item['content']);
                    return $item;
                }
                return $item;
            };
        }

        $items = is_array($responseResult) ? $responseResult : [$responseResult];
        $processedResults = [];

        foreach ($items as $key => $item) {
            if ($item === null) break;
            try {
                $callbackReturn = $callback($item, $key);
                if ($callbackReturn === null) break;
                $processedResults[$key] = $callbackReturn;
            } catch (Throwable $e) {
                $processedResults[$key] = $item;
            }
        }
        return is_array($responseResult) ? $processedResults : ($processedResults[0] ?? null);
    }

    public static function layer_init() {
        if (self::$cachedLayer !== null) return self::$cachedLayer;
        return self::$cachedLayer = range(0, 255);
    }

    public static function map_table($x = "", $y = "") {
        if (is_array($x) || is_object($x) || is_array($y) || is_object($y)) return null;
        if ($x === "dec") $x = 0;
        elseif ($x === "hex") $x = 1;
        elseif ($x === "bin") $x = 2;

        if ($x !== "" && $y !== "") {
            $y = (int)$y;
            if ($y < 0 || $y > 255) return null;
            if ($x === 0) return (string)$y;
            if ($x === 1) return sprintf('%02x', $y);
            if ($x === 2) return sprintf('%08b', $y);
            return null;
        }
        if (self::$cachedTable !== null && $x === "" && $y === "") return self::$cachedTable;

        $source = [];
        for ($i = 0; $i <= 255; $i++) {
            $source[] = [(string)$i, sprintf('%02x', $i), sprintf('%08b', $i)];
        }
        return self::$cachedTable = $source;
    }

    public static function map_key($i, $j, $k, $l, $m, $type = "hex") {
        $h_i = self::map_table($type, $i);
        $h_j = self::map_table($type, $j);
        $h_k = self::map_table($type, $k);
        $h_l = self::map_table($type, $l);
        $h_m = self::map_table($type, $m);
        return "\${$h_i}{$h_j}{$h_k}{$h_l}[{$h_m}]";
    }

    // 5-Layer Foreach Engine Loop รองรับแสนรอบ + ตรรกะดักจับ null จบการทำงานทันที
    public static function execute($data_array, $ip_start = '0.0.0.0', $functionName = 'GLOBAL', callable $callback = null) {
        if (is_callable($ip_start)) {
            $callback = $ip_start;
            $ip_start = '0.0.0.0';
        } elseif (is_callable($functionName)) {
            $callback = $functionName;
            $functionName = 'GLOBAL';
        }

        if (empty($ip_start) || !is_string($ip_start) || strpos($ip_start, '.') === false) {
            $ip_start = '0.0.0.0';
        }

        if ($callback === null) {
            $callback = function($virtualKey, $volume) {};
        }

        $range_256 = self::layer_init();
        $chars = mb_str_split($data_array['str'] ?? '', 1, "utf-8");
        $total_chars = count($chars);
        if ($total_chars === 0) return;

        $char_index = 0;
        $ip_parts = array_pad(array_map('intval', explode(".", $ip_start)), 4, 0);
        [$o0, $o1, $o2, $o3] = $ip_parts;

        // 5-Layer Foreach structure
        foreach ($range_256 as $i) {
            if ($i < $o0) continue;
            foreach ($range_256 as $j) {
                if ($i === $o0 && $j < $o1) continue;
                foreach ($range_256 as $k) {
                    if ($i === $o0 && $j === $o1 && $k < $o2) continue;
                    foreach ($range_256 as $l) {
                        if ($i === $o0 && $j === $o1 && $k === $o2 && $l < $o3) continue;
                        foreach ($range_256 as $m) {
                            
                            if ($char_index >= $total_chars) break 5;
                            
                            $volume = $chars[$char_index++];
                            if ($volume === null) break 5;

                            $virtualKey = self::map_key($i, $j, $k, $l, $m, "hex");

                            try {
                                $res = $callback($virtualKey, $volume);
                                if ($res === null) break 5;
                            } catch (Throwable $e) {
                                break 5;
                            }

                        }
                    }
                }
            }
        }
    }

    public static function close() {
        if (self::$logStream) {
            @fclose(self::$logStream);
            self::$logStream = null;
        }
    }
}
