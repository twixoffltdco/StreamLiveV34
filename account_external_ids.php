<?php
/**
 * FTP-данные пользователя (зашифровано).
 * Редактирование: можно задать/сменить не чаще 1 раза в 30 дней.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/user_secrets.php';

$user = current_user();
if (!$user) {
  flash_set('error', 'Нужна авторизация');
  redirect('/login.php');
}

user_secrets_ensure_table();
foreach ([
  "ALTER TABLE user_external_ids ADD COLUMN ftp_host_enc TEXT NULL",
  "ALTER TABLE user_external_ids ADD COLUMN ftp_user_enc TEXT NULL",
  "ALTER TABLE user_external_ids ADD COLUMN ftp_pass_enc TEXT NULL",
  "ALTER TABLE user_external_ids ADD COLUMN ftp_path_enc TEXT NULL",
  "ALTER TABLE user_external_ids ADD COLUMN ftp_updated_at DATETIME NULL",
] as $q) {
  try { db()->exec($q); } catch (Throwable $e) {}
}

$row = null;
try {
  $st = db()->prepare('SELECT * FROM user_external_ids WHERE user_id = ? LIMIT 1');
  $st->execute([(int)$user['id']]);
  $row = $st->fetch() ?: null;
} catch (Throwable $e) { $row = null; }

$DAYS = 30;
$lastTs = 0;
if ($row) {
  $ref = $row['ftp_updated_at'] ?? $row['filled_at'] ?? null;
  if ($ref) $lastTs = (int)strtotime((string)$ref);
}
$canEdit = true;
$nextEditAt = null;
if ($lastTs > 0) {
  $next = $lastTs + ($DAYS * 86400);
  if (time() < $next) {
    $canEdit = false;
    $nextEditAt = $next;
  }
}

$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
  $host = trim((string)($_POST['ftp_host'] ?? ''));
  $fuser = trim((string)($_POST['ftp_user'] ?? ''));
  $fpass = (string)($_POST['ftp_pass'] ?? '');
  $fpath = trim((string)($_POST['ftp_path'] ?? '/'));
  if ($fpath === '') $fpath = '/';
  if ($host === '' || $fuser === '' || $fpass === '') {
    $error = 'Укажите FTP host, логин и пароль';
  } else {
    try {
      db()->prepare(
        'INSERT INTO user_external_ids (user_id, ftp_host_enc, ftp_user_enc, ftp_pass_enc, ftp_path_enc, filled_at, ftp_updated_at)
         VALUES (?,?,?,?,?,NOW(),NOW())
         ON DUPLICATE KEY UPDATE
           ftp_host_enc = VALUES(ftp_host_enc),
           ftp_user_enc = VALUES(ftp_user_enc),
           ftp_pass_enc = VALUES(ftp_pass_enc),
           ftp_path_enc = VALUES(ftp_path_enc),
           filled_at = COALESCE(filled_at, NOW()),
           ftp_updated_at = NOW()'
      )->execute([
        (int)$user['id'],
        user_secrets_encrypt($host),
        user_secrets_encrypt($fuser),
        user_secrets_encrypt($fpass),
        user_secrets_encrypt($fpath),
      ]);
      $ok = 'FTP сохранён (зашифровано). Следующее изменение — через ' . $DAYS . ' дней.';
      $canEdit = false;
      $nextEditAt = time() + ($DAYS * 86400);
      $st = db()->prepare('SELECT * FROM user_external_ids WHERE user_id = ? LIMIT 1');
      $st->execute([(int)$user['id']]);
      $row = $st->fetch() ?: null;
    } catch (Throwable $e) {
      $error = 'Ошибка сохранения: ' . $e->getMessage();
    }
  }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$canEdit) {
  $error = 'FTP можно менять не чаще раза в ' . $DAYS . ' дней. Доступно с ' . date('d.m.Y H:i', (int)$nextEditAt);
}

$pageTitle = 'FTP для зеркала';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:640px;margin:24px auto;padding:0 16px">
  <h1 style="font-size:22px;margin:0 0 8px">FTP-доступ (зеркало)</h1>
  <p style="color:var(--text-dim,#888);font-size:14px;margin:0 0 18px">
    Данные хранятся в зашифрованном виде. Менять можно не чаще 1 раза в <?= (int)$DAYS ?> дней.
    Используются для выгрузки обновлений на ваш сайт-зеркало (cron).
  </p>

  <?php if ($error): ?><div class="alert" style="background:#3b1212;color:#fecaca;padding:12px;border-radius:10px;margin-bottom:14px"><?= e($error) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert" style="background:#0f2e1a;color:#bbf7d0;padding:12px;border-radius:10px;margin-bottom:14px"><?= e($ok) ?></div><?php endif; ?>

  <?php if (!$canEdit && $row): ?>
    <div style="padding:16px;border:1px solid rgba(255,255,255,.1);border-radius:12px;background:rgba(255,255,255,.03)">
      <p style="margin:0 0 8px"><strong>FTP уже задан</strong></p>
      <p style="margin:0;font-size:13px;opacity:.75">
        Хост сохранён (скрыт). Следующее редактирование:
        <b><?= $nextEditAt ? e(date('d.m.Y H:i', (int)$nextEditAt)) : '—' ?></b>
      </p>
      <p style="margin:12px 0 0;font-size:13px">
        Зеркало MySQL: <a href="/account_mirror.php">account_mirror.php</a>
      </p>
    </div>
  <?php else: ?>
    <form method="POST" style="display:grid;gap:12px">
      <?= function_exists('csrf_field') ? csrf_field() : '' ?>
      <label style="display:grid;gap:6px"><span>FTP host</span>
        <input name="ftp_host" required maxlength="255" placeholder="ftp.example.com"
          style="padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:inherit">
      </label>
      <label style="display:grid;gap:6px"><span>FTP логин</span>
        <input name="ftp_user" required maxlength="255"
          style="padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:inherit">
      </label>
      <label style="display:grid;gap:6px"><span>FTP пароль</span>
        <input type="password" name="ftp_pass" required autocomplete="new-password"
          style="padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:inherit">
      </label>
      <label style="display:grid;gap:6px"><span>Путь на сервере</span>
        <input name="ftp_path" maxlength="512" value="/public_html" placeholder="/public_html"
          style="padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:inherit">
      </label>
      <button type="submit" class="btn btn-primary">Сохранить FTP</button>
    </form>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
