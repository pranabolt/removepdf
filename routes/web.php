<?php
/* ===========================================================================
   Remove Password from PDF — Routing Logic
*/

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

if ($action === 'health') {
  header('Content-Type: application/json');
  $checks = [];
  $checks['php_version'] = PHP_VERSION;
  $checks['session'] = session_status() === PHP_SESSION_ACTIVE;
  $checks['storage_writable'] = is_writable(STORAGE_DIR) && is_writable(STORAGE_DIR . '/tmp') && is_writable(STORAGE_DIR . '/out');
  $checks['qpdf'] = has_bin('qpdf');
  $checks['ghostscript'] = has_bin('gs');
  echo json_encode(['ok' => true, 'checks' => $checks], JSON_UNESCAPED_SLASHES);
  exit;
}

// Checkout/Pro features are disabled: the tool is fully free for now.

// ----------------------- PAGES: blog, post, pricing, contact, terms, privacy
if ($action === 'blog') {
  header('Cache-Control: public, max-age=600');
  $title = 'Blog — Remove Password from PDF';
  $desc  = 'Privacy-first tips and product updates.';
  require_once __DIR__ . '/../views/blog.php';
  exit;
}

if ($action === 'post') {
  header('Cache-Control: public, max-age=600');
  $slug = (string)($_GET['slug'] ?? '');
  $post = null; foreach ($BLOG_POSTS as $p) { if ($p['slug'] === $slug) { $post = $p; break; } }
  if (!$post) { http_response_code(404); echo 'Post not found'; exit; }
  $title = $post['title'] . ' — Remove Password from PDF';
  $desc  = $post['description'];
  require_once __DIR__ . '/../views/post.php';
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
  require_once __DIR__ . '/../views/' . $action . '.php';
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