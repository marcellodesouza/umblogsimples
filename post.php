<?php
require_once __DIR__ . '/config.php';

$slug = $_GET['slug'] ?? '';
$slug = preg_replace('/[^a-zA-Z0-9_\-]/', '', $slug);
if (!$slug) { header('Location: index.php'); exit; }

$arquivo = POSTS_DIR . '/' . $slug . '.md';
if (!file_exists($arquivo)) { http_response_code(404); die('Post não encontrado.'); }

// ── Cache ──────────────────────────────────────────────────────────────────
$cache_key  = 'post_' . $slug;
$cache_file = CACHE_DIR . '/' . $cache_key . '.html';

// Serve cache se existir e for mais recente que o .md
if (file_exists($cache_file) && filemtime($cache_file) >= filemtime($arquivo)) {
    echo file_get_contents($cache_file);
    exit;
}

// ── Processa o post ────────────────────────────────────────────────────────
$conteudo_raw = file_get_contents($arquivo);
$status = '';
$titulo = $slug; $tag = ''; $conteudo = $conteudo_raw;

if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $conteudo_raw, $m)) {
    if (preg_match('/^titulo:\s*(.+)$/m', $m[1], $t)) $titulo = trim($t[1]);
    if (preg_match('/^tag:\s*(.+)$/m',    $m[1], $t)) $tag    = trim($t[1]);
    if (preg_match('/^status:\s*(.+)$/m', $m[1], $t)) $status = trim($t[1]);
    $conteudo = substr($conteudo_raw, strlen($m[0]));
}

if ($status === 'rascunho') { http_response_code(404); die('Post não encontrado.'); }

$data_fmt = '';
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $slug, $d)) {
    $meses = ['','jan','fev','mar','abr','mai','jun','jul','ago','set','out','nov','dez'];
    $data_fmt = (int)$d[3] . ' de ' . $meses[(int)$d[2]] . ' de ' . $d[1];
}

function md_to_html(string $md): string {
    $md = str_replace(["\r\n", "\r"], "\n", $md);

    $blocos = [];
    $idx    = 0;

    // 1. Extrai blocos de código ANTES de escapar HTML
    $md = preg_replace_callback('/```(\w*)\n([\s\S]*?)```/m', function($m) use (&$blocos, &$idx) {
        $k = "@@BLOCO{$idx}@@"; $idx++;
        $lang = $m[1] ? ' class="language-' . htmlspecialchars($m[1]) . '"' : '';
        $blocos[$k] = '<pre><code' . $lang . '>' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '</code></pre>';
        return $k;
    }, $md);

    // 2. Extrai iframes de domínios seguros
    $md = preg_replace_callback('/<iframe[^>]*>.*?<\/iframe>/si', function($m) use (&$blocos, &$idx) {
        if (!preg_match('/src=["\']https:\/\/(www\.)?(youtube\.com|youtu\.be|player\.vimeo\.com|vimeo\.com)/i', $m[0])) return '';
        $k = "@@BLOCO{$idx}@@"; $idx++;
        $blocos[$k] = '<div class="video-wrap">' . $m[0] . '</div>';
        return "\n\n{$k}\n\n";
    }, $md);

    // 3. Escapa HTML do restante
    $md = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');

    // 4. Headers
    $md = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $md);
    $md = preg_replace('/^## (.+)$/m',  '<h2>$1</h2>', $md);
    $md = preg_replace('/^# (.+)$/m',   '<h1>$1</h1>', $md);

    // 5. Negrito / itálico / tachado
    $md = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $md);
    $md = preg_replace('/\*\*(.+?)\*\*/',     '<strong>$1</strong>',          $md);
    $md = preg_replace('/\*(.+?)\*/',          '<em>$1</em>',                 $md);
    $md = preg_replace('/_(.+?)_/',            '<em>$1</em>',                 $md);
    $md = preg_replace('/~~(.+?)~~/',          '<del>$1</del>',               $md);

    // 6. Áudio
    $md = preg_replace('/!\[audio\]\(([^)]+)\)/',
        '<audio controls><source src="$1" type="audio/mpeg">Seu navegador não suporta áudio.</audio>', $md);

    // 7. Imagens
    $md = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/',
        '<img src="$2" alt="$1" class="post-img" loading="lazy">', $md);

    // 8. Links
    $md = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" rel="noopener">$1</a>', $md);

    // 9. Código inline
    $md = preg_replace('/`(.+?)`/', '<code>$1</code>', $md);

    // 10. Citação
    $md = preg_replace('/^&gt; (.+)$/m', '<blockquote>$1</blockquote>', $md);

    // 11. Separador
    $md = preg_replace('/^---$/m', '<hr>', $md);

    // 12. Listas não ordenadas (com suporte a tarefas)
    $md = preg_replace_callback('/(^- .+\n?)+/m', function($m) {
        $items = preg_split('/\n/', trim($m[0]));
        $html = '<ul class="post-list">';
        foreach ($items as $i) {
            $i = preg_replace('/^- /', '', $i);
            if (!trim($i)) continue;
            if (preg_match('/^\[x\] /i', $i)) {
                $i = preg_replace('/^\[x\] /i', '', $i);
                $html .= '<li class="task-item task-done"><span class="task-box task-box-done">&#10003;</span>' . $i . '</li>';
            } elseif (preg_match('/^\[ \] /', $i)) {
                $i = preg_replace('/^\[ \] /', '', $i);
                $html .= '<li class="task-item"><span class="task-box">&#9675;</span>' . $i . '</li>';
            } else {
                $html .= '<li>' . $i . '</li>';
            }
        }
        return $html . '</ul>';
    }, $md);

    // 13. Listas ordenadas
    $md = preg_replace_callback('/(^\d+\. .+\n?)+/m', function($m) {
        $items = preg_split('/\n/', trim($m[0]));
        $html = '<ol>';
        foreach ($items as $i) {
            $i = preg_replace('/^\d+\. /', '', $i);
            if (trim($i)) $html .= '<li>' . $i . '</li>';
        }
        return $html . '</ol>';
    }, $md);

    // 14. Tabelas
    $md = preg_replace_callback('/^(\|.+\|
)((?:\|[-:| ]+\|
))((?:\|.+\|
?)+)/m', function($m) {
        $headers = array_filter(array_map('trim', explode('|', trim($m[1], "| 
"))));
        $html = '<div class="table-wrap"><table><thead><tr>';
        foreach ($headers as $h) $html .= '<th>' . $h . '</th>';
        $html .= '</tr></thead><tbody>';
        $rows = array_filter(explode("
", trim($m[3])));
        foreach ($rows as $row) {
            $cols = array_filter(array_map('trim', explode('|', trim($row, "| 
"))));
            if (!$cols) continue;
            $html .= '<tr>';
            foreach ($cols as $col) $html .= '<td>' . $col . '</td>';
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }, $md);

    // 15. Parágrafos
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

    foreach ($blocos as $k => $v) {
        $html = str_replace($k, $v, $html);
    }

    return $html;
}

$html_post = md_to_html($conteudo);

// ── Gera e salva o HTML completo ───────────────────────────────────────────
ob_start();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($titulo) ?> — <?= htmlspecialchars(BLOG_TITLE) ?></title>
  <link rel="stylesheet" href="style.css">
  <link id="hl-theme" rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
</head>
<body>
<div class="container">

  <header class="site-header">
    <div class="inner">
      <a href="index.php" class="site-title"><?= htmlspecialchars(BLOG_TITLE) ?></a>
      <div class="header-actions">
        <a href="arquivo.php" class="btn" style="font-size:var(--text-xs)">arquivo</a>
        <button class="btn-theme" id="theme-toggle" title="Alternar modo escuro">🌙</button>
      </div>
    </div>
  </header>

  <main>
    <a href="index.php" class="btn-back">← voltar</a>

    <header class="post-header">
      <h1 class="post-title"><?= htmlspecialchars($titulo) ?></h1>
      <div class="post-meta">
        <span class="post-date"><?= $data_fmt ?: date('j \de F \de Y', filemtime($arquivo)) ?></span>
        <?php if ($tag): ?>
          <span class="post-tag"><?= htmlspecialchars($tag) ?></span>
        <?php endif; ?>
      </div>
    </header>

    <article class="post-body">
      <?= $html_post ?>
    </article>
  </main>

  <footer class="site-footer">
    <span class="accent-dot">·</span>
  </footer>

</div>

<script>
var btn     = document.getElementById('theme-toggle');
var html    = document.documentElement;
var hlTheme = document.getElementById('hl-theme');
var CDN     = 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/';

function applyTheme(t) {
  html.dataset.theme = t;
  btn.textContent    = t === 'dark' ? '☀' : '🌙';
  hlTheme.href = CDN + (t === 'dark' ? 'github-dark.min.css' : 'github.min.css');
}

applyTheme(localStorage.getItem('theme') || 'light');
document.addEventListener('DOMContentLoaded', function() { hljs.highlightAll(); });
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

// Salva no cache
if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
file_put_contents($cache_file, $html_final);

echo $html_final;