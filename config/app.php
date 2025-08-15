<?php
/* ===========================================================================
   Remove Password from PDF — Configuration
*/

declare(strict_types=1);

// ---------------------------- CONFIG ----------------------------------------
const MAX_SIZE_BYTES = 1024 * 1024 * 1024;      // 1 GB soft cap (server/browser may still limit)
const FREE_MAX_SIZE_BYTES = MAX_SIZE_BYTES;     // Free = full limit for now
const PRO_MAX_SIZE_BYTES  = MAX_SIZE_BYTES;     // Same as free (future use)
const FILE_TTL_SEC   = 3600;                    // 1 hour
const STORAGE_DIR    = __DIR__ . '/../_storage';   // auto-created: _storage/tmp, _storage/out
const HOST_ALLOWLIST = [];                      // e.g. ['remove-password-from-pdf.com'] leave [] to allow any host
// -----------------------------------------------------------------------------

// Production-safe defaults
@ini_set('display_errors', '0');
@ini_set('expose_php', '0');

// Ensure storage folders exist
@mkdir(STORAGE_DIR, 0775, true);
@mkdir(STORAGE_DIR . '/tmp', 0775, true);
@mkdir(STORAGE_DIR . '/out', 0775, true);

// Opportunistic cleanup on every request (cron-less)
(function () {
    $dirs = [STORAGE_DIR . '/tmp', STORAGE_DIR . '/out'];
    $now  = time();
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;
        foreach (@scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            if (is_file($p) && ($now - @filemtime($p) > FILE_TTL_SEC)) @unlink($p);
        }
    }
})();