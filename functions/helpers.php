<?php
/* ===========================================================================
   Remove Password from PDF — Helper Functions
*/

// Helpers
function json_fail(string $msg, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

function ok_host(): bool {
  // Allow override via environment: HOST_ALLOWLIST="example.com,www.example.com"
  $env = get_env('HOST_ALLOWLIST', '');
  $hosts = array_filter(array_map('trim', explode(',', (string)$env)));
  $allow = $hosts ?: (HOST_ALLOWLIST ?? []);
  if (!$allow) return true;
  $host = $_SERVER['HTTP_HOST'] ?? '';
  return in_array($host, $allow, true);
}

function has_bin(string $bin): bool {
    $out = @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
    return is_string($out) && trim($out) !== '';
}

function site_url(string $path): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/' . ltrim($path, '/');
}

function canonical_url(): string {
  // Prefer CANONICAL_HOST env if provided (e.g., example.com)
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $hostEnv = get_env('CANONICAL_HOST');
  $hostReq = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $host = $hostEnv ?: $hostReq;
  $uri = $_SERVER['REQUEST_URI'] ?? '/';
  // strip query string for canonical
  $path = parse_url($uri, PHP_URL_PATH) ?: '/';
  return $scheme . '://' . $host . $path;
}

function is_pro(): bool {
  return ($_SESSION['plan'] ?? 'free') === 'pro';
}

function flash_set(string $type, string $msg): void {
  $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flash_get(): array {
  $f = $_SESSION['flash'] ?? [];
  unset($_SESSION['flash']);
  return $f;
}

function get_env(string $key, ?string $default = null): ?string {
  $v = getenv($key);
  if ($v === false || $v === '') return $default;
  return $v;
}