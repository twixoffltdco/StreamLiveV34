<?php
/**
 * Переключатель UI + данные пользователя для оболочки Платформы
 * Modes: streamlife|platforma|telegram|prohub|flex|smotrim|streamvideolive
 */
try {
  if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) {
    @session_start();
  }
} catch (Throwable $e) { /* ignore */ }

$pl_mode = 'streamlife';
try {
  if (!empty($_COOKIE['pl_ui_mode'])) {
    $pl_mode = (string)$_COOKIE['pl_ui_mode'];
  } elseif (!empty($_SESSION['pl_ui_mode'])) {
    $pl_mode = (string)$_SESSION['pl_ui_mode'];
  }
} catch (Throwable $e) {}
if (!in_array($pl_mode, ['streamlife', 'platforma', 'telegram', 'prohub', 'flex', 'smotrim', 'streamvideolive'], true)) {
  $pl_mode = 'streamlife';
}

$pl_skin = 'dark';
$pl_accent = 'red';
$pl_preset = '';
if (!empty($_COOKIE['pl_skin']) && in_array($_COOKIE['pl_skin'], ['dark', 'light'], true)) {
  $pl_skin = $_COOKIE['pl_skin'];
}
if (!empty($_COOKIE['site_color_mode']) && $_COOKIE['site_color_mode'] === 'light') {
  $pl_skin = 'light';
}
if (!empty($_COOKIE['pl_accent']) && in_array($_COOKIE['pl_accent'], ['red','blue','orange','green','purple','pink'], true)) {
  $pl_accent = $_COOKIE['pl_accent'];
}
if (!empty($_COOKIE['pl_preset'])) {
  $pl_preset = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$_COOKIE['pl_preset']));
}

$pl_logged = false;
$pl_username = '';
$pl_uid = 0;
try {
  if (function_exists('current_user')) {
    $u = current_user();
    if ($u && !empty($u['id'])) {
      $pl_uid = (int)$u['id'];
      $pl_logged = true;
      $pl_username = (string)($u['username'] ?? $u['name'] ?? '');
    }
  }
  if (!$pl_logged && !empty($_SESSION['user_id'])) {
    $pl_uid = (int)$_SESSION['user_id'];
    $pl_logged = $pl_uid > 0;
    $pl_username = (string)($_SESSION['user']['username'] ?? $_SESSION['username'] ?? '');
  }
} catch (Throwable $e) {}

$pl_subs = [];
$pl_sw = '/platforma/switch.php';
$pl_r = rawurlencode((string)($_SERVER['REQUEST_URI'] ?? '/'));
?>
<style>
.pl-mode-switch{display:flex;flex-wrap:wrap;gap:6px;padding:8px 12px;align-items:center}
.pl-mode-switch a{padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700;text-decoration:none;color:#ccc;background:rgba(255,255,255,.06)}
.pl-mode-switch a:hover{color:#fff;background:rgba(255,255,255,.08)}
.pl-mode-switch a.on-sl{background:#f1f1f1;color:#0f0f0f}
.pl-mode-switch a.on-pl{background:#ff0000;color:#fff}
.pl-mode-switch a.on-tg{background:#2AABEE;color:#fff}
.pl-mode-switch a.on-ph{background:#3b82f6;color:#fff}
.pl-mode-switch a.on-fx{background:#00a2ff;color:#fff}
.pl-mode-switch a.on-sm{background:#e31c25;color:#fff}
.pl-mode-switch a.on-svl{background:#ff3347;color:#fff}
@media(max-width:700px){.pl-mode-switch a{padding:8px 10px;font-size:12px;min-height:36px;display:inline-flex;align-items:center}}
</style>
<div class="pl-mode-switch" role="navigation" aria-label="Стиль интерфейса">
  <a href="<?= htmlspecialchars($pl_sw . '?mode=streamlife&redirect=' . $pl_r, ENT_QUOTES, 'UTF-8') ?>" class="<?= $pl_mode === 'streamlife' ? 'on-sl' : '' ?>">StreamLife</a>
  <a href="<?= htmlspecialchars($pl_sw . '?mode=platforma&redirect=' . $pl_r, ENT_QUOTES, 'UTF-8') ?>" class="<?= $pl_mode === 'platforma' ? 'on-pl' : '' ?>">Платформа</a>
  <a href="<?= htmlspecialchars($pl_sw . '?mode=telegram&redirect=' . $pl_r, ENT_QUOTES, 'UTF-8') ?>" class="<?= $pl_mode === 'telegram' ? 'on-tg' : '' ?>">Telegram</a>
  <a href="<?= htmlspecialchars($pl_sw . '?mode=prohub&redirect=' . $pl_r, ENT_QUOTES, 'UTF-8') ?>" class="<?= $pl_mode === 'prohub' ? 'on-ph' : '' ?>">ProHub</a>
  <a href="<?= htmlspecialchars($pl_sw . '?mode=flex&redirect=' . $pl_r, ENT_QUOTES, 'UTF-8') ?>" class="<?= $pl_mode === 'flex' ? 'on-fx' : '' ?>">Flex</a>
  <a href="<?= htmlspecialchars($pl_sw . '?mode=smotrim&redirect=' . $pl_r, ENT_QUOTES, 'UTF-8') ?>" class="<?= $pl_mode === 'smotrim' ? 'on-sm' : '' ?>">Смотрим</a>
  <a href="<?= htmlspecialchars($pl_sw . '?mode=streamvideolive&redirect=' . $pl_r, ENT_QUOTES, 'UTF-8') ?>" class="<?= $pl_mode === 'streamvideolive' ? 'on-svl' : '' ?>">Stream Video Live</a>
</div>
<?php if ($pl_mode === 'streamvideolive'): ?>
<link rel="stylesheet" href="/platforma/theme-streamvideolive.css?v=3">
<script>
window.PL_SVL = {
  logged: <?= $pl_logged ? 'true' : 'false' ?>,
  id: <?= (int)$pl_uid ?>,
  name: <?= json_encode($pl_username, JSON_UNESCAPED_UNICODE) ?>
};
try{
  document.documentElement.classList.add('pl-theme-svl');
  document.addEventListener('DOMContentLoaded',function(){
    if(document.body) document.body.classList.add('pl-theme-svl');
  });
}catch(e){}
</script>
<script src="/platforma/theme-streamvideolive.js?v=3" defer></script>
<?php elseif ($pl_mode === 'platforma'): ?>
<link rel="stylesheet" href="/platforma/theme-platforma.css?v=20260803pl20">
<script>try{document.documentElement.classList.add('pl-theme-platforma');}catch(e){}</script>
<script src="/platforma/theme-platforma.js?v=20260803pl20" defer></script>
<?php elseif ($pl_mode === 'smotrim'): ?>
<link rel="stylesheet" href="/platforma/theme-smotrim.css?v=20260824org">
<script>try{document.documentElement.classList.add('pl-theme-smotrim');}catch(e){}</script>
<script src="/platforma/theme-smotrim.js?v=20260824org" defer></script>
<?php endif; ?>
