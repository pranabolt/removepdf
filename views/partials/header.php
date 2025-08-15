<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?php echo htmlspecialchars($title ?? 'Remove Password from PDF — Fast, Private PDF unlocker'); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars($desc ?? 'Unlock your own password-protected PDF securely. No signup.'); ?>"/>
  <link rel="canonical" href="<?php echo htmlspecialchars(canonical_url()); ?>"/>
  <link rel="icon" href="assets/logo.svg" type="image/svg+xml"/>
  <link rel="apple-touch-icon" href="assets/logo.svg"/>
  <link rel="preconnect" href="https://cdn.tailwindcss.com" crossorigin>
  <link rel="preconnect" href="https://unpkg.com" crossorigin>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php if (isset($includeAlpine) && $includeAlpine): ?>
  <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
  <?php endif; ?>
  <?php if (isset($structuredData)): ?>
  <script type="application/ld+json"><?php echo $structuredData; ?></script>
  <?php endif; ?>
  <style>
    .glass{backdrop-filter: blur(12px)}
    [x-cloak]{display:none !important}
  /* Minimal fallback if Tailwind fails to load */
  html { font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, Noto Sans, "Apple Color Emoji", "Segoe UI Emoji"; }
  body { margin: 0; }
  img { max-width: 100%; height: auto; }
  </style>
</head>
<body class="min-h-screen flex flex-col bg-gradient-to-b from-indigo-50 via-white to-slate-100 text-slate-900">
  <main class="flex-1 px-6 py-12">
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
            <img src="assets/logo.svg" alt="Remove Password from PDF" class="w-9 h-9 rounded-xl" width="36" height="36"/>
            <div class="leading-tight">
              <div class="text-xl font-semibold">Remove Password from PDF</div>
              <div class="text-[0.9375rem] text-slate-500">Fast • Private • Free for everyone</div>
            </div>
          </a>
          <nav class="flex items-center gap-4 text-sm font-medium">
            <?php if (isset($showBlogLink) && $showBlogLink): ?>
            <a class="text-indigo-700 hover:underline" href="index.php?action=blog">Blog</a>
            <?php else: ?>
            <a class="text-indigo-700 hover:underline" href="index.php">Home</a>
            <?php endif; ?>
          </nav>
        </div>