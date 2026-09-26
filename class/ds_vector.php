<?php
/**
 * 🧠 Ds_Vector — อาเรย์อัจฉริยะ + เมทริกซ์
 * @version 1.0.0
 */
if (!defined('DS_VECTOR_V1')) define('DS_VECTOR_V1', true);

class Ds_Vector implements ArrayAccess, Countable, IteratorAggregate {
    private array $data = [];
    private string $keyMode;

    public function __construct(array $arr = [], string $keyMode = 'auto') {
        $this->keyMode = $keyMode;
        foreach ($arr as $v) $this[] = $v;
    }

    public function offsetExists($offset): bool { return isset($this->data[$offset]); }
    public function offsetGet($offset): mixed { return $this->data[$offset] ?? null; }
    public function offsetSet($offset, $value): void {
        if ($offset === null) $this->data[] = $value;
        else $this->data[$offset] = $value;
    }
    public function offsetUnset($offset): void { unset($this->data[$offset]); }
    public function count(): int { return count($this->data); }
    public function getIterator(): Traversable { return new ArrayIterator($this->data); }
    public function toArray(): array { return $this->data; }
}

/**
 * 📐 ดึงทุกคอลัมน์ — แกน X → Y
 */
function array_column_all(array $arrays): array {
    if (empty($arrays)) return [];
    $colCount = 0;
    foreach ($arrays as $row) {
        $colCount = max($colCount, is_array($row) ? count($row) : 0);
    }
    $out = [];
    for ($i = 0; $i < $colCount; $i++) {
        $col = [];
        foreach ($arrays as $row) {
            $col[] = is_array($row) ? ($row[$i] ?? null) : null;
        }
        $out[] = $col;
    }
    return $out;
}
