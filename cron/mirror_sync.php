<?php
/**
 * Синхронизация зеркал: проверка MySQL + FTP, выгрузка marker-файла.
 * CLI: php cron/mirror_sync.php
 * Cron: 0 3 * * * cd /path/to/site && php cron/mirror_sync.php
 */
if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

require_once dirname(__DIR__) . '/includes/functions.php';
if (is_file(dirname(__DIR__) . '/includes/user_secrets.php')) {
  require_once dirname(__DIR__) . '/includes/user_secrets.php';
}

function mirror_ftp_put_marker(array $ftp, string $body): string {
  $host = (string)($ftp['host'] ?? '');
  $user = (string)($ftp['user'] ?? '');
  $pass = (string)($ftp['pass'] ?? '');
  $path = rtrim((string)($ftp['path'] ?? '/'), '/') . '/';
  if ($host === '' || $user === '') return 'ftp: empty credentials';

  if (!function_exists('ftp_connect')) {
    $remote = 'ftp://' . rawurlencode($user) . ':' . rawurlencode($pass) . '@' . $host . $path . 'streamlive_mirror_ok.txt';
    $tmp = tempnam(sys_get_temp_dir(), 'mrr');
    file_put_contents($tmp, $body);
    $ch = curl_init($remote);
    curl_setopt_array($ch, [
      CURLOPT_UPLOAD => true,
      CURLOPT_INFILE => fopen($tmp, 'rb'),
      CURLOPT_INFILESIZE => filesize($tmp),
      CURLOPT_FTP_CREATE_MISSING_DIRS => true,
      CURLOPT_TIMEOUT => 40,
      CURLOPT_RETURNTRANSFER => true,
    ]);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    @unlink($tmp);
    return ($ok !== false && $err === '') ? 'ok' : ('curl-ftp: ' . ($err ?: 'fail'));
  }

  $conn = @ftp_connect($host, 21, 20);
  if (!$conn) return 'ftp: connect failed';
  if (!@ftp_login($conn, $user, $pass)) {
    ftp_close($conn);
    return 'ftp: login failed';
  }
  @ftp_pasv($conn, true);
  $tmp = tempnam(sys_get_temp_dir(), 'mrr');
  file_put_contents($tmp, $body);
  $remote = $path . 'streamlive_mirror_ok.txt';
  $put = @ftp_put($conn, $remote, $tmp, FTP_ASCII);
  @unlink($tmp);
  ftp_close($conn);
  return $put ? 'ok' : 'ftp: put failed (path?)';
}

try {
  $rows = db()->query('SELECT * FROM site_mirrors WHERE is_active = 1')->fetchAll();
} catch (Throwable $e) {
  fwrite(STDERR, "No site_mirrors table yet: " . $e->getMessage() . "\n");
  exit(0);
}

if (!$rows) {
  echo "No active mirrors\n";
  exit(0);
}

foreach ($rows as $m) {
  $id = (int)$m['id'];
  $uid = (int)$m['user_id'];
  $status = 'ok';
  $errParts = [];

  try {
    $pass = (string)$m['db_pass_enc'];
    if (function_exists('user_secrets_decrypt')) {
      $dec = user_secrets_decrypt($pass);
      if ($dec !== null && $dec !== '') $pass = $dec;
    }
    $dsn = sprintf(
      'mysql:host=%s;dbname=%s;charset=utf8mb4',
      $m['db_host'],
      $m['db_name']
    );
    $pdo = new PDO($dsn, $m['db_user'], $pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_TIMEOUT => 15,
    ]);
    $pdo->query('SELECT 1');
  } catch (Throwable $e) {
    $status = 'db_error';
    $errParts[] = 'db: ' . $e->getMessage();
  }

  try {
    $ftp = ['host' => '', 'user' => '', 'pass' => '', 'path' => '/'];
    $st = db()->prepare('SELECT * FROM user_external_ids WHERE user_id = ? LIMIT 1');
    $st->execute([$uid]);
    $ext = $st->fetch();
    if ($ext && function_exists('user_secrets_decrypt')) {
      $ftp['host'] = (string)(user_secrets_decrypt($ext['ftp_host_enc'] ?? '') ?? '');
      $ftp['user'] = (string)(user_secrets_decrypt($ext['ftp_user_enc'] ?? '') ?? '');
      $ftp['pass'] = (string)(user_secrets_decrypt($ext['ftp_pass_enc'] ?? '') ?? '');
      $ftp['path'] = (string)(user_secrets_decrypt($ext['ftp_path_enc'] ?? '') ?? '/');
    }
    if ($ftp['host'] === '') {
      $errParts[] = 'ftp: not configured (account_external_ids.php)';
      if ($status === 'ok') $status = 'ftp_missing';
    } else {
      $body = "StreamLive mirror OK\nuser_id={$uid}\nmirror_id={$id}\nat=" . gmdate('c') . "\nurl=" . ($m['mirror_url'] ?? '') . "\n";
      $ftpRes = mirror_ftp_put_marker($ftp, $body);
      if ($ftpRes !== 'ok') {
        $errParts[] = $ftpRes;
        if ($status === 'ok') $status = 'ftp_error';
      }
    }
  } catch (Throwable $e) {
    $errParts[] = 'ftp: ' . $e->getMessage();
    if ($status === 'ok') $status = 'ftp_error';
  }

  $err = $errParts ? implode(' | ', $errParts) : null;
  try {
    db()->prepare('UPDATE site_mirrors SET last_sync_at = NOW(), last_status = ?, last_error = ? WHERE id = ?')
      ->execute([$status, $err, $id]);
  } catch (Throwable $e) {}

  echo "#{$id} user={$uid} status={$status}" . ($err ? " err={$err}" : '') . "\n";
}

echo "Done\n";
