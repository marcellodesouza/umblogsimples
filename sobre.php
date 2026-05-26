<?php
require_once __DIR__ . '/config.php';

// ── Cache ──────────────────────────────────────────────────────────────────
$arquivo    = __DIR__ . '/sobre.md';
$cache_file = CACHE_DIR . '/sobre.html';

if (file_exists($cache_file) && file_exists($arquivo) && filemtime($cache_file) >= filemtime($arquivo)) {
    echo file_get_contents($cache_file);
    exit;
}

// ── Conteúdo ───────────────────────────────────────────────────────────────
$conteudo = file_exists($arquivo)
    ? file_get_contents($arquivo)
    : "Escreva um pouco sobre você aqui.\n\nEdite o arquivo `sobre.md` pelo editor.";

// Reutiliza o parser do post.php
function md_to_html(string $md): string {
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    $blocos = []; $idx = 0;

    $md = preg_replace_callback('/```(\w*)\n([\s\S]*?)```/m', function($m) use (&$blocos, &$idx) {
        $k = "@@BLOCO{$idx}@@"; $idx++;
        $lang = $m[1] ? ' class="language-' . htmlspecialchars($m[1]) . '"' : '';
        $blocos[$k] = '<pre><code' . $lang . '>' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '</code></pre>';
        return $k;
    }, $md);

    $md = preg_replace_callback('/<iframe[^>]*>.*?<\/iframe>/si', function($m) use (&$blocos, &$idx) {
        if (!preg_match('/src=["\']https:\/\/(www\.)?(youtube\.com|youtu\.be|player\.vimeo\.com|vimeo\.com)/i', $m[0])) return '';
        $k = "@@BLOCO{$idx}@@"; $idx++;
        $blocos[$k] = '<div class="video-wrap">' . $m[0] . '</div>';
        return "\n\n{$k}\n\n";
    }, $md);

    $md = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');
    $md = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $md);
    $md = preg_replace('/^## (.+)$/m',  '<h2>$1</h2>', $md);
    $md = preg_replace('/^# (.+)$/m',   '<h1>$1</h1>', $md);
    $md = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $md);
    $md = preg_replace('/\*\*(.+?)\*\*/',     '<strong>$1</strong>', $md);
    $md = preg_replace('/\*(.+?)\*/',          '<em>$1</em>', $md);
    $md = preg_replace('/_(.+?)_/',            '<em>$1</em>', $md);
    $md = preg_replace('/~~(.+?)~~/',          '<del>$1</del>', $md);
    $md = preg_replace('/!\[audio\]\(([^)]+)\)/', '<audio controls><source src="$1" type="audio/mpeg">Seu navegador não suporta áudio.</audio>', $md);
    $md = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1" class="post-img" loading="lazy">', $md);
    $md = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" rel="noopener">$1</a>', $md);
    $md = preg_replace('/`(.+?)`/', '<code>$1</code>', $md);
    $md = preg_replace('/^&gt; (.+)$/m', '<blockquote>$1</blockquote>', $md);
    $md = preg_replace('/^---$/m', '<hr>', $md);

    $parags = preg_split('/\n{2,}/', trim($md));
    $html = '';
    foreach ($parags as $p) {
        $p = trim($p);
        if (!$p) continue;
        if (preg_match('/^(<h[1-6]|<blockquote|<pre|<ul|<ol|<hr|<audio|<div|@@BLOCO)/', $p)) {
            $html .= $p . "\n";
        } else {
            $p = str_replace("\n", '<br>', $p);
            $html .= '<p>' . $p . "</p>\n";
        }
    }
    foreach ($blocos as $k => $v) $html = str_replace($k, $v, $html);
    return $html;
}

$html_sobre = md_to_html($conteudo);

ob_start();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sobre — <?= htmlspecialchars(BLOG_TITLE) ?></title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">

  <header class="site-header">
    <div class="inner">
      <div>
        <a href="index.php" class="site-title"><?= htmlspecialchars(BLOG_TITLE) ?></a>
        <div class="site-subtitle"><?= htmlspecialchars(BLOG_SUBTITLE) ?></div>
      </div>
      <div class="header-actions">
        <a href="sobre.php" class="post-tag">sobre</a>
        <a href="arquivo.php" class="post-tag">arquivo</a>
        <button class="btn-theme" id="theme-toggle" title="Alternar modo escuro">🌙</button>
      </div>
    </div>
  </header>

  <main>
    <a href="index.php" class="btn-back">← voltar</a>

    <header class="post-header">
      <h1 class="post-title">Sobre</h1>
    </header>

    <article class="post-body">
      <?= $html_sobre ?>
    </article>
  </main>

  <footer class="site-footer">
    <span class="accent-dot">·</span>
  </footer>

</div>

<script>
var btn  = document.getElementById('theme-toggle');
var html = document.documentElement;
function applyTheme(t) {
  html.dataset.theme = t;
  btn.textContent    = t === 'dark' ? '☀' : '🌙';
}
applyTheme(localStorage.getItem('theme') || 'light');
btn.addEventListener('click', function() {
  var n = html.dataset.theme === 'dark' ? 'light' : 'dark';
  localStorage.setItem('theme', n);
  applyTheme(n);
});
</script>
</body>
</html>
<?php
$html_final = ob_get_clean();
if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
file_put_contents($cache_file, $html_final);
echo $html_final;