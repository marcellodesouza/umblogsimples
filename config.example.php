<?php
// ── Um Blog Simples — Configuração ─────────────────────────────────────────
//
// Renomeie este arquivo para config.php e preencha com os seus dados.
// NUNCA suba o config.php para o GitHub — ele contém sua senha.
//
// ───────────────────────────────────────────────────────────────────────────

// Nome do blog (aparece no header e no título da aba)
define('BLOG_TITLE',    'Meu Blog');

// Subtítulo (aparece abaixo do nome na home)
define('BLOG_SUBTITLE', 'escrevo aqui.');

// Senha para acessar o editor — gere o hash com:
// php -r "echo password_hash('sua-senha', PASSWORD_DEFAULT);"
// Ou acesse: https://bcrypt-generator.com
define('BLOG_PASSWORD_HASH', '$2y$12$SUBSTITUA_PELO_SEU_HASH_AQUI');

// ── Caminhos no servidor ────────────────────────────────────────────────────
define('POSTS_DIR',   __DIR__ . '/posts');
define('UPLOADS_DIR', __DIR__ . '/uploads');
define('CACHE_DIR',   __DIR__ . '/cache');

// ── URLs públicas ───────────────────────────────────────────────────────────
// Raiz do site:    define('UPLOADS_URL', '/uploads');
// Em subpasta:     define('UPLOADS_URL', '/blog/uploads');
define('UPLOADS_URL', '/uploads');

// ── Tipos de arquivo permitidos no upload ───────────────────────────────────
define('ALLOWED_IMAGE', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('ALLOWED_AUDIO', ['mp3', 'ogg', 'wav', 'm4a']);
define('ALLOWED_VIDEO', ['mp4']);

// Tamanho máximo de upload em MB
define('MAX_UPLOAD_MB', 20);

// ── Proteção contra força bruta ─────────────────────────────────────────────
// Número máximo de tentativas de login antes de bloquear
define('MAX_LOGIN_ATTEMPTS', 5);
// Tempo de bloqueio em segundos (padrão: 15 minutos)
define('LOGIN_LOCKOUT_SECONDS', 900);
