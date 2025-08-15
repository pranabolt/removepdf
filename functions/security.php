<?php
/* ===========================================================================
   Remove Password from PDF — Security Functions
*/

function send_common_headers(): void {
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: DENY');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  // CSP allows our own page plus Tailwind CDN and Alpine CDN; inline is used for small scripts/styles
  header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://unpkg.com; connect-src 'self'; base-uri 'self'; form-action 'self'");
}

function csrf_token(): string {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
  }
  return (string)$_SESSION['csrf'];
}

function rate_limit_check(string $bucket, int $limit, int $windowSec): bool {
  $dir = STORAGE_DIR . '/rate';
  @mkdir($dir, 0775, true);
  $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
  $key = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $bucket . '_' . $ip);
  $file = $dir . '/' . $key . '.json';
  $now = time();
  $data = ['start' => $now, 'count' => 0];
  $fp = @fopen($file, 'c+');
  if (!$fp) return true; // fail-open to avoid blocking legit users
  @flock($fp, LOCK_EX);
  $raw = stream_get_contents($fp);
  if ($raw) {
    $tmp = json_decode($raw, true);
    if (is_array($tmp) && isset($tmp['start'], $tmp['count'])) $data = $tmp;
  }
  if ($now - (int)$data['start'] > $windowSec) {
    $data = ['start' => $now, 'count' => 0];
  }
  $data['count'] = (int)$data['count'] + 1;
  $ok = $data['count'] <= $limit;
  ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($data)); fflush($fp); @flock($fp, LOCK_UN); fclose($fp);
  return $ok;
}