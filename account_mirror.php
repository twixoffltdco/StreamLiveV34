<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/user_secrets.php';

$user = require_login();
user_secrets_ensure_table();

try {
  db()->exec("CREATE TABLE IF NOT EXISTS site_mirrors (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    mirror_url VARCHAR(512) NOT NULL,
    db_host VARCHAR(255) NOT NULL,
    db_name VARCHAR(255) NOT NULL,
    db_user VARCHAR(255) NOT NULL,
    db_pass_enc TEXT NOT NULL,
    last_sync_at DATETIME NULL DEFAULT NULL,
    last_status VARCHAR(64) NULL DEFAULT NULL,
    last_error TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mirror_user (user_id),
    KEY idx_mirror_active (is_active)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$row = null;
try {
  $st = db()->prepare('SELECT * FROM site_mirrors WHERE user_id = ? LIMIT 1');
  $st->execute([(int)$user['id']]);
  $row = $st->fetch() ?: null;
} catch (Throwable $e) { $row = null; }

$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? 'save');
  if ($action === 'disable' && $row) {
    try {
      db()->prepare('UPDATE site_mirrors SET is_active = 0 WHERE user_id = ?')->execute([(int)$user['id']]);
      $ok = 'Зеркало отключено';
      $st = db()->prepare('SELECT * FROM site_mirrors WHERE user_id = ? LIMIT 1');
      $st->execute([(int)$user['id']]);
      $row = $st->fetch() ?: null;
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  } else {
    $url = trim((string)($_POST['mirror_url'] ?? ''));
    $dbHost = trim((string)($_POST['db_host'] ?? ''));
    $dbName = trim((string)($_POST['db_name'] ?? ''));
    $dbUser = trim((string)($_POST['db_user'] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    if ($url === '' || $dbHost === '' || $dbName === '' || $dbUser === '') {
      $error = 'Заполните URL и параметры MySQL';
    } elseif ($dbPass === '' && !$row) {
      $error = 'Укажите пароль MySQL';
    } else {
      try {
        $encPass = $row && $dbPass === ''
          ? (string)$row['db_pass_enc']
          : user_secrets_encrypt($dbPass);
        if ($row) {
          db()->prepare(
            'UPDATE site_mirrors SET mirror_url=?, db_host=?, db_name=?, db_user=?, db_pass_enc=?, is_active=1 WHERE user_id=?'
          )->execute([$url, $dbHost, $dbName, $dbUser, $encPass, (int)$user['id']]);
        } else {
          db()->prepare(
            'INSERT INTO site_mirrors (user_id, mirror_url, db_host, db_name, db_user, db_pass_enc, is_active)
             VALUES (?,?,?,?,?,?,1)'
          )->execute([(int)$user['id'], $url, $dbHost, $dbName, $dbUser, $encPass]);
        }
        $ok = 'Зеркало сохранено';
        $st = db()->prepare('SELECT * FROM site_mirrors WHERE user_id = ? LIMIT 1');
        $st->execute([(int)$user['id']]);
        $row = $st->fetch() ?: null;
      } catch (Throwable $e) {
        $error = 'Ошибка: ' . $e->getMessage();
      }
    }
  }
}

$pageTitle = 'Сайт-зеркало';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:680px;margin:24px auto;padding:0 16px">
  <h1 style="font-size:22px;margin:0 0 8px">Сайт-зеркало</h1>
  <p style="color:var(--text-dim,#888);font-size:14px;margin:0 0 16px">
    Один аккаунт — одно активное зеркало. Укажите URL зеркала и доступ к его MySQL.
    FTP задаётся отдельно: <a href="/account_external_ids.php">FTP для зеркала</a>.
  </p>

  <?php if ($error): ?><div style="background:#3b1212;color:#fecaca;padding:12px;border-radius:10px;margin-bottom:12px"><?= e($error) ?></div><?php endif; ?>
  <?php if ($ok): ?><div style="background:#0f2e1a;color:#bbf7d0;padding:12px;border-radius:10px;margin-bottom:12px"><?= e($ok) ?></div><?php endif; ?>

  <?php if ($row): ?>
  <div style="padding:14px;border-radius:12px;border:1px solid rgba(255,255,255,.1);margin-bottom:16px;font-size:13px">
    <div>Статус: <b><?= e((string)($row['last_status'] ?? '—')) ?></b>
      <?= !empty($row['is_active']) ? '(активно)' : '(отключено)' ?></div>
    <div>Последний синк: <?= e((string)($row['last_sync_at'] ?? 'ещё не было')) ?></div>
    <?php if (!empty($row['last_error'])): ?>
      <div style="color:#fca5a5;margin-top:6px">Ошибка: <?= e((string)$row['last_error']) ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <form method="POST" style="display:grid;gap:12px">
    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
    <label style="display:grid;gap:6px"><span>URL зеркала</span>
      <input name="mirror_url" required value="<?= e($row['mirror_url'] ?? '') ?>" placeholder="https://mirror.example.com"
        style="padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:inherit">
    </label>
    <label style="display:grid;gap:6px"><span>MySQL host</span>
      <input name="db_host" required value="<?= e($row['db_host'] ?? '') ?>" placeholder="localhost"
        style="padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:inherit">
    </label>
    <label style="display:grid;gap:6px"><span>MySQL database</span>
      <input name="db_name" required value="<?= e($row['db_name'] ?? '') ?>"
        style="padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:inherit">
    </label>
    <label style="display:grid;gap:6px"><span>MySQL user</span>
      <input name="db_user" required value="<?= e($row['db_user'] ?? '') ?>"
        style="padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:inherit">
    </label>
    <label style="display:grid;gap:6px"><span>MySQL password</span>
      <input type="password" name="db_pass" <?= $row ? '' : 'required' ?> autocomplete="new-password" placeholder="<?= $row ? '••••••••' : '' ?>"
        style="padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:inherit">
    </label>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button type="submit" name="action" value="save" class="btn btn-primary">Сохранить зеркало</button>
      <?php if ($row && !empty($row['is_active'])): ?>
        <button type="submit" name="action" value="disable" class="btn btn-outline">Отключить</button>
      <?php endif; ?>
    </div>
  </form>
  <p style="font-size:12px;color:var(--text-dim,#888);margin-top:16px">
    Cron на <b>основном</b> сайте: <code>php cron/mirror_sync.php</code> раз в сутки. Проверяет MySQL зеркала и FTP (файл <code>streamlive_mirror_ok.txt</code>). FTP задаётся в <a href="/account_external_ids.php">FTP для зеркала</a> (правка раз в 30 дней).
  </p>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
