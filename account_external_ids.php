<?php
/**
 * FTP-данные пользователя (зашифровано, только один раз).
 * Раньше ошибочно называлось WTP — исправлено на FTP.
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
try { db()->exec("ALTER TABLE user_external_ids ADD COLUMN ftp_host_enc TEXT NULL"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE user_external_ids ADD COLUMN ftp_user_enc TEXT NULL"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE user_external_ids ADD COLUMN ftp_pass_enc TEXT NULL"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE user_external_ids ADD COLUMN ftp_path_enc TEXT NULL"); } catch (Throwable $e) {}

$row = null;
try {
  $st = db()->prepare('SELECT * FROM user_external_ids WHERE user_id = ? LIMIT 1');
  $st->execute([(int)$user['id']]);
  $row = $st->fetch() ?: null;
} catch (Throwable $e) { $row = null; }

$locked = $row && !empty($row['filled_at']);
$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
  $host = trim((string)($_POST['ftp_host'] ?? ''));
  $fuser = trim((string)($_POST['ftp_user'] ?? ''));
  $fpass = (string)($_POST['ftp_pass'] ?? '');
  $fpath = trim((string)($_POST['ftp_path'] ?? '/'));
  if ($host === '' || $fuser === '' || $fpass === '') {
    $error = 'Укажите FTP host, логин и пароль';
  } else {
    try {
      db()->prepare(
        'INSERT INTO user_external_ids (user_id, ftp_host_enc, ftp_user_enc, ftp_pass_enc, ftp_path_enc, filled_at)
         VALUES (?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE
           ftp_host_enc = IF(filled_at IS NULL, VALUES(ftp_host_enc), ftp_host_enc),
           ftp_user_enc = IF(filled_at IS NULL, VALUES(ftp_user_enc), ftp_user_enc),
           ftp_pass_enc = IF(filled_at IS NULL, VALUES(ftp_pass_enc), ftp_pass_enc),
           ftp_path_enc = IF(filled_at IS NULL, VALUES(ftp_path_enc), ftp_path_enc),
           filled_at = IF(filled_at IS NULL, NOW(), filled_at)'
      )->execute([
        (int)$user['id'],
        user_secrets_encrypt($host),
        user_secrets_encrypt($fuser),
        user_secrets_encrypt($fpass),
        user_secrets_encrypt($fpath),
      ]);
      $ok = 'FTP сохранён в зашифрованном виде. Изменить больше нельзя.';
      $locked = true;
      $st = db()->prepare('SELECT * FROM user_external_ids WHERE user_id = ? LIMIT 1');
      $st->execute([(int)$user['id']]);
      $row = $st->fetch() ?: null;
    } catch (Throwable $e) {
      $error = 'Ошибка сохранения: ' . $e->getMessage();
    }
  }
}

$pageTitle = 'FTP';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:640px;padding:24px 16px">
  <h1 style="margin:0 0 8px;font-size:22px">FTP-доступ</h1>
  <p style="color:var(--text-dim,#aaa);margin:0 0 20px;font-size:14px">
    Укажите FTP один раз. Данные хранятся <b>зашифрованными</b> (AES-256). Повторный ввод недоступен.
  </p>
  <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:14px"><?= e($error) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success" style="margin-bottom:14px"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($locked): ?>
    <div style="padding:16px;border-radius:12px;background:rgba(167,139,250,.12);border:1px solid rgba(167,139,250,.35)">
      <b>FTP уже сохранён</b> (<?= e((string)($row['filled_at'] ?? '')) ?>).<br>
      Открытый просмотр и изменение отключены.
    </div>
  <?php else: ?>
    <form method="post" style="display:grid;gap:14px" autocomplete="off">
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
      <label style="display:grid;gap:6px"><span>Путь (необязательно)</span>
        <input name="ftp_path" maxlength="512" value="/" placeholder="/public_html"
          style="padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:inherit">
      </label>
      <button type="submit" class="btn btn-primary">Сохранить навсегда</button>
    </form>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
