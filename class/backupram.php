<?php
if (!function_exists('array_any')) {
     function array_any(array $array, ?callable $callback = null): bool {
         foreach ($array as $value) {
             if ($callback === null && $value) return true;
             if ($callback !== null && $callback($value)) return true;
         }
         return false;
     }
 }

// ... โค้ดเดิม ...

// ✅ แก้: เปลี่ยน $stream_opts เป็น $opts ตรงที่ใช้ stream_context_create
$opts = [
     'http' => [
         'method' => 'POST',
         'header' => 'Content-type: application/x-www-form-urlencoded'
     ]
 ];
 $context = stream_context_create($opts); 
$file = new SplFileObject('php://filter/read=string.toupper|string.rot13/resource='.$file, 'rw');
  

 


//✅ แก้: ลูป while ต้องกำหนด $file ก่อนใช้ และลบ fclose ออกจาก SplFileObject
$line=[];
while (!$file->eof()) {
     $line = $file->fgets();
     }

 // ❌ ลบ fclose($file); ออกเด็ดขาด ถ้า $file เป็น SplFileObject