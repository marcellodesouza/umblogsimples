<?php
require_once __DIR__ . '/config.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

function json_ok(mixed $data = null): void {
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}
function json_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function requer_auth(): void {
    if (empty($_SESSION['autenticado'])) json_err('Não autenticado', 401);
}
function slug_seguro(string $s): string {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $s);
}

// ── Validação CSRF ─────────────────────────────────────────────────────────
function validar_csrf(): void {
    $token_sessao  = $_SESSION['csrf_token'] ?? '';
    $token_request = $_SERVER['HTTP_X_CSRF_TOKEN']
                  ?? $_POST['csrf_token']
                  ?? '';
    if (!$token_sessao || !hash_equals($token_sessao, $token_request)) {
        json_err('Token inválido', 403);
    }
}

// ── Cache helpers ──────────────────────────────────────────────────────────
function cache_invalidar_post(string $slug): void {
    $f = CACHE_DIR . '/post_' . $slug . '.html';
    if (file_exists($f)) unlink($f);
}
function cache_invalidar_listagens(): void {
    if (!is_dir(CACHE_DIR)) return;
    foreach (glob(CACHE_DIR . '/index.html')    as $f) unlink($f);
    foreach (glob(CACHE_DIR . '/arquivo*.html') as $f) unlink($f);
}

// ── Rate limit de upload (por sessão) ─────────────────────────────────────
function verificar_rate_limit_upload(): void {
    $janela   = 60;   // segundos
    $max      = 20;   // uploads por janela
    $agora    = time();
    $uploads  = $_SESSION['upload_log'] ?? [];

    // Remove entradas fora da janela
    $uploads = array_filter($uploads, fn($t) => $agora - $t < $janela);

    if (count($uploads) >= $max) {
        json_err('Muitos uploads. Aguarde um momento.', 429);
    }

    $uploads[] = $agora;
    $_SESSION['upload_log'] = array_values($uploads);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Login ──────────────────────────────────────────────────────────────────
if ($action === 'login') {
    $senha = $_POST['senha'] ?? '';
    if (password_verify($senha, BLOG_PASSWORD_HASH)) {
        $_SESSION['autenticado'] = true;
        session_regenerate_id(true);
        json_ok();
    }
    json_err('Senha incorreta', 403);
}

// ── Logout ─────────────────────────────────────────────────────────────────
if ($action === 'logout') {
    session_destroy();
    json_ok();
}

// A partir daqui: autenticação + CSRF obrigatórios
requer_auth();
validar_csrf();

// ── Listar posts ───────────────────────────────────────────────────────────
if ($action === 'list') {
    $posts    = [];
    $arquivos = is_dir(POSTS_DIR) ? (glob(POSTS_DIR . '/*.md') ?: []) : [];
    foreach ($arquivos as $f) $posts[] = basename($f, '.md');
    rsort($posts);
    json_ok($posts);
}

// ── Carregar post ──────────────────────────────────────────────────────────
if ($action === 'load') {
    $slug = slug_seguro($_GET['slug'] ?? '');
    $f    = POSTS_DIR . '/' . $slug . '.md';
    if (!$slug || !file_exists($f)) json_err('Post não encontrado', 404);
    json_ok(['conteudo' => file_get_contents($f)]);
}

// ── Salvar post ────────────────────────────────────────────────────────────
if ($action === 'save') {
    $titulo   = trim($_POST['titulo'] ?? '');
    $tag      = trim($_POST['tag'] ?? '');
    $conteudo = $_POST['conteudo'] ?? '';

    if (!$titulo) json_err('Título obrigatório');

    $data   = date('Y-m-d');
    $slug_t = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $titulo), '-'));
    $slug_t = str_replace(
        ['á','à','ã','â','é','ê','í','ó','ô','õ','ú','ü','ç','ñ'],
        ['a','a','a','a','e','e','i','o','o','o','u','u','c','n'],
        $slug_t
    );
    $slug_t = substr(slug_seguro($slug_t), 0, 60);
    $slug   = $data . '-' . $slug_t;

    $slug_existente = slug_seguro($_POST['slug_original'] ?? '');
    if ($slug_existente && file_exists(POSTS_DIR . '/' . $slug_existente . '.md')) {
        preg_match('/^(\d{4}-\d{2}-\d{2})/', $slug_existente, $dm);
        $data_original = $dm[1] ?? $data;
        $novo_slug = $data_original . '-' . $slug_t;
        if ($novo_slug !== $slug_existente) {
            rename(POSTS_DIR . '/' . $slug_existente . '.md', POSTS_DIR . '/' . $novo_slug . '.md');
            cache_invalidar_post($slug_existente);
        }
        $slug = $novo_slug;
    }

    $status = in_array($_POST['status'] ?? '', ['rascunho','publicado']) ? $_POST['status'] : 'publicado';
    $md = "---
titulo: {$titulo}
tag: {$tag}
status: {$status}
---

{$conteudo}";

    if (!is_dir(POSTS_DIR)) mkdir(POSTS_DIR, 0755, true);
    file_put_contents(POSTS_DIR . '/' . $slug . '.md', $md);

    cache_invalidar_post($slug);
    cache_invalidar_listagens();

    json_ok(['slug' => $slug]);
}

// ── Upload de mídia ────────────────────────────────────────────────────────
if ($action === 'upload') {
    verificar_rate_limit_upload();

    if (empty($_FILES['arquivo'])) json_err('Nenhum arquivo enviado');

    $f   = $_FILES['arquivo'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));

    $tipo = null;
    if (in_array($ext, ALLOWED_IMAGE)) $tipo = 'imagem';
    elseif (in_array($ext, ALLOWED_AUDIO)) $tipo = 'audio';
    elseif (in_array($ext, ALLOWED_VIDEO)) $tipo = 'video';
    else json_err('Tipo de arquivo não permitido');

    // Valida magic bytes para imagens
    if ($tipo === 'imagem') {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $f['tmp_name']);
        finfo_close($finfo);
        $mimes_permitidos = ['image/jpeg','image/png','image/gif','image/webp'];
        if (!in_array($mime, $mimes_permitidos)) json_err('Tipo de arquivo inválido');
    }

    if ($f['size'] > MAX_UPLOAD_MB * 1024 * 1024) json_err('Arquivo maior que ' . MAX_UPLOAD_MB . 'MB');

    if (!is_dir(UPLOADS_DIR)) mkdir(UPLOADS_DIR, 0755, true);

    $nome_seguro = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destino     = UPLOADS_DIR . '/' . $nome_seguro;

    if (!move_uploaded_file($f['tmp_name'], $destino)) json_err('Falha ao salvar arquivo');

    $url = UPLOADS_URL . '/' . $nome_seguro;
    $md_snippet = match($tipo) {
        'imagem' => "![descrição]({$url})",
        'audio'  => "![audio]({$url})",
        'video'  => "Vídeo: [{$nome_seguro}]({$url})",
    };

    json_ok(['url' => $url, 'tipo' => $tipo, 'md' => $md_snippet]);
}

// ── Carregar página Sobre ──────────────────────────────────────────────────
if ($action === 'load_sobre') {
    $f = __DIR__ . '/sobre.md';
    $conteudo = file_exists($f) ? file_get_contents($f) : '';
    json_ok(['conteudo' => $conteudo]);
}

// ── Salvar página Sobre ────────────────────────────────────────────────────
if ($action === 'save_sobre') {
    $conteudo = $_POST['conteudo'] ?? '';
    file_put_contents(__DIR__ . '/sobre.md', $conteudo);
    // Invalida cache do sobre
    $f = CACHE_DIR . '/sobre.html';
    if (file_exists($f)) unlink($f);
    json_ok();
}

json_err('Ação desconhecida', 400);