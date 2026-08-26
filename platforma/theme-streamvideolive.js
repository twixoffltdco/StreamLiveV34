(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }
  function qs(s, r) { return (r || document).querySelector(s); }
  function qsa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function fmtNum(n) {
    n = parseInt(String(n).replace(/[^\d]/g, ''), 10) || 0;
    if (n >= 1000000) return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
    return String(n);
  }
  function pickViews(it) {
    if (!it) return 0;
    if (it.views != null && it.views !== '') return parseInt(it.views, 10) || 0;
    if (it.viewers != null && it.viewers !== '') return parseInt(it.viewers, 10) || 0;
    if (it.views_count != null) return parseInt(it.views_count, 10) || 0;
    if (it.meta) {
      var parts = String(it.meta).split(/[·|•]/);
      for (var i = parts.length - 1; i >= 0; i--) {
        var m = parts[i].replace(/\s/g, '').match(/([\d]+)/);
        if (m) return parseInt(m[1], 10) || 0;
      }
    }
    return 0;
  }
  function getCsrf() {
    var i = qs('input[name="csrf"], input[name="_csrf"], input[name="csrf_token"]');
    if (i && i.value) return i.value;
    var m = document.cookie.match(/(?:^|;\s*)csrf_token=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
  }
  function getVideoId() {
    var i = qs('input[name="video_id"]');
    if (i && i.value) return parseInt(i.value, 10) || 0;
    var el = qs('[data-video-id]');
    if (el) return parseInt(el.getAttribute('data-video-id'), 10) || 0;
    return 0;
  }
  function getChannelId() {
    var i = qs('input[name="channel_id"]');
    if (i && i.value) return parseInt(i.value, 10) || 0;
    var el = qs('[data-channel-id]');
    if (el) return parseInt(el.getAttribute('data-channel-id'), 10) || 0;
    var a = qs('a[href*="channel.php"]');
    if (a) {
      var m = a.href.match(/[?&]id=(\d+)/);
      if (m) return parseInt(m[1], 10);
    }
    return 0;
  }
  function isLogged() {
    if (window.PL_SVL && window.PL_SVL.logged) return true;
    if (window.PL_USER && window.PL_USER.logged) return true;
    return !!qs('a[href*="logout"], .user-menu, .avatar-mine');
  }
  function fetchJson(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  function injectTop() {
    if (qs('.svl-topbar')) return;
    var bar = document.createElement('div');
    bar.className = 'svl-topbar';
    bar.innerHTML =
      '<a class="svl-logo" href="/">' +
      '<span class="svl-logo-mark">▶</span> Stream Video' +
      '<span class="svl-live-pill">LIVE</span></a>' +
      '<span class="svl-spacer"></span>' +
      '<button type="button" class="svl-icon-btn" id="svl-search" aria-label="Поиск">🔍</button>';
    document.body.insertBefore(bar, document.body.firstChild);
    var s = qs('#svl-search');
    if (s) s.addEventListener('click', function () {
      var q = prompt('Поиск');
      if (q) location.href = '/videos.php?q=' + encodeURIComponent(q);
    });
  }

  function injectBottom() {
    if (qs('.svl-bottom-nav')) return;
    var path = location.pathname || '/';
    var nav = document.createElement('nav');
    nav.className = 'svl-bottom-nav';
    function item(href, ico, label, on) {
      return '<a href="' + href + '" class="' + (on ? 'on' : '') + '"><span class="svl-ico">' + ico + '</span>' + label + '</a>';
    }
    var onHome = path === '/' || /index|catalog/i.test(path);
    var onVid = /video/i.test(path);
    var onLive = /channel|live|broadcast/i.test(path);
    nav.innerHTML =
      item('/', '🏠', 'Главная', onHome) +
      item('/videos.php', '▶', 'Видео', onVid && !onLive) +
      item('/channels.php', '📡', 'Эфиры', onLive) +
      item('/account.php', '👤', 'Профиль', /account/i.test(path));
    document.body.appendChild(nav);
  }

  function loadLiveRow() {
    if (qs('.svl-live-row')) return;
    var host = qs('.video-grid') || qs('.channels-grid') || qs('.container');
    if (!host || /video\.php/i.test(location.pathname)) return;
    var row = document.createElement('div');
    row.className = 'svl-live-row';
    row.innerHTML = '<div style="color:#888;font-size:13px;padding:8px">Загрузка…</div>';
    host.parentNode.insertBefore(row, host);
    function render(items) {
      row.innerHTML = '';
      if (!items || !items.length) { row.remove(); return; }
      items.slice(0, 16).forEach(function (it, i) {
        var href = it.embed || it.url || (it.slug ? '/channel.php?slug=' + encodeURIComponent(it.slug) : '#');
        var thumb = it.thumb || it.logo || it.cover || '';
        var views = pickViews(it);
        var title = it.title || it.name || 'Канал';
        var el = document.createElement('a');
        el.className = 'svl-live-card' + (i === 0 ? ' is-focus' : '');
        el.href = href;
        el.innerHTML =
          '<div class="svl-live-thumb">' +
          (thumb ? '<img src="' + esc(thumb) + '" alt="" loading="lazy">' : '<div class="svl-ph">📺</div>') +
          (it.live ? '<span class="svl-live-tag">LIVE</span>' : '') +
          '<span class="svl-live-viewers">' + esc(fmtNum(views)) + '</span></div>' +
          '<div class="svl-live-meta"><div class="svl-live-title">' + esc(title) + '</div>' +
          '<div class="svl-live-sub">' + esc(it.meta || (views ? fmtNum(views) + ' просм.' : '')) + '</div></div>';
        row.appendChild(el);
      });
    }
    fetchJson('/platforma/api_live.php?limit=16')
      .then(function (d) {
        var items = Array.isArray(d) ? d : (d.items || []);
        if (items.length) { render(items); return null; }
        return fetchJson('/platforma/api_channels.php?limit=24');
      })
      .then(function (d) {
        if (d == null) return;
        render(Array.isArray(d) ? d : (d.items || []));
      })
      .catch(function () { row.remove(); });
  }

  function loadCategories() {
    if (qs('.svl-cat-grid')) return;
    if (/video\.php/i.test(location.pathname) || qs('input[name="video_id"]')) return;
    var wrap = document.createElement('section');
    wrap.className = 'svl-cats';
    wrap.innerHTML = '<h2>Каналы <a href="/catalog.php">Показать все</a></h2>';
    var grid = document.createElement('div');
    grid.className = 'svl-cat-grid';
    wrap.appendChild(grid);
    var host = qs('.container') || qs('main') || document.body;
    host.insertBefore(wrap, host.firstChild);
    function addCard(href, title, thumb, views) {
      var card = document.createElement('a');
      card.className = 'svl-cat-card';
      card.href = href || '#';
      var v = parseInt(views, 10) || 0;
      card.innerHTML =
        '<div class="svl-cat-thumb">' +
        (thumb
          ? '<img src="' + esc(thumb) + '" alt="" loading="lazy">'
          : '<div class="svl-ph">' + esc((title || '?').charAt(0).toUpperCase()) + '</div>') +
        '<span class="svl-badge-live">' + esc(fmtNum(v)) + '</span>' +
        '</div><div class="svl-cat-name">' + esc(title || '') + '</div>';
      grid.appendChild(card);
    }
    fetchJson('/platforma/api_channels.php?limit=24')
      .then(function (d) {
        var items = Array.isArray(d) ? d : (d.items || []);
        if (!items.length) throw new Error('empty');
        items.forEach(function (it) {
          var href = it.embed || (it.slug ? '/channel.php?slug=' + encodeURIComponent(it.slug) : '/catalog.php');
          addCard(href, it.title || it.name, it.thumb || it.logo, pickViews(it));
        });
      })
      .catch(function () { if (!grid.children.length) wrap.remove(); });
  }

  function styleVideoRecommendations() {
    var sec = qs('.recommendations-section, .recs-block, #recommendations, .platforma-recs');
    if (!sec) return;
    sec.classList.add('svl-recs');
    var title = qs('h2, h3', sec);
    if (title && !/для вас/i.test(title.textContent)) title.textContent = 'Рекомендации для вас';
  }

  function mobileButtonsCleanup() {
    document.documentElement.classList.add('svl-mobile-actions');
    qsa('.sl-mobile-bar, .streamlabs-bar, .mobile-actions-old, .fixed-bottom-actions:not(.svl-bottom-nav)')
      .forEach(function (el) { el.style.display = 'none'; });
  }

  function enhanceWatchPage() {
    var player = qs('.yt-player-box, .player-wrap, .video-container, #player');
    if (!player) return;
    var titleEl = qs('.yt-title, h1.video-title, .video-title, h1');
    var title = titleEl ? titleEl.textContent.trim() : document.title;
    var ch = qs('.yt-channel, a.channel-link, .yt-channel-name');
    var chName = ch ? (ch.textContent || '').trim() : '';
    var av = qs('.yt-channel-avatar, .channel-avatar, img.avatar');
    var viewsEl = qs('.yt-channel-sub, .video-views, .views-count');
    var viewsText = viewsEl ? viewsEl.textContent.trim() : '';

    if (!qs('.svl-watch-head')) {
      var head = document.createElement('div');
      head.className = 'svl-watch-head';
      head.innerHTML =
        '<div class="svl-channel-row">' +
        (av ? '<img class="svl-avatar" src="' + esc(av.src) + '" alt="">' : '<div class="svl-avatar"></div>') +
        '<div><div class="svl-ch-name">' + esc(chName || 'Канал') + '</div>' +
        '<div class="svl-ch-subs">' + esc(viewsText || '') + '</div></div></div>' +
        '<div class="svl-watch-title">' + esc(title) + '</div>' +
        '<div class="svl-action-row svl-action-row-mobile">' +
        '<button type="button" class="svl-btn svl-btn-follow" id="svl-follow">+ Отслеживать</button>' +
        '<a class="svl-btn svl-btn-donate" href="#comments">💬 Комментарии</a>' +
        '<button type="button" class="svl-btn svl-btn-ghost" id="svl-share">↗</button>' +
        '</div>';
      if (player.parentNode) player.parentNode.insertBefore(head, player.nextSibling);

      qs('#svl-follow') && qs('#svl-follow').addEventListener('click', function () {
        if (!isLogged()) {
          location.href = '/login.php?redirect=' + encodeURIComponent(location.pathname + location.search);
          return;
        }
        var cid = getChannelId();
        if (!cid) { alert('Канал не найден'); return; }
        fetch('/channel_favorite.php', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ channel_id: cid })
        }).then(function (r) { return r.json(); }).then(function (d) {
          var b = qs('#svl-follow');
          if (b && d && d.ok) b.textContent = d.favorited ? '✓ Отслеживаете' : '+ Отслеживать';
        });
      });
      qs('#svl-share') && qs('#svl-share').addEventListener('click', function () {
        if (navigator.share) navigator.share({ url: location.href, title: title });
        else { try { navigator.clipboard.writeText(location.href); alert('Ссылка скопирована'); } catch (e) {} }
      });
    }

    qsa('.yt-actions, .video-owner-actions').forEach(function (el) {
      el.classList.add('svl-hide-mobile');
    });

    var chat = qs('.svl-chat');
    if (!chat) {
      chat = document.createElement('div');
      chat.className = 'svl-chat';
      chat.id = 'svl-chat-box';
      if (player.parentNode) player.parentNode.appendChild(chat);
    }
    chat.innerHTML = '';
    var real = [];
    qsa('.comments-list .comment, .comment-item, #comments .comment').forEach(function (m) {
      var nick = (m.querySelector('.author, .username, .nick, b, strong, a') || {}).textContent || '';
      nick = nick.trim();
      var textNode = m.querySelector('.text, .body, .message, p');
      var text = textNode ? textNode.textContent : m.textContent;
      text = (text || '').replace(nick, '').trim();
      if (text) real.push({ nick: nick || 'user', text: text });
    });
    if (real.length) {
      real.forEach(function (c) {
        var d = document.createElement('div');
        d.className = 'svl-chat-msg';
        d.innerHTML = '<span class="svl-nick">' + esc(c.nick) + '</span>' + esc(c.text.slice(0, 500));
        chat.appendChild(d);
      });
    } else {
      chat.innerHTML = '<div class="svl-chat-empty">Пока нет комментариев</div>';
    }

    var inpWrap = qs('.svl-chat-input');
    if (!inpWrap) {
      inpWrap = document.createElement('div');
      inpWrap.className = 'svl-chat-input';
      document.body.appendChild(inpWrap);
    }
    var videoId = getVideoId();
    if (!isLogged()) {
      inpWrap.innerHTML =
        '<a href="/login.php?redirect=' + encodeURIComponent(location.pathname + location.search) +
        '" class="svl-btn svl-btn-follow" style="flex:1;text-align:center">Войдите, чтобы комментировать</a>';
      return;
    }
    if (!videoId) {
      inpWrap.innerHTML = '<div class="svl-chat-empty" style="padding:0">Комментарии недоступны</div>';
      return;
    }
    var csrf = getCsrf();
    inpWrap.innerHTML =
      '<input type="text" placeholder="Написать комментарий…" maxlength="1000" id="svl-msg" autocomplete="off">' +
      '<button type="button" class="svl-btn svl-btn-follow" id="svl-send" style="padding:8px 14px">➤</button>';

    function postComment() {
      var i = qs('#svl-msg');
      if (!i) return;
      var msg = (i.value || '').trim();
      if (!msg) return;
      var btn = qs('#svl-send');
      if (btn) { btn.disabled = true; btn.textContent = '…'; }
      var body = new FormData();
      body.append('video_id', String(videoId));
      body.append('message', msg);
      qsa('input[type="hidden"]').forEach(function (h) {
        if (/csrf/i.test(h.name) && h.value) body.append(h.name, h.value);
      });
      if (csrf) { body.append('csrf', csrf); body.append('_csrf', csrf); }
      fetch('/video_comment_add.php', { method: 'POST', credentials: 'same-origin', body: body, redirect: 'follow' })
        .then(function () {
          var empty = qs('.svl-chat-empty', chat);
          if (empty) empty.remove();
          var d = document.createElement('div');
          d.className = 'svl-chat-msg';
          var uname = (window.PL_SVL && window.PL_SVL.name) || 'Вы';
          d.innerHTML = '<span class="svl-nick">' + esc(uname) + '</span>' + esc(msg);
          chat.appendChild(d);
          chat.scrollTop = chat.scrollHeight;
          i.value = '';
        })
        .catch(function () { alert('Не удалось отправить'); })
        .then(function () { if (btn) { btn.disabled = false; btn.textContent = '➤'; } });
    }
    qs('#svl-send') && qs('#svl-send').addEventListener('click', postComment);
    qs('#svl-msg') && qs('#svl-msg').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); postComment(); }
    });
  }

  function chips() {
    if (qs('.svl-chips') || qs('input[name="video_id"]')) return;
    var row = document.createElement('div');
    row.className = 'svl-chips';
    row.innerHTML =
      '<a class="svl-chip active" href="/videos.php">Видео</a>' +
      '<a class="svl-chip" href="/catalog.php">Каталог</a>' +
      '<a class="svl-chip" href="/channels.php">Каналы</a>' +
      '<a class="svl-chip" href="/videos.php?sort=new">Новые</a>';
    var host = qs('.svl-topbar');
    if (host && host.parentNode) host.parentNode.insertBefore(row, host.nextSibling);
  }

  ready(function () {
    try {
      document.documentElement.classList.add('pl-theme-svl');
      if (document.body) document.body.classList.add('pl-theme-svl');
      injectTop();
      chips();
      injectBottom();
      mobileButtonsCleanup();
      loadLiveRow();
      loadCategories();
      styleVideoRecommendations();
      enhanceWatchPage();
    } catch (e) {
      console && console.warn && console.warn('SVL', e);
    }
  });
})();
