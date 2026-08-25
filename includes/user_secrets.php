<?php
/**
 * Encrypted once-fields: WTP / Musicle + mirror DB password.
 * Key: APP_SECRET or SITE_SECRET or fallback hash of DB credentials.
 */
if (!function_exists('user_secrets_key')) {
  function user_secrets_key(): string {
    if (defined('APP_SECRET') && APP_SECRET) return hash('sha256', (string)APP_SECRET, true);
    if (defined('SITE_SECRET') && SITE_SECRET) return hash('sha256', (string)SITE_SECRET, true);
    $seed = (defined('DB_HOST') ? DB_HOST : '') . '|' . (defined('DB_NAME') ? DB_NAME : 'streamlive');
    return hash('sha256', $seed . '|user_secrets_v1', true);
  }
}

if (!function_exists('user_secrets_encrypt')) {
  function user_secrets_encrypt(string $plain): string {
    $plain = trim($plain);
    if ($plain === '') return '';
    $key = user_secrets_key();
    $iv = random_bytes(16);
    $ct = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($ct === false) return '';
    return base64_encode($iv . $ct);
  }
}

if (!function_exists('user_secrets_decrypt')) {
  function user_secrets_decrypt(string $blob): string {
    $blob = trim($blob);
    if ($blob === '') return '';
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 17) return '';
    $iv = substr($raw, 0, 16);
    $ct = substr($raw, 16);
    $pt = openssl_decrypt($ct, 'AES-256-CBC', user_secrets_key(), OPENSSL_RAW_DATA, $iv);
    return $pt === false ? '' : $pt;
  }
}

if (!function_exists('user_secrets_ensure_table')) {
  function user_secrets_ensure_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
      db()->exec("CREATE TABLE IF NOT EXISTS user_external_ids (
        user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        wtp_enc TEXT NULL,
        musicle_enc TEXT NULL,
        filled_at DATETIME NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
  }
}
