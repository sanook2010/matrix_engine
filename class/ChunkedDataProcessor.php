<?php
set_time_limit(0);
ini_set('memory_limit', '-1');

class ChunkedDataProcessor {
    private $data;
    private $chunkSize;

    public function __construct($data = null, $chunkSize = 256) {
        $this->data = $data;
        $this->chunkSize = $chunkSize; 
    }

    public function prepareDataArray($inputData = null) {
        $targetData = $inputData !== null ? $inputData : $this->data;
        if (is_array($targetData)) { return $targetData; } 
        elseif (is_string($targetData)) { return explode("\n", str_replace(["\r\n", "\r"], "\n", $targetData)); }
        return (array)$targetData;
    }

    public function loopLayer($dataInput = null) {
        $strArray = $this->prepareDataArray($dataInput);
        $totalCount = count($strArray);
        
        // เลือกโหมด 4:1 เมื่อจำนวนงานอยู่ในระดับหลักแสนรอบขึ้นไป (>= 100,000)
        $mode = $totalCount >= 100000 ? '4:1' : '1:1';
        $template = template_for_count($totalCount, $mode);

        $preparedItems = [];
        $processedCount = 0;

        if ($mode === '4:1' && isset($template['chunk_map'])) {
            $chunkId = 0;
            foreach (array_chunk($strArray, 4) as $chunkGroup) {
                if ($processedCount >= $totalCount) break;
                $mappedTemplate = call_user_func($template['chunk_map'], $chunkId);
                foreach ($chunkGroup as $idx => $item) {
                    if ($item === null) {
                        continue; // พบ null แล้วข้าม
                    }
                    if ($processedCount >= $totalCount) {
                        break; // หยุดเมื่อครบจำนวน
                    }
                    $preparedItems[] = [
                        'data' => $item,
                        'bin'  => $mappedTemplate['bin'][$idx] ?? '0',
                        'vol'  => $mappedTemplate['vol'][$idx] ?? 255
                    ];
                    $processedCount++;
                }
                $chunkId++;
            }
        } else {
            foreach ($strArray as $index => $item) {
                if ($item === null) {
                    continue; // พบ null แล้วข้าม
                }
                if ($processedCount >= $totalCount) {
                    break; // หยุดเมื่อครบจำนวน
                }
                $preparedItems[] = [
                    'data' => $item,
                    'bin'  => $template['index_to_bin'][$index] ?? '0',
                    'vol'  => $template['index_to_vol'][$index] ?? 255
                ];
                $processedCount++;
            }
        }

        $chunks = [];
        // looplayer ยังคงใช้ foreach 5 ขั้น + อาเรย์แบน
        foreach ($preparedItems as $prep) {
            $r = $prep['data'];
            foreach (str_split($r, $this->chunkSize) as $s => $t) {
                if ($t === null) continue;
                foreach (str_split($t, $this->chunkSize) as $x => $y) {
                    if ($y === null) continue;
                    foreach (str_split($y, $this->chunkSize) as $k => $v) {
                        if ($v === null) continue;
                        foreach (str_split($v, 1) as $p => $q) {
                            if ($q === null) continue;
                            $chunks[] = $q;
                        }
                    }
                }
            }
        }
        return $chunks;
    }

    public function routeAfterLoop($dataInput, callable $callback) {
        $processedChunks = $this->loopLayer($dataInput);
        return call_user_func($callback, $processedChunks);
    }

  public function template_for_count(int $count, string $mode = '1:1'): array {
    $data = [
        "0" => 0, "1" => 1, "10" => 2, "11" => 3,
        "100" => 4, "101" => 5, "110" => 6, "111" => 7,
        "1000" => 8, "1001" => 9, "1010" => 10, "1011" => 11,
        "1100" => 12, "1101" => 13, "1110" => 14, "1111" => 15,
        "10000" => 16, "10001" => 17, "10010" => 18, "10011" => 19,
        "10100" => 20, "10101" => 21, "10110" => 22, "10111" => 23,
        "11000" => 24, "11001" => 25, "11010" => 26, "11011" => 27,
        "11100" => 28, "11101" => 29, "11110" => 30, "11111" => 31,
        "100000" => 32, "100001" => 33, "100010" => 34, "100011" => 35,
        "100100" => 36, "100101" => 37, "100110" => 38, "100111" => 39,
        "101000" => 40, "101001" => 41, "101010" => 42, "101011" => 43,
        "101100" => 44, "101101" => 45, "101110" => 46, "101111" => 47,
        "110000" => 48, "110001" => 49, "110010" => 50, "110011" => 51,
        "110100" => 52, "110101" => 53, "110110" => 54, "110111" => 55,
        "111000" => 56, "111001" => 57, "111010" => 58, "111011" => 59,
        "111100" => 60, "111101" => 61, "111110" => 62, "111111" => 63,
        "1000000" => 64, "1000001" => 65, "1000010" => 66, "1000011" => 67,
        "1000100" => 68, "1000101" => 69, "1000110" => 70, "1000111" => 71,
        "1001000" => 72, "1001001" => 73, "1001010" => 74, "1001011" => 75,
        "1001100" => 76, "1001101" => 77, "1001110" => 78, "1001111" => 79,
        "1010000" => 80, "1010001" => 81, "1010010" => 82, "1010011" => 83,
        "1010100" => 84, "1010101" => 85, "1010110" => 86, "1010111" => 87,
        "1011000" => 88, "1011001" => 89, "1011010" => 90, "1011011" => 91,
        "1011100" => 92, "1011101" => 93, "1011110" => 94, "1011111" => 95,
        "1100000" => 96, "1100001" => 97, "1100010" => 98, "1100011" => 99,
        "1100100" => 100, "1100101" => 101, "1100110" => 102, "1100111" => 103,
        "1101000" => 104, "1101001" => 105, "1101010" => 106, "1101011" => 107,
        "1101100" => 108, "1101101" => 109, "1101110" => 110, "1101111" => 111,
        "1110000" => 112, "1110001" => 113, "1110010" => 114, "1110011" => 115,
        "1110100" => 116, "1110101" => 117, "1110110" => 118, "1110111" => 119,
        "1111000" => 120, "1111001" => 121, "1111010" => 122, "1111011" => 123,
        "1111100" => 124, "1111101" => 125, "1111110" => 126, "1111111" => 127,
        "10000000" => 128, "10000001" => 129, "10000010" => 130, "10000011" => 131,
        "10000100" => 132, "10000101" => 133, "10000110" => 134, "10000111" => 135,
        "10001000" => 136, "10001001" => 137, "10001010" => 138, "10001011" => 139,
        "10001100" => 140, "10001101" => 141, "10001110" => 142, "10001111" => 143,
        "10010000" => 144, "10010001" => 145, "10010010" => 146, "10010011" => 147,
        "10010100" => 148, "10010101" => 149, "10010110" => 150, "10010111" => 151,
        "10011000" => 152, "10011001" => 153, "10011010" => 154, "10011011" => 155,
        "10011100" => 156, "10011101" => 157, "10011110" => 158, "10011111" => 159,
        "10100000" => 160, "10100001" => 161, "10100010" => 162, "10100011" => 163,
        "10100100" => 164, "10100101" => 165, "10100110" => 166, "10100111" => 167,
        "10101000" => 168, "10101001" => 169, "10101010" => 170, "10101011" => 171,
        "10101100" => 172, "10101101" => 173, "10101110" => 174, "10101111" => 175,
        "10110000" => 176, "10110001" => 177, "10110010" => 178, "10110011" => 179,
        "10110100" => 180, "10110101" => 181, "10110110" => 182, "10110111" => 183,
        "10111000" => 184, "10111001" => 185, "10111010" => 186, "10111011" => 187,
        "10111100" => 188, "10111101" => 189, "10111110" => 190, "10111111" => 191,
        "11000000" => 192, "11000001" => 193, "11000010" => 194, "11000011" => 195,
        "11000100" => 196, "11000101" => 197, "11000110" => 198, "11000111" => 199,
        "11001000" => 200, "11001001" => 201, "11001010" => 202, "11001011" => 203,
        "11001100" => 204, "11001101" => 205, "11001110" => 206, "11001111" => 207,
        "11010000" => 208, "11010001" => 209, "11010010" => 210, "11010011" => 211,
        "11010100" => 212, "11010101" => 213, "11010110" => 214, "11010111" => 215,
        "11011000" => 216, "11011001" => 217, "11011010" => 218, "11011011" => 219,
        "11011100" => 220, "11011101" => 221, "11011110" => 222, "11011111" => 223,
        "11100000" => 224, "11100001" => 225, "11100010" => 226, "11100011" => 227,
        "11100100" => 228, "11100101" => 229, "11100110" => 230, "11100111" => 231,
        "11101000" => 232, "11101001" => 233, "11101010" => 234, "11101011" => 235,
        "11101100" => 236, "11101101" => 237, "11101110" => 238, "11101111" => 239,
        "11110000" => 240, "11110001" => 241, "11110010" => 242, "11110011" => 243
    ];
    $base = [
        'size' => $count <= 4 ? 4 : ($count <= 16 ? 16 : ($count <= 256 ? 256 : 65536)),
        'template' => $data
    ];
    if ($mode === '4:1') {
        $base['mode'] = '4:1';
        $base['chunk_map'] = function(int $chunkId) use ($base): array {
            $s = $chunkId * 4;
            return [
                'bin' => [$s, $s+1, $s+2, $s+3],
                'vol' => [255, 128, 64, 32]
            ];
        };
    } else {
        $base['mode'] = '1:1';
    }
    return $base;
}

    public function mapKeysWithData(array $keys, $customData = null) {
        $dataArray = $this->prepareDataArray($customData);
        $totalCount = count($dataArray);
        $mode = $totalCount >= 100000 ? '4:1' : '1:1';
        $template = template_for_count($totalCount, $mode);

        $mappedResult = [];
        $keyCount = count($keys);
        $processedCount = 0;

        if ($mode === '4:1' && isset($template['chunk_map'])) {
            $chunkId = 0;
            foreach (array_chunk($dataArray, 4) as $chunkGroup) {
                if ($processedCount >= $totalCount) break;
                $mappedTemplate = call_user_func($template['chunk_map'], $chunkId);
                foreach ($chunkGroup as $idx => $line) {
                    if ($line === null) {
                        continue; // พบ null แล้วข้าม
                    }
                    if ($processedCount >= $totalCount) {
                        break; // หยุดเมื่อครบจำนวน
                    }
                    $activeKey = $keys[$processedCount % $keyCount];
                    $mappedResult[$activeKey][] = [
                        'data' => $line,
                        'bin'  => $mappedTemplate['bin'][$idx] ?? '0',
                        'vol'  => $mappedTemplate['vol'][$idx] ?? 255
                    ];
                    $processedCount++;
                }
                $chunkId++;
            }
        } else {
            foreach ($dataArray as $index => $line) {
                if ($line === null) {
                    continue; // พบ null แล้วข้าม
                }
                if ($processedCount >= $totalCount) {
                    break; // หยุดเมื่อครบจำนวน
                }
                $activeKey = $keys[$index % $keyCount];
                $mappedResult[$activeKey][] = [
                    'data' => $line,
                    'bin'  => $template['index_to_bin'][$index] ?? '0',
                    'vol'  => $template['index_to_vol'][$index] ?? 255
                ];
                $processedCount++;
            }
        }
        return $mappedResult;
    }

    public function processFileChunks($sourcePath, $destinationPath) {
        $handleIn = fopen($sourcePath, 'rb');
        if ($handleIn === false) throw new Exception("ไม่สามารถเปิดไฟล์ต้นทางได้");
        $handleOut = fopen($destinationPath, 'w+b');
        if ($handleOut === false) { fclose($handleIn); throw new Exception("ไม่สามารถสร้างไฟล์ปลายทางได้"); }
        while (!feof($handleIn)) {
            $chunk = fread($handleIn, $this->chunkSize);
            if ($chunk === null) {
                continue; // พบ null แล้วข้าม
            }
            $layeredChunks = $this->loopLayer($chunk);
            $processedData = implode('', $layeredChunks);
            fwrite($handleOut, $processedData);
        }
        fclose($handleIn); fclose($handleOut); return true;
    }

    public function jsonRowForApi($dataRows, $isStream = true) {
        $rows = $this->prepareDataArray($dataRows);
        $totalCount = count($rows);
        $mode = $totalCount >= 100000 ? '4:1' : '1:1';
        $template = template_for_count($totalCount, $mode);

        if ($isStream) {
            while (ob_get_level()) { ob_end_clean(); }
            header('Content-Type: application/x-json-stream; charset=utf-8');
            header('Cache-Control: no-cache');
            $processedCount = 0;
            foreach ($rows as $row) {
                if ($row === null) {
                    continue; // พบ null แล้วข้าม
                }
                if ($processedCount >= $totalCount) {
                    break; // หยุดเมื่อครบจำนวน
                }
                if (!empty(trim($row))) {
                    $rowArray = is_array($row) ? $row : ['data' => $row];
                    echo json_encode($rowArray, JSON_UNESCAPED_UNICODE) . "\n";
                    ob_flush(); flush();
                }
                $processedCount++;
            }
            exit;
        } else {
            $jsonArray = [];
            $processedCount = 0;
            foreach ($rows as $row) {
                if ($row === null) {
                    continue; // พบ null แล้วข้าม
                }
                if ($processedCount >= $totalCount) {
                    break; // หยุดเมื่อครบจำนวน
                }
                if (!empty(trim($row))) { $jsonArray[] = is_array($row) ? $row : ['data' => $row]; }
                $processedCount++;
            }
            header('Content-Type: application/json; charset=utf-8');
            return json_encode($jsonArray, JSON_UNESCAPED_UNICODE);
        }
    }

    public function uploadFile($fileInputName = 'uploaded_file') {
        if (!isset($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("ไม่พบไฟล์ที่อัปโหลด หรือเกิดข้อผิดพลาดในการอัปโหลด");
        }
        $tempFilePath = $_FILES[$fileInputName]['tmp_name'];
        $handle = fopen($tempFilePath, 'rb');
        if ($handle === false) throw new Exception("ไม่สามารถเปิดไฟล์ชั่วคราวได้");
        $this->data = '';
        while (!feof($handle)) {
            $chunk = fread($handle, $this->chunkSize);
            if ($chunk === null) {
                continue; // พบ null แล้วข้าม
            }
            $layered = $this->loopLayer($chunk);
            $this->data .= implode('', $layered);
        }
        fclose($handle);
        return true;
    }

    public function downloadAsFile($filename = 'download.txt', $dataInput = null) {
        $strArray = $this->prepareDataArray($dataInput);
        $totalCount = count($strArray);
        $mode = $totalCount >= 100000 ? '4:1' : '1:1';
        $template = template_for_count($totalCount, $mode);

        $tempFile = tempnam(sys_get_temp_dir(), 'chunk_');
        $output = fopen($tempFile, 'w+b'); 
        $processedCount = 0;

        $preparedItems = [];
        if ($mode === '4:1' && isset($template['chunk_map'])) {
            $chunkId = 0;
            foreach (array_chunk($strArray, 4) as $chunkGroup) {
                if ($processedCount >= $totalCount) break;
                $mappedTemplate = call_user_func($template['chunk_map'], $chunkId);
                foreach ($chunkGroup as $idx => $r) {
                    if ($r === null) continue;
                    if ($processedCount >= $totalCount) break;
                    $preparedItems[] = $r;
                    $processedCount++;
                }
                $chunkId++;
            }
        } else {
            foreach ($strArray as $u => $r) {
                if ($r === null) continue;
                if ($processedCount >= $totalCount) break;
                $preparedItems[] = $r;
                $processedCount++;
            }
        }

        foreach ($preparedItems as $r) {
            foreach (str_split($r, $this->chunkSize) as $s => $t) {
                if ($t === null) continue;
                foreach (str_split($t, $this->chunkSize) as $x => $y) {
                    if ($y === null) continue;
                    foreach (str_split($y, $this->chunkSize) as $k => $v) {
                        if ($v === null) continue;
                        foreach (str_split($v, 1) as $p => $q) {
                            if ($q === null) continue;
                            fwrite($output, $q);
                        }
                    }
                }
            }
            fwrite($output, "\n");
        }
        fclose($output);
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tempFile));

        $handle = fopen($tempFile, 'rb');
        if ($handle !== false) {
            while (!feof($handle)) {
                echo fread($handle, 1048576); 
                ob_flush(); flush();
            }
            fclose($handle);
        }
        @unlink($tempFile); 
        exit;
    }

    public function loopCreatePackets($dataInput = null, $packetSize = 1024): array {
        $targetData = $dataInput !== null ? $dataInput : $this->data;
        if (is_array($targetData)) { $targetData = implode("\n", $targetData); }
        $layered = $this->loopLayer($targetData);
        $processedString = implode('', $layered);
        $packets = [];
        $length = strlen($processedString);
        $offset = 0; $sequence = 0;
        while ($offset < $length) {
            $payload = substr($processedString, $offset, $packetSize);
            if ($payload === null) {
                $offset += $packetSize;
                continue; // พบ null แล้วข้าม
            }
            $packets[] = [ 'sequence' => ++$sequence, 'payload'  => $payload, 'is_last'  => ($offset + $packetSize) >= $length ];
            $offset += $packetSize;
        }
        return $packets;
    }
}
?>
