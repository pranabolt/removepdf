<?php
$showBlogLink = false;
require_once __DIR__ . '/partials/header.php';
?>
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
  </footer>
</body>
</html>