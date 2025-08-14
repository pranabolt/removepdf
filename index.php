<?php
/* ===========================================================================
   Remove Password from PDF — Single-file app (index.php)
   Stack: PHP 8+, Tailwind CDN, Alpine.js
   Processing: qpdf (preferred) or Ghostscript (fallback)
   ---------------------------------------------------------------------------
   QUICK START
   - Upload this file as public/index.php
   - Ensure PHP can run shell_exec and either `qpdf` or `gs` is installed.
   - Make sure the web user can write to STORAGE_DIR (created automatically).
   =========================================================================== */

declare(strict_types=1);
session_start();

// ---------------------------- CONFIG ----------------------------------------
const MAX_SIZE_BYTES = 1024 * 1024 * 1024;      // 1 GB soft cap (server/browser may still limit)
const FREE_MAX_SIZE_BYTES = MAX_SIZE_BYTES;     // Free = full limit for now
const PRO_MAX_SIZE_BYTES  = MAX_SIZE_BYTES;     // Same as free (future use)
const FILE_TTL_SEC   = 3600;                    // 1 hour
const STORAGE_DIR    = __DIR__ . '/_storage';   // auto-created: _storage/tmp, _storage/out
const HOST_ALLOWLIST = [];                      // e.g. ['remove-password-from-pdf.com'] leave [] to allow any host
// -----------------------------------------------------------------------------

// Production-safe defaults
@ini_set('display_errors', '0');
@ini_set('expose_php', '0');

function send_common_headers(): void {
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: DENY');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  // CSP allows our own page plus Tailwind CDN and Alpine CDN; inline is used for small scripts/styles
  header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://unpkg.com; connect-src 'self'; base-uri 'self'; form-action 'self'");
}

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

// Helpers
function json_fail(string $msg, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}
function ok_host(): bool {
    if (!HOST_ALLOWLIST) return true;
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return in_array($host, HOST_ALLOWLIST, true);
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

function csrf_token(): string {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
  }
  return (string)$_SESSION['csrf'];
}

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

// Simple blog content (SEO-friendly, no DB)
$BLOG_POSTS = [
  [
    'slug' => 'why-a-paid-pdf-unlocker-protects-your-privacy',
    'title' => 'Why a Paid PDF Unlocker Protects Your Privacy',
    'description' => 'Free sites often monetize with tracking or unclear data practices. Here’s why paying for privacy is worth it.',
    'date' => '2025-08-01',
  'content' => '<p>"Free" often hides costs: ads, trackers, and unclear data retention. When documents matter, privacy should not be an afterthought. A paid service aligns incentives with you—the customer—not advertisers.</p>
         <p>We process files briefly, never store your password, and auto-delete uploads within ~1 hour.</p>
         <h2 class="text-lg font-semibold mt-6">Paying for certainty</h2>
         <p>Predictable limits and transparent deletion policies mean you know exactly what happens to your files.</p>
         <p class="mt-4">Need to unlock your own PDF now? Try <a class="text-indigo-700 hover:underline" href="index.php">RemovePasswordfromPDF.com</a>.</p>'
  ],
  [
    'slug' => 'how-we-handle-your-files-and-passwords',
    'title' => 'How We Handle Your Files and Passwords',
    'description' => 'A clear, plain-English explanation of our short-lived processing and deletion timeline.',
    'date' => '2025-08-05',
  'content' => '<p>Your PDF and password are used only to unlock your file. The password is never stored and is discarded immediately after processing. Files are auto-deleted within ~1 hour.</p>
         <p>For a quick unlock, use <a class="text-indigo-700 hover:underline" href="index.php">RemovePasswordfromPDF.com</a>.</p>'
  ],
  // New long-form SEO posts
  [
    'slug' => 'remove-password-from-pdf-complete-guide',
    'title' => 'Remove Password from PDF: Complete Guide',
    'description' => 'Everything you need to know to remove password protection from your own PDFs safely and quickly.',
    'date' => '2025-08-11',
  'content' => '<p>If you are searching for “Remove Password from PDF,” this guide explains the safest and fastest way to unlock a PDF you already own. When you know the correct password, you can remove it and save a clean copy so you don’t have to type the password each time.</p>
                 <h2 class="text-lg font-semibold mt-6">When it makes sense to remove a password</h2>
                 <ul class="list-disc pl-6 text-slate-700">
                   <li>You regularly access a document and want faster, offline access.</li>
                   <li>You need to share the file internally without burdening teammates with a password.</li>
                   <li>You want to archive a copy that opens instantly while preserving the original, secured version elsewhere.</li>
                 </ul>
                 <h2 class="text-lg font-semibold mt-6">How it works here</h2>
                 <p>Upload your PDF, enter the correct password, and download the unlocked file. Your password is used only during processing and is not stored. Uploads are automatically deleted after about an hour.</p>
                 <h2 class="text-lg font-semibold mt-6">Tips for best results</h2>
                 <ol class="list-decimal pl-6 text-slate-700">
                   <li>Verify you have the right to remove protection. Only unlock documents you own or are authorized to modify.</li>
                   <li>Use the smallest file that meets your needs. If the PDF is huge, consider optimizing it first.</li>
                   <li>Keep a backup of the original, password-protected copy for safekeeping.</li>
                 </ol>
                 <p class="mt-4">Ready to go? Open <a class="text-indigo-700 hover:underline" href="index.php">RemovePasswordfromPDF.com</a> and unlock your PDF now.</p>'
  ],
  [
    'slug' => 'how-to-remove-password-from-pdf-step-by-step',
    'title' => 'How to Remove Password from PDF (Step‑by‑Step)',
    'description' => 'A simple, step‑by‑step walkthrough to remove a password from a PDF you own.',
    'date' => '2025-08-12',
  'content' => '<p>Wondering how to remove password from PDF? If you already know the password, the process is straightforward. Follow these steps to create an unlocked copy of your document.</p>
                 <h2 class="text-lg font-semibold mt-6">Step‑by‑step</h2>
                 <ol class="list-decimal pl-6 text-slate-700">
           <li>Open <a class="text-indigo-700 hover:underline" href="index.php">RemovePasswordfromPDF.com</a>.</li>
                   <li>Choose your PDF (drag & drop works too).</li>
                   <li>Enter the correct password when prompted.</li>
                   <li>Click “Unlock PDF” and wait a moment.</li>
                   <li>Download your clean, unlocked copy.</li>
                 </ol>
                 <h2 class="text-lg font-semibold mt-6">Why this method</h2>
                 <p>It’s fast, private, and requires no installation. We use your password only to unlock the file during processing, then discard it. Uploads are auto‑deleted within about an hour.</p>
                 <h2 class="text-lg font-semibold mt-6">Common issues</h2>
                 <ul class="list-disc pl-6 text-slate-700">
                   <li><strong>Wrong password:</strong> Double‑check capitalization and keyboard layout.</li>
                   <li><strong>Large files:</strong> The Free plan allows up to 5 MB; upgrade for larger files.</li>
                   <li><strong>Corruption:</strong> If the file won’t open even with the password, try re‑downloading the original.</li>
                 </ul>'
  ],
  [
    'slug' => 'remove-password-from-pdf-online-safely',
    'title' => 'Remove Password from PDF Online Safely',
    'description' => 'How to remove password from PDF online while keeping privacy and speed top of mind.',
    'date' => '2025-08-13',
  'content' => '<p>Searching for “remove password from pdf” usually returns a mix of tools. The safest approach is the one that minimizes data exposure and time‑to‑download. Here’s what to look for and how to do it here.</p>
                 <h2 class="text-lg font-semibold mt-6">What to look for</h2>
                 <ul class="list-disc pl-6 text-slate-700">
                   <li>Short‑lived processing and automatic deletion.</li>
                   <li>No storing of your password.</li>
                   <li>Clear limits and simple pricing if you need larger files.</li>
                 </ul>
                 <h2 class="text-lg font-semibold mt-6">Unlock in three steps</h2>
                 <ol class="list-decimal pl-6 text-slate-700">
                   <li>Upload a PDF you own.</li>
                   <li>Enter the correct password.</li>
                   <li>Download the unlocked file—done.</li>
                 </ol>
                 <p class="mt-4">Start now at <a class="text-indigo-700 hover:underline" href="index.php">RemovePasswordfromPDF.com</a>.</p>'
  ],
  [
    'slug' => 'how-do-i-remove-a-password-from-a-pdf',
    'title' => 'How Do I Remove a Password from a PDF?',
    'description' => 'Answers to the most common questions about removing a password from a PDF you already have access to.',
    'date' => '2025-08-14',
  'content' => '<p>“How do I remove a password from a PDF?” If you know the password, it’s simple: unlock once, save an unprotected copy, and skip the prompt next time. Here are quick answers to common questions.</p>
                 <h2 class="text-lg font-semibold mt-6">Do I need special software?</h2>
                 <p>No installation required here. You can unlock a PDF in your browser and download the new copy.</p>
                 <h2 class="text-lg font-semibold mt-6">Is this legal?</h2>
                 <p>Only remove protection from files you own or are authorized to modify. Always follow your local laws and organizational policies.</p>
                 <h2 class="text-lg font-semibold mt-6">Will my password be stored?</h2>
                 <p>Your password is used only during processing to unlock the file and is not stored. Uploads are auto‑deleted after a short period.</p>
                 <h2 class="text-lg font-semibold mt-6">What if I forget the password?</h2>
         <p>We can’t help recover or crack passwords. You must know the correct password to unlock the file.</p>
         <p class="mt-4">Need it done now? Use <a class="text-indigo-700 hover:underline" href="index.php">RemovePasswordfromPDF.com</a> to unlock your PDF in seconds.</p>'
  ],
  [
    'slug' => 'how-to-remove-password-protection-from-pdf',
    'title' => 'How to Remove Password Protection from PDF',
    'description' => 'A practical walkthrough to remove password protection from a PDF you own, plus tips and FAQs.',
    'date' => '2025-08-15',
  'content' => '<p>If you’re asking how to remove password protection from PDF, the core requirement is simple: you must already know the password. Once entered, you can save an unlocked version for faster access.</p>
                 <h2 class="text-lg font-semibold mt-6">Walkthrough</h2>
                 <ol class="list-decimal pl-6 text-slate-700">
                   <li>Open the tool in your browser.</li>
                   <li>Select or drag in your PDF.</li>
                   <li>Type the correct password and confirm.</li>
                   <li>Download the unlocked copy.</li>
                 </ol>
                 <h2 class="text-lg font-semibold mt-6">Good practices</h2>
                 <ul class="list-disc pl-6 text-slate-700">
                   <li>Keep the original protected file in a safe place.</li>
                   <li>Remove sensitive files from shared devices after use.</li>
                   <li>Be mindful of large files on slow connections.</li>
                 </ul>'
  ],
  [
    'slug' => 'how-can-i-remove-password-from-pdf',
    'title' => 'How Can I Remove Password from PDF?',
    'description' => 'Clear steps to remove a password from a PDF you own, including troubleshooting advice and FAQs.',
    'date' => '2025-08-16',
  'content' => '<p>“How can I remove password from pdf?” It’s easy if you have the correct password: upload, unlock, download. Below are steps and quick troubleshooting tips.</p>
                 <h2 class="text-lg font-semibold mt-6">Steps</h2>
                 <ol class="list-decimal pl-6 text-slate-700">
                   <li>Upload your PDF (drag & drop supported).</li>
                   <li>Enter the correct password.</li>
                   <li>Click unlock and download your file.</li>
                 </ol>
                 <h2 class="text-lg font-semibold mt-6">Troubleshooting</h2>
                 <ul class="list-disc pl-6 text-slate-700">
                   <li>If the password fails, check for caps lock and extra spaces.</li>
                   <li>If the PDF is very large, consider compressing it or upgrading your plan.</li>
                   <li>If the original file is damaged, re‑obtain it from the source and try again.</li>
                 </ul>
                 <p class="mt-4">When you’re ready, open <a class="text-indigo-700 hover:underline" href="index.php">RemovePasswordfromPDF.com</a> to remove a PDF password in seconds.</p>'
  ],
  [
    'slug' => 'how-do-i-remove-password-protection-from-pdf',
    'title' => 'How Do I Remove Password Protection from PDF?',
    'description' => 'Direct, step‑by‑step answer with privacy notes for removing password protection from a PDF you own.',
    'date' => '2025-08-17',
  'content' => '<p>If you are asking “how do I remove password protection from PDF,” the key is that you must already know the password. Once entered, you can save an unlocked copy and skip future prompts.</p>
                 <h2 class="text-lg font-semibold mt-6">Quick steps</h2>
                 <ol class="list-decimal pl-6 text-slate-700">
                   <li>Open the tool in your browser.</li>
                   <li>Upload the PDF and enter the correct password.</li>
                   <li>Download the unlocked version.</li>
                 </ol>
                 <h2 class="text-lg font-semibold mt-6">Privacy and limits</h2>
                 <p>We process your file briefly to remove protection, never store your password, and auto‑delete uploads after a short period.</p>
                 <p class="mt-4">Try it now at <a class="text-indigo-700 hover:underline" href="index.php">RemovePasswordfromPDF.com</a>.</p>'
  ],
  [
    'slug' => 'how-to-remove-password-from-pdf-file',
    'title' => 'How to Remove Password from PDF File',
    'description' => 'Clear guide to remove a password from a PDF file you own, with tips for common errors.',
    'date' => '2025-08-18',
  'content' => '<p>Looking for how to remove password from PDF file? If you own the document and know the password, you can unlock it in a few clicks and save a clean copy.</p>
                 <h2 class="text-lg font-semibold mt-6">Guide</h2>
                 <ol class="list-decimal pl-6 text-slate-700">
                   <li>Select your password‑protected PDF.</li>
                   <li>Enter the correct password.</li>
                   <li>Click to unlock and download.</li>
                 </ol>
                 <h2 class="text-lg font-semibold mt-6">Fixing issues</h2>
                 <ul class="list-disc pl-6 text-slate-700">
                   <li>Re‑type the password carefully if you see an error.</li>
                   <li>Try a fresh copy of the PDF if it appears corrupted.</li>
                   <li>Very large files may take longer to upload on slow connections.</li>
                 </ul>
                 <p class="mt-4">Unlock your PDF now at <a class="text-indigo-700 hover:underline" href="index.php">RemovePasswordfromPDF.com</a>.</p>'
  ],
  [
    'slug' => 'how-do-i-remove-a-password-from-a-pdf-file',
    'title' => 'How Do I Remove a Password from a PDF File?',
    'description' => 'Simple steps to remove a password from a PDF file you own, plus FAQs.',
    'date' => '2025-08-19',
  'content' => '<p>“How do I remove a password from a PDF file?” Enter the correct password once, unlock, and save an open copy. Here’s the quick process and answers to common questions.</p>
                 <h2 class="text-lg font-semibold mt-6">Process</h2>
                 <ol class="list-decimal pl-6 text-slate-700">
                   <li>Upload your PDF file.</li>
                   <li>Provide the correct password.</li>
                   <li>Download the unlocked file.</li>
                 </ol>
                 <h2 class="text-lg font-semibold mt-6">FAQs</h2>
                 <ul class="list-disc pl-6 text-slate-700">
                   <li><strong>Can you crack it?</strong> No—you must know the password.</li>
                   <li><strong>Is the password stored?</strong> No, it is used only during processing.</li>
                   <li><strong>How long are files kept?</strong> Uploads are auto‑deleted shortly after processing.</li>
                 </ul>
                 <p class="mt-4">Do it now at <a class="text-indigo-700 hover:underline" href="index.php">RemovePasswordfromPDF.com</a>.</p>'
  ],
  [
    'slug' => 'how-to-remove-password-from-password-protected-pdf',
    'title' => 'How to Remove Password from a Password‑Protected PDF',
    'description' => 'Unlock a PDF you own by entering its password and saving an unprotected copy.',
    'date' => '2025-08-20',
  'content' => '<p>To remove password from a password‑protected PDF, you must have the correct password. Once verified, you can save an unlocked version for faster access.</p>
                 <h2 class="text-lg font-semibold mt-6">Steps</h2>
                 <ol class="list-decimal pl-6 text-slate-700">
                   <li>Upload the protected PDF.</li>
                   <li>Enter the correct password.</li>
                   <li>Download your unlocked copy.</li>
                 </ol>
                 <p class="mt-4">Only remove protection from files you own or are authorized to modify. When ready, head to <a class="text-indigo-700 hover:underline" href="index.php">RemovePasswordfromPDF.com</a>.</p>'
  ],
  [
    'slug' => 'how-to-remove-password-protect-from-pdf',
    'title' => 'Remove Password Protection from a PDF (Quick Guide)',
    'description' => 'A concise guide often searched as “how to remove password protect from pdf.”',
    'date' => '2025-08-21',
  'content' => '<p>Many people search “how to remove password protect from pdf.” The phrasing may vary, but the steps are the same: if you know the password, you can remove protection and save a clean copy.</p>
                 <h2 class="text-lg font-semibold mt-6">Quick guide</h2>
                 <ol class="list-decimal pl-6 text-slate-700">
                   <li>Upload your PDF.</li>
                   <li>Type the correct password.</li>
                   <li>Download the unlocked PDF.</li>
                 </ol>
                 <p class="mt-4">For sensitive documents, delete local copies when finished and keep a secure backup of the original. To unlock now, visit <a class="text-indigo-700 hover:underline" href="index.php">RemovePasswordfromPDF.com</a>.</p>'
  ],
  [
    'slug' => 'how-to-remove-a-password-from-a-pdf-mac-windows-mobile',
    'title' => 'How to Remove a Password from a PDF (Mac, Windows, Mobile)',
    'description' => 'Platform‑specific tips for removing a password from a PDF on desktop and mobile.',
    'date' => '2025-08-22',
  'content' => '<p>Here’s how to remove a password from a PDF whether you are on Mac, Windows, iOS, or Android—always assuming you know the correct password and own the file.</p>
                 <h2 class="text-lg font-semibold mt-6">Mac & Windows</h2>
                 <ul class="list-disc pl-6 text-slate-700">
                   <li>Use the browser‑based tool to upload, authenticate with the password, and download the unlocked copy.</li>
                   <li>Keep both the original (protected) and the unlocked version organized.</li>
                 </ul>
                 <h2 class="text-lg font-semibold mt-6">Mobile (iOS & Android)</h2>
                 <ul class="list-disc pl-6 text-slate-700">
                   <li>Open the site in your mobile browser, select the PDF from Files or a cloud drive, enter the password, and download.</li>
                   <li>On mobile, consider clearing downloads after use if the document is sensitive.</li>
                 </ul>
                 <p class="mt-4">Unlock your PDF across devices at <a class="text-indigo-700 hover:underline" href="index.php">RemovePasswordfromPDF.com</a>.</p>'
  ],
];

// Send common security headers
send_common_headers();

// Router (single file)
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

// Security: optional host allowlist
if (!ok_host()) {
    http_response_code(403);
    echo 'Forbidden host';
    exit;
}

if ($action === 'process') {
    // ----------------------- PROCESS: unlock PDF ----------------------------
    header('Content-Type: application/json');

  if (!rate_limit_check('process', 30, 60)) json_fail('Too many requests. Please wait a minute and try again.', 429);
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_fail('Invalid method', 405);
  if (!hash_equals((string)($_POST['csrf'] ?? ''), csrf_token())) json_fail('Invalid session. Refresh and try again.', 403);
    if (!isset($_FILES['pdf']) || !isset($_POST['password'])) json_fail('Missing file or password.');

    $pwd = trim((string)($_POST['password'] ?? ''));
    if ($pwd === '') json_fail('Password required.');

    $f = $_FILES['pdf'];
    if ($f['error'] !== UPLOAD_ERR_OK) json_fail('Upload failed.');
  if ($f['size'] <= 0 || $f['size'] > MAX_SIZE_BYTES) json_fail('File too big or empty.');

  // Allow large files up to MAX_SIZE_BYTES (tool is currently free for everyone)
  if ($f['size'] > MAX_SIZE_BYTES) {
    json_fail('File is too large. Maximum allowed is ' . (int)(MAX_SIZE_BYTES / 1024 / 1024) . ' MB.', 413);
  }

    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') json_fail('Only PDF files are allowed.');

    $mime = @mime_content_type($f['tmp_name']);
    if (!in_array($mime, ['application/pdf', 'application/octet-stream'], true)) {
        json_fail('Invalid file type.');
    }

    $tmpIn  = STORAGE_DIR . '/tmp/' . uniqid('in_', true)  . '.pdf';
    $tmpOut = STORAGE_DIR . '/out/' . uniqid('out_', true) . '.pdf';

    if (!@move_uploaded_file($f['tmp_name'], $tmpIn)) json_fail('Could not save upload.');

    $ok = false; $stderr = '';

    if (has_bin('qpdf')) {
        // qpdf --password=PWD --decrypt in.pdf out.pdf
        $cmd = 'qpdf --password=' . escapeshellarg($pwd) . ' --decrypt '
             . escapeshellarg($tmpIn) . ' ' . escapeshellarg($tmpOut) . ' 2>&1';
        $out = @shell_exec($cmd);
        if (is_file($tmpOut) && filesize($tmpOut) > 0) {
            $ok = true;
        } else {
            $stderr = $out ?: 'qpdf failed.';
        }
    }

    if (!$ok && has_bin('gs')) {
        // gs -o out.pdf -sDEVICE=pdfwrite -dSAFER -dNOPAUSE -dBATCH -sPDFPassword=PWD in.pdf
        $cmd = 'gs -o ' . escapeshellarg($tmpOut)
             . ' -sDEVICE=pdfwrite -dSAFER -dNOPAUSE -dBATCH -sPDFPassword=' . escapeshellarg($pwd)
             . ' ' . escapeshellarg($tmpIn) . ' 2>&1';
        $out = @shell_exec($cmd);
        if (is_file($tmpOut) && filesize($tmpOut) > 0) {
            $ok = true;
        } else {
            $stderr = $out ?: 'Ghostscript failed.';
        }
    }

    @unlink($tmpIn);

    if (!$ok) json_fail('Could not unlock PDF. Wrong password or missing server tools. ' . $stderr, 422);

    $downloadUrl = site_url('index.php?action=download&f=' . urlencode(basename($tmpOut)));

    echo json_encode(['success' => true, 'download_url' => $downloadUrl], JSON_UNESCAPED_SLASHES);
    exit;
}

// Checkout/Pro features are disabled: the tool is fully free for now.

// ----------------------- PAGES: blog, post, pricing, contact, terms, privacy
if ($action === 'blog') {
  header('Cache-Control: public, max-age=600');
  $title = 'Blog — Remove Password from PDF';
  $desc  = 'Privacy-first tips and product updates.';
  ?><!DOCTYPE html><html lang="en"><head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?php echo htmlspecialchars($title); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars($desc); ?>"/>
  <script src="https://cdn.tailwindcss.com"></script>
  </head><body class="min-h-screen flex flex-col bg-gradient-to-b from-indigo-50 via-white to-slate-100 text-slate-900">
  <main class="flex-1 px-6 py-12">
    <section class="max-w-5xl mx-auto">
      <div class="flex justify-between items-center py-2">
        <a href="index.php" aria-label="Go to home" class="flex items-center gap-3 hover:opacity-90">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-b from-indigo-600 to-violet-600 text-white grid place-items-center">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5">
              <path d="M8 3h5l5 5v11a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/>
              <path d="M13 3v6h6"/>
              <rect x="11" y="14" width="6" height="5" rx="1"/>
              <path d="M14 13v-2a2.5 2.5 0 1 0-5 0"/>
            </svg>
          </div>
          <div class="leading-tight">
            <div class="text-base font-semibold">Remove Password from PDF</div>
            <div class="text-xs text-slate-500">Private • Fast</div>
          </div>
        </a>
        <nav class="text-sm"><a class="text-indigo-700 hover:underline" href="index.php">Home</a></nav>
      </div>
      <h1 class="text-2xl font-semibold mt-6">Blog</h1>
      <div class="mt-6 space-y-6">
        <?php global $BLOG_POSTS; foreach ($BLOG_POSTS as $p): ?>
          <article class="space-y-1">
            <h2 class="text-xl font-semibold"><a class="text-slate-900 hover:text-indigo-700" href="<?php echo 'index.php?action=post&slug=' . urlencode($p['slug']); ?>"><?php echo htmlspecialchars($p['title']); ?></a></h2>
            <div class="text-xs text-slate-500"><?php echo htmlspecialchars($p['date']); ?></div>
            <p class="text-slate-600 text-sm"><?php echo htmlspecialchars($p['description']); ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  </main>
  <footer class="py-6 text-center text-sm text-slate-600">
    © <script>document.write(new Date().getFullYear())</script> RemovePasswordfromPDF.com · 1kbApps.com ·
    <a class="hover:underline" href="index.php?action=blog">Blog</a> ·
    <a class="hover:underline" href="index.php?action=contact">Contact</a> ·
    <a class="hover:underline" href="index.php?action=terms">Terms</a> ·
    <a class="hover:underline" href="index.php?action=privacy">Privacy</a>
  </footer></body></html><?php
  exit;
}

if ($action === 'post') {
  header('Cache-Control: public, max-age=600');
  $slug = (string)($_GET['slug'] ?? '');
  $post = null; foreach ($BLOG_POSTS as $p) { if ($p['slug'] === $slug) { $post = $p; break; } }
  if (!$post) { http_response_code(404); echo 'Post not found'; exit; }
  $title = $post['title'] . ' — Remove Password from PDF';
  $desc  = $post['description'];
  ?><!DOCTYPE html><html lang="en"><head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?php echo htmlspecialchars($title); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars($desc); ?>"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <script type="application/ld+json">{
    "@context":"https://schema.org","@type":"Article",
    "headline": <?php echo json_encode($post['title']); ?>,
    "datePublished": <?php echo json_encode($post['date']); ?>,
    "author": {"@type":"Organization","name":"Remove Password from PDF"}
  }</script>
  </head><body class="min-h-screen flex flex-col bg-gradient-to-b from-indigo-50 via-white to-slate-100 text-slate-900">
  <main class="flex-1 px-6 py-12">
    <section class="max-w-3xl mx-auto">
      <div class="flex justify-between items-center py-2">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-b from-indigo-600 to-violet-600 text-white grid place-items-center">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5">
              <path d="M8 3h5l5 5v11a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/>
              <path d="M13 3v6h6"/>
              <rect x="11" y="14" width="6" height="5" rx="1"/>
              <path d="M14 13v-2a2.5 2.5 0 1 0-5 0"/>
            </svg>
          </div>
          <div class="leading-tight">
            <div class="text-base font-semibold">Remove Password from PDF</div>
            <div class="text-xs text-slate-500">Private • Fast</div>
          </div>
        </div>
        <nav class="text-sm"><a class="text-indigo-700 hover:underline" href="index.php?action=blog">Blog</a></nav>
      </div>
      <article class="prose prose-slate max-w-none">
        <h1 class="text-2xl font-semibold"><?php echo htmlspecialchars($post['title']); ?></h1>
        <div class="text-xs text-slate-500 mb-4"><?php echo htmlspecialchars($post['date']); ?></div>
        <div class="text-slate-700 text-base leading-7"><?php echo $post['content']; ?></div>
      </article>
    </section>
  </main>
  <footer class="py-6 text-center text-sm text-slate-600">
    © <script>document.write(new Date().getFullYear())</script> RemovePasswordfromPDF.com · 1kbApps.com ·
    <a class="hover:underline" href="index.php?action=blog">Blog</a> ·
    <a class="hover:underline" href="index.php?action=contact">Contact</a> ·
    <a class="hover:underline" href="index.php?action=terms">Terms</a> ·
    <a class="hover:underline" href="index.php?action=privacy">Privacy</a>
  </footer></body></html><?php
  exit;
}

if ($action === 'contact' || $action === 'terms' || $action === 'privacy') {
  header('Cache-Control: public, max-age=600');
  $map = [
    'contact' => ['title' => 'Contact — Remove Password from PDF', 'desc' => 'Get in touch with our team.'],
    'terms'   => ['title' => 'Terms — Remove Password from PDF',   'desc' => 'Terms of Service.'],
    'privacy' => ['title' => 'Privacy — Remove Password from PDF', 'desc' => 'Our privacy practices explained clearly.'],
  ];
  $meta = $map[$action];
  ?><!DOCTYPE html><html lang="en"><head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?php echo htmlspecialchars($meta['title']); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars($meta['desc']); ?>"/>
  <script src="https://cdn.tailwindcss.com"></script>
  </head><body class="min-h-screen flex flex-col bg-gradient-to-b from-indigo-50 via-white to-slate-100 text-slate-900">
  <main class="flex-1 px-6 py-12">
    <section class="max-w-3xl mx-auto">
      <div class="flex justify-between items-center py-2">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-b from-indigo-600 to-violet-600 text-white grid place-items-center font-bold">RP</div>
          <div class="leading-tight">
            <div class="text-base font-semibold">Remove Password from PDF</div>
            <div class="text-xs text-slate-500">Private • Fast</div>
          </div>
        </div>
        <nav class="text-sm"><a class="text-indigo-700 hover:underline" href="index.php">Home</a></nav>
      </div>

  <?php if ($action==='contact'): ?>
        <h1 class="text-2xl font-semibold mt-6">Contact</h1>
        <div class="mt-4 text-slate-700">
          <p>Questions or billing help? Email us at <a class="text-indigo-700 hover:underline" href="mailto:support@1kbapps.com">support@1kbapps.com</a> or <a class="text-indigo-700 hover:underline" href="mailto:rethas@pm.me">rethas@pm.me</a>.</p>
          <p class="mt-2">We aim to reply within 1 business day.</p>
        </div>
      <?php elseif ($action==='terms'): ?>
        <h1 class="text-2xl font-semibold mt-6">Terms of Service</h1>
        <div class="mt-4 text-slate-700 space-y-3">
          <p>By using this service, you agree to provide only PDFs you own or control. We do not crack passwords; you must supply the correct one.</p>
          <p><strong>Illegal use is prohibited.</strong> Do not use this service to access, modify, or distribute documents without authorization. You are responsible for complying with all applicable laws and policies.</p>
          <p>Service is provided “as is” without warranties. Limitations of liability apply to the extent permitted by law.</p>
          <p>We may update these terms; continued use means acceptance of changes.</p>
        </div>
      <?php else: ?>
        <h1 class="text-2xl font-semibold mt-6">Privacy Policy</h1>
        <div class="mt-4 text-slate-700 space-y-3">
          <p>We process your file briefly to remove protection after you provide the correct password. Files auto-delete within ~1 hour. Passwords are used only during processing and are not stored.</p>
          <p>We do not sell or share your data. Minimal analytics may be used in aggregate to improve reliability.</p>
          <p>Contact <a class="text-indigo-700 hover:underline" href="mailto:support@1kbapps.com">support@1kbapps.com</a> or <a class="text-indigo-700 hover:underline" href="mailto:rethas@pm.me">rethas@pm.me</a> with privacy questions.</p>
        </div>
      <?php endif; ?>
    </section>
  </main>
  <footer class="py-6 text-center text-sm text-slate-600">
    © <script>document.write(new Date().getFullYear())</script> Remove Password from PDF ·
    <a class="hover:underline" href="index.php?action=blog">Blog</a> ·
    <a class="hover:underline" href="index.php?action=contact">Contact</a> ·
    <a class="hover:underline" href="index.php?action=terms">Terms</a> ·
    <a class="hover:underline" href="index.php?action=privacy">Privacy</a>
  </footer></body></html><?php
  exit;
}

if ($action === 'download') {
    // ----------------------- DOWNLOAD: serve & delete -----------------------
    $name = basename($_GET['f'] ?? '');
    $path = STORAGE_DIR . '/out/' . $name;

    if (!preg_match('/^out_[A-Za-z0-9\.\-_]+\.pdf$/', $name)) {
        http_response_code(400); echo 'Invalid file.'; exit;
    }
    if (!is_file($path)) {
        http_response_code(404); echo 'Not found.'; exit;
    }

  header('Content-Type: application/pdf');
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
    header('Content-Disposition: attachment; filename="unlocked.pdf"');
    header('Content-Length: ' . filesize($path));
  $fp = fopen($path, 'rb');
  if ($fp) { fpassthru($fp); fclose($fp); }
    @unlink($path);
    exit;
}

// ---------------------------- UI (GET) --------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Remove Password from PDF — Fast, Private PDF unlocker</title>
  <script src="https://cdn.tailwindcss.com" defer></script>
  <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
  <meta name="description" content="Unlock your own password-protected PDF securely. No signup."/>
  <style>.glass{backdrop-filter: blur(12px)}</style>
</head>
<body class="min-h-screen bg-gradient-to-b from-indigo-50 via-white to-slate-100 text-slate-900">
  <main class="px-6 py-12">
    <?php $flash = flash_get(); if (!empty($flash)): ?>
      <div class="max-w-5xl mx-auto mb-6">
        <div class="text-sm <?php echo $flash['type']==='error' ? 'text-rose-700' : 'text-emerald-700'; ?>">
          <?php echo htmlspecialchars($flash['msg']); ?>
        </div>
      </div>
    <?php endif; ?>

    <section class="max-w-5xl mx-auto">
      <div class="p-0 md:p-0">
        <!-- Header moved into the same container as main content -->
        <div class="flex justify-between items-center py-4">
          <a href="index.php" aria-label="Go to home" class="flex items-center gap-3 hover:opacity-90">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-b from-indigo-600 to-violet-600 text-white grid place-items-center">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5">
                <path d="M8 3h5l5 5v11a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/>
                <path d="M13 3v6h6"/>
                <rect x="11" y="14" width="6" height="5" rx="1"/>
                <path d="M14 13v-2a2.5 2.5 0 1 0-5 0"/>
              </svg>
            </div>
            <div class="leading-tight">
              <div class="text-base font-semibold">Remove Password from PDF</div>
              <div class="text-xs text-slate-500">Fast • Private • Free for everyone</div>
            </div>
          </a>
          <nav class="flex items-center gap-4 text-sm font-medium"></nav>
        </div>
        <div class="grid md:grid-cols-2 gap-10 items-start">
          <div class="order-2 md:order-1">
            <div class="inline-flex items-center gap-2 text-xs px-2 py-1 rounded-full bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200">
              Private • Fast • No installations
            </div>
            <h1 class="mt-3 text-3xl md:text-4xl font-semibold tracking-tight">Unlock your password‑protected PDF in seconds</h1>
            <p class="mt-3 text-slate-600">
              Upload a PDF you own, enter its password, and get a clean, unlocked copy—fast and private.
              We don’t crack files—you must know the password.
            </p>
            <ul class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm text-slate-700">
              <li class="flex items-center gap-2"><span class="w-5 h-5 rounded-full bg-emerald-100 text-emerald-700 grid place-items-center text-xs">✓</span> Files auto-delete in ~1 hour</li>
              <li class="flex items-center gap-2"><span class="w-5 h-5 rounded-full bg-emerald-100 text-emerald-700 grid place-items-center text-xs">✓</span> Password never stored</li>
              <li class="flex items-center gap-2"><span class="w-5 h-5 rounded-full bg-emerald-100 text-emerald-700 grid place-items-center text-xs">✓</span> No signup or install</li>
              <li class="flex items-center gap-2"><span class="w-5 h-5 rounded-full bg-emerald-100 text-emerald-700 grid place-items-center text-xs">✓</span> Large files supported (no signup)</li>
            </ul>
            <!-- CTA just below the four lines -->
            <div class="mt-6 max-w-md">
              <label class="block text-sm font-medium">Your PDF</label>
              <div class="mt-1 group cursor-pointer border-2 border-dashed rounded-xl p-4 sm:p-6 transition bg-white hover:bg-slate-50 border-slate-300" onclick="document.getElementById('file-input-main')?.click()">
                <div class="flex items-center gap-3 flex-wrap">
                  <button type="button" onclick="document.getElementById('file-input-main')?.click()" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 text-white group-hover:bg-slate-800">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                    Choose PDF
                  </button>
                  <span class="text-sm text-slate-700 font-medium underline decoration-dashed underline-offset-4">or drag & drop your .pdf</span>
                </div>
                <div class="mt-2 text-xs text-slate-500">PDF only • Max ~<?php echo (int)(effective_upload_limit_bytes() / 1024 / 1024); ?> MB (server limit)</div>
              </div>
            </div>
            <div class="mt-6 flex gap-3"></div>
          </div>

          <!-- APP CARD -->
          <div x-data="uploader()" :class="{'text-left': align==='left','text-center': align==='center','text-right': align==='right'}" class="p-0 order-1 md:order-2">
            <form class="space-y-4" @submit.prevent="submit()">
              <input type="hidden" name="action" value="process" />
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>" />

              <!-- Dropzone -->
              <div class="space-y-2 max-w-md">
                <label class="block text-sm font-medium">Your PDF</label>
       <div class="mt-1 group cursor-pointer border-2 border-dashed rounded-xl p-4 sm:p-6 transition border-slate-300 bg-white hover:bg-slate-50"
         @dragover.prevent="drag=true" @dragleave.prevent="drag=false" @drop.prevent="onDrop($event)" @click="$refs.file.click()"
         :class="drag ? 'border-indigo-500 bg-indigo-50' : ''">
                  <input id="file-input-main" type="file" x-ref="file" @change="onFile()" accept="application/pdf" class="hidden"/>
                  <div class="flex items-center gap-3 flex-wrap justify-start text-left">
                    <button type="button" @click.stop="$refs.file.click()" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 text-white group-hover:bg-slate-800">
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                      Choose PDF
                    </button>
                    <span class="text-sm text-slate-700 font-medium underline decoration-dashed underline-offset-4">or drag & drop your .pdf</span>
                  </div>
                  <div class="mt-2 text-xs text-slate-500">PDF only • Max ~<?php echo (int)(effective_upload_limit_bytes() / 1024 / 1024); ?> MB (server limit)</div>
                  <template x-if="fileName">
                    <div class="mt-1 text-sm font-medium text-indigo-700" x-text="fileName"></div>
                  </template>
                </div>
              </div>

              <!-- Password -->
              <div class="max-w-md">
                <label class="block text-sm font-medium mb-1">Password</label>
                <input type="password" x-ref="pwd" minlength="1" required placeholder="Enter the PDF password"
                       class="bg-white rounded-lg w-full h-10 text-sm px-3 outline-none ring-1 ring-slate-300 focus:ring-2 focus:ring-indigo-600"/>
                <p class="mt-1 text-xs text-slate-500">Used only to unlock your file during processing, then discarded.</p>
              </div>

              <!-- Actions -->
              <div class="max-w-md">
                <div class="flex items-center gap-2" :class="{'justify-start': align==='left','justify-center': align==='center','justify-end': align==='right'}">
                  <button type="button" @click="reset()" :disabled="busy"
                          class="px-3 py-2 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-50 disabled:opacity-50">Clear</button>
                  <button type="submit" :disabled="busy || !file"
                          :class="['px-4 py-2 rounded-xl font-medium transition inline-flex items-center gap-2',
                                   busy ? 'bg-slate-300 text-slate-600' : 'bg-gradient-to-b from-indigo-600 to-indigo-500 text-white hover:from-indigo-500 hover:to-indigo-400']">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                    <span x-text="busy ? 'Processing…' : 'Unlock PDF'">Unlock PDF</span>
                  </button>
                </div>
              </div>

              <!-- Progress -->
              <div class="h-2 w-full bg-slate-100 rounded-full overflow-hidden" x-show="busy">
                <div class="h-full bg-indigo-500 transition-all" :style="`width:${progress}%`"></div>
              </div>

              <template x-if="downloadUrl">
                <div class="mt-2 border border-emerald-200 bg-emerald-50 text-emerald-800 rounded-lg p-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                  <div class="flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                    <span class="text-sm">Your unlocked PDF is ready.</span>
                  </div>
                  <a :href="downloadUrl" class="text-sm inline-flex items-center justify-center px-3 py-1.5 rounded-md bg-emerald-600 text-white hover:bg-emerald-500">Download file</a>
                </div>
              </template>

              <template x-if="error">
                <div class="text-sm text-rose-700" x-text="error"></div>
              </template>

              <p class="text-xs text-slate-500">We recommend removing sensitive docs after download. Files auto-delete within ~1 hour. Do not use this tool for illegal purposes or to access documents you do not own or are not authorized to modify.</p>

              <!-- Plan messaging removed: fully free for now -->
            </form>
          </div>
        </div>
      </div>
    </section>
    <!-- Single-page layout ends here. Removed separate blocks for features/pricing/faq/about. -->
  </main>

  <footer class="py-6 text-center text-sm text-slate-600">
    © <script>document.write(new Date().getFullYear())</script> RemovePasswordfromPDF.com · 1kbApps.com ·
    <a class="hover:underline" href="index.php?action=blog">Blog</a> ·
    
    <a class="hover:underline" href="index.php?action=contact">Contact</a> ·
    <a class="hover:underline" href="index.php?action=terms">Terms</a> ·
    <a class="hover:underline" href="index.php?action=privacy">Privacy</a>
  </footer>

  <script>
    function uploader() {
      return {
  // Alignment for the action row: 'left' | 'center' | 'right'
  align: 'left',
        drag: false,
        file: null,
        fileName: '',
        busy: false,
        progress: 0,
        status: '',
        error: '',
        downloadUrl: '',
  showBenefits: false,

        reset() {
          if (this.$refs.file) this.$refs.file.value = '';
          if (this.$refs.pwd) this.$refs.pwd.value = '';
          this.drag = false;
          this.file = null; this.fileName = ''; this.error = ''; this.downloadUrl = ''; this.progress = 0; this.busy = false; this.status = '';
        },
        onFile() {
          const f = this.$refs.file.files[0];
          if (!f) { this.file = null; this.fileName = ''; return; }
          if (!f.name.toLowerCase().endsWith('.pdf')) {
            this.error = 'Only PDF files are allowed.'; this.$refs.file.value = ''; this.file = null; this.fileName = ''; return;
          }
          // Optional soft guard: extremely large files may fail in browser limits
          const maxLimit = <?php echo (int)(effective_upload_limit_bytes()); ?>;
          if (f.size > maxLimit) { this.error = 'File is larger than the maximum allowed size.'; this.file = null; this.fileName = ''; return; }
          this.error = ''; this.file = f; this.fileName = f.name;
        },
        onDrop(e) {
          this.drag = false;
          const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
          if (!f) return;
          if (!f.name.toLowerCase().endsWith('.pdf')) { this.error = 'Only PDF files are allowed.'; this.file = null; this.fileName = ''; return; }
          const maxLimit = <?php echo (int)(effective_upload_limit_bytes()); ?>;
          if (f.size > maxLimit) { this.error = 'File is larger than the maximum allowed size.'; this.file = null; this.fileName = ''; return; }
          this.error = ''; this.file = f; this.fileName = f.name;
        },
        async submit() {
          if (this.busy || !this.file) return;
          this.error = ''; this.downloadUrl = ''; this.status = 'Uploading…'; this.busy = true; this.progress = 20;

          // Build FormData and POST to this same file (action=process)
          const fd = new FormData();
          fd.append('action', 'process');
          fd.append('pdf', this.file);
          fd.append('password', this.$refs.pwd.value);

          try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            this.progress = 60; this.status = 'Processing…';
            const data = await res.json();
            this.progress = 90;

            if (!res.ok || !data.success) {
              this.error = data && data.message ? data.message : 'Failed to process file.';
              this.status = ''; this.busy = false; this.progress = 0; return;
            }

            this.status = 'Done.'; this.progress = 100;
            this.downloadUrl = data.download_url;
          } catch (e) {
            this.error = 'Network error. Try again.'; this.status = '';
          } finally {
            this.busy = false;
          }
        }
      }
    }

    // Sanity self-test (catches syntax issues in production quickly)
    (function () {
      try {
        const u = uploader();
        ['align','drag','file','fileName','busy','progress','status','error','downloadUrl','reset','onFile','onDrop','submit']
          .forEach(k => { if (!(k in u)) throw new Error('Missing: ' + k) });
        console.log('UI self-test passed');
      } catch (e) { console.error('UI self-test failed', e); }
    })();
  </script>
</body>
</html>
