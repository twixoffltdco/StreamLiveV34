<?php
/**
 * Общий bootstrap для Platforma API.
 * Ищет конфиг/БД StreamLife на диске и нормализует каналы.
 */
declare(strict_types=1);

function pl_root_candidates(): array {
    $here = dirname(__DIR__);
    return array_values(array_unique(array_filter([
        $here,
        dirname($here),
        $here . '/..',
        $_SERVER['DOCUMENT_ROOT'] ?? '',
    ])));
}

function pl_load_streamlife(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (pl_root_candidates() as $root) {
        if (!$root || !is_dir($root)) continue;
        foreach (['includes/functions.php', 'includes/db.php', 'config.php', 'includes/config.php'] as $rel) {
            $p = $root . '/' . $rel;
            if (is_file($p)) {
                try { require_once $p; } catch (Throwable $e) {}
            }
        }
        if (function_exists('db')) return;
    }
}

function pl_pdo(): ?PDO {
    pl_load_streamlife();
    try {
        if (function_exists('db')) {
            $pdo = db();
            if ($pdo instanceof PDO) return $pdo;
        }
    } catch (Throwable $e) {}
    return null;
}

function pl_tables(PDO $pdo): array {
    try {
        $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
        return array_map(static fn($r) => (string)$r[0], $rows ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

function pl_find_table(PDO $pdo, array $candidates): ?string {
    $tables = pl_tables($pdo);
    $lower = array_map('strtolower', $tables);
    foreach ($candidates as $c) {
        $i = array_search(strtolower($c), $lower, true);
        if ($i !== false) return $tables[$i];
    }
    return null;
}

function pl_columns(PDO $pdo, string $table): array {
    try {
        $rows = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn($r) => (string)$r['Field'], $rows ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

function pl_pick_col(array $cols, array $want): ?string {
    $map = [];
    foreach ($cols as $c) $map[strtolower($c)] = $c;
    foreach ($want as $w) {
        if (isset($map[strtolower($w)])) return $map[strtolower($w)];
    }
    return null;
}

function pl_site_web(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host;
}

function pl_normalize_channel(array $row, array $cols, string $site): array {
    $idCol = pl_pick_col($cols, ['id', 'channel_id', 'cid']);
    $titleCol = pl_pick_col($cols, ['title', 'name', 'channel_name', 'stream_title', 'display_name']);
    $slugCol = pl_pick_col($cols, ['slug', 'key', 'username', 'login']);
    $thumbCol = pl_pick_col($cols, ['logo_url', 'cover_url', 'thumbnail', 'thumb', 'image', 'poster', 'cover', 'avatar', 'logo']);
    $liveCol = pl_pick_col($cols, ['last_stream_live', 'is_live', 'live', 'online', 'stream_status']);
    $viewCol = pl_pick_col($cols, ['views', 'viewers', 'viewer_count', 'watchers', 'online_count', 'views_count']);
    $typeCol = pl_pick_col($cols, ['type', 'category', 'kind', 'channel_type']);

    $id = $idCol ? ($row[$idCol] ?? '') : '';
    $title = $titleCol ? (string)($row[$titleCol] ?? 'Канал') : 'Канал';
    $slug = $slugCol ? (string)($row[$slugCol] ?? '') : '';

    $thumb = '';
    if ($thumbCol) $thumb = (string)($row[$thumbCol] ?? '');
    if ($thumb === '') {
        $cover = pl_pick_col($cols, ['cover_url', 'logo_url']);
        if ($cover && $cover !== $thumbCol) $thumb = (string)($row[$cover] ?? '');
    }

    $live = false;
    if ($liveCol) {
        $v = $row[$liveCol];
        $live = ($v === 1 || $v === '1' || $v === true || $v === 'live' || $v === 'online' || $v === 'on');
    }
    $viewers = $viewCol ? (int)($row[$viewCol] ?? 0) : 0;

    $type = 'channel';
    if ($typeCol) {
        $t = strtolower(trim((string)($row[$typeCol] ?? '')));
        if ($t === 'tv' || strpos($t, 'tv') !== false || strpos($t, 'tele') !== false) $type = 'tv';
        elseif ($t === 'radio' || strpos($t, 'radio') !== false) $type = 'radio';
        elseif ($t !== '') $type = $t;
    }
    $tl = function_exists('mb_strtolower') ? mb_strtolower($title) : strtolower($title);
    if ($type === 'channel' || $type === '') {
        if (strpos($tl, 'радио') !== false || strpos($tl, 'radio') !== false || preg_match('/\bfm\b/u', $tl)) $type = 'radio';
        elseif (strpos($tl, 'телеканал') !== false || strpos($tl, 'тв') !== false || strpos($tl, 'tv') !== false) $type = 'tv';
    }

    $meta = $live ? 'В эфире' : ($type === 'tv' ? 'ТВ' : ($type === 'radio' ? 'Радио' : 'Канал'));
    if ($viewers > 0) $meta .= ' · ' . number_format($viewers);

    $key = $slug !== '' ? $slug : (string)$id;
    if ($slug !== '') {
        $embed = $site . '/channel.php?slug=' . rawurlencode($slug);
    } elseif ($id !== '' && $id !== null) {
        $embed = $site . '/channel.php?id=' . rawurlencode((string)$id);
    } else {
        $embed = $site . '/catalog.php';
    }
    $embed_alt = [];
    if ($slug !== '') $embed_alt[] = $site . '/channel.php?slug=' . rawurlencode($slug);
    if ($id !== '') $embed_alt[] = $site . '/channel.php?id=' . rawurlencode((string)$id);
    if ($key !== '') {
        $embed_alt[] = $site . '/embed.php?slug=' . rawurlencode($key);
        $embed_alt[] = $site . '/embed.php?channel=' . rawurlencode($key);
    }
    $embed_alt = array_values(array_unique($embed_alt));

    if ($thumb !== '' && strpos($thumb, 'http') !== 0 && isset($thumb[0]) && $thumb[0] !== '/') {
        $thumb = $site . '/' . ltrim($thumb, '/');
    }

    return [
        'id' => $id !== '' ? $id : $key,
        'title' => $title,
        'type' => $type,
        'meta' => $meta,
        'live' => $live,
        'views' => (int)$viewers,
        'viewers' => (int)$viewers,
        'thumb' => $thumb,
        'embed' => $embed,
        'embed_alt' => $embed_alt,
        'slug' => $slug,
    ];
}

function pl_json($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=30');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
