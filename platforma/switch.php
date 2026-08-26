<?php
/**
 * Переключение UI-режима (cookie + session)
 * /platforma/switch.php?mode=streamlife|platforma|telegram|prohub|flex|smotrim|streamvideolive&redirect=/
 */
$mode = (string)($_GET['mode'] ?? 'streamlife');
$allowed = ['streamlife', 'platforma', 'telegram', 'prohub', 'flex', 'smotrim', 'streamvideolive'];
if (!in_array($mode, $allowed, true)) {
  $mode = 'streamlife';
}
setcookie('pl_ui_mode', $mode, [
  'expires' => time() + 86400 * 365,
  'path' => '/',
  'secure' => false,
  'httponly' => false,
  'samesite' => 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) {
  @session_start();
}
$_SESSION['pl_ui_mode'] = $mode;

$redirect = (string)($_GET['redirect'] ?? '/');
if ($redirect === '' || preg_match('#^(https?:)?//#i', $redirect)) {
  $redirect = '/';
}
if ($redirect[0] !== '/') {
  $redirect = '/' . $redirect;
}
if ($mode === 'flex') {
  $redirect = '/flex/world.php';
}
header('Cache-Control: no-store');
header('Location: ' . $redirect, true, 302);
exit;
