<?php
/**
 * Полная синхронизация зеркал:
 *  1) FTP — весь скрипт (php/css/js/assets/sql/cron/includes …)
 *  2) MySQL — схема + данные контентных таблиц
 *
 * CLI: php cron/mirror_sync.php
 * Cron: 0 3 * * * cd /path/to/site && php cron/mirror_sync.php >> /tmp/mirror_sync.log 2>&1
 */
if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/functions.php';
if (is_file($ROOT . '/includes/user_secrets.php')) {
  require_once $ROOT . '/includes/user_secrets.php';
}

function mirror_content_tables(PDO $pdo): array {
  $want = [
    'videos', 'channels', 'channel_sources', 'channel_schedule',
    'content_articles', 'video_comments', 'video_likes', 'video_favorites',
    'categories', 'tags', 'video_tags',
    'users', 'playlists', 'playlist_items',
    'forum_threads', 'forum_posts', 'forum_categories',
    'resources', 'brands',
  ];
  $have = [];
  try {
    $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    $all = array_map(static fn($r) => (string)$r[0], $rows);
    foreach ($want as $t) {
      if (in_array($t, $all, true)) $have[] = $t;
    }
    foreach ($all as $t) {
      if (preg_match('/^(content_|video_|channel_)/', $t) && !in_array($t, $have, true)) {
        if (!preg_match('/(session|token|secret|password|oauth)/i', $t)) {
          $have[] = $t;
        }
      }
    }
  } catch (Throwable $e) {}
  return $have;
}

function mirror_ftp_skip(string $rel): bool {
  $rel = str_replace('\\', '/', $rel);
  $deny = [
    '#^\.git(/|$)#',
    '#^\.env#',
    '#^storage/#',
    '#^uploads/#',
    '#^tmp/#',
    '#^vendor/#',
    '#^node_modules/#',
    '#^cache/#',
    '#^\.grok/#',
    '#\.zip$#i',
    '#\.log$#i',
    '#^config\.local\.php$#',
    '#^includes/config\.local#',
  ];
  foreach ($deny as $re) {
    if (preg_match($re, $rel)) return true;
  }
  return false;
}

function mirror_ftp_collect(string $root): array {
  $out = [];
  $root = rtrim($root, '/\\');
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
  );
  $allowExt = ['php','css','js','html','htm','svg','png','jpg','jpeg','gif','webp','ico','woff','woff2','ttf','eot','map','json','md','txt','sql','xml'];
  foreach ($it as $file) {
    if (!$file->isFile()) continue;
    $full = $file->getPathname();
    $rel = ltrim(str_replace('\\', '/', substr($full, strlen($root))), '/');
    if ($rel === '' || mirror_ftp_skip($rel)) continue;
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if ($ext !== '' && !in_array($ext, $allowExt, true)) continue;
    $out[] = $rel;
  }
  sort($out);
  return $out;
}

function mirror_ftp_mkdirs($conn, string $dir): void {
  $dir = trim(str_replace('\\', '/', $dir), '/');
  if ($dir === '') return;
  $parts = explode('/', $dir);
  $cur = '';
  foreach ($parts as $p) {
    if ($p === '' || $p === '.') continue;
    $cur .= '/' . $p;
    @ftp_mkdir($conn, $cur);
  }
}

function mirror_ftp_upload_tree(array $ftp, string $root, array $files): array {
  $host = (string)($ftp['host'] ?? '');
  $user = (string)($ftp['user'] ?? '');
  $pass = (string)($ftp['pass'] ?? '');
  $base = rtrim((string)($ftp['path'] ?? '/'), '/') . '/';
  $errors = [];
  $uploaded = 0;

  if ($host === '' || $user === '') {
    return ['ok' => false, 'uploaded' => 0, 'errors' => ['ftp: empty credentials']];
  }
  if (!function_exists('ftp_connect')) {
    return ['ok' => false, 'uploaded' => 0, 'errors' => ['ftp: extension not available']];
  }

  $conn = @ftp_connect($host, 21, 25);
  if (!$conn) return ['ok' => false, 'uploaded' => 0, 'errors' => ['ftp: connect failed']];
  if (!@ftp_login($conn, $user, $pass)) {
    ftp_close($conn);
    return ['ok' => false, 'uploaded' => 0, 'errors' => ['ftp: login failed']];
  }
  @ftp_pasv($conn, true);

  $root = rtrim($root, '/\\');
  $n = 0;
  foreach ($files as $rel) {
    $local = $root . '/' . $rel;
    if (!is_file($local)) continue;
    $remoteDir = $base . dirname($rel);
    if (dirname($rel) !== '.') {
      mirror_ftp_mkdirs($conn, $remoteDir);
    }
    $remote = $base . $rel;
    $ok = @ftp_put($conn, $remote, $local, FTP_BINARY);
    if ($ok) {
      $uploaded++;
    } else {
      $errors[] = 'put fail: ' . $rel;
      if (count($errors) > 30) {
        $errors[] = '… more errors truncated';
        break;
      }
    }
    $n++;
    if ($n % 50 === 0) {
      @ftp_raw($conn, 'NOOP');
    }
  }

  $marker = "StreamLive full mirror\nat=" . gmdate('c') . "\nfiles={$uploaded}\n";
  $tmp = tempnam(sys_get_temp_dir(), 'mrr');
  file_put_contents($tmp, $marker);
  @ftp_put($conn, $base . 'streamlive_mirror_ok.txt', $tmp, FTP_ASCII);
  @unlink($tmp);

  ftp_close($conn);
  return ['ok' => $uploaded > 0 && count($errors) < 5, 'uploaded' => $uploaded, 'errors' => $errors];
}

function mirror_dump_table(PDO $src, string $table): string {
  $sql = "-- table `{$table}`\n";
  try {
    $row = $src->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM);
    if ($row && !empty($row[1])) {
      $sql .= 'DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . "`;\n";
      $sql .= $row[1] . ";\n\n";
    }
  } catch (Throwable $e) {
    return $sql . '-- SHOW CREATE failed: ' . $e->getMessage() . "\n";
  }

  try {
    $st = $src->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
    $batch = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $cols = array_keys($r);
      $vals = [];
      foreach ($r as $v) {
        if ($v === null) $vals[] = 'NULL';
        else $vals[] = $src->quote((string)$v);
      }
      $sql .= 'INSERT INTO `' . str_replace('`', '``', $table) . '` (`'
        . implode('`,`', array_map(static fn($c) => str_replace('`', '``', $c), $cols))
        . '`) VALUES (' . implode(',', $vals) . ");\n";
      $batch++;
      if ($batch > 50000) {
        $sql .= "-- truncated at 50000 rows\n";
        break;
      }
    }
    $sql .= "\n";
  } catch (Throwable $e) {
    $sql .= '-- data dump failed: ' . $e->getMessage() . "\n";
  }
  return $sql;
}

function mirror_import_sql(PDO $dst, string $sql): array {
  $errors = [];
  $ok = 0;
  $parts = preg_split('/;\s*\n/', $sql);
  foreach ($parts as $chunk) {
    $chunk = trim($chunk);
    if ($chunk === '' || str_starts_with($chunk, '--')) continue;
    if (!str_ends_with($chunk, ';')) $chunk .= ';';
    try {
      $dst->exec($chunk);
      $ok++;
    } catch (Throwable $e) {
      $msg = $e->getMessage();
      if (stripos($msg, 'already exists') !== false) continue;
      $errors[] = mb_substr($msg, 0, 200);
      if (count($errors) > 40) break;
    }
  }
  return ['ok' => $ok, 'errors' => $errors];
}

function mirror_open_remote(array $m): PDO {
  $pass = (string)($m['db_pass_enc'] ?? '');
  if (function_exists('user_secrets_decrypt')) {
    $dec = user_secrets_decrypt($pass);
    if ($dec !== null && $dec !== '') $pass = $dec;
  }
  $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $m['db_host'], $m['db_name']);
  return new PDO($dsn, $m['db_user'], $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 60,
  ]);
}

function mirror_load_ftp(int $uid): array {
  $ftp = ['host' => '', 'user' => '', 'pass' => '', 'path' => '/'];
  try {
    $st = db()->prepare('SELECT * FROM user_external_ids WHERE user_id = ? LIMIT 1');
    $st->execute([$uid]);
    $ext = $st->fetch();
    if ($ext && function_exists('user_secrets_decrypt')) {
      $ftp['host'] = (string)(user_secrets_decrypt($ext['ftp_host_enc'] ?? '') ?? '');
      $ftp['user'] = (string)(user_secrets_decrypt($ext['ftp_user_enc'] ?? '') ?? '');
      $ftp['pass'] = (string)(user_secrets_decrypt($ext['ftp_pass_enc'] ?? '') ?? '');
      $ftp['path'] = (string)(user_secrets_decrypt($ext['ftp_path_enc'] ?? '') ?? '/');
    }
  } catch (Throwable $e) {}
  return $ftp;
}

try {
  $rows = db()->query('SELECT * FROM site_mirrors WHERE is_active = 1')->fetchAll();
} catch (Throwable $e) {
  fwrite(STDERR, "No site_mirrors: " . $e->getMessage() . "\n");
  exit(0);
}

if (!$rows) {
  echo "No active mirrors\n";
  exit(0);
}

$src = db();
$files = mirror_ftp_collect($ROOT);
echo "Code files to upload: " . count($files) . "\n";

$tables = mirror_content_tables($src);
echo "Tables to sync: " . implode(', ', $tables) . "\n";

foreach ($rows as $m) {
  $id = (int)$m['id'];
  $uid = (int)$m['user_id'];
  $status = 'ok';
  $errParts = [];
  $info = [];

  echo "=== mirror #{$id} user={$uid} ===\n";

  try {
    $dst = mirror_open_remote($m);
    $dst->exec('SET FOREIGN_KEY_CHECKS=0');
    $dst->exec('SET NAMES utf8mb4');
    $dump = "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n";
    foreach ($tables as $t) {
      echo "  dump {$t}…\n";
      $dump .= mirror_dump_table($src, $t);
    }
    $imp = mirror_import_sql($dst, $dump);
    $info[] = 'db_stmts=' . $imp['ok'];
    if ($imp['errors']) {
      $errParts[] = 'db: ' . implode('; ', array_slice($imp['errors'], 0, 5));
      $status = 'db_partial';
    }
    $dst->exec('SET FOREIGN_KEY_CHECKS=1');
    echo "  db import ok={$imp['ok']} errors=" . count($imp['errors']) . "\n";
  } catch (Throwable $e) {
    $status = 'db_error';
    $errParts[] = 'db: ' . $e->getMessage();
    echo "  db FAIL: " . $e->getMessage() . "\n";
  }

  $ftp = mirror_load_ftp($uid);
  if ($ftp['host'] === '') {
    $errParts[] = 'ftp: not configured';
    if ($status === 'ok') $status = 'ftp_missing';
    echo "  ftp missing\n";
  } else {
    echo "  ftp upload " . count($files) . " files…\n";
    $up = mirror_ftp_upload_tree($ftp, $ROOT, $files);
    $info[] = 'ftp_files=' . $up['uploaded'];
    if (!$up['ok'] || $up['errors']) {
      $errParts[] = 'ftp: uploaded=' . $up['uploaded'] . ' ' . implode('; ', array_slice($up['errors'], 0, 5));
      if ($status === 'ok') $status = 'ftp_partial';
      if ($up['uploaded'] === 0) $status = 'ftp_error';
    }
    echo "  ftp uploaded={$up['uploaded']} errors=" . count($up['errors']) . "\n";
  }

  if ($status === 'ok' && $errParts) $status = 'partial';
  $err = $errParts ? implode(' | ', $errParts) : null;
  if ($info) {
    $err = ($err ? $err . ' | ' : '') . implode(' ', $info);
  }

  try {
    db()->prepare('UPDATE site_mirrors SET last_sync_at = NOW(), last_status = ?, last_error = ? WHERE id = ?')
      ->execute([$status, $err, $id]);
  } catch (Throwable $e) {}

  echo "  status={$status}\n";
}

echo "Done\n";
