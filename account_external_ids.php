<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/user_secrets.php';

$user = current_user();
if (!$user) {
  flash_set('error', 'Нужна авторизация');
  redirect('/login.php');
}

user_secrets_ensure_table();

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
  $wtp = trim((string)($_POST['wtp'] ?? ''));
  $mus = trim((string)($_POST['musicle'] ?? ''));
  if ($wtp === '' && $mus === '') {
    $error = 'Укажите хотя бы одно поле: WTP или Musicle';
  } else {
    try {
      $wEnc = $wtp !== '' ? user_secrets_encrypt($wtp) : null;
      $mEnc = $mus !== '' ? user_secrets_encrypt($mus) : null;
      db()->prepare(
        'INSERT INTO user_external_ids (user_id, wtp_enc, musicle_enc, filled_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
           wtp_enc = IF(filled_at IS NULL, VALUES(wtp_enc), wtp_enc),
           musicle_enc = IF(filled_at IS NULL, VALUES(musicle_enc), musicle_enc),
           filled_at = IF(filled_at IS NULL, NOW(), filled_at)'
      )->execute([(int)$user['id'], $wEnc, $mEnc]);
      $ok = 'Сохранено. Данные зашифрованы. Повторное изменение недоступно.';
      $locked = true;
      $st = db()->prepare('SELECT * FROM user_external_ids WHERE user_id = ? LIMIT 1');
      $st->execute([(int)$user['id']]);
      $row = $st->fetch() ?: null;
    } catch (Throwable $e) {
      $error = 'Ошибка сохранения: ' . $e->getMessage();
    }
  }
}

$pageTitle = 'WTP / Musicle';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:640px;padding:24px 16px">
  <h1 style="margin:0 0 8px;font-size:22px">WTP и Musicle</h1>
  <p style="color:var(--text-dim,#aaa);margin:0 0 20px;font-size:14px">
    Укажите свои идентификаторы. Данные хранятся <b>в зашифрованном виде</b>. Сохранить можно <b>только один раз</b>.
  </p>

  <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:14px"><?= e($error) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success" style="margin-bottom:14px"><?= e($ok) ?></div><?php endif; ?>

  <?php if ($locked): ?>
    <div style="padding:16px;border-radius:12px;background:rgba(167,139,250,.12);border:1px solid rgba(167,139,250,.35)">
      <b>Данные уже сохранены</b> (<?= e((string)($row['filled_at'] ?? '')) ?>).<br>
      Повторный ввод и просмотр в открытом виде недоступны — так задумано для безопасности.
    </div>
  <?php else: ?>
    <form method="post" style="display:grid;gap:14px">
      <label style="display:grid;gap:6px">
        <span>WTP</span>
        <input type="text" name="wtp" maxlength="255" autocomplete="off" placeholder="Ваш WTP ID"
          style="padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:inherit">
      </label>
      <label style="display:grid;gap:6px">
        <span>Musicle</span>
        <input type="text" name="musicle" maxlength="255" autocomplete="off" placeholder="Ваш Musicle ID"
          style="padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:inherit">
      </label>
      <button type="submit" class="btn btn-primary">Сохранить навсегда</button>
      <p style="font-size:12px;color:var(--text-dim,#888);margin:0">После сохранения изменить будет нельзя.</p>
    </form>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
