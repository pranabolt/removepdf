<?php
/* ===========================================================================
   Remove Password from PDF — File Handler Functions
*/

function parse_ini_bytes(string $val): int {
  $val = trim($val);
  if ($val === '') return 0;
  $unit = strtolower(substr($val, -1));
  $num  = (int)$val;
  return match($unit) {
    'g' => $num * 1024 * 1024 * 1024,
    'm' => $num * 1024 * 1024,
    'k' => $num * 1024,
    default => (int)$val,
  };
}

function effective_upload_limit_bytes(): int {
  $upload = parse_ini_bytes((string)ini_get('upload_max_filesize'));
  $post   = parse_ini_bytes((string)ini_get('post_max_size'));
  $mem    = parse_ini_bytes((string)ini_get('memory_limit'));
  $candidates = array_filter([$upload, $post, $mem], fn($v) => $v > 0);
  $min = $candidates ? min($candidates) : MAX_SIZE_BYTES;
  return min($min, MAX_SIZE_BYTES);
}