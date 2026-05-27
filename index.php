<?php
require_once __DIR__ . '/config.php';

$cache_file = CACHE_DIR . '/index.html';
$usar_cache = false;
if (file_exists($cache_file)) {
    $usar_cache  = true;
    $cache_mtime = filemtime($cache_file);
    foreach (glob(POSTS_DIR . '/*.md') as $f) {
        if (filemtime($f) > $cache_mtime) { $usar_cache = false; break; }
    }
}
if ($usar_cache) { echo file_get_contents($cache_file); exit; }

function listar_posts(): array {
    $posts = [];
    if (!is_dir(POSTS_DIR)) return $posts;
    foreach (glob(POSTS_DIR . '/*.md') as $arquivo) {
        $nome     = basename($arquivo, '.md');
        $conteudo = file_get_contents($arquivo);
        $titulo = $nome; $tag = '';
        if (preg_match('/^---\s*\n(.*?)\n---/s', $conteudo, $m)) {
            if (preg_match('/^titulo:\s*(.+)$/m', $m[1], $t)) $titulo = trim($t[1]);
            if (preg_match('/^tag:\s*(.+)$/m',    $m[1], $t)) $tag    = trim($t[1]);
            if (preg_match('/^status:\s*(.+)$/m', $m[1], $t) && trim($t[1]) === 'rascunho') continue;
        }
        $data = null;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $nome, $d)) $data = $d[1];
        $posts[] = [
            'slug'   => $nome,
            'titulo' => $titulo,
            'tag'    => $tag,
            'data'   => $data ?? date('Y-m-d', filemtime($arquivo)),
        ];
    }
    usort($posts, fn($a, $b) => strcmp($b['data'], $a['data']));
    return $posts;
}

function agrupar_por_mes(array $posts): array {
    $meses_pt = [
        1=>'janeiro',2=>'fevereiro',3=>'março',4=>'abril',
        5=>'maio',6=>'junho',7=>'julho',8=>'agosto',
        9=>'setembro',10=>'outubro',11=>'novembro',12=>'dezembro'
    ];
    $grupos = [];
    foreach ($posts as $p) {
        [$ano, $mes] = explode('-', $p['data']);
        $chave = $ano . '-' . $mes;
        $label = $meses_pt[(int)$mes] . ' de ' . $ano;
        $grupos[$chave]['label']   = $label;
        $grupos[$chave]['posts'][] = $p;
    }
    return $grupos;
}

$posts  = listar_posts();
$grupos = agrupar_por_mes($posts);

ob_start();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars(BLOG_TITLE) ?></title>
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
        <button class="btn-theme" id="theme-toggle" title="Alternar modo escuro" aria-label="Modo escuro">🌙</button>
      </div>
    </div>
  </header>

  <main>
    <?php if (empty($posts)): ?>
      <p style="color:var(--text-muted); font-style:italic;">Nenhum post ainda. <a href="editor.php">Escreva o primeiro →</a></p>
    <?php else: ?>
      <?php foreach ($grupos as $chave => $grupo): ?>
        <section class="month-group">
          <div class="month-label">
            <a href="arquivo.php?mes=<?= $chave ?>" style="color:inherit;text-decoration:none;" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='inherit'">
              <?= htmlspecialchars($grupo['label']) ?>
            </a>
          </div>
          <?php foreach ($grupo['posts'] as $p):
            $dia = substr($p['data'], 8, 2);
          ?>
          <article class="post-item">
            <span class="post-day"><?= ltrim($dia, '0') ?></span>
            <a href="/blog/<?= urlencode($p['slug']) ?>" class="post-item-title">
              <?= htmlspecialchars($p['titulo']) ?>
            </a>
            <?php if ($p['tag']): ?>
              <span class="post-tag"><?= htmlspecialchars($p['tag']) ?></span>
            <?php endif; ?>
          </article>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>

  <footer class="site-footer">
    <span class="accent-dot">·</span>
  </footer>

</div>

<script>
const html  = document.documentElement;
const btn   = document.getElementById('theme-toggle');
const saved = localStorage.getItem('theme') || 'light';
function applyTheme(t) {
  html.dataset.theme = t;
  btn.textContent    = t === 'dark' ? '☀️' : '🌙';
}
applyTheme(saved);
btn.addEventListener('click', () => {
  const next = html.dataset.theme === 'dark' ? 'light' : 'dark';
  localStorage.setItem('theme', next);
  applyTheme(next);
});
</script>
</body>
</html>
<?php
$html_final = ob_get_clean();
if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
file_put_contents($cache_file, $html_final);
echo $html_final;