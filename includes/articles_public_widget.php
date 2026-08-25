<?php
/** Публичный блок статей (только status=published) */
if (!function_exists('articles_public_render')) {
  function articles_public_render(?int $channelId = null, ?int $videoId = null, int $limit = 12, string $title = 'Статьи'): void {
    if (!function_exists('content_articles_list')) {
      if (is_file(__DIR__ . '/content_articles.php')) require_once __DIR__ . '/content_articles.php';
    }
    if (!function_exists('content_articles_list')) return;
    $list = [];
    if ($videoId) $list = content_articles_list(null, $videoId, $limit, true);
    if (!$list && $channelId) $list = content_articles_list($channelId, null, $limit, true);
    if (!$list) return;
    echo '<section class="articles-public" style="margin:18px 0;padding:14px;border-radius:12px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06)">';
    echo '<h3 style="margin:0 0 12px;font-size:16px">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>';
    echo '<ul style="list-style:none;margin:0;padding:0;display:grid;gap:10px">';
    foreach ($list as $a) {
      $id = (int)($a['id'] ?? 0);
      $t = htmlspecialchars((string)($a['title'] ?? ''), ENT_QUOTES, 'UTF-8');
      $v = (int)($a['views'] ?? 0);
      $u = htmlspecialchars((string)($a['username'] ?? ''), ENT_QUOTES, 'UTF-8');
      echo '<li style="display:flex;gap:10px;align-items:center">';
      if (!empty($a['cover_url'])) {
        $c = htmlspecialchars((string)$a['cover_url'], ENT_QUOTES, 'UTF-8');
        echo '<img src="' . $c . '" alt="" width="64" height="40" style="object-fit:cover;border-radius:8px;flex-shrink:0">';
      }
      echo '<div style="min-width:0"><a href="/article.php?id=' . $id . '" style="color:inherit;font-weight:600;text-decoration:none">' . $t . '</a>';
      echo '<div style="font-size:12px;opacity:.65">' . $v . ' просм.' . ($u ? ' · ' . $u : '') . '</div></div></li>';
    }
    echo '</ul></section>';
  }
}
