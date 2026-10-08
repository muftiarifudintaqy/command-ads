<?php
/* ============================================================
   LOGIN TAHAN LAMA ("ingat saya") — cookie bertanda tangan HMAC.
   - Tidak bergantung umur file sesi PHP (yang bisa dihapus server tiap 24 menit).
   - Otomatis tidak berlaku kalau password user diganti / user dihapus dari config.
   ============================================================ */
const MA_REMEMBER_DAYS = 1825;   // 5 tahun

function ma_secret(): string {
    $f = __DIR__ . '/logs/.remember_secret';
    if (!is_file($f)) { @mkdir(__DIR__ . '/logs', 0750, true); @file_put_contents($f, bin2hex(random_bytes(32))); @chmod($f, 0600); }
    $s = trim((string)@file_get_contents($f));
    return $s !== '' ? $s : hash('sha256', __DIR__ . php_uname());
}
function ma_pw_for(array $C, string $u): ?string {
    $users = $C['login_users'] ?? [];
    if ($users) { foreach ($users as $k => $p) if (strcasecmp(trim((string)$k), $u) === 0) return (string)$p; return null; }
    return ($C['app_password'] ?? '') !== '' ? (string)$C['app_password'] : null;
}
function ma_cookie_opts(int $exp): array {
    return ['expires' => $exp, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax'];
}
function ma_remember_set(array $C, string $u): void {
    $exp = time() + MA_REMEMBER_DAYS * 86400;
    $sig = hash_hmac('sha256', "$u|$exp|" . hash('sha256', ma_pw_for($C, $u) ?? ''), ma_secret());
    setcookie('ma_remember', base64_encode("$u|$exp|$sig"), ma_cookie_opts($exp));
}
function ma_remember_check(array $C): ?string {
    $raw = base64_decode((string)($_COOKIE['ma_remember'] ?? ''), true);
    if (!$raw) return null;
    $p = explode('|', $raw);
    if (count($p) !== 3) return null;
    [$u, $exp, $sig] = $p;
    if ((int)$exp < time()) return null;
    $pw = ma_pw_for($C, $u);
    if ($pw === null) return null;
    return hash_equals(hash_hmac('sha256', "$u|$exp|" . hash('sha256', $pw), ma_secret()), (string)$sig) ? $u : null;
}
function ma_remember_clear(): void { setcookie('ma_remember', '', ma_cookie_opts(time() - 3600)); }

/* Sesi PHP disimpan di folder sendiri (logs/sessions) supaya tidak dibersihkan cron bawaan Ubuntu,
   dan bertahan 30 hari walau tab ditutup. Lewat dari itu, cookie "ingat saya" di atas yang memasukkan lagi. */
function ma_session_start(): void {
    $dir = __DIR__ . '/logs/sessions';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string)(86400 * 30));
    session_set_cookie_params(['lifetime' => 86400 * MA_REMEMBER_DAYS, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}