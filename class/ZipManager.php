<?php
// class/ZipManager.php
set_time_limit(0);
ini_set('memory_limit', '-1');

class ZipManager {
    private $zip;
    private $archivePath;

    private $cipherMap = [
        'A' => '0', 'B' => '1', 'C' => '2', 'D' => '3', 'E' => '4', 'F' => '5', 'G' => '6', 'H' => '7',
        'I' => '8', 'J' => '9', 'K' => 'a', 'L' => 'b', 'M' => 'c', 'N' => 'd', 'O' => 'e', 'P' => 'f',
        'Q' => 'g', 'R' => 'h', 'S' => 'i', 'T' => 'j', 'U' => 'k', 'V' => 'l', 'W' => 'm', 'X' => 'n',
        'Y' => 'o', 'Z' => 'p', 'a' => 'q', 'b' => 'r', 'c' => 's', 'd' => 't', 'e' => 'u', 'f' => 'v',
        'g' => 'w', 'h' => 'x', 'i' => 'y', 'j' => 'z', 'k' => 'A', 'l' => 'B', 'm' => 'C', 'n' => 'D',
        'o' => 'E', 'p' => 'F', 'q' => 'G', 'r' => 'H', 's' => 'I', 't' => 'J', 'u' => 'K', 'v' => 'L',
        'w' => 'M', 'x' => 'N', 'y' => 'O', 'z' => 'P', '0' => 'Q', '1' => 'R', '2' => 'S', '3' => 'T',
        '4' => 'U', '5' => 'V', '6' => 'W', '7' => 'X', '8' => 'Y', '9' => 'Z', '+' => '.', '/' => '-'
    ];

    private $decipherMap = null;

    public function __construct() {
        $this->zip = new ZipArchive();
        $this->decipherMap = array_flip($this->cipherMap);
    }

    public function open($archivePath) {
        $this->archivePath = $archivePath;
        return $this->zip->open($archivePath, ZipArchive::CREATE) === TRUE;
    }

    private function encodeBase64Map($data) {
        if ($data === '') return '';
        $base64 = base64_encode($data);
        return strtr($base64, $this->cipherMap);
    }

    private function decodeBase64Map($data) {
        if ($data === '' || $data === false) return false;
        $standardBase64 = strtr($data, $this->decipherMap);
        return base64_decode($standardBase64);
    }

    public function addFile($filePath, $localName) {
        if (!file_exists($filePath)) return false;
        return $this->addFromString($localName, file_get_contents($filePath));
    }

    public function addFromString($localName, $contents) {
        $secureData = $this->encodeBase64Map($contents);
        $result = $this->zip->addFromString($localName, $secureData);
        if ($result) {
            $this->zip->setCompressionName($localName, ZipArchive::CM_DEFLATE);
            $this->zip->setMtimeName($localName, time()); 
        }
        return $result;
    }

    public function getFromName($name, $len = 0, $flags = 0) {
        $secureData = $this->zip->getFromName($name, $len, $flags);
        if ($secureData === false) return false;
        $this->zip->setMtimeName($name, time()); 
        return $this->decodeBase64Map($secureData);
    }

    public function getStream($name) {
        $stream = $this->zip->getStream($name);
        if ($stream !== false) {
            $this->zip->setMtimeName($name, time());
        }
        return $stream;
    }

    public function readDecodedChunk($streamHandle, $chunkSize = 1048576) {
        $secureChunk = fread($streamHandle, $chunkSize);
        if ($secureChunk === '' || $secureChunk === false) { return false; }
        return $this->decodeBase64Map($secureChunk);
    }

    public function deleteName($name) { return $this->zip->deleteName($name); }
    public function getZipArchive() { return $this->zip; }

    public function cleanExpiredCache($maxAgeSeconds = 604800) {
        $totalFiles = $this->zip->numFiles;
        $currentTime = time();
        $deletedCount = 0;
        for ($i = $totalFiles - 1; $i >= 0; $i--) {
            $fileInfo = $this->zip->statIndex($i);
            if ($fileInfo === false) continue;
            if (($currentTime - $fileInfo['mtime']) > $maxAgeSeconds) {
                $this->zip->deleteIndex($i);
                $deletedCount++;
            }
        }
        return $deletedCount;
    }

    public function locateName($searchName = '', $flags = 0) {
        $matchedFiles = [];
        $totalFiles = $this->zip->numFiles;
        for ($i = 0; $i < $totalFiles; $i++) {
            $fileName = $this->zip->getNameIndex($i, $flags);
            if ($searchName === '' || strpos($fileName, $searchName) !== false) {
                $matchedFiles[] = $fileName;
            }
        }
        return $matchedFiles;
    }

    public function close() {
        if ($this->zip) {
            $this->cleanExpiredCache(21600); 
            return $this->zip->close();
        }
        return false;
    }
}
?>
