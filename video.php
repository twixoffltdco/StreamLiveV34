<?php
require_once __DIR__ . '/includes/functions.php';
if (is_file(__DIR__ . '/includes/bbcode.php')) require_once __DIR__ . '/includes/bbcode.php';
if (is_file(__DIR__ . '/includes/share.php')) require_once __DIR__ . '/includes/share.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/recommendations.php';
require_once __DIR__ . '/includes/player_ads.php';
require_once __DIR__ . '/includes/video_embed.php';
if (is_file(__DIR__ . '/includes/content_articles.php')) require_once __DIR__ . '/includes/content_articles.php';
if (is_file(__DIR__ . '/includes/premiere_helpers.php')) {
  require_once __DIR__ . '/includes/premiere_helpers.php';
  if (function_exists('premiere_ensure_columns')) premiere_ensure_columns();
  if (function_exists('premiere_mark_used_if_ended')) premiere_mark_used_if_ended();
}


$slug = trim((string)($_GET['slug'] ?? ''));
if (is_file(__DIR__ . '/includes/paid_access.php')) require_once __DIR__ . '/includes/paid_access.php';
if (is_file(__DIR__ . '/includes/video_url_fix.php')) require_once __DIR__ . '/includes/video_url_fix.php';
$video = null;
try {
  $stmt = db()->prepare("SELECT v.*, c.title AS channel_title, c.slug AS channel_slug, c.logo_url AS channel_avatar, c.type AS channel_type, c.owner_id AS channel_owner_id
                          FROM videos v JOIN channels c ON c.id = v.channel_id
                          WHERE v.slug = ? LIMIT 1");
  $stmt->execute([$slug]);
  $video = $stmt->fetch() ?: null;
} catch (Throwable $e) {
  try {
    $stmt = db()->prepare("SELECT v.*, c.title AS channel_title, c.slug AS channel_slug, c.logo_url AS channel_avatar
                            FROM videos v JOIN channels c ON c.id = v.channel_id WHERE v.slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $video = $stmt->fetch() ?: null;
  } catch (Throwable $e2) { $video = null; }
}
if ($video) {
  $__st = strtolower((string)($video['status'] ?? 'published'));
  $__userGate = function_exists('current_user') ? current_user() : null;
  $__own = $__userGate && (
    (int)($video['user_id'] ?? 0) === (int)$__userGate['id']
    || (int)($video['channel_owner_id'] ?? 0) === (int)$__userGate['id']
  );
  $__staff = $__userGate && in_array((string)($__userGate['role'] ?? ''), ['admin','moderator'], true);
  if (in_array($__st, ['draft','failed','deleted'], true) && !$__own && !$__staff) {
    $video = null;
  }
}
if ($video && function_exists('paid_require_video_access')) {
  paid_require_video_access($video, function_exists('current_user') ? current_user() : null);
}
if ($video && function_exists('video_row_fix_urls')) {
  $video = video_row_fix_urls($video);
}

$extraHead = ($extraHead ?? '') . '<link rel="stylesheet" href="/assets/css/youtube-watch.css?v=5">';

// Режим Платформы?
$__platformStyle = false;
if (!empty($_COOKIE['pl_ui_mode']) && $_COOKIE['pl_ui_mode'] === 'platforma') $__platformStyle = true;
if (!$__platformStyle && !empty($_SESSION['pl_ui_mode']) && $_SESSION['pl_ui_mode'] === 'platforma') $__platformStyle = true;
if (!$__platformStyle && !empty($_COOKIE['site_theme']) && stripos((string)$_COOKIE['site_theme'], 'platform') !== false) $__platformStyle = true;
if ($__platformStyle) {
  $extraHead .= '<link rel="stylesheet" href="/assets/css/platform-youtube-shell.css?v=5">'
    . '<link rel="stylesheet" href="/assets/css/platform-videos.css?v=5">';
}

if (!$video) {
  http_response_code(404);
  require_once __DIR__ . '/includes/header.php';
  echo '<div class="container yt-watch-page"><p>Видео не найдено</p></div>';
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

$user = current_user();
if (is_file(__DIR__ . '/includes/content_views.php')) {
  require_once __DIR__ . '/includes/content_views.php';
}
$__views = 0;
try {
  if (function_exists('content_view_register')) {
    $__views = content_view_register('video', (int)$video['id']);
  } else {
    db()->prepare('UPDATE videos SET views_count = COALESCE(views_count,0) + 1 WHERE id = ?')->execute([$video['id']]);
    $__views = (int)($video['views_count'] ?? 0) + 1;
  }
} catch (Throwable $e) {
  try {
    db()->prepare('UPDATE videos SET views = COALESCE(views,0) + 1 WHERE id = ?')->execute([$video['id']]);
  } catch (Throwable $e2) {}
  $__views = (int)($video['views_count'] ?? $video['views'] ?? 0);
}
$video['views_count'] = $__views;
$video['views'] = $__views;

// Премьера (восстановлено из v27)
$__premiereWait = false;
$__premiereAt = 0;
if ($video && function_exists('premiere_state')) {
  $__ps = premiere_state($video);
  $__premiereAt = (int)($__ps['at'] ?? 0);
  $__premiereWait = !empty($__ps['wait']);
} elseif ($video && !empty($video['premiere_at'])) {
  $__premiereAt = (int)strtotime((string)$video['premiere_at']);
  if ($__premiereAt > time()) $__premiereWait = true;
}

if (function_exists('record_video_view')) {
  try { record_video_view((int)$video['id'], $user['id'] ?? null); } catch (Throwable $e) {}
}

$liked = $fav = false;
if ($user) {
  $s = db()->prepare('SELECT id FROM video_likes WHERE video_id = ? AND user_id = ?');
  $s->execute([$video['id'], $user['id']]);
  $liked = (bool)$s->fetch();
  $s = db()->prepare('SELECT id FROM video_favorites WHERE video_id = ? AND user_id = ?');
  $s->execute([$video['id'], $user['id']]);
  $fav = (bool)$s->fetch();
}

$comments = db()->prepare(
  'SELECT vc.*, u.username FROM video_comments vc JOIN users u ON u.id = vc.user_id
   WHERE vc.video_id = ? ORDER BY vc.created_at DESC LIMIT 100'
);
$comments->execute([$video['id']]);
$comments = $comments->fetchAll();

$recs = function_exists('get_recommended_videos')
  ? get_recommended_videos((int)$video['id'], 12)
  : [];

$pageTitle = $video['title'];
$seoDescription = mb_substr($video['description'] ?: $video['title'], 0, 200);
$seoImage = $video['thumbnail_url'];
require_once __DIR__ . '/includes/header.php';

$thumb = $video['thumbnail_url'] ?: '/assets/img/video-placeholder.png';
$chAvatar = $video['channel_avatar'] ?: '/assets/img/avatar-default.png';
?>
<div class="yt-watch<?= $__platformStyle ? ' pl-yt-watch' : '' ?>">
    <div class="pl-watch-cats platform-videos-tabs" style="grid-column:1/-1;margin-bottom:8px">
<?php
  $__cats = [
    '' => 'Все',
    'Музыка' => 'Музыка',
    'Игры' => 'Игры',
    'Новости' => 'Новости',
    'Спорт' => 'Спорт',
    'Обучение' => 'Обучение',
    'Развлечения' => 'Развлечения',
    'Технологии' => 'Технологии',
  ];
  foreach ($__cats as $k => $lab):
    $h = $k === '' ? '/videos' : '/videos?cat=' . rawurlencode($k);
?>
    <a href="<?= e($h) ?>"><?= e($lab) ?></a>
<?php endforeach; ?>
  </div>
<div class="yt-primary">
<!-- player CSS in youtube-watch.css v5 -->

    <?php if (!empty($__premiereWait)): ?>
    <div class="yt-premiere-box">
      <?php if (!empty($video['thumbnail_url'])): ?>
        <img src="<?= e($video['thumbnail_url']) ?>" alt="">
      <?php endif; ?>
      <div class="premiere-overlay">
        <div class="premiere-label">Премьера</div>
        <div class="premiere-title"><?= e($video['title']) ?></div>
        <div class="premiere-time">Начало <b><?= e(date('d.m.Y H:i', (int)$__premiereAt)) ?></b></div>
        <div class="premiere-countdown" id="premiereCountdown">—</div>
        <div class="premiere-note">Видео станет доступно после начала премьеры</div>
      </div>
    </div>
    <script>
    (function(){
      var ts = <?= (int)$__premiereAt ?> * 1000;
      function tick(){
        var el = document.getElementById('premiereCountdown');
        if (!el) return;
        var d = ts - Date.now();
        if (d <= 0) { el.textContent = 'Сейчас'; location.reload(); return; }
        var s = Math.floor(d/1000);
        var h = Math.floor(s/3600); s%=3600;
        var m = Math.floor(s/60); s%=60;
        el.textContent = (h>0?h+':':'') + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
      }
      tick(); setInterval(tick, 1000);
    })();
    </script>
    <?php else: ?>
    <div class="player-wrap yt-player-box">
      <?php
        $emb = (string)$video['embed_url'];
        $plat = (string)$video['platform'];
        $viaEmbedSite = (strpos($emb, 'embedwebsite') !== false)
          || in_array($plat, ['dropbox', 'instagram', 'iframe'], true);
      ?>
      <?php if ($plat === 'mp4' && !$viaEmbedSite): ?>
        <video src="<?= e($emb) ?>" controls poster="<?= e($thumb) ?>"
          class="yt-fill" playsinline></video>
      <?php elseif ($viaEmbedSite):
        if (strpos($emb, 'embedwebsite') === false) {
          $emb = '/embedwebsite/index.php?url=' . rawurlencode($video['source_url'] ?: $emb) . '&embed=1';
        } elseif (strpos($emb, 'embed=') === false) {
          $emb .= (strpos($emb, '?') !== false ? '&' : '?') . 'embed=1';
        }
      ?>
        <iframe src="<?= e($emb) ?>" allowfullscreen loading="lazy"
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
          class="yt-fill"></iframe>
      <?php elseif ($video['platform'] === 'm3u8'): ?>
        <video id="hlsPlayer" controls playsinline poster="<?= e($thumb) ?>"
          class="yt-fill"></video>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.15/hls.min.js"></script>
        <script>
          (function () {
            var src = <?= json_encode($video['embed_url']) ?>;
            var v = document.getElementById('hlsPlayer');
            if (window.Hls && Hls.isSupported()) { var hls = new Hls(); hls.loadSource(src); hls.attachMedia(v); }
            else { v.src = src; }
          })();
        </script>
      <?php elseif ($video['platform'] === 'tiktok'): ?>
        <blockquote class="tiktok-embed" cite="<?= e($video['source_url']) ?>" style="max-width:100%;position:absolute;inset:0">
          <a href="<?= e($video['source_url']) ?>">TikTok video</a>
        </blockquote>
        <script async src="https://www.tiktok.com/embed.js"></script>
      <?php elseif ($video['platform'] === 'twitter'): ?>
        <blockquote class="twitter-tweet" style="position:absolute;inset:0"><a href="<?= e($video['source_url']) ?>"></a></blockquote>
        <script async src="https://platform.twitter.com/widgets.js"></script>
      <?php elseif ($video['platform'] === 'reddit'): ?>
        <blockquote class="reddit-embed-bq" style="position:absolute;inset:0;height:100%">
          <a href="<?= e($video['source_url']) ?>">Reddit post</a>
        </blockquote>
        <script async src="https://embed.reddit.com/widgets.js"></script>
      <?php else: ?>
        <iframe src="<?= e($video['embed_url']) ?>" allowfullscreen loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
          class="yt-fill"></iframe>
      <?php endif; ?>
      <?php if (function_exists('player_ads_render')) player_ads_render('video'); ?>
    </div>

    <?php if (($video['platform'] ?? '') === 'instagram'): ?>
      <p style="font-size:12px;color:var(--text-dim,#aaa);margin:8px 0 0">Контент Instagram · доступность зависит от сети зрителя</p>
    <?php endif; ?>

    <?php endif; /* premiere countdown end */ ?>

    <h1 class="yt-title"><?= e($video['title']) ?></h1>

    <div class="yt-meta-row">
      <a class="yt-channel" href="/channel.php?slug=<?= e($video['channel_slug']) ?>">
        <img class="yt-channel-avatar" src="<?= e($chAvatar) ?>" alt="" width="40" height="40"
          onerror="this.src='/assets/img/avatar-default.png'">
        <div>
          <div class="yt-channel-name"><?= e($video['channel_title']) ?></div>
          <div class="yt-channel-sub"><?= (int)$video['views_count'] ?> просмотров</div>

<?php
if (is_file(__DIR__ . '/includes/video_owner_actions.php')) {
  require_once __DIR__ . '/includes/video_owner_actions.php';
  video_owner_actions_render($video, $user ?? $__user ?? null);
}
?>

        </div>
      </a>
      <div class="yt-actions">
        <button id="likeBtn" data-id="<?= (int)$video['id'] ?>" class="btn btn-outline btn-sm <?= $liked ? 'active' : '' ?>">
          👍 <span id="likeCount"><?= (int)$video['likes_count'] ?></span>
        </button>
        <button id="favBtn" data-id="<?= (int)$video['id'] ?>" class="btn btn-outline btn-sm <?= $fav ? 'active' : '' ?>">⭐</button>
        <button id="playlistBtn" class="btn btn-outline btn-sm">➕ Плейлист</button>
        <button type="button" id="embedBtn" class="btn btn-outline btn-sm">⛶ Встроить</button>
        <?php if (in_array($video['platform'], ['mp4', 'm3u8', 'dropbox'], true) && $user): ?>
        <form method="POST" action="/watch_room?action=create" style="display:inline;margin:0">
          <?= csrf_field() ?><input type="hidden" name="video_id" value="<?= (int)$video['id'] ?>">
          <button type="submit" class="btn btn-outline btn-sm">👥 Вместе</button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if (function_exists('share_buttons')): echo share_buttons('/video.php?slug=' . rawurlencode((string)$video['slug']), (string)$video['title']); endif; ?>

    <?php
      $__canWriteArticle = false;
      if (!empty($user['id']) && !empty($video)) {
        if ((int)($video['user_id'] ?? 0) === (int)$user['id']) $__canWriteArticle = true;
        if (!$__canWriteArticle && !empty($video['channel_id'])) {
          try {
            $__ow = db()->prepare('SELECT owner_id FROM channels WHERE id = ? LIMIT 1');
            $__ow->execute([(int)$video['channel_id']]);
            $__oid = (int)($__ow->fetchColumn() ?: 0);
            if ($__oid === (int)$user['id']) $__canWriteArticle = true;
          } catch (Throwable $e) {}
        }
        if (!empty($user['role']) && $user['role'] === 'admin') $__canWriteArticle = true;
      }
      if ($__canWriteArticle):
        $__aw = '/article_write.php?video_id=' . (int)$video['id'] . '&channel_id=' . (int)($video['channel_id'] ?? 0);
        if (!empty($video['thumbnail_url'])) $__aw .= '&cover=' . rawurlencode((string)$video['thumbnail_url']);
    ?>
      <a class="btn btn-outline btn-sm" href="<?= e($__aw) ?>" style="margin-left:6px">✍️ Статья как в Дзене</a>
    <?php endif; ?>

    <?php if ($video['description']): ?>
      <div class="yt-desc"><?= function_exists('bbcode_to_html') ? bbcode_to_html((string)$video['description']) : nl2br(e($video['description'])) ?></div>
    <?php endif; ?>

    <?php if ($video['tags']): ?>
      <p class="tags" style="margin:0 0 16px">
        <?php foreach (explode(',', $video['tags']) as $t):
          $t = trim($t); if ($t === '') continue; ?>
          <a class="tag" href="/videos?q=<?= e(urlencode($t)) ?>"
            style="display:inline-block;background:rgba(255,255,255,.08);padding:3px 10px;border-radius:20px;font-size:12px;margin:2px;color:inherit;text-decoration:none">#<?= e($t) ?></a>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>

    <h2 id="comments" style="font-size:16px;margin:20px 0 10px">Комментарии · <?= (int)$video['comments_count'] ?></h2>
    <?php if ($user): ?>
      <form method="post" action="/video_comment_add" style="display:flex;gap:8px;margin:10px 0">
        <?= csrf_field() ?>
        <input type="hidden" name="video_id" value="<?= (int)$video['id'] ?>">
        <textarea name="message" rows="2" maxlength="1000" placeholder="Комментарий..." style="flex:1;padding:10px;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);color:inherit"></textarea>
        <button type="submit" class="btn btn-primary btn-sm">Отправить</button>
      </form>
    <?php else: ?>
      <p style="color:var(--text-dim,#aaa)"><a href="/auth/login.php" style="color:var(--accent-2,#3ea6ff)">Войдите</a>, чтобы комментировать</p>
    <?php endif; ?>

    <div class="comments-list">
      <?php foreach ($comments as $c): ?>
        <div class="comment" style="padding:12px 0;border-top:1px solid rgba(255,255,255,.08)">
          <b><?= e($c['username']) ?></b>
          <span style="color:var(--text-dim,#aaa);font-size:12px"> · <?= e($c['created_at']) ?></span>
          <p style="margin:4px 0 0;white-space:pre-line"><?= function_exists('bbcode_to_html') ? bbcode_to_html((string)$c['message']) : nl2br(e($c['message'])) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <aside class="yt-secondary">
    <h2 class="yt-side-title">Рекомендуем</h2>
    <?php if (!$recs): ?>
      <p style="color:var(--text-dim,#aaa);font-size:13px">Пока нет рекомендаций</p>
    <?php endif; ?>
    <?php foreach ($recs as $r): ?>
      <a class="yt-rec" href="/video.php?slug=<?= e($r['slug']) ?>">
        <img class="yt-rec-thumb" src="<?= e($r['thumbnail_url'] ?: '/assets/img/video-placeholder.png') ?>" alt=""
          loading="lazy" onerror="this.onerror=null;this.src='/assets/img/video-placeholder.png';">
        <div class="yt-rec-info">
          <div class="yt-rec-title"><?= e($r['title']) ?></div>
          <div class="yt-rec-meta">
            <?= e($r['channel_title'] ?? '') ?>
            <?php if (isset($r['views_count'])): ?> · <?= (int)$r['views_count'] ?> просм.<?php endif; ?>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </aside>
</div>

<script>
function toggleAction(btnId, url, videoId, countId) {
  var el = document.getElementById(btnId);
  if (!el) return;
  el.addEventListener('click', function () {
    var self = this;
    fetch(url, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({video_id: videoId}) })
      .then(function (r) { return r.json(); }).then(function (d) {
        if (!d.ok) { alert(d.error || 'Ошибка'); return; }
        self.classList.toggle('active', d.liked ?? d.favorited);
        if (countId && 'count' in d) document.getElementById(countId).textContent = d.count;
      });
  });
}
toggleAction('likeBtn', '/video_like.php', <?= (int)$video['id'] ?>, 'likeCount');
toggleAction('favBtn', '/video_favorite.php', <?= (int)$video['id'] ?>, null);

document.getElementById('playlistBtn')?.addEventListener('click', function () {
  <?php if (!$user): ?>
  location.href = '/auth/login.php';
  <?php else: ?>
  var title = prompt('Название нового плейлиста');
  if (!title) return;
  fetch('/playlist_add', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({video_id: <?= (int)$video['id'] ?>, new_title: title})
  }).then(function (r) { return r.json(); }).then(function (d) {
    alert(d.ok ? 'Добавлено в «' + title + '»' : (d.error || 'Ошибка'));
  });
  <?php endif; ?>
});

<?php if (in_array($video['platform'], ['mp4', 'm3u8', 'dropbox'], true) && $user): ?>
(function () {
  var v = document.querySelector('#hlsPlayer, .player-wrap video');
  if (!v) return;
  var videoId = <?= (int)$video['id'] ?>;
  <?php if (isset($_GET['resume'])): ?>
  fetch('/video_progress_get?video_id=' + videoId).then(function (r) { return r.json(); }).then(function (d) {
    if (d.ok && d.position > 5) v.currentTime = d.position;
  });
  <?php endif; ?>
  var lastSaved = 0;
  v.addEventListener('timeupdate', function () {
    if (Math.abs(v.currentTime - lastSaved) < 5) return;
    lastSaved = v.currentTime;
    fetch('/video_progress_save', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({video_id: videoId, position: Math.floor(v.currentTime), duration: Math.floor(v.duration || 0)})
    });
  });
})();
<?php endif; ?>

(function(){
  var d = document.querySelector('.yt-desc');
  if (!d || d.textContent.length < 180) return;
  d.classList.add('collapsed');
  var b = document.createElement('button');
  b.type = 'button'; b.className = 'yt-desc-toggle'; b.textContent = 'Ещё';
  b.onclick = function(){ d.classList.toggle('collapsed'); b.textContent = d.classList.contains('collapsed') ? 'Ещё' : 'Свернуть'; };
  d.after(b);
})();

(function(){
  var btn = document.getElementById('embedBtn');
  if (!btn) return;
  btn.addEventListener('click', function(){
    var slug = <?= json_encode((string)$video['slug']) ?>;
    var url = location.origin + '/video_embed.php?slug=' + encodeURIComponent(slug);
    var code = '<iframe src="' + url + '" width="720" height="405" frameborder="0" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen style="width:100%;aspect-ratio:16/9;border:0;border-radius:12px"></iframe>';
    var w = window.open('', 'embed', 'width=520,height=320');
    if (w) {
      w.document.write('<!doctype html><title>Код встраивания</title><body style="font-family:system-ui;padding:16px;background:#111;color:#eee"><h3>Код для вставки</h3><textarea style="width:100%;height:120px;padding:10px;border-radius:8px">'+code.replace(/</g,'<')+'</textarea><p style="font-size:13px;opacity:.7;word-break:break-all">'+url+'</p><p style="font-size:12px;opacity:.6">Скопируйте код в буфер вручную (Ctrl+C)</p></body>');
    } else {
      prompt('Код iframe', code);
    }
  });
})();

</script>
<?php
$__vidArticles = [];
if (function_exists('content_articles_list') && !empty($video['id'])) {
  $__vidArticles = content_articles_list(null, (int)$video['id'], 12, true);
  if (!$__vidArticles && !empty($video['channel_id'])) {
    $__vidArticles = content_articles_list((int)$video['channel_id'], null, 12, true);
  }
}
if (!empty($__vidArticles)): ?>
<section class="yt-articles" style="margin:20px 0;padding:14px;border-radius:12px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06)">
  <h3 style="margin:0 0 12px;font-size:16px">Статьи</h3>
  <ul style="list-style:none;margin:0;padding:0;display:grid;gap:10px">
    <?php foreach ($__vidArticles as $__a): ?>
    <li style="display:flex;gap:10px;align-items:center">
      <?php if (!empty($__a['cover_url'])): ?>
        <img src="<?= e($__a['cover_url']) ?>" alt="" width="64" height="40" style="object-fit:cover;border-radius:8px;flex-shrink:0">
      <?php endif; ?>
      <div style="min-width:0">
        <a href="/article.php?id=<?= (int)$__a['id'] ?>" style="color:inherit;font-weight:600;text-decoration:none"><?= e($__a['title']) ?></a>
        <div style="font-size:12px;opacity:.65"><?= (int)($__a['views'] ?? 0) ?> просм. · <?= e((string)($__a['username'] ?? '')) ?></div>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
