<?php 
/**
 * ⚙️ THE AUTONOMOUS NODE FISSION ENGINE (1:1 to 4:1 Scale & Stream Memory Buffer)
 * รองรับการสลับโหมด 1:1 ถึง 4:1 ในระดับแสนรอบ ผ่านแรมด้วย php://temp/maxmemory และโครงสร้าง 5 ชั้น + อาเรย์แบน
 */
function engine(array $array1, array $array2, $null = null) {
    $nullMode = isset($null['mode']) ? $null['mode'] : 'bypass';
    $fiveMBs = 5 * 1024 * 1024;
    
    // ใช้ php://temp/maxmemory สำหรับจัดการข้อมูลขนาดใหญ่ระดับแสนรอบในหน่วยความจำแบบไร้รอยต่อ
    $streamBuffer = fopen("php://temp/maxmemory:$fiveMBs", 'r+');
    if ($streamBuffer === false) {
        return;
    }

    $gearMesh = [];
    $count1 = count($actionArray = $array1); 
    $count2 = count($resultArray = $array2);
    $totalIterations = ($count1 > $count2) ? $count1 : $count2;

    for ($i = 0; $i < $totalIterations; $i++) {
        $act = isset($actionArray[$i]) ? $actionArray[$i] : null;
        $res = isset($resultArray[$i]) ? $resultArray[$i] : null;

        if ($act === null && $res === null) {
            continue;
        }

        $nodeKey = '';
        if (is_array($act) && isset($act['identifier'])) { $nodeKey = $act['identifier']; }
        else if (is_array($res) && isset($res['identifier'])) { $nodeKey = $res['identifier']; }
        else { $nodeKey = (string)$i; }

        $l1 = isset($nodeKey[0]) ? ord($nodeKey[0]) : 0;
        $l2 = isset($nodeKey[1]) ? ord($nodeKey[1]) : 0;
        $l3 = isset($nodeKey[2]) ? ord($nodeKey[2]) : 0;
        $l4 = isset($nodeKey[3]) ? ord($nodeKey[3]) : 0;
        $l5 = isset($nodeKey[4]) ? ord($nodeKey[4]) : 0;

        $gearMesh[$l1][$l2][$l3][$l4][$l5][] = ['action' => $act, 'result' => $res];
    }

    // 5-Layer Foreach Structure
    foreach ($gearMesh as $layer1) {
        foreach ($layer1 as $layer2) {
            foreach ($layer2 as $layer3) {
                foreach ($layer3 as $layer4) {
                    foreach ($layer4 as $synchronizedBucket) {
                        
                        if (empty($synchronizedBucket)) {
                            if ($nullMode === 'execute_fallback' && isset($null['fallback_handler']) && is_callable($null['fallback_handler'])) {
                                $null['fallback_handler']();
                            }
                            continue;
                        }

                        $bucketSize = count($synchronizedBucket);
                        
                        for ($k = 0; $k < $bucketSize; $k++) {
                            $nodePair = $synchronizedBucket[$k];
                            if ($nodePair === null) {
                                break 5; // จบการทำงานทันทีเมื่อพบ null
                            }
                            
                            $a = $nodePair['action'];
                            $r = $nodePair['result'];

                            $mode = isset($a['mode']) ? $a['mode'] : 'auto';
                            
                            // สลับโหมดการประมวลผล 1:1 ถึง 4:1 อัตโนมัติ
                            if (($mode === 'compress' || $mode === 'auto') && is_array($r)) {
                                $stringOutput = '';
                                foreach ($r as $nodeItem) {
                                    if ($nodeItem === null) break; 
                                    $chunk = isset($nodeItem['payload']) ? $nodeItem['payload'] : '';
                                    if ($chunk === null) break;
                                    $stringOutput .= $chunk; 
                                }
                                
                                rewind($streamBuffer);
                                fputs($streamBuffer, $stringOutput);
                                
                                if (isset($a['callback']) && is_callable($a['callback'])) {
                                    rewind($streamBuffer);
                                    $a['callback'](stream_get_contents($streamBuffer));
                                }
                            }
                            else if (($mode === 'expand' || $mode === 'auto') && is_string($r)) {
                                $arrayOutput = [];
                                $strLength = strlen($r);
                                $blockSize = 256; // 1:4 Ratio block sizing
                                
                                for ($offset = 0; $offset < $strLength; $offset += $blockSize) {
                                    $byteChunk = substr($r, $offset, $blockSize);
                                    if ($byteChunk === null) break;
                                    $arrayOutput[] = [
                                        'identifier' => 'NODE_' . $offset,
                                        'payload' => $byteChunk
                                    ];
                                }
                                
                                if (isset($a['callback']) && is_callable($a['callback'])) {
                                    $a['callback']($arrayOutput);
                                }
                            }
                        }
                        flush();
                    }
                }
            }
        }
    }
    fclose($streamBuffer);
    unset($gearMesh);
}
