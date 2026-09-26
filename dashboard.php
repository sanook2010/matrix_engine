<?php
// dashboard.php - เวอร์ชันจับคู่คีย์วิ่งตรงช่อง Input ตามระบบพิกัดตารางกริดและเกราะคัดกรองเนื้อหาของพี่ 100%
header('Content-Type: text/html; charset=utf-8');
define('WEBROOT', $_SERVER['DOCUMENT_ROOT']);
require_once(WEBROOT.'/class/function.php');
require_once(WEBROOT.'/user/auth_session.php'); // ดักเช็คสิทธิ์ล็อกอินฝั่งส่งตามปกติ

echo "<h4>⚡ เครื่องยนต์ลูปสาดคีย์ไอพี วนพิกัด 1-65536 ตรงช่อง Input (Stealth Grid Pipeline):</h4>";

try {
    // 🎯 กำหนดหมายเลข Set ที่ต้องการทดสอบยิงสตรีมเข้าพิกัด (เช่น สั่งรันเข้าแผงคลัง Set 0)
    $targetSets =array(); 
    $maxSequencePerSet = 65536; // ความกว้างคีย์สะสมสูงสุดต่อ 1 Set ตามสูตร 256x256 ของพี่

    echo "<div style='background:#000; color:#34d399; font-family:monospace; padding:15px; border-radius:6px; max-height:400px; overflow-y:auto; font-size:11px; text-align:left;'>";
    echo "[READY] ด่านรับสแตนด์บายเปิดช่อง Input รออยู่... เริ่มต้นลูปสาดสตรีมข้อมูลย่อยขนานแท้!<br><br>";

    $requestWindowCount = 0;

    foreach ($targetSets as $blockSet) {
        echo "<b style='color:#38bdf8;'>=== เริ่มสายส่งสตรีมคีย์พิกัดกลุ่ม SET #{$blockSet} ===</b><br>";
        
        // 🎯 วนลูปสาดคีย์เริ่มจาก 1 ถึง 65,536 ต่อหนึ่งพิกัด Set ตามสั่ง
        for ($sequenceId = 1; $sequenceId <= $maxSequencePerSet; $sequenceId++) {
            
            // 🎯 คำนวณบล็อกพิกัดบนตารางกริด (chunk.group.set.id) บีบตัวเลขเศษไม่ให้หลุดขอบเขต 256
            $blockGroup = floor($sequenceId / 256) % 256; 
            
            // 🎯 ตัวไอดี (block_chunk) ชิ้นส่วนช่องจิ๋วบนจอ ทำหน้าที่เป็น "ตัวคีย์ (Key)" วิ่งเข้าชนกล่อง Input
            $blockChunk = $sequenceId % 256;              

            // 🎯 รูปแบบ Payload ขาส่งเป็นโครงสร้างหมายเลขไอพีสัมพันธ์ตามกลุ่มพิกัดช่องจิ๋ว
            $ipPayloadKey = "192.168.{$blockGroup}.{$blockChunk}";

            // 🎯 ประกอบพารามิเตอร์ขา POST เป็นตัวเลขดิบและรหัสคีย์สั้นตรงล็อก ดักตัดข้อความขยะทิ้งทั้งหมดตามกฎของพี่
            $postData = [
                'sequence'    => $sequenceId,          // ตัวเลขดิบเริ่มนับจาก 1
                'block_set'   => (int)$blockSet,       // แท็กระบุ Set ของพี่
                'block_group' => (int)$blockGroup,     // แท็กระบุ Group ของพี่
                'block_chunk' => (int)$blockChunk,     // ไอดีช่องจิ๋ว (Key) ป้อนตรงเข้ากล่อง Input
                'payload'     => $ipPayloadKey,        // สาดคีย์ข้อมูลรูปแบบไอพีตรงช่อง
                'header_key'  => $sequenceId,          // รหัสคีย์ยืนยันความปลอดภัยหลัก
                'filename'    => $blockSet,            
                'line_num'    => $sequenceId,          
                'location'    => $blockSet             
            ];

            // วิ่งท่อเน็ตเวิร์ก cURL ดีดตัวกลับเข้าพิกัดตรวจสอบหาตัวเองในไฟล์หลัก stream_autoscale_system.php
            $targetEndpoint = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/block_chunk.php";
            
            $ch = curl_init($targetEndpoint);
            curl_setopt($ch, CURLOPT_URL, $targetEndpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData)); // คลี่สายส่งสตรีมสตริงตรงล็อก
            $serverOutput = curl_exec($ch);
            curl_close($ch);

            $apiResponse = json_decode($serverOutput, true);
            $statusResult = $apiResponse['status'] ?? 'reject';

            // พ่นสถิติโชว์บนจอเบราว์เซอร์สุ่มทุกๆ 500 รอบ เพื่อไม่ให้เบราว์เซอร์มือถือค้างหน่วงค้าง
            if ($sequenceId === 1 || $sequenceId === $maxSequencePerSet || $sequenceId % 500 === 0) {
                echo "Set: {$blockSet} -> ลำดับ: {$sequenceId}/65536 -> พิกัดกริด: {$blockGroup}.{$blockChunk} (Input ID) -> ผลลัพธ์: <b style='color:".($statusResult === 'success' ? '#10b981':'#ef4444')."'>{$statusResult}</b><br>";
                if (ob_get_level() > 0) ob_flush();
                flush();
            }

            // คุมจังหวะความเร็วหน่วงหลบเกราะแอนตี้ DDoS Window 2 วินาทีของพี่อย่างปลอดภัย
            $requestWindowCount++;
            if ($requestWindowCount >= 25) {
                // 🎯 [จงใจขาดช่วง] เว้นสัญญาณหยุดรอ 1.6 วินาที เพื่อให้ตัวรับดักตรวจเจอ Gap สั่งตัดประมวลผลเช็คบัฟเฟอร์คลังสินค้า
                usleep(1600000); 
                $requestWindowCount = 0;
            } else {
                usleep(1000); // ดีเลย์สั้นระดับไมโครวินาทีให้สตรีมสาดไหลต่อเนื่องลื่นไหล
            }
        }
        echo "<span style='color:#ef4444; font-weight:bold;'>➔ [SIGNAL GAP CUT-OFF] สัญญาณขาดช่วงขบวนแรกเสร็จสิ้น... ปล่อยด่านรับประมวลผลปิดรอบ!</span><br><br>";
    }
    
    echo "</div>";
    echo "<p style='color:#10b981; font-weight:bold; margin-top:10px;'>🚀 ข้อมูลจับคู่คีย์ไอพี วนลำดับทะลวงเข้าช่อง Input ของพี่เรียบร้อยสมบูรณ์แบบแล้วครับ!</p>";

} catch (Exception $e) {
    echo "<p style='color:#ef4444; font-weight:bold;'>❌ เกิดข้อผิดพลาดในระบบส่งคีย์พิกัดกริด: " . $e->getMessage() . "</p>";
}
?>
