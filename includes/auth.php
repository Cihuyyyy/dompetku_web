<?php
/**
 * includes/auth.php
 * Autentikasi stateless berbasis cookie bertanda tangan (HMAC-SHA256).
 * Tidak memakai session_start() karena filesystem serverless Vercel tidak persisten antar-request.
 *
 * Environment variable:
 *   AUTH_SECRET = string acak min. 32 karakter
 *                 (php -r "echo bin2hex(random_bytes(32));")
 *   Mengganti AUTH_SECRET akan me-logout semua perangkat.
 *
 * Format cookie:  base64url(JSON payload) . base64url(HMAC)
 * Payload:        uid, usr, sat (waktu login awal), exp (kedaluwarsa)
 */

declare(strict_types=1);

require_once __DIR__ . '/gsheet.php';   // gs_env(), e()

const AUTH_COOKIE = 'dompetku_auth';
const CSRF_COOKIE = 'dompetku_csrf';
const AUTH_TTL    = 7 * 86400;    // masa berlaku cookie: 7 hari
const AUTH_RENEW  = 3 * 86400;    // perpanjang otomatis jika sisa waktu < 3 hari (sliding)
const AUTH_MAX    = 30 * 86400;   // batas mutlak sejak login awal: 30 hari

/* ======================== UTILITAS ======================== */

function auth_secret(): string
{
    $s = gs_env('AUTH_SECRET');
    if (strlen($s) < 32) {
        error_log('[auth] AUTH_SECRET kosong atau kurang dari 32 karakter');
        http_response_code(500);
        exit('Konfigurasi server belum lengkap.');
    }
    return $s;
}

function b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64url_decode(string $str): ?string
{
    $bin = base64_decode(strtr($str, '-_', '+/'), true);
    return $bin === false ? null : $bin;
}

/** HMAC dengan pemisahan tujuan (auth vs csrf) agar token satu jenis tidak bisa dipakai untuk jenis lain. */
function auth_sign(string $data, string $purpose): string
{
    return hash_hmac('sha256', $purpose . '|' . $data, auth_secret(), true);
}

function is_https(): bool
{
    return (($_SERVER['HTTPS'] ?? '') === 'on')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
}

function cookie_set(string $name, string $value, int $expires): void
{
    setcookie($name, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 302);
    exit;
}

function send_no_cache(): void
{
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
}

/* ======================== COOKIE LOGIN ======================== */

/** Terbitkan cookie login. $sat dipakai saat perpanjangan agar batas mutlak tetap dihitung dari login awal. */
function auth_issue(string $userId, string $username, ?int $sat = null): void
{
    $now     = time();
    $payload = b64url_encode(json_encode([
        'uid' => $userId,
        'usr' => $username,
        'sat' => $sat ?? $now,
        'exp' => $now + AUTH_TTL,
    ], JSON_UNESCAPED_UNICODE));

    $token = $payload . '.' . b64url_encode(auth_sign($payload, 'auth'));

    cookie_set(AUTH_COOKIE, $token, $now + AUTH_TTL);
    $_COOKIE[AUTH_COOKIE] = $token;
}

function auth_clear(): void
{
    cookie_set(AUTH_COOKIE, '', time() - 3600);
    unset($_COOKIE[AUTH_COOKIE]);
}

/** Validasi cookie. Mengembalikan data user atau null bila tidak valid / kedaluwarsa. */
function auth_user(): ?array
{
    $token = $_COOKIE[AUTH_COOKIE] ?? '';
    if (!is_string($token) || $token === '' || strlen($token) > 1024) {
        return null;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }
    [$payload, $sigB64] = $parts;

    $sig = b64url_decode($sigB64);
    if ($sig === null || !hash_equals(auth_sign($payload, 'auth'), $sig)) {
        return null;
    }

    $json = b64url_decode($payload);
    $data = $json === null ? null : json_decode($json, true);
    if (!is_array($data) || !isset($data['uid'], $data['usr'], $data['exp'], $data['sat'])) {
        return null;
    }

    $now = time();
    if ((int) $data['exp'] <= $now || ($now - (int) $data['sat']) > AUTH_MAX) {
        return null;
    }

    return [
        'user_id'  => (string) $data['uid'],
        'username' => (string) $data['usr'],
        'exp'      => (int) $data['exp'],
        'sat'      => (int) $data['sat'],
    ];
}

function auth_check(): bool
{
    return auth_user() !== null;
}

/** Panggil di awal setiap halaman terproteksi (sebelum ada output). */
function require_login(): array
{
    send_no_cache();

    $user = auth_user();
    if ($user === null) {
        auth_clear();
        redirect('/login');
    }

    // Sliding expiration: perpanjang jika sisa masa berlaku tinggal sedikit
    if ($user['exp'] - time() < AUTH_RENEW) {
        auth_issue($user['user_id'], $user['username'], $user['sat']);
    }

    return $user;
}

/** Untuk halaman login: bila sudah login, langsung ke dashboard. */
function require_guest(): void
{
    if (auth_check()) {
        redirect('/dashboard');
    }
}

/* ======================== CSRF (double-submit cookie, bertanda tangan) ======================== */

function csrf_token(): string
{
    $cur = $_COOKIE[CSRF_COOKIE] ?? '';
    if (is_string($cur) && csrf_signature_ok($cur)) {
        return $cur;
    }

    $rand  = b64url_encode(random_bytes(16));
    $token = $rand . '.' . b64url_encode(auth_sign($rand, 'csrf'));

    cookie_set(CSRF_COOKIE, $token, 0);   // cookie sesi browser
    $_COOKIE[CSRF_COOKIE] = $token;
    return $token;
}

function csrf_signature_ok(string $token): bool
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return false;
    }
    $sig = b64url_decode($parts[1]);
    return $sig !== null && hash_equals(auth_sign($parts[0], 'csrf'), $sig);
}

/** Cocokkan token dari form dengan cookie, sekaligus cek tanda tangannya. */
function csrf_verify($submitted): bool
{
    $cookie = $_COOKIE[CSRF_COOKIE] ?? '';
    if (!is_string($cookie) || $cookie === '' || !is_string($submitted) || $submitted === '') {
        return false;
    }
    return hash_equals($cookie, $submitted) && csrf_signature_ok($cookie);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}