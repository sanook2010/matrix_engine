<?php
// =============================================================================
// UNIFIED HIGH-LEVEL GRID STREAM & STORAGE ENGINE (SINGLE-FILE ALL-IN-ONE)
// =============================================================================

set_time_limit(0);
ini_set('memory_limit', '-1');
ini_set('display_errors', '0');
error_reporting(E_ALL);
define('WEBROOT', $_SERVER['DOCUMENT_ROOT']);
define('GRID_SIZE', 256); // 256 ช่องต่อ 1 เซต/กรุ๊ป

$storageDir = WEBROOT . '/storage';
$bufferDir = WEBROOT . '/storage/buffer';
if (!is_dir($storageDir)) { @mkdir($storageDir, 0755, true); }
if (!is_dir($bufferDir)) { @mkdir($bufferDir, 0755, true); }

$dbPath = $storageDir . '/grid_multidim.db';

// =============================================================================
// UTILITY & SECURITY FUNCTIONS (IP, Subnet, DDoS, Dual-Layer Hash)
// =============================================================================

function get_client_ip(): string {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function get_subnet(string $ip): string {
  $parts = explode('.', $ip);
  return (count($parts) === 4) ? "{$parts[0]}.{$parts[1]}.{$parts[2]}.0/24" : 'unknown';
}

function generate_front_back_hash(string $data): string {
  $clean_data = trim($data);
  $len = strlen($clean_data);
  $front = ($len >= 32) ? substr($clean_data, 0, 32) : $clean_data;
  $back = ($len >= 32) ? substr($clean_data, -32) : $clean_data;
  if (function_exists('sodium_crypto_generichash')) {
    return bin2hex(sodium_crypto_generichash($front . "|" . $back));
  }
  return md5($front . "|" . $back);
}

function generate_full_packet_hash(string $data): string {
  return md5(trim($data));
}

// =============================================================================
// CLASS 1: UNIFIED DATABASE & STORAGE LAYER
// =============================================================================
class DatabaseManager {

  private PDO $pdo;
  private string $storageDir;

  public function __construct(string $dbPath, string $storageDir) {
    $this->storageDir = rtrim($storageDir, '/') . '/';
    $this->pdo = new PDO('sqlite:' . $dbPath);
    $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $this->pdo->exec("PRAGMA journal_mode = WAL;");
    $this->pdo->exec("PRAGMA synchronous = NORMAL;");
    
    $this->initTables();
    $this->seedInitialData();
  }

  public function getPdo(): PDO {
    return $this->pdo;
  }

  public function getStorageDir(): string {
    return $this->storageDir;
  }

  private function initTables(): void {
    // 1. ตาราง Block Packets (จากระบบ Grid Stream)
    $this->pdo->exec("CREATE TABLE IF NOT EXISTS block_packets (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      sequence INTEGER UNIQUE,
      block_set INTEGER,
      block_group INTEGER,
      block_chunk INTEGER,
      header_key TEXT,
      filename TEXT,
      payload TEXT,
      line_num INTEGER,
      location TEXT,
      client_ip TEXT,
      subnet TEXT,
      front_back_hash TEXT,
      full_packet_hash TEXT,
      is_last INTEGER,
      created_at INTEGER
    );");

    // 2. ตาราง DDoS Protection
    $this->pdo->exec("CREATE TABLE IF NOT EXISTS ddos_guard (
      client_ip TEXT PRIMARY KEY,
      request_count INTEGER,
      last_request INTEGER
    );");

    // 3. ตารางผู้ใช้งาน และสิทธิ์โควตา
    $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
      user_id INTEGER PRIMARY KEY AUTOINCREMENT,
      username TEXT NOT NULL UNIQUE,
      api_key TEXT NOT NULL UNIQUE,
      role TEXT DEFAULT 'user',
      quota_bytes INTEGER DEFAULT 104857600,
      used_bytes INTEGER DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 4. ตาราง Tag Index
    $this->pdo->exec("CREATE TABLE IF NOT EXISTS tag_index (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      col1_code TEXT NOT NULL,
      col2_number_seq TEXT NOT NULL,
      col3_num_length INTEGER NOT NULL
    );");

    // 5. ตาราง Zip Stream Index
    $this->pdo->exec("CREATE TABLE IF NOT EXISTS zip_index (
      file_id INTEGER PRIMARY KEY,
      user_id INTEGER NOT NULL,
      host_url TEXT NULL,
      zip_name TEXT NOT NULL,
      inner_file TEXT NOT NULL,
      line_start INTEGER DEFAULT 0,
      line_limit INTEGER DEFAULT 0,
      file_size INTEGER DEFAULT 0,
      FOREIGN KEY (user_id) REFERENCES users(user_id)
    );");

    // 6. ตาราง Git Engine
    $this->pdo->exec("CREATE TABLE IF NOT EXISTS git_commits (
      commit_hash TEXT PRIMARY KEY,
      parent_hash TEXT NULL,
      user_id INTEGER NOT NULL,
      branch_name TEXT NOT NULL DEFAULT 'main',
      message TEXT NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(user_id)
    );");

    $this->pdo->exec("CREATE TABLE IF NOT EXISTS git_blobs (
      blob_hash TEXT NOT NULL,
      file_id INTEGER NOT NULL,
      commit_hash TEXT NOT NULL,
      PRIMARY KEY (blob_hash, commit_hash),
      FOREIGN KEY (file_id) REFERENCES zip_index(file_id),
      FOREIGN KEY (commit_hash) REFERENCES git_commits(commit_hash)
    );");

    $this->pdo->exec("CREATE TABLE IF NOT EXISTS git_refs (
      ref_name TEXT PRIMARY KEY,
      commit_hash TEXT NOT NULL,
      FOREIGN KEY (commit_hash) REFERENCES git_commits(commit_hash)
    );");

    // Create Indexes
    $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_grid_coords ON block_packets(block_set, block_group, block_chunk);");
    $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_front_back ON block_packets(front_back_hash);");
    $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_full_hash ON block_packets(full_packet_hash);");
    $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_subnet ON block_packets(subnet);");
  }

  private function seedInitialData(): void {
    $stmtUser = $this->pdo->query("SELECT COUNT(*) FROM users");
    if ((int)$stmtUser->fetchColumn() === 0) {
      $stmtInsert = $this->pdo->prepare("INSERT INTO users (username, api_key, role, quota_bytes) VALUES (:username, :api_key, :role, :quota)");
      $stmtInsert->execute([':username' => 'admin_system', ':api_key' => 'KEY_ADMIN_1234', ':role' => 'admin', ':quota' => 1073741824]);
      $stmtInsert->execute([':username' => 'client_user1', ':api_key' => 'KEY_USER_5678', ':role' => 'user', ':quota' => 10485760]);
    }

    $stmtTag = $this->pdo->query("SELECT COUNT(*) FROM tag_index");
    if ((int)$stmtTag->fetchColumn() === 0) {
      $this->pdo->beginTransaction();
      $insertStmt = $this->pdo->prepare("INSERT INTO tag_index (col1_code, col2_number_seq, col3_num_length) VALUES (:code, :number_seq, :num_length)");
      $alphaList = $this->generateAlphaSequence(300);
      $allowedLengths = [6, 9, 12, 15];

      foreach ($alphaList as $code) {
        $length = $allowedLengths[array_rand($allowedLengths)];
        $randomDigits = '';
        for ($j = 0; $j < $length; $j++) {
          $randomDigits .= (string)mt_rand(0, 9);
        }
        $insertStmt->execute([':code' => $code, ':number_seq' => $randomDigits, ':num_length' => $length]);
      }
      $this->pdo->commit();
    }
  }

  private function generateAlphaSequence(int $limit): array {
    $chars = array_merge(range('A', 'Z'), range('a', 'z'));
    $sequence = [];
    foreach ($chars as $c) {
      $sequence[] = $c;
      if (count($sequence) >= $limit) return $sequence;
    }
    foreach ($chars as $c1) {
      foreach ($chars as $c2) {
        $sequence[] = $c1 . $c2;
        if (count($sequence) >= $limit) return $sequence;
      }
    }
    return $sequence;
  }

  public function checkDdosProtection(string $ip): bool {
    $now = time();
    $limitWindow = 2;
    $maxRequests = 30;

    $stmt = $this->pdo->prepare("SELECT request_count, last_request FROM ddos_guard WHERE client_ip = :ip");
    $stmt->execute([':ip' => $ip]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($res) {
      if (($now - (int)$res['last_request']) <= $limitWindow) {
        if ((int)$res['request_count'] >= $maxRequests) {
          return false;
        }
        $upd = $this->pdo->prepare("UPDATE ddos_guard SET request_count = request_count + 1, last_request = :now WHERE client_ip = :ip");
        $upd->execute([':now' => $now, ':ip' => $ip]);
      } else {
        $upd = $this->pdo->prepare("UPDATE ddos_guard SET request_count = 1, last_request = :now WHERE client_ip = :ip");
        $upd->execute([':now' => $now, ':ip' => $ip]);
      }
    } else {
      $ins = $this->pdo->prepare("INSERT INTO ddos_guard (client_ip, request_count, last_request) VALUES (:ip, 1, :now)");
      $ins->execute([':ip' => $ip, ':now' => $now]);
    }
    return true;
  }

  public function authenticateUser(string $apiKey): ?array {
    $stmt = $this->pdo->prepare("SELECT user_id, username, role, quota_bytes, used_bytes FROM users WHERE api_key = :api_key");
    $stmt->execute([':api_key' => $apiKey]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
  }

  public function checkQuotaAvailable(int $userId, int $incomingSizeBytes): bool {
    $stmt = $this->pdo->prepare("SELECT quota_bytes, used_bytes FROM users WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    return ($row['used_bytes'] + $incomingSizeBytes) <= $row['quota_bytes'];
  }

  public function updateUsedQuota(int $userId, int $bytesAdded): void {
    $stmt = $this->pdo->prepare("UPDATE users SET used_bytes = used_bytes + :bytes WHERE user_id = :user_id");
    $stmt->execute([':bytes' => $bytesAdded, ':user_id' => $userId]);
  }

  public function registerZipChunk(int $userId, int $fileId, string $zipName, string $innerFile, ?string $hostUrl = null, int $lineStart = 0, int $lineLimit = 0, int $fileSize = 0): bool {
    if (!$this->checkQuotaAvailable($userId, $fileSize)) {
      throw new Exception("Quota Limit Exceeded");
    }

    $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO zip_index (file_id, user_id, host_url, zip_name, inner_file, line_start, line_limit, file_size) VALUES (:file_id, :user_id, :host_url, :zip_name, :inner_file, :line_start, :line_limit, :file_size)");
    $success = $stmt->execute([
      ':file_id'  => $fileId,
      ':user_id'  => $userId,
      ':host_url'  => $hostUrl !== null ? rtrim($hostUrl, '/') : null,
      ':zip_name'  => $zipName,
      ':inner_file' => $innerFile,
      ':line_start' => $lineStart,
      ':line_limit' => $lineLimit,
      ':file_size' => $fileSize
    ]);

    if ($success && $fileSize > 0) {
      $this->updateUsedQuota($userId, $fileSize);
    }
    return $success;
  }

  public function getZipIndexMap(array $fileIds, int $userId): array {
    if (empty($fileIds)) return [];
    $inClause = implode(',', array_fill(0, count($fileIds), '?'));
    $params = array_merge($fileIds, [$userId]);

    $stmt = $this->pdo->prepare("SELECT file_id, host_url, zip_name, inner_file, line_start, line_limit FROM zip_index WHERE file_id IN ($inClause) AND user_id = ?");
    $stmt->execute($params);

    $zipMap = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $zipMap[$row['file_id']] = [
        'host_url'  => $row['host_url'],
        'zip_name'  => $row['zip_name'],
        'inner_file' => $row['inner_file'],
        'line_start' => (int)$row['line_start'],
        'line_limit' => (int)$row['line_limit']
      ];
    }
    return $zipMap;
  }

  public function fetchStreamData(array $info): string {
    $hostUrl  = $info['host_url'];
    $zipName  = $info['zip_name'];
    $innerFile = $info['inner_file'];
    $lineStart = $info['line_start'];
    $lineLimit = $info['line_limit'];

    if (!empty($hostUrl)) {
      $queryParams = http_build_query(['file' => $innerFile, 'start' => $lineStart, 'limit' => $lineLimit]);
      $remoteEndpoint = $hostUrl . '/' . $zipName . '?' . $queryParams;
      $opts = ["http" => ["method" => "GET", "header" => "User-Agent: DataCodec-Stream/1.0\r\n", "timeout" => 5]];
      $context = stream_context_create($opts);
      $content = @file_get_contents($remoteEndpoint, false, $context);
      return $content !== false ? $content : '';
    } else {
      $zipPath = $this->storageDir . $zipName;
      $streamPath = "zip://" . $zipPath . "#" . $innerFile;
      if (file_exists($zipPath) && $fp = @fopen($streamPath, 'rb')) {
        $output = '';
        if ($lineStart > 0 || $lineLimit > 0) {
          $currentLine = 0;
          $collectedCount = 0;
          while (($line = fgets($fp)) !== false) {
            $currentLine++;
            if ($lineStart > 0 && $currentLine < $lineStart) continue;
            $output .= $line;
            $collectedCount++;
            if ($lineLimit > 0 && $collectedCount >= $lineLimit) break;
          }
        } else {
          $output = stream_get_contents($fp);
        }
        fclose($fp);
        return $output !== false ? $output : '';
      }
    }
    return '';
  }

  public function commitGitState(int $userId, array $fileIds, string $message, string $branch = 'main'): string {
    $this->pdo->beginTransaction();
    try {
      $stmtRef = $this->pdo->prepare("SELECT commit_hash FROM git_refs WHERE ref_name = :ref_name");
      $stmtRef->execute([':ref_name' => $branch]);
      $parentHash = $stmtRef->fetchColumn() ?: null;

      $commitHash = sha1(microtime(true) . $message . implode(',', $fileIds));

      $stmtCommit = $this->pdo->prepare("INSERT INTO git_commits (commit_hash, parent_hash, user_id, branch_name, message) VALUES (:commit_hash, :parent_hash, :user_id, :branch, :message)");
      $stmtCommit->execute([
        ':commit_hash' => $commitHash,
        ':parent_hash' => $parentHash,
        ':user_id'   => $userId,
        ':branch'   => $branch,
        ':message'   => $message
      ]);

      $stmtBlob = $this->pdo->prepare("INSERT INTO git_blobs (blob_hash, file_id, commit_hash) VALUES (:blob_hash, :file_id, :commit_hash)");
      foreach ($fileIds as $fileId) {
        $blobHash = sha1('file_' . $fileId . '_' . $commitHash);
        $stmtBlob->execute([':blob_hash' => $blobHash, ':file_id' => $fileId, ':commit_hash' => $commitHash]);
      }

      $stmtUpdateRef = $this->pdo->prepare("INSERT OR REPLACE INTO git_refs (ref_name, commit_hash) VALUES (:ref_name, :commit_hash)");
      $stmtUpdateRef->execute([':ref_name' => $branch, ':commit_hash' => $commitHash]);
      $stmtUpdateRef->execute([':ref_name' => 'HEAD', ':commit_hash' => $commitHash]);

      $this->pdo->commit();
      return $commitHash;
    } catch (Exception $e) {
      $this->pdo->rollBack();
      throw $e;
    }
  }

  public function getUserGitLog(int $userId, int $limit = 10): array {
    $stmt = $this->pdo->prepare("SELECT commit_hash, parent_hash, branch_name, message, created_at FROM git_commits WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :limit");
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  public function getTagsForEncode(): array {
    $stmt = $this->pdo->query("SELECT col2_number_seq, '[' || col1_code || ']' AS wrapped_code FROM tag_index ORDER BY col3_num_length DESC, id ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  public function getTagsForDecode(): array {
    $stmt = $this->pdo->query("SELECT '[' || col1_code || ']' AS wrapped_code, col2_number_seq FROM tag_index ORDER BY col3_num_length ASC, id ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
}

// =============================================================================í
// CLASS 2: ENCODER LAYER
// =============================================================================
class TagEncoder {

  private DatabaseManager $dbManager;

  public function __construct(DatabaseManager $dbManager) {
    $this->dbManager = $dbManager;
  }

  public function dec2bin(string $text): string {
    return bin2hex($text);
  }

  public function bin2dec(string $hex): string {
    return ($hex === '') ? '' : (hex2bin($hex) ?: '');
  }

  public function calculateHash(string $text): string {
    return md5($text);
  }

  public function encode(string $text): array {
    if ($text === '') {
      return ["hash" => md5(''), "encode" => ''];
    }

    $hash = $this->calculateHash($text);
    $hexString = $this->dec2bin($text);

    $tags = $this->dbManager->getTagsForEncode();
    $searchNumbers = array_column($tags, 'col2_number_seq');
    $replaceCodes = array_column($tags, 'wrapped_code');

    $encodedString = str_replace($searchNumbers, $replaceCodes, $hexString);

    return ["hash" => $hash, "encode" => $encodedString];
  }

  public function decode(string $encodedStream, int $userId): string {
    if ($encodedStream === '') return '';

    $streamWithFiles = $this->replaceFileTags($encodedStream, $userId);

    $tags = $this->dbManager->getTagsForDecode();
    $searchCodes  = array_column($tags, 'wrapped_code');
    $replaceNumbers = array_column($tags, 'col2_number_seq');

    $hexString = str_replace($searchCodes, $replaceNumbers, $streamWithFiles);

    return $this->bin2dec($hexString);
  }

  private function replaceFileTags(string $encodedStream, int $userId): string {
    if (preg_match_all('/\[(\d+)\]/', $encodedStream, $fileMatches)) {
      $fileIds = array_unique($fileMatches[1]);
      if (empty($fileIds)) return $encodedStream;

      $zipMap = $this->dbManager->getZipIndexMap($fileIds, $userId);

      $searchFileTags = [];
      $fileContents  = [];

      foreach ($fileIds as $id) {
        $searchFileTags[] = '[' . $id . ']';
        $content = '';
        if (isset($zipMap[$id])) {
          $content = $this->dbManager->fetchStreamData($zipMap[$id]);
        }
        $fileContents[] = $content;
      }

      return str_replace($searchFileTags, $fileContents, $encodedStream);
    }
    return $encodedStream;
  }
}

// =============================================================================
// CLASS 3: MAIN DATA PROCESSOR
// =============================================================================
class MainDataProcessor {

  private DatabaseManager $db;
  private TagEncoder $encoder;

  public function __construct(DatabaseManager $db, TagEncoder $encoder) {
    $this->db = $db;
    $this->encoder = $encoder;
  }

  public function handleIncomingData(string $rawText): array {
    return $this->encoder->encode($rawText);
  }

  public function registerAndCommitChunk(
    int $userId, int $fileId, string $zipName, string $innerFile,
    ?string $hostUrl = null, int $lineStart = 0, int $lineLimit = 0,
    int $fileSize = 0, string $commitMessage = "Auto commit chunk"
  ): string {
    $this->db->registerZipChunk($userId, $fileId, $zipName, $innerFile, $hostUrl, $lineStart, $lineLimit, $fileSize);
    return $this->db->commitGitState($userId, [$fileId], $commitMessage);
  }

  public function handleDecoding(string $encodedStream, int $userId): string {
    return $this->encoder->decode($encodedStream, $userId);
  }
}

// =============================================================================
// FRONT CONTROLLER & HYBRID ROUTER (REQUEST HANDLING)
// =============================================================================

$dbManager = new DatabaseManager($dbPath, $storageDir);
$tagEncoder = new TagEncoder($dbManager);
$processor = new MainDataProcessor($dbManager, $tagEncoder);

$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true) ?: [];
$request = array_merge($_GET, $_POST, $jsonData);

// -----------------------------------------------------------------------------
// 1. GRID STREAM - RECEIVE DATA ROUTE
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sequence'])) {
  header('Content-Type: application/json; charset=utf-8');
  
  $clientIp = get_client_ip();
  $subnet  = get_subnet($clientIp);

  if (!$dbManager->checkDdosProtection($clientIp)) {
    echo json_encode(["status" => "error", "message" => "DDoS Protection Triggered: Too many requests from IP/Subnet."]);
    exit;
  }

  $seq   = (int)$_POST['sequence'];
  $bset   = (int)$_POST['block_set'];
  $bgrp   = (int)$_POST['block_group'];
  $bchk   = (int)$_POST['block_chunk'];
  $payload = trim($_POST['payload']);
  $hkey   = isset($_POST['header_key']) ? trim($_POST['header_key']) : 'KEY_' . $seq;
  $filename = isset($_POST['filename']) ? trim($_POST['filename']) : 'stream_file_' . $bset . '.dat';
  $lineNum = isset($_POST['line_num']) ? (int)$_POST['line_num'] : 1;
  $location = isset($_POST['location']) ? trim($_POST['location']) : 'GRID_ZONE_' . $bset;
  $time   = time();

  if ($seq >= 0 && $payload !== '') {
    $fb_hash  = generate_front_back_hash($payload);
    $full_hash = generate_full_packet_hash($payload);

    // Check Duplicate Index
    $stmtCheck = $dbManager->getPdo()->prepare("SELECT id, full_packet_hash, sequence FROM block_packets WHERE front_back_hash = :fb_hash");
    $stmtCheck->execute([':fb_hash' => $fb_hash]);
    while ($row = $stmtCheck->fetch(PDO::FETCH_ASSOC)) {
      if ($row['full_packet_hash'] === $full_hash) {
        echo json_encode([
          "status" => "duplicate", 
          "message" => "Data already exists in Index (Matched Seq: {$row['sequence']})",
          "matched_sequence" => $row['sequence']
        ]);
        exit;
      }
    }

    $bufferFile = $bufferDir . "/buffer_set_{$bset}_group_{$bgrp}.ndjson";
    $packet = json_encode([
      'sequence' => $seq, 'block_set' => $bset, 'block_group' => $bgrp, 'block_chunk' => $bchk,
      'header_key' => $hkey, 'filename' => $filename, 'payload' => $payload, 'line_num' => $lineNum,
      'location' => $location, 'client_ip' => $clientIp, 'subnet' => $subnet,
      'front_back_hash' => $fb_hash, 'full_packet_hash' => $full_hash, 'created_at' => $time
    ]) . "\n";

    if (@file_put_contents($bufferFile, $packet, FILE_APPEND | LOCK_EX) !== false) {
      $lineCount = 0;
      if (file_exists($bufferFile)) {
        $lineCount = iterator_count(new SplFileObject($bufferFile, 'r'));
      }

      $next_set = $bset;
      $next_grp = $bgrp;
      if ($lineCount >= 240) { 
        $next_grp++;
        if ($next_grp >= 10) { $next_grp = 0; $next_set++; }
        $nextBufferFile = $bufferDir . "/buffer_set_{$next_set}_group_{$next_grp}.ndjson";
        if (!file_exists($nextBufferFile)) {
          @file_put_contents($nextBufferFile, "", LOCK_EX);
        }
      }

      echo json_encode([
        "status" => "success", "sequence" => $seq, "subnet" => $subnet,
        "buffered_count" => $lineCount, "auto_scaled_next" => "set_{$next_set}_group_{$next_grp}"
      ]);
    } else {
      echo json_encode(["status" => "error", "message" => "Stream buffer write failed"]);
    }
  } else {
    echo json_encode(["status" => "error", "message" => "Invalid data"]);
  }
  exit;
}

// -----------------------------------------------------------------------------
// 2. GRID STREAM - COMMIT ROUTE
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'commit_set') {
  header('Content-Type: application/json; charset=utf-8');
  $bset = (int)$_POST['set'];
  $bgrp = (int)$_POST['group'];
  $bufferFile = $bufferDir . "/buffer_set_{$bset}_group_{$bgrp}.ndjson";

  if (file_exists($bufferFile) && filesize($bufferFile) > 0) {
    try {
      $pdo = $dbManager->getPdo();
      $pdo->beginTransaction();
      $stmt = $pdo->prepare("INSERT OR REPLACE INTO block_packets (sequence, block_set, block_group, block_chunk, header_key, filename, payload, line_num, location, client_ip, subnet, front_back_hash, full_packet_hash, is_last, created_at) VALUES (:seq, :bset, :bgrp, :bchk, :hkey, :filename, :payload, :line_num, :location, :client_ip, :subnet, :fb_hash, :full_hash, 1, :time)");

      $handle = fopen($bufferFile, "r");
      if ($handle) {
        while (($line = fgets($handle)) !== false) {
          $data = json_decode(trim($line), true);
          if ($data) {
            $fb  = $data['front_back_hash'] ?? generate_front_back_hash($data['payload']);
            $full = $data['full_packet_hash'] ?? generate_full_packet_hash($data['payload']);

            $stmt->execute([
              ':seq' => $data['sequence'], ':bset' => $data['block_set'], ':bgrp' => $data['block_group'],
              ':bchk' => $data['block_chunk'], ':hkey' => $data['header_key'], ':filename' => $data['filename'],
              ':payload' => $data['payload'], ':line_num' => $data['line_num'], ':location' => $data['location'],
              ':client_ip' => $data['client_ip'], ':subnet' => $data['subnet'], ':fb_hash' => $fb,
              ':full_hash' => $full, ':time' => $data['created_at']
            ]);
          }
        }
        fclose($handle);
      }

      $pdo->commit();
      @unlink($bufferFile);
      echo json_encode(["status" => "success", "message" => "Committed successfully with Dual-Layer Hash & IP Subnet Tracked"]);
    } catch (Exception $e) {
      if ($dbManager->getPdo()->inTransaction()) $dbManager->getPdo()->rollBack();
      echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
  } else {
    echo json_encode(["status" => "success", "message" => "No buffer data to commit"]);
  }
  exit;
}

// -----------------------------------------------------------------------------
// 3. API CONTROLLER ROUTE (ACTIONS: encode_store, decode_fetch, etc.)
// -----------------------------------------------------------------------------
if (isset($request['action']) && in_array(strtolower($request['action']), ['encode_store', 'decode_fetch', 'register_chunk', 'git_log', 'user_info'])) {
  header('Content-Type: application/json; charset=utf-8');

  $headers = array_change_key_case(getallheaders(), CASE_LOWER);
  $apiKey = $headers['x-api-key'] ?? ($request['api_key'] ?? '');

  if (empty($apiKey)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: ไม่พบ API Key ในคำขอ'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $currentUser = $dbManager->authenticateUser($apiKey);
  if (!$currentUser) {
    echo json_encode(['success' => false, 'message' => 'Forbidden: API Key ไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $action = strtolower($request['action']);

  try {
    switch ($action) {
      case 'encode_store':
        $textToEncode = $request['data'] ?? '';
        if ($textToEncode === '') {
          echo json_encode(['success' => false, 'message' => 'Bad Request: ไม่พบข้อมูล'], JSON_UNESCAPED_UNICODE);
          exit;
        }
        $result = $processor->handleIncomingData($textToEncode);
        echo json_encode([
          'success' => true,
          'message' => 'เข้ารหัสข้อมูลสำเร็จ',
          'data'  => ['user' => $currentUser['username'], 'hash' => $result['hash'], 'encode' => $result['encode']]
        ], JSON_UNESCAPED_UNICODE);
        break;

      case 'decode_fetch':
        $encodedStream = $request['stream'] ?? '';
        if ($encodedStream === '') {
          echo json_encode(['success' => false, 'message' => 'Bad Request: ไม่พบ Stream'], JSON_UNESCAPED_UNICODE);
          exit;
        }
        $decodedText = $processor->handleDecoding($encodedStream, (int)$currentUser['user_id']);
        echo json_encode([
          'success' => true,
          'message' => 'ถอดรหัสและดึงข้อมูล Stream สำเร็จ',
          'data'  => ['user' => $currentUser['username'], 'decoded_data' => $decodedText]
        ], JSON_UNESCAPED_UNICODE);
        break;

       case 'register_chunk':
        $fileId  = (int)($request['file_id'] ?? 0);
        $zipName  = $request['zip_name'] ?? '';
        $innerFile = $request['inner_file'] ?? '';
        $hostUrl  = $request['host_url'] ?? null;
        $lineStart = (int)($request['line_start'] ?? 0);
        $lineLimit = (int)($request['line_limit'] ?? 0);
        $fileSize = (int)($request['file_size'] ?? 0);
        $msg    = $request['message'] ?? 'Register file chunk';

        if ($fileId <= 0 || empty($zipName) || empty($innerFile)) {
          echo json_encode(['success' => false, 'message' => 'Bad Request: พารามิเตอร์ไม่ครบถ้วน'], JSON_UNESCAPED_UNICODE);
          exit;
        }

        // แก้ไขจุดนี้: เปลี่ยนจาก $data เป็น $request และเรียงลำดับตัวแปรให้ตรงกับ Method ต้นทาง
        $commitHash = $processor->registerAndCommitChunk(
          $currentUser['user_id'], // พารามิเตอร์ที่ 1: $userId
          $fileId,                 // พารามิเตอร์ที่ 2: $fileId
          $zipName,                // พารามิเตอร์ที่ 3: $zipName
          $innerFile,              // พารามิเตอร์ที่ 4: $innerFile
          $hostUrl,                // พารามิเตอร์ที่ 5: $hostUrl
          $lineStart,              // พารามิเตอร์ที่ 6: $lineStart
          $lineLimit,              // พารามิเตอร์ที่ 7: $lineLimit
          $fileSize,               // พารามิเตอร์ที่ 8: $fileSize
          $msg                     // พารามิเตอร์ที่ 9: $commitMessage
        );

        echo json_encode([
          'success' => true,
          'message' => 'ลงทะเบียน Chunk และบันทึก Git Commit สำเร็จ',
          'data'  => ['file_id' => $fileId, 'commit_hash' => $commitHash]
        ], JSON_UNESCAPED_UNICODE);
        break;


      case 'git_log':
        $logs = $dbManager->getUserGitLog((int)$currentUser['user_id']);
        echo json_encode([
          'success' => true, 'message' => 'ดึงประวัติ Commit สำเร็จ',
          'data'  => ['user' => $currentUser['username'], 'logs' => $logs]
        ], JSON_UNESCAPED_UNICODE);
        break;

      case 'user_info':
        echo json_encode([
          'success' => true, 'message' => 'ดึงข้อมูลผู้ใช้สำเร็จ',
          'data'  => [
            'username'  => $currentUser['username'],
            'role'    => $currentUser['role'],
            'quota_bytes' => (int)$currentUser['quota_bytes'],
            'used_bytes' => (int)$currentUser['used_bytes'],
            'usage_pct'  => round(($currentUser['used_bytes'] / $currentUser['quota_bytes']) * 100, 2) . '%'
          ]
        ], JSON_UNESCAPED_UNICODE);
        break;
    }
  } catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

// -----------------------------------------------------------------------------
// 4. WEB DASHBOARD VIEW ROUTE (RENDER HTML GRID INTERFACE)
// -----------------------------------------------------------------------------
$current_set = isset($_GET['set']) ? max(0, (int)$_GET['set']) : 0;
$current_group = isset($_GET['group']) ? max(0, (int)$_GET['group']) : 0;
$current_client_ip = get_client_ip();
$current_subnet = get_subnet($current_client_ip);

$saved_chunks = [];

try {
  $stmt = $dbManager->getPdo()->prepare("SELECT block_chunk FROM block_packets WHERE block_set = :set AND block_group = :group");
  $stmt->execute([':set' => $current_set, ':group' => $current_group]);
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $saved_chunks[$row['block_chunk']] = true;
  }
} catch (Exception $e) {}

$bufferFile = $bufferDir . "/buffer_set_{$current_set}_group_{$current_group}.ndjson";
$has_buffer = false;
if (file_exists($bufferFile)) {
  $handle = fopen($bufferFile, "r");
  if ($handle) {
    while (($line = fgets($handle)) !== false) {
      $data = json_decode(trim($line), true);
      if ($data) {
        $has_buffer = true;
        $saved_chunks[$data['block_chunk']] = 'buffered';
      }
    }
    fclose($handle);
  }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Unified Auto-Scaling Stream Grid & High-Level Storage Engine</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background-color: #0f172a; color: #f8fafc;
      display: flex; flex-direction: column; align-items: center; padding: 15px; min-height: 100vh;
    }
    header {
      width: 100%; max-width: 1100px; display: flex; justify-content: space-between; align-items: center;
      margin-bottom: 15px; background-color: #1e293b; padding: 12px 20px; border-radius: 8px; border: 1px solid #334155;
    }
    h1 { font-size: 1.2rem; color: #38bdf8; }
    .nav-controls { display: flex; gap: 10px; align-items: center; font-size: 0.85rem; }
    select, button {
      background-color: #0f172a; color: #f8fafc; border: 1px solid #475569;
      padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 0.85rem;
    }
    button.action-btn { background-color: #0284c7; border: none; font-weight: bold; }
    button.action-btn:hover { background-color: #0369a1; }
    button.commit-btn { background-color: #10b981; border: none; font-weight: bold; }
    button.commit-btn:hover { background-color: #059669; }
    .grid-wrapper {
      width: 100%; max-width: 1100px; height: 65vh; overflow: auto;
      border: 2px solid #334155; border-radius: 8px; background-color: #1e293b; padding: 10px;
    }
    .grid-container {
      display: grid; grid-template-columns: repeat(256, 14px); grid-auto-rows: 14px; gap: 2px; width: max-content;
    }
    .cell {
      background-color: #334155; border-radius: 50%; position: relative; display: flex; align-items: center; justify-content: center;
    }
    .cell input {
      position: absolute; width: 100%; height: 100%; border: none; background: transparent;
      text-align: center; font-size: 7px; color: #f8fafc; outline: none; border-radius: 50%; cursor: text;
    }
    .cell.locked { background-color: #ef4444; font-size: 7px; cursor: not-allowed; }
    .cell.buffered { background-color: #f59e0b; font-size: 7px; cursor: not-allowed; }
    .footer-info { margin-top: 10px; font-size: 0.8rem; color: #94a3b8; text-align: center; display: flex; gap: 15px; }
  </style>
</head>
<body>

  <header>
    <div>
      <h1>🛡️ Unified Grid Engine (IP Subnet + Dual-Layer Hash + DDoS Shield)</h1>
      <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 2px;">ตรวจเช็คไอพี/ซับเน็ต | ตรวจสอบไฟล์ซ้ำจาก Index | รองรับ Unified API Endpoint</div>
    </div>
    <div class="nav-controls">
      <form method="GET" action="" style="display: flex; gap: 8px; align-items: center;">
        <label>Set:</label>
        <select name="set" onchange="this.form.submit()">
          <?php for($s=0; $s<10; $s++): ?>
            <option value="<?= $s ?>" <?= ($current_set == $s) ? 'selected' : '' ?>><?= $s ?></option>
          <?php endfor; ?>
        </select>
        <label>Group:</label>
        <select name="group" onchange="this.form.submit()">
          <?php for($g=0; $g<10; $g++): ?>
            <option value="<?= $g ?>" <?= ($current_group == $g) ? 'selected' : '' ?>><?= $g ?></option>
          <?php endfor; ?>
        </select>
      </form>
      <button class="action-btn" id="simBtn">⚡ เทสสตรีมลูป</button>
      <?php if ($has_buffer): ?>
        <button class="commit-btn" id="commitBtn">💾 Commit ลง SQL</button>
      <?php endif; ?>
    </div>
  </header>

  <div class="grid-wrapper">
    <div class="grid-container" id="gridContainer">
      <?php
      for ($chunk = 0; $chunk < GRID_SIZE; $chunk++) {
        $sequence = ($current_set * 65536) + ($current_group * 256) + $chunk;

        if (isset($saved_chunks[$chunk])) {
          if ($saved_chunks[$chunk] === 'buffered') {
            echo '<div class="cell buffered" title="Seq: ' . $sequence . ' (รอ Commit)">⏳</div>';
          } else {
            echo '<div class="cell locked" title="Seq: ' . $sequence . ' (บันทึกแล้ว)">🔒</div>';
          }
        } else {
          echo '<div class="cell" title="Seq: ' . $sequence . '">';
          echo '<input type="text" maxlength="1" data-seq="' . $sequence . '" data-set="' . $current_set . '" data-group="' . $current_group . '" data-chunk="' . $chunk . '">';
          echo '</div>';
        }
      }
      ?>
    </div>
  </div>

  <div class="footer-info">
    <div>พิกัดหน้าปัจจุบัน: <strong>Set <?= $current_set ?></strong> / <strong>Group <?= $current_group ?></strong></div>
    <div>IP ล่าสุด: <strong style="color: #38bdf8;"><?= $current_client_ip ?></strong></div>
    <div>Subnet: <strong style="color: #34d399;"><?= $current_subnet ?></strong></div>
    <?php if ($has_buffer): ?>
      <div style="color: #f59e0b;">(มีสตรีมค้างใน Buffer รอ Commit)</div>
    <?php endif; ?>
  </div>

  <script>
    const container = document.getElementById('gridContainer');

    container.addEventListener('change', function(e) {
      if (e.target.tagName === 'INPUT') {
        const input = e.target;
        const cell = input.parentElement;
        const formData = new URLSearchParams();
        formData.append('sequence', input.getAttribute('data-seq'));
        formData.append('block_set', input.getAttribute('data-set'));
        formData.append('block_group', input.getAttribute('data-group'));
        formData.append('block_chunk', input.getAttribute('data-chunk'));
        formData.append('payload', input.value.trim());
        formData.append('filename', 'manual_grid_input.dat');
        formData.append('line_num', 1);
        formData.append('location', 'ZONE_SET_' + input.getAttribute('data-set'));
        formData.append('header_key', 'INPUT_KEY_' + input.getAttribute('data-seq'));

        fetch(window.location.href, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: formData.toString()
        })
        .then(res => res.json())
        .then(data => {
          if (data.status === 'success') {
            cell.className = 'cell buffered';
            cell.innerHTML = '⏳';
          } else if (data.status === 'duplicate') {
            alert('⚠️ ข้อมูลซ้ำในระบบ Index: ' + data.message);
            input.value = '';
          } else if (data.status === 'error') {
            alert('❌ ' + data.message);
            input.value = '';
          }
        });
      }
    });

    document.getElementById('simBtn')?.addEventListener('click', function() {
      const randomChunk = Math.floor(Math.random() * 256);
      const currentSet = <?= $current_set ?>;
      const currentGroup = <?= $current_group ?>;
      const seq = (currentSet * 65536) + (currentGroup * 256) + randomChunk;

      const sampleValues = [
        { "v1": "ø", "v2": "æ", "id": 101, "tag": "secure_stream_packet_alpha_90s_rs_music_track" },
        { "v1": "ß", "v2": "×", "id": 0, "tag": "secure_stream_packet_beta_90s_rs_music_track" }
      ];

      const formData = new URLSearchParams();
      formData.append('sequence', seq);
      formData.append('block_set', currentSet);
      formData.append('block_group', currentGroup);
      formData.append('block_chunk', randomChunk);
      formData.append('filename', 'simulated_stream_source.dat');
      formData.append('payload', JSON.stringify(sampleValues));
      formData.append('line_num', randomChunk + 1);
      formData.append('location', 'LOC_GRID_' + currentSet + '_' + currentGroup);
      formData.append('header_key', 'SIM_LOOP_' + seq);

      fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
      })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'success') { 
          location.reload(); 
        } else if (data.status === 'duplicate') {
          alert('⚠️ ตรวจพบข้อมูลซ้ำผ่าน Index (ป้องกันสำเร็จ): ' + data.message);
        } else if (data.status === 'error') {
          alert('❌ ' + data.message);
        }
      });
    });

    document.getElementById('commitBtn')?.addEventListener('click', function() {
      if (confirm('ยืนยันบันทึกข้อมูลสตรีมทั้งหมดของเซ็ตนี้ลงฐานข้อมูล SQLite พร้อม Index ครบถ้วน?')) {
        const formData = new URLSearchParams();
        formData.append('action', 'commit_set');
        formData.append('set', <?= $current_set ?>);
        formData.append('group', <?= $current_group ?>);

        fetch(window.location.href, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: formData.toString()
        })
        .then(res => res.json())
        .then(data => {
          if (data.status === 'success') {
            alert('คอมมิตข้อมูลสำเร็จ!');
            location.reload();
          }
        });
      }
    });
  </script>
</body>
</html>
