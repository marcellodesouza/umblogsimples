<?php
require_once __DIR__ . '/config.php';

$meses_pt = [
    1=>'janeiro',2=>'fevereiro',3=>'março',4=>'abril',
    5=>'maio',6=>'junho',7=>'julho',8=>'agosto',
    9=>'setembro',10=>'outubro',11=>'novembro',12=>'dezembro'
];

$filtro = preg_replace('/[^0-9\-]/', '', $_GET['mes'] ?? '');

// ── Cache ──────────────────────────────────────────────────────────────────
$cache_key  = $filtro ? 'arquivo_' . $filtro : 'arquivo';
$cache_file = CACHE_DIR . '/' . $cache_key . '.html';

$usar_cache = false;
if (file_exists($cache_file)) {
    $usar_cache  = true;
    $cache_mtime = filemtime($cache_file);
    foreach (glob(POSTS_DIR . '/*.md') as $f) {
        if (filemtime($f) > $cache_mtime) { $usar_cache = false; break; }
    }
}
if ($usar_cache) { echo file_get_contents($cache_file); exit; }

// ── Processa ───────────────────────────────────────────────────────────────
function listar_posts(): array {
    $posts = [];
    if (!is_dir(POSTS_DIR)) return $posts;
    foreach (glob(POSTS_DIR . '/*.md') as $arquivo) {
        $nome     = basename($arquivo, '.md');
        $conteudo = file_get_contents($arquivo);
        $titulo   = $nome; $tag = '';
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

$todos  = listar_posts();
$grupos = [];
foreach ($todos as $p) {
    [$ano, $mes] = explode('-', $p['data']);
    $chave = $ano . '-' . $mes;
    $grupos[$chave][] = $p;
}

if ($filtro && isset($grupos[$filtro])) {
    $posts_exibir  = $grupos[$filtro];
    [$ano_f, $mes_f] = explode('-', $filtro);
    $titulo_pagina = $meses_pt[(int)$mes_f] . ' de ' . $ano_f;
} else {
    $filtro        = '';
    $posts_exibir  = [];
    $titulo_pagina = '';
}

ob_start();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Arquivo — <?= htmlspecialchars(BLOG_TITLE) ?></title>
  <link rel="stylesheet" href="style.css">
  <style>
    .arquivo-layout {
      display: grid;
      grid-template-columns: 200px 1fr;
      gap: 3rem;
      margin-top: 2rem;
    }
    .arquivo-nav { position: sticky; top: calc(var(--header-h) + 1.5rem); align-self: start; }
    .arquivo-nav-title {
      font-family: var(--font-mono);
      font-size: var(--text-xs);
      letter-spacing: .1em;
      text-transform: uppercase;
      color: var(--text-muted);
      margin-bottom: 1rem;
    }
    .arquivo-mes-link {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: .4rem .65rem;
      border-radius: var(--radius);
      font-size: var(--text-sm);
      color: var(--text-muted);
      text-decoration: none;
      transition: background .15s, color .15s;
      gap: .5rem;
    }
    .arquivo-mes-link:hover { background: var(--accent-soft); color: var(--accent); text-decoration: none; }
    .arquivo-mes-link.ativo { background: var(--accent-soft); color: var(--accent); font-weight: 500; }
    .arquivo-mes-count {
      font-family: var(--font-mono);
      font-size: var(--text-xs);
      color: var(--accent);
      background: var(--accent-soft);
      border: 1px solid var(--accent);
      border-radius: 4px;
      padding: .1rem .4rem;
      flex-shrink: 0;
    }
    .arquivo-mes-link.ativo .arquivo-mes-count { background: var(--accent); color: #fff; }
    .arquivo-header {
      margin-bottom: 2rem;
      padding-bottom: 1.25rem;
      border-bottom: 1px solid var(--border);
    }
    .arquivo-header h2 {
      font-family: var(--font-display);
      font-size: var(--text-xl);
      font-weight: 400;
      letter-spacing: -.02em;
    }
    .arquivo-empty { color: var(--text-muted); font-style: italic; margin-top: 3rem; text-align: center; }
    @media (max-width: 640px) {
      .arquivo-layout { grid-template-columns: 1fr; gap: 2rem; }
      .arquivo-nav { position: static; }
    }
  </style>
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

    <?php if (empty($todos)): ?>
      <p class="arquivo-empty">Nenhum post ainda.</p>
    <?php else: ?>
    <div class="arquivo-layout">
      <nav class="arquivo-nav">
        <div class="arquivo-nav-title">por mês</div>
        <?php foreach ($grupos as $chave => $lista):
          [$ano, $mes] = explode('-', $chave);
          $label = $meses_pt[(int)$mes] . ' ' . $ano;
          $ativo = $chave === $filtro ? ' ativo' : '';
        ?>
          <a href="arquivo.php?mes=<?= $chave ?>" class="arquivo-mes-link<?= $ativo ?>">
            <span><?= $label ?></span>
            <span class="arquivo-mes-count"><?= count($lista) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <section>
        <?php if (!$filtro): ?>
          <p class="arquivo-empty" style="text-align:left;margin-top:0">Selecione um mês ao lado.</p>
        <?php else: ?>
          <div class="arquivo-header">
            <h2><?= htmlspecialchars($titulo_pagina) ?></h2>
          </div>
          <?php foreach ($posts_exibir as $p):
            $dia = substr($p['data'], 8, 2);
          ?>
          <article class="post-item">
            <span class="post-day"><?= ltrim($dia, '0') ?></span>
            <a href="post.php?slug=<?= urlencode($p['slug']) ?>" class="post-item-title">
              <?= htmlspecialchars($p['titulo']) ?>
            </a>
            <?php if ($p['tag']): ?>
              <span class="post-tag"><?= htmlspecialchars($p['tag']) ?></span>
            <?php endif; ?>
          </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>
    </div>
    <?php endif; ?>
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