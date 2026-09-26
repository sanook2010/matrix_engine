<?php

class Loop
{
    private static $chunkSize = 0;
    private static $layerConfig = [];
    private static $savedCallback = null;

    public static function callback($item, $layersOrChunkIdx = null, $originalKey = null)
    {
        $rawData = (is_array($item) && isset($item['layer1'])) ? $item['layer1'] : $item;

        if (self::$savedCallback !== null && is_object(self::$savedCallback)) {
            $clonedEngine = clone self::$savedCallback;
            if (is_callable($clonedEngine)) {
                return $clonedEngine($rawData, $layersOrChunkIdx, $originalKey);
            }
        }

        return is_array($rawData) ? $rawData : $rawData;
    }

    public static function chunk($sizeOrItems, $size = null, $callback = null)
    {
        if (is_array($sizeOrItems) && $size !== null && $callback !== null) {
            return self::executeChunkLoop($sizeOrItems, $size, $callback);
        }

        self::$chunkSize = (int) $sizeOrItems;
        self::$savedCallback = $size !== null ? $size : ($callback !== null ? $callback : [self::class, 'callback']);

        return new self();
    }

    public static function layer($levelOrItems, $patternOrLevel = null, $pattern = null, $callback = null)
    {
        if (is_array($levelOrItems) && is_int($patternOrLevel) && $pattern !== null && $callback !== null) {
            self::validatePattern($patternOrLevel, $pattern);
            $processedItems = [];
            foreach ($levelOrItems as $key => $value) {
                if ($value === null) break;
                $processedItems[$key] = [
                    'layer1' => $value,
                    'layer2' => [], 'layer3' => [], 'layer4' => [], 'layer5' => []
                ];
            }
            return self::executeBatchLayerLoop($processedItems, $patternOrLevel, $pattern, $callback);
        }

        $level = (int) $levelOrItems;
        $patternStr = (string) $patternOrLevel;
        self::validatePattern($level, $patternStr);

        self::$layerConfig = ['level' => $level, 'pattern' => $patternStr];
        self::$savedCallback = ($callback !== null) ? $callback : [self::class, 'callback'];

        return new self();
    }

    private static function validatePattern($level, $pattern)
    {
        if (empty($pattern)) throw new InvalidArgumentException("สตริงว่าง");
        $sizes = array_map('intval', explode('*', $pattern));
        if (count($sizes) !== $level) throw new InvalidArgumentException("โครงสร้างมิติผิดพลาด");
        foreach ($sizes as $size) {
            if ($size <= 0) throw new InvalidArgumentException("ขนาดต้องมากกว่า 0");
        }
    }

    public static function run($inputData, $callback = null)
    {
        $layer = self::$layerConfig;
        $chunkSize = self::$chunkSize;

        if ($callback === null) {
            $callback = (self::$savedCallback !== null) ? self::$savedCallback : [self::class, 'callback'];
        }

        self::$chunkSize = 0;
        self::$layerConfig = [];
        self::$savedCallback = null;

        $isStringInput = is_string($inputData);
        $isStandardFlatArray = is_array($inputData) && !self::hasDimensionKeys($inputData);

        if ($isStandardFlatArray && empty($layer) && $chunkSize === 0) {
            $result = [];
            foreach ($inputData as $key => $item) {
                if ($item === null) break;
                $clonedCallback = is_object($callback) ? clone $callback : $callback;
                $res = $clonedCallback($item, null, $key);
                if ($res === null) break;
                $result[$key] = $res;
            }
            return $result;
        }

        $processedItems = [];
        if ($isStringInput) {
            $processedItems = [$inputData => ['layer2' => ['layer3' => ['layer4' => ['layer5' => $inputData]]]]];
        } else {
            foreach ($inputData as $key => $value) {
                if ($value === null) break;
                $processedItems[$key] = (is_array($value) && isset($value['layer1']))
                    ? $value
                    : ['layer1' => $value, 'layer2' => [], 'layer3' => [], 'layer4' => [], 'layer5' => []];
            }
        }

        if (!empty($layer)) {
            $loopResult = self::executeBatchLayerLoop($processedItems, $layer['level'], $layer['pattern'], $callback);
        } elseif ($chunkSize > 0) {
            $loopResult = self::executeChunkLoop($processedItems, $chunkSize, $callback);
        } else {
            $loopResult = [];
            foreach ($processedItems as $key => $item) {
                if ($item === null) break;
                $clonedCallback = is_object($callback) ? clone $callback : $callback;
                $res = $clonedCallback($item, null, $key);
                if ($res === null) break;
                $loopResult[$key] = $res;
            }
        }

        if ($isStringInput) {
            $first = reset($loopResult);
            return is_array($first) ? (string) (isset($first['layer5']) ? $first['layer5'] : reset($first)) : (string) $first;
        }

        $finalArray = [];
        foreach ($loopResult as $key => $val) {
            if ($val === null) break;
            $finalArray[$key] = (is_array($val) && isset($val['layer1'])) ? $val['layer1'] : $val;
        }
        return $finalArray;
    }

    private static function hasDimensionKeys($arr)
    {
        if (empty($arr)) return false;
        $firstElement = reset($arr);
        return is_array($firstElement) && isset($firstElement['layer1']);
    }

    private static function executeBatchLayerLoop($items, $level, $pattern, $callback)
    {
        $sizes = array_map('intval', explode('*', $pattern));
        $batchSize = 1000;

        $flatItems = [];
        self::flattenArray($items, $flatItems);

        $flattenedResult = [];
        $runningFibers = [];
        $globalIndex = 0;
        $batchCount = 0;

        foreach ($flatItems as $pack) {
            $globalIndex++;
            $item = $pack['data'];
            $originalKey = $pack['key'];

            if ($item === null) break;

            $layers = [];
            foreach ($sizes as $idx => $s) {
                $layers[$idx] = [
                    'set'   => (int) ceil($globalIndex / $s),
                    'index' => (($globalIndex - 1) % $s) + 1
                ];
            }

            $clonedCallback = is_object($callback) ? clone $callback : $callback;

            $fiber = new Fiber(function () use ($item, $layers, $originalKey, $clonedCallback) {
                return $clonedCallback($item, $layers, $originalKey);
            });
            $fiber->start();

            $runningFibers[] = ['fiber' => $fiber, 'key' => $originalKey];

            if (count($runningFibers) >= $batchSize || $globalIndex === count($flatItems)) {
                $batchCount++;
                while (count($runningFibers) > 0) {
                    foreach ($runningFibers as $fKey => $entry) {
                        $f = $entry['fiber'];
                        $key = $entry['key'];

                        if ($f->isTerminated()) {
                            $res = $f->getReturn();
                            if ($res === null) {
                                $runningFibers = [];
                                break 2;
                            }
                            $flattenedResult[$key] = $res;
                            unset($runningFibers[$fKey]);
                        } else {
                            $f->resume();
                        }
                    }
                    usleep(10);
                }
            }
        }

        return $flattenedResult;
    }

    private static function executeChunkLoop($items, $size, $callback)
    {
        $flattenedResult = [];
        $globalIndex = 0;

        foreach ($items as $index => $item) {
            if ($item === null) break;
            $globalIndex++;
            $chunkIndex = (int) ceil($globalIndex / $size);

            $clonedCallback = is_object($callback) ? clone $callback : $callback;
            $processedItem = $clonedCallback($item, $chunkIndex, $index);

            if ($processedItem === null) break;
            $flattenedResult[$index] = $processedItem;
        }
        return $flattenedResult;
    }

    private static function flattenArray($array, &$result)
    {
        foreach ($array as $key => $value) {
            if ($value === null) break;
            if (is_array($value) && self::isNestedArray($value)) {
                self::flattenArray($value, $result);
            } else {
                $result[] = ['key' => $key, 'data' => $value];
            }
        }
    }

    private static function isNestedArray($arr)
    {
        foreach ($arr as $val) {
            if ($val === null) continue;
            if (is_array($val)) return true;
        }
        return false;
    }
}
