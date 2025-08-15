<?php
$showBlogLink = true;
$structuredData = '{
    "@context":"https://schema.org","@type":"Article",
    "headline": ' . json_encode($post['title']) . ',
    "datePublished": ' . json_encode($post['date']) . ',
    "author": {"@type":"Organization","name":"Remove Password from PDF"}
  }';
require_once __DIR__ . '/partials/header.php';
?>
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
  </footer>
</body>
</html>