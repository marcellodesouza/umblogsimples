<?php
require_once __DIR__ . '/config.php';
session_start();

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: editor.php');
    exit;
}

$autenticado = !empty($_SESSION['autenticado']);
$erro_login  = '';

// ── Proteção contra força bruta ────────────────────────────────────────────
if (!$autenticado && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $chave    = 'login_attempts_' . md5($ip);
    $chave_ts = 'login_lockout_' . md5($ip);

    $tentativas  = (int)($_SESSION[$chave]    ?? 0);
    $bloqueado_ate = (int)($_SESSION[$chave_ts] ?? 0);

    if ($bloqueado_ate > time()) {
        $restante = ceil(($bloqueado_ate - time()) / 60);
        $erro_login = "Muitas tentativas. Tente novamente em {$restante} minuto(s).";
    } else {
        $senha = $_POST['senha'] ?? '';
        if (password_verify($senha, BLOG_PASSWORD_HASH)) {
            $_SESSION['autenticado'] = true;
            $_SESSION[$chave]        = 0;
            $_SESSION[$chave_ts]     = 0;
            // Regenera sessão após login bem-sucedido
            session_regenerate_id(true);
            $autenticado = true;
        } else {
            $tentativas++;
            $_SESSION[$chave] = $tentativas;
            if ($tentativas >= MAX_LOGIN_ATTEMPTS) {
                $_SESSION[$chave_ts] = time() + LOGIN_LOCKOUT_SECONDS;
                $erro_login = 'Muitas tentativas. Acesso bloqueado por 15 minutos.';
            } else {
                $restante = MAX_LOGIN_ATTEMPTS - $tentativas;
                $erro_login = "Senha incorreta. {$restante} tentativa(s) restante(s).";
            }
        }
    }
}

// ── CSRF token ─────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Editor</title>
  <link rel="stylesheet" href="style.css">
  <script src="https://cdn.jsdelivr.net/npm/marked@9/marked.min.js"></script>
  <script>document.addEventListener("DOMContentLoaded",function(){marked.setOptions({html:true});});</script>

  <style>
    .btn[title] { position: relative; }
    .btn[title]:hover::after {
      content: attr(title);
      position: absolute;
      bottom: calc(100% + 6px);
      left: 50%;
      transform: translateX(-50%);
      background: var(--text);
      color: var(--bg);
      font-family: var(--font-mono);
      font-size: .7rem;
      white-space: nowrap;
      padding: .25rem .55rem;
      border-radius: 4px;
      pointer-events: none;
      z-index: 200;
      letter-spacing: .03em;
    }
    .btn[title]:hover::before {
      content: '';
      position: absolute;
      bottom: calc(100% + 1px);
      left: 50%;
      transform: translateX(-50%);
      border: 5px solid transparent;
      border-top-color: var(--text);
      pointer-events: none;
      z-index: 200;
    }
  </style>
</head>
<body>

<?php if (!$autenticado): ?>
<div class="container">
  <header class="site-header" style="margin-bottom:0">
    <div class="inner">
      <a href="index.php" class="site-title"><?= htmlspecialchars(BLOG_TITLE) ?></a>
      <button class="btn-theme" id="theme-toggle">🌙</button>
    </div>
  </header>
  <div class="login-wrap">
    <div class="login-box">
      <h2>editor</h2>
      <?php if ($erro_login): ?>
        <p class="login-error"><?= htmlspecialchars($erro_login) ?></p>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <input type="password" name="senha" class="editor-input" placeholder="senha" autofocus autocomplete="current-password">
        <br>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:.5rem">
          entrar
        </button>
      </form>
    </div>
  </div>
</div>
<?php else: ?>

<div class="container">
  <header class="site-header">
    <div class="inner">
      <a href="index.php" class="site-title"><?= htmlspecialchars(BLOG_TITLE) ?></a>
      <div class="header-actions">
        <button class="btn-theme" id="theme-toggle">🌙</button>
        <a href="?logout" class="btn" style="font-size:.8rem">sair</a>
      </div>
    </div>
  </header>

  <div class="editor-wrap">

    <!-- Seletor de post -->
    <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:.75rem">
      <select id="post-select" class="editor-input" style="flex:1">
        <option value="">carregando...</option>
      </select>
      <button class="btn" onclick="carregarPost()">carregar</button>
      <button class="btn" onclick="novoPost()">+ novo</button>
      <button class="btn" title="Editar a página Sobre" onclick="carregarSobre()">sobre</button>
    </div>

    <!-- Titulo e tag -->
    <div class="editor-fields">
      <input type="text" id="titulo" class="editor-input" placeholder="Titulo do post" autocomplete="off">
      <input type="text" id="tag"    class="editor-input" style="max-width:160px" placeholder="tag" autocomplete="off">
    </div>
    <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.75rem;">
      <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:var(--text-xs);color:var(--text-muted);user-select:none;">
        <input type="checkbox" id="chk-rascunho" style="accent-color:var(--accent);width:1rem;height:1rem;">
        salvar como rascunho
      </label>
      <span id="status-badge" style="display:none;font-size:var(--text-xs);font-family:var(--font-mono);padding:.15rem .5rem;border-radius:4px;background:#FFF3CD;color:#856404;border:1px solid #FFEAA7;">rascunho</span>
    </div>

    <!-- Botoes de formatacao -->
    <div class="editor-toolbar">
      <button class="btn" title="Negrito"         onclick="fmt('**','**')"><strong>B</strong></button>
      <button class="btn" title="Itálico"         onclick="fmt('_','_')"><em>i</em></button>
      <button class="btn" title="Código inline"   onclick="fmt('`','`')">`&nbsp;`</button>
      <button class="btn" title="Bloco de código" onclick="fmtBloco()">```</button>
      <button class="btn" title="Citação"         onclick="fmtLinha('> ')">&#10077;</button>
      <button class="btn" title="Subtítulo (H2)"  onclick="fmtLinha('## ')">H2</button>
      <button class="btn" title="Lista"           onclick="fmtLinha('- ')">&#8212;</button>
      <button class="btn" title="Separador"       onclick="fmtLinha('---')">&#247;</button>
      <button class="btn" title="Inserir imagem"  onclick="document.getElementById('up-img').click()">IMG</button>
      <button class="btn" title="Inserir áudio"   onclick="document.getElementById('up-audio').click()">MP3</button>
      <button class="btn" title="Inserir vídeo YouTube / Vimeo" onclick="inserirVideo()">YT/Vimeo</button>
      <input type="file" id="up-img"   accept="image/*" style="display:none" onchange="upload(this)">
      <input type="file" id="up-audio" accept="audio/*" style="display:none" onchange="upload(this)">
      <div class="btn-spacer"></div>
      <button class="btn" id="btn-foco" title="Modo foco — ESC para sair" onclick="toggleFoco()">modo foco</button>
      <button class="btn btn-primary"   title="Salvar e publicar (Ctrl+S)" onclick="publicar()">Publicar</button>
    </div>

    <!-- Abas -->
    <div style="border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;">
      <div style="display:flex;background:var(--bg-2);border-bottom:1px solid var(--border);">
        <button id="tab-md"      onclick="aba('md')"      style="font-family:var(--font-mono);font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;padding:.55rem 1.1rem;border:none;border-bottom:2px solid var(--accent);background:transparent;color:var(--accent);cursor:pointer;">markdown</button>
        <button id="tab-preview" onclick="aba('preview')" style="font-family:var(--font-mono);font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;padding:.55rem 1.1rem;border:none;border-bottom:2px solid transparent;background:transparent;color:var(--text-muted);cursor:pointer;">preview</button>
      </div>
      <div style="height:calc(100vh - 320px);min-height:380px;background:var(--bg);">
        <div id="pane-md"      style="height:100%;display:flex;flex-direction:column;">
          <textarea id="md-input" placeholder="Escreva aqui..." spellcheck="true" style="flex:1;width:100%;border:none;background:transparent;color:var(--text);font-family:var(--font-mono);font-size:.875rem;line-height:1.7;padding:1.25rem;resize:none;outline:none;"></textarea>
        </div>
        <div id="pane-preview" style="height:100%;display:none;overflow-y:auto;"><div id="md-preview" style="padding:1.5rem;">
        </div></div>
      </div>
    </div>

  </div>
</div>

<div class="toast" id="toast"></div>

<!-- CSRF token disponível para o JavaScript -->
<script>var CSRF_TOKEN = '<?= $csrf_token ?>';</script>

<script>
var inp  = document.getElementById('md-input');
var prev = document.getElementById('md-preview');
var tst  = document.getElementById('toast');
var slugOriginal = '';

marked.setOptions({html: true});

// ── Abas ─────────────────────────────────────
function aba(nome) {
  var isMd = (nome === 'md');
  document.getElementById('pane-md').style.display      = isMd ? 'flex' : 'none';
  document.getElementById('pane-preview').style.display = isMd ? 'none' : 'block';
  document.getElementById('tab-md').style.color         = isMd ? 'var(--accent)' : 'var(--text-muted)';
  document.getElementById('tab-preview').style.color    = isMd ? 'var(--text-muted)' : 'var(--accent)';
  document.getElementById('tab-md').style.borderBottomColor      = isMd ? 'var(--accent)' : 'transparent';
  document.getElementById('tab-preview').style.borderBottomColor = isMd ? 'transparent' : 'var(--accent)';
  if (!isMd) prev.innerHTML = marked.parse(inp.value || '');
  else inp.focus();
}

// ── Lista posts ───────────────────────────────
async function listarPosts() {
  var sel = document.getElementById('post-select');
  try {
    var r = await fetch('api.php?action=list', {
      headers: { 'X-CSRF-Token': CSRF_TOKEN }
    });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    var j = await r.json();
    sel.innerHTML = '<option value="">-- novo post --</option>';
    if (!j.ok || j.data.length === 0) {
      if (!j.ok) showToast('Erro: ' + j.error);
      return;
    }
    j.data.forEach(function(slug) {
      var o = document.createElement('option');
      o.value = slug;
      o.textContent = slug.replace(/^[0-9]{4}-[0-9]{2}-[0-9]{2}-/, '').replace(/-/g, ' ');
      sel.appendChild(o);
    });
  } catch(e) {
    sel.innerHTML = '<option>erro ao carregar</option>';
    showToast('Erro: ' + e.message);
  }
}
listarPosts();

// ── Carrega post ──────────────────────────────
async function carregarPost() {
  var slug = document.getElementById('post-select').value;
  if (!slug) { novoPost(); return; }
  showToast('Carregando...', 0);
  try {
    var r = await fetch('api.php?action=load&slug=' + encodeURIComponent(slug), {
      headers: { 'X-CSRF-Token': CSRF_TOKEN }
    });
    var j = await r.json();
    if (!j.ok) { showToast('Erro ao carregar'); return; }
    var md = j.data.conteudo;
    var titulo = '', tag = '', corpo = md, fm = '';
    var partes = md.split('---');
    if (partes.length >= 3 && md.startsWith('---')) {
      fm = partes[1];
      var tm = fm.match(/titulo:\s*(.+)/);
      var gm = fm.match(/tag:\s*(.+)/);
      if (tm) titulo = tm[1].trim();
      if (gm) tag    = gm[1].trim();
      corpo = partes.slice(2).join('---').trim();
    }
    document.getElementById('titulo').value = titulo;
    document.getElementById('tag').value    = tag;
    inp.value    = corpo;
    slugOriginal = slug;
    var isRascunho = fm.match(/status:\s*rascunho/);
    var chk   = document.getElementById('chk-rascunho');
    var badge = document.getElementById('status-badge');
    chk.checked = !!isRascunho;
    badge.style.display = isRascunho ? 'inline' : 'none';
    aba('md');
    showToast('Post carregado');
  } catch(e) { showToast('Falha ao carregar'); }
}

// ── Novo post ─────────────────────────────────
function novoPost() {
  document.getElementById('post-select').value = '';
  document.getElementById('titulo').value = '';
  document.getElementById('tag').value    = '';
  inp.value    = '';
  slugOriginal = '';
  editandoSobre = false;
  aba('md');
}

// ── Formatação ────────────────────────────────
function fmt(a, d) {
  var s = inp.selectionStart, e = inp.selectionEnd;
  var sel = inp.value.slice(s, e) || 'texto';
  inp.value = inp.value.slice(0, s) + a + sel + d + inp.value.slice(e);
  inp.focus();
  inp.setSelectionRange(s + a.length, s + a.length + sel.length);
}

function fmtLinha(p) {
  var s = inp.selectionStart;
  var i = inp.value.lastIndexOf('\n', s - 1) + 1;
  inp.value = inp.value.slice(0, i) + p + inp.value.slice(i);
  inp.focus();
}

function fmtBloco() {
  var s = inp.selectionStart, e = inp.selectionEnd;
  var sel = inp.value.slice(s, e);
  var placeholder = 'código aqui';
  var conteudo = sel || placeholder;
  var antes  = inp.value.slice(0, s);
  var depois = inp.value.slice(e);
  if (antes.length > 0 && !antes.endsWith('\n')) antes += '\n';
  var bloco = '```\n' + conteudo + '\n```';
  inp.value = antes + bloco + depois;
  inp.focus();
  var inicio = antes.length + 4;
  inp.setSelectionRange(inicio, inicio + conteudo.length);
}

// ── Upload ────────────────────────────────────
async function upload(fi) {
  var file = fi.files[0]; if (!file) return;
  showToast('Enviando...', 0);
  var fd = new FormData();
  fd.append('arquivo', file);
  fd.append('csrf_token', CSRF_TOKEN);
  try {
    var r = await fetch('api.php?action=upload', {
      method: 'POST',
      headers: { 'X-CSRF-Token': CSRF_TOKEN },
      body: fd
    });
    var j = await r.json();
    if (!j.ok) { showToast('Erro: ' + j.error); return; }
    var pos = inp.selectionStart;
    inp.value = inp.value.slice(0, pos) + '\n' + j.data.md + '\n' + inp.value.slice(pos);
    showToast('Midia inserida');
  } catch(e) { showToast('Falha no upload'); }
  finally { fi.value = ''; }
}

// ── Vídeo ─────────────────────────────────────
function inserirVideo() {
  var url = prompt('Cole a URL do YouTube ou Vimeo:');
  if (!url || !url.trim()) return;
  url = url.trim();
  var embed = '';
  var yt    = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
  var vimeo = url.match(/vimeo\.com\/([0-9]+)/);
  if (yt) {
    embed = '<iframe width="100%" height="400" src="https://www.youtube.com/embed/' + yt[1] + '" frameborder="0" allowfullscreen></iframe>';
  } else if (vimeo) {
    embed = '<iframe width="100%" height="400" src="https://player.vimeo.com/video/' + vimeo[1] + '" frameborder="0" allowfullscreen></iframe>';
  } else {
    embed = '<iframe width="100%" height="400" src="' + url + '" frameborder="0" allowfullscreen></iframe>';
  }
  var pos = inp.selectionStart;
  inp.value = inp.value.slice(0, pos) + '\n' + embed + '\n' + inp.value.slice(pos);
  inp.focus();
}

// ── Publicar ──────────────────────────────────
async function publicar() {
  // Se está editando o Sobre, salva de forma diferente
  if (editandoSobre) {
    var fd = new FormData();
    fd.append('conteudo', inp.value);
    fd.append('csrf_token', CSRF_TOKEN);
    showToast('Salvando...', 0);
    try {
      var r = await fetch('api.php?action=save_sobre', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF_TOKEN },
        body: fd
      });
      var j = await r.json();
      if (!j.ok) { showToast('Erro: ' + j.error); return; }
      showToast('Página Sobre salva');
    } catch(e) { showToast('Falha ao salvar'); }
    return;
  }
  var titulo = document.getElementById('titulo').value.trim();
  var tag    = document.getElementById('tag').value.trim();
  if (!titulo) { showToast('Adicione um titulo.'); return; }
  var fd = new FormData();
  var rascunho = document.getElementById('chk-rascunho').checked ? 'rascunho' : 'publicado';
  fd.append('titulo', titulo);
  fd.append('tag', tag);
  fd.append('conteudo', inp.value);
  fd.append('slug_original', slugOriginal);
  fd.append('status', rascunho);
  fd.append('csrf_token', CSRF_TOKEN);
  showToast('Salvando...', 0);
  try {
    var r = await fetch('api.php?action=save', {
      method: 'POST',
      headers: { 'X-CSRF-Token': CSRF_TOKEN },
      body: fd
    });
    var j = await r.json();
    if (!j.ok) { showToast('Erro: ' + j.error); return; }
    slugOriginal = j.data.slug;
    listarPosts();
    showToast('Post publicado');
  } catch(e) { showToast('Falha ao salvar'); }
}

// ── Modo foco ─────────────────────────────────
var focoAtivo = false;
function toggleFoco() {
  focoAtivo = !focoAtivo;
  document.body.classList.toggle('focus-mode', focoAtivo);
  document.getElementById('btn-foco').textContent = focoAtivo ? 'sair do foco' : 'modo foco';
  if (focoAtivo) inp.focus();
}

// ── Toast ─────────────────────────────────────
var tt;
function showToast(msg, dur) {
  if (dur === undefined) dur = 2800;
  tst.textContent = msg; tst.classList.add('show');
  clearTimeout(tt);
  if (dur > 0) tt = setTimeout(function() { tst.classList.remove('show'); }, dur);
}

// ── Checkbox rascunho ─────────────────────────
document.getElementById('chk-rascunho').addEventListener('change', function() {
  document.getElementById('status-badge').style.display = this.checked ? 'inline' : 'none';
});

// ── Atalhos ───────────────────────────────────
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && focoAtivo) toggleFoco();
  if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); publicar(); }
});

// ── Modo escuro ───────────────────────────────
var btnT = document.getElementById('theme-toggle');
function applyTheme(t) {
  document.documentElement.dataset.theme = t;
  btnT.textContent = t === 'dark' ? '☀' : '🌙';
}
applyTheme(localStorage.getItem('theme') || 'light');
btnT.addEventListener('click', function() {
  var n = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
  localStorage.setItem('theme', n); applyTheme(n);
});
</script>

<?php endif; ?>

<script>
(function() {
  var h = document.documentElement;
  h.dataset.theme = localStorage.getItem('theme') || 'light';
  var b = document.getElementById('theme-toggle');
  if (b && !b._init) {
    b._init = true;
    b.textContent = h.dataset.theme === 'dark' ? '☀' : '🌙';
    b.addEventListener('click', function() {
      var n = h.dataset.theme === 'dark' ? 'light' : 'dark';
      localStorage.setItem('theme', n); h.dataset.theme = n;
      b.textContent = n === 'dark' ? '☀' : '🌙';
    });
  }
})();
</script>
</body>
</html>