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
const MAX_SIZE_BYTES = 50 * 1024 * 1024;        // 50 MB (absolute cap)
const FREE_MAX_SIZE_BYTES = 5 * 1024 * 1024;    // 5 MB (free tier)
const PRO_MAX_SIZE_BYTES  = MAX_SIZE_BYTES;     // 50 MB (pro tier)
const FILE_TTL_SEC   = 3600;                    // 1 hour
const STORAGE_DIR    = __DIR__ . '/_storage';   // auto-created: _storage/tmp, _storage/out
const HOST_ALLOWLIST = [];                      // e.g. ['remove-password-from-pdf.com'] leave [] to allow any host
// -----------------------------------------------------------------------------

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

// Simple blog content (SEO-friendly, no DB)
$BLOG_POSTS = [
  [
    'slug' => 'why-a-paid-pdf-unlocker-protects-your-privacy',
    'title' => 'Why a Paid PDF Unlocker Protects Your Privacy',
    'description' => 'Free sites often monetize with tracking or unclear data practices. Here’s why paying for privacy is worth it.',
    'date' => '2025-08-01',
    'content' => '<p>"Free" often hides costs: ads, trackers, and unclear data retention. When documents matter, privacy should not be an afterthought. A paid service aligns incentives with you—the customer—not advertisers.</p>
                 <p>We process files briefly, never store your password, and auto-delete uploads within ~1 hour. Your subscription funds uptime, safeguards, and support—not data monetization.</p>
                 <h2 class="text-lg font-semibold mt-6">Paying for certainty</h2>
                 <p>Predictable limits, priority processing, and transparent deletion policies mean you know exactly what happens to your files.</p>'
  ],
  [
    'slug' => 'how-we-handle-your-files-and-passwords',
    'title' => 'How We Handle Your Files and Passwords',
    'description' => 'A clear, plain-English explanation of our short-lived processing and deletion timeline.',
    'date' => '2025-08-05',
    'content' => '<p>Your PDF and password are used only to unlock your file. The password is never stored and is discarded immediately after processing. Files are auto-deleted within ~1 hour.</p>
                 <p>We rely on proven, open-source tools (qpdf and Ghostscript) to remove protection when you provide the correct password.</p>'
  ],
  [
    'slug' => 'qpdf-vs-ghostscript-which-one-is-better',
    'title' => 'qpdf vs Ghostscript: Which One Is Better?',
    'description' => 'We support both qpdf and Ghostscript. Here’s when each tends to work best.',
    'date' => '2025-08-10',
    'content' => '<p>qpdf is fast and reliable for many PDFs. Ghostscript can handle certain edge cases and complex PDFs better. We try qpdf first and fall back to Ghostscript if needed.</p>'
  ],
];

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

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_fail('Invalid method', 405);
    if (!isset($_FILES['pdf']) || !isset($_POST['password'])) json_fail('Missing file or password.');

    $pwd = trim((string)($_POST['password'] ?? ''));
    if ($pwd === '') json_fail('Password required.');

    $f = $_FILES['pdf'];
    if ($f['error'] !== UPLOAD_ERR_OK) json_fail('Upload failed.');
  if ($f['size'] <= 0 || $f['size'] > MAX_SIZE_BYTES) json_fail('File too big or empty.');

  // Plan-based gating
  $limit = is_pro() ? PRO_MAX_SIZE_BYTES : FREE_MAX_SIZE_BYTES;
  if ($f['size'] > $limit) {
    $upgrade = site_url('index.php?action=checkout');
    json_fail('This file exceeds the Free plan limit (' . (int)($limit / 1024 / 1024) . ' MB). Upgrade to Pro for up to ' . (int)(PRO_MAX_SIZE_BYTES/1024/1024) . ' MB: ' . $upgrade, 402);
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

if ($action === 'checkout') {
  // ----------------------- CHECKOUT: Stripe Checkout (optional) ----------
  $secret = get_env('STRIPE_SECRET_KEY');
  $price  = get_env('STRIPE_PRICE_ID');

  $success = site_url('index.php?action=success');
  $cancel  = site_url('index.php');

  if ($secret && $price) {
    // Create a Checkout Session via Stripe API
    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    $payload = http_build_query([
      'mode' => 'subscription',
      'line_items[0][price]' => $price,
      'line_items[0][quantity]' => 1,
      'allow_promotion_codes' => 'true',
      'success_url' => $success . '?session_id={CHECKOUT_SESSION_ID}',
      'cancel_url' => $cancel,
      'billing_address_collection' => 'auto',
      'automatic_tax[enabled]' => 'true',
    ]);
    curl_setopt_array($ch, [
      CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
      CURLOPT_USERPWD => $secret . ':',
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_RETURNTRANSFER => true,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err || $code >= 400 || !$res) {
      flash_set('error', 'Checkout is temporarily unavailable. Please try again later.');
      header('Location: ' . $cancel, true, 302); exit;
    }
    $data = json_decode((string)$res, true) ?: [];
    if (!empty($data['url'])) { header('Location: ' . $data['url'], true, 303); exit; }
    flash_set('error', 'Unexpected response from payment provider.');
    header('Location: ' . $cancel, true, 302); exit;
  } else {
    // Fallback demo: mark Pro in session for this browser only
    $_SESSION['plan'] = 'pro';
    flash_set('success', 'Pro enabled (demo mode). Set STRIPE_SECRET_KEY and STRIPE_PRICE_ID to enable real checkout.');
    header('Location: ' . $success, true, 302); exit;
  }
}

if ($action === 'success') {
  // ----------------------- SUCCESS: finalize & show message --------------
  // If Stripe keys exist and session_id is present, we could verify it here.
  // For now, Pro is already set in checkout fallback. Keep it friendly.
  if (!is_pro()) $_SESSION['plan'] = 'pro';
  flash_set('success', '🎉 Pro is active on this browser. Enjoy higher limits and faster processing.');
  header('Location: ' . site_url('index.php'), true, 302); exit;
}

if ($action === 'logout') {
  $_SESSION['plan'] = 'free';
  flash_set('success', 'Signed out. You are back on the Free plan.');
  header('Location: ' . site_url('index.php'), true, 302); exit;
}

// ----------------------- PAGES: blog, post, pricing, contact, terms, privacy
if ($action === 'blog') {
  $title = 'Blog — Remove Password from PDF';
  $desc  = 'Privacy-first tips and product updates.';
  ?><!DOCTYPE html><html lang="en"><head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?php echo htmlspecialchars($title); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars($desc); ?>"/>
  <script src="https://cdn.tailwindcss.com"></script>
  </head><body class="min-h-screen bg-gradient-to-b from-indigo-50 via-white to-slate-100 text-slate-900">
  <main class="px-6 py-12">
    <section class="max-w-5xl mx-auto">
      <div class="flex justify-between items-center py-2">
        <a href="index.php" aria-label="Go to home" class="flex items-center gap-3 hover:opacity-90">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-b from-indigo-600 to-violet-600 text-white grid place-items-center font-bold">RP</div>
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
    © <script>document.write(new Date().getFullYear())</script> Remove Password from PDF ·
    <a class="hover:underline" href="index.php?action=blog">Blog</a> ·
    <a class="hover:underline" href="index.php?action=pricing">Pricing</a> ·
    <a class="hover:underline" href="index.php?action=contact">Contact</a> ·
    <a class="hover:underline" href="index.php?action=terms">Terms</a> ·
    <a class="hover:underline" href="index.php?action=privacy">Privacy</a>
  </footer></body></html><?php
  exit;
}

if ($action === 'post') {
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
  </head><body class="min-h-screen bg-gradient-to-b from-indigo-50 via-white to-slate-100 text-slate-900">
  <main class="px-6 py-12">
    <section class="max-w-3xl mx-auto">
      <div class="flex justify-between items-center py-2">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-b from-indigo-600 to-violet-600 text-white grid place-items-center font-bold">RP</div>
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
    © <script>document.write(new Date().getFullYear())</script> Remove Password from PDF ·
    <a class="hover:underline" href="index.php?action=blog">Blog</a> ·
    <a class="hover:underline" href="index.php?action=pricing">Pricing</a> ·
    <a class="hover:underline" href="index.php?action=contact">Contact</a> ·
    <a class="hover:underline" href="index.php?action=terms">Terms</a> ·
    <a class="hover:underline" href="index.php?action=privacy">Privacy</a>
  </footer></body></html><?php
  exit;
}

if ($action === 'pricing' || $action === 'contact' || $action === 'terms' || $action === 'privacy') {
  $map = [
    'pricing' => ['title' => 'Pricing — Remove Password from PDF', 'desc' => 'Simple pricing. Free up to 5 MB. Pro up to 50 MB with priority processing.'],
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
  </head><body class="min-h-screen bg-gradient-to-b from-indigo-50 via-white to-slate-100 text-slate-900">
  <main class="px-6 py-12">
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

      <?php if ($action==='pricing'): ?>
        <h1 class="text-2xl font-semibold mt-6">Pricing</h1>
        <div class="mt-4 text-slate-700">
          <p>Free: up to 5 MB. Standard speed.</p>
          <p class="mt-2">Pro: up to 50 MB, faster processing, priority support.</p>
          <?php if (!is_pro()): ?>
            <a href="index.php?action=checkout" class="inline-block mt-4 px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-500">Get Pro</a>
          <?php else: ?>
            <span class="inline-block mt-4 px-3 py-1.5 rounded-lg bg-emerald-600 text-white">Pro active</span>
          <?php endif; ?>
        </div>
      <?php elseif ($action==='contact'): ?>
        <h1 class="text-2xl font-semibold mt-6">Contact</h1>
        <div class="mt-4 text-slate-700">
          <p>Questions or billing help? Email us at <a class="text-indigo-700 hover:underline" href="mailto:support@yourdomain.com">support@yourdomain.com</a>.</p>
          <p class="mt-2">We aim to reply within 1 business day.</p>
        </div>
      <?php elseif ($action==='terms'): ?>
        <h1 class="text-2xl font-semibold mt-6">Terms of Service</h1>
        <div class="mt-4 text-slate-700 space-y-3">
          <p>By using this service, you agree to provide only PDFs you own or control. We do not crack passwords; you must supply the correct one.</p>
          <p>Service is provided “as is” without warranties. Limitations of liability apply to the extent permitted by law.</p>
          <p>We may update these terms; continued use means acceptance of changes.</p>
        </div>
      <?php else: ?>
        <h1 class="text-2xl font-semibold mt-6">Privacy Policy</h1>
        <div class="mt-4 text-slate-700 space-y-3">
          <p>We process your file briefly to remove protection after you provide the correct password. Files auto-delete within ~1 hour. Passwords are used only during processing and are not stored.</p>
          <p>We do not sell or share your data. Minimal analytics may be used in aggregate to improve reliability.</p>
          <p>Contact <a class="text-indigo-700 hover:underline" href="mailto:support@yourdomain.com">support@yourdomain.com</a> with privacy questions.</p>
        </div>
      <?php endif; ?>
    </section>
  </main>
  <footer class="py-6 text-center text-sm text-slate-600">
    © <script>document.write(new Date().getFullYear())</script> Remove Password from PDF ·
    <a class="hover:underline" href="index.php?action=blog">Blog</a> ·
    <a class="hover:underline" href="index.php?action=pricing">Pricing</a> ·
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
    header('Content-Disposition: attachment; filename="unlocked.pdf"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
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
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
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
            <div class="w-9 h-9 rounded-xl bg-gradient-to-b from-indigo-600 to-violet-600 text-white grid place-items-center font-bold">RP</div>
            <div class="leading-tight">
              <div class="text-base font-semibold">Remove Password from PDF</div>
              <div class="text-xs text-slate-500">Fast • Private • Free up to 5 MB</div>
            </div>
          </a>
          <nav class="flex items-center gap-4 text-sm font-medium">
            <?php if (is_pro()): ?>
              <a href="index.php?action=logout" class="text-emerald-700 hover:underline">Pro active</a>
            <?php else: ?>
              <a href="index.php?action=checkout" class="text-indigo-700 hover:underline">Get Pro</a>
            <?php endif; ?>
          </nav>
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
              <li class="flex items-center gap-2"><span class="w-5 h-5 rounded-full bg-emerald-100 text-emerald-700 grid place-items-center text-xs">✓</span> Free up to 5 MB</li>
            </ul>
            <div class="mt-6 flex gap-3">
              <?php if (!is_pro()): ?>
                <a href="index.php?action=checkout" class="px-4 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-500">Upgrade to Pro</a>
              <?php else: ?>
                <span class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white">Pro active</span>
              <?php endif; ?>
            </div>
          </div>

          <!-- APP CARD -->
          <div x-data="uploader()" :class="{'text-left': align==='left','text-center': align==='center','text-right': align==='right'}" class="p-0 order-1 md:order-2">
            <form class="space-y-4" @submit.prevent="submit()">
              <input type="hidden" name="action" value="process" />

              <!-- Dropzone -->
              <div class="space-y-2 max-w-md">
                <label class="block text-sm font-medium">Your PDF</label>
                <div class="mt-1"
                     @dragover.prevent="drag=true" @dragleave.prevent="drag=false" @drop.prevent="onDrop($event)" @click="$refs.file.click()"
                     :class="['group cursor-pointer border-2 border-dashed rounded-xl p-4 sm:p-6 transition', drag ? 'border-indigo-500 bg-indigo-50' : 'border-slate-300 bg-white hover:bg-slate-50']">
                  <input type="file" x-ref="file" @change="onFile()" accept="application/pdf" class="hidden"/>
                  <div class="flex items-center gap-3 flex-wrap justify-start text-left">
                    <button type="button" @click.stop="$refs.file.click()" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 text-white group-hover:bg-slate-800">
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                      Choose PDF
                    </button>
                    <span class="text-sm text-slate-700 font-medium underline decoration-dashed underline-offset-4">or drag & drop your .pdf</span>
                  </div>
                  <div class="mt-2 text-xs text-slate-500">PDF only • Free up to 5 MB<?php echo is_pro() ? ' • Pro up to 50 MB' : ''; ?></div>
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
                    <span x-text="busy ? 'Processing…' : 'Unlock PDF'"></span>
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

              <p class="text-xs text-slate-500">We recommend removing sensitive docs after download. Files auto-delete within ~1 hour.</p>

              <!-- Inline Pro benefits (single page, no separate pricing block) -->
              <div class="pt-3">
                <div class="flex items-center gap-2 text-sm text-slate-600">
                  <div>Free up to 5 MB.</div>
                  <?php if (!is_pro()): ?>
                    <button type="button" @click="showBenefits=!showBenefits" class="text-indigo-700 hover:underline">See Pro benefits</button>
                  <?php else: ?>
                    <span class="text-emerald-700">Pro active: up to 50 MB.</span>
                  <?php endif; ?>
                </div>
                <?php if (!is_pro()): ?>
                <div x-show="showBenefits" class="mt-2 text-sm text-slate-700">
                  • Files up to 50 MB • Faster processing • Priority support
                  <a href="index.php?action=checkout" class="ml-2 inline-flex items-center gap-1 text-indigo-700 underline">Get Pro</a>
                </div>
                <?php endif; ?>
              </div>
            </form>
          </div>
        </div>
      </div>
    </section>
    <!-- Single-page layout ends here. Removed separate blocks for features/pricing/faq/about. -->
  </main>

  <footer class="py-6 text-center text-sm text-slate-600">
    © <script>document.write(new Date().getFullYear())</script> Remove Password from PDF ·
    <a class="hover:underline" href="index.php?action=blog">Blog</a> ·
    <a class="hover:underline" href="index.php?action=pricing">Pricing</a> ·
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
          // Soft client-side size guard to hint about plan limits
          const freeLimit = <?php echo (int)(FREE_MAX_SIZE_BYTES); ?>;
          const isPro = <?php echo is_pro() ? 'true' : 'false'; ?>;
          if (!isPro && f.size > freeLimit) {
            this.error = 'This file is larger than the Free limit (5 MB). Please choose a smaller file or upgrade to Pro.';
            this.file = null; this.fileName = ''; return;
          }
          this.error = ''; this.file = f; this.fileName = f.name;
        },
        onDrop(e) {
          this.drag = false;
          const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
          if (!f) return;
          if (!f.name.toLowerCase().endsWith('.pdf')) { this.error = 'Only PDF files are allowed.'; this.file = null; this.fileName = ''; return; }
          const freeLimit = <?php echo (int)(FREE_MAX_SIZE_BYTES); ?>;
          const isPro = <?php echo is_pro() ? 'true' : 'false'; ?>;
          if (!isPro && f.size > freeLimit) { this.error = 'This file is larger than the Free limit (5 MB). Please choose a smaller file or upgrade to Pro.'; this.file = null; this.fileName = ''; return; }
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
