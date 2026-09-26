<?php
// class/VariableSteam.php
declare(strict_types=1);

class VariableStreams {
    public int $position = 0;
    public string $varname = '';

    /**
     * ฟังก์ชันแปลง Path สตรีมจำลองให้เป็น Key โกลบอลที่ปลอดภัย
     */
    private function getVarKey(string $path): string {
        return preg_replace('#^var://#i', '', $path);
    }
    
    /**
     * [แก้ไข] เอาคำว่า static ออก เพื่อให้โค้ดฝั่งไฟล์หลักสามารถเรียกใช้ผ่าน $this->variableSteams->register ได้ถูกต้อง
     */
    public function register(string $filename, callable $callback): void {
        $key = $this->getVarKey("var://" . $filename);
        
        if (!isset($GLOBALS[$key]) || empty($GLOBALS[$key])) {
            $GLOBALS[$key] = call_user_func($callback);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool {
        $this->varname = $this->getVarKey($path);
        $this->position = 0;

        if (!isset($GLOBALS[$this->varname])) {
            $GLOBALS[$this->varname] = '';
        }

        return true;
    }

    public function stream_read(int $count): string {
        $ret = substr($GLOBALS[$this->varname], $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }

    public function stream_write(string $data): int {
        $left = substr($GLOBALS[$this->varname], 0, $this->position);
        $right = substr($GLOBALS[$this->varname], $this->position + strlen($data));
        $GLOBALS[$this->varname] = $left . $data . $right;
        $this->position += strlen($data);
        return strlen($data);
    }

    public function stream_tell(): int {
        return $this->position;
    }

    public function stream_eof(): bool {
        return $this->position >= strlen($GLOBALS[$this->varname]);
    }

    public function stream_seek(int $offset, int $whence): bool {
        switch ($whence) {
            case SEEK_SET:
                if ($offset < strlen($GLOBALS[$this->varname]) && $offset >= 0) {
                     $this->position = $offset;
                     return true;
                }
                return false;
            case SEEK_CUR:
                if ($offset >= 0) {
                     $this->position += $offset;
                     return true;
                }
                return false;
            case SEEK_END:
                if (strlen($GLOBALS[$this->varname]) + $offset >= 0) {
                     $this->position = strlen($GLOBALS[$this->varname]) + $offset;
                     return true;
                }
                return false;
            default:
                return false;
        }
    }

    public function stream_metadata(string $path, int $option, $var): bool {
        if ($option === STREAM_META_TOUCH) {
            $varname = $this->getVarKey($path);
            if (!isset($GLOBALS[$varname])) {
                $GLOBALS[$varname] = '';
            }
            return true;
        }
        return false;
    }

    /**
     * ฟังก์ชันบังคับสำหรับ PHP 8+ เพื่อให้เอนจินของ PHP อนุญาตให้ดึงหน่วยความจำสตรีมไปประมวลผลคำสั่งรันสคริปต์ได้
     */
    public function stream_stat(): array {
        return [
            'size' => strlen($GLOBALS[$this->varname] ?? '')
        ];
    }
}

// ลงทะเบียนโปรโตคอลสตรีมเสมือน 'var://' เข้าสู่ระบบ Core PHP Engine
if (!in_array('var', stream_get_wrappers(), true)) {
    stream_wrapper_register('var', 'VariableStreams');
}
