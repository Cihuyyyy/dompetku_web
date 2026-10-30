<?php
/**
 * api/index.php
 * Router & entry point tunggal (semua request diarahkan ke sini oleh vercel.json).
 * Lokal: php -S localhost:8000 api/index.php
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/includes/auth.php';

// Header keamanan dasar untuk semua respons
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

/* ---------- Normalisasi path ---------- */
$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$path = rawurldecode($path);
if (strpos($path, "\0") !== false) {
    not_found();
}
$path = '/' . trim($path, '/');   // '/login/' -> '/login', '' -> '/'

/* ---------- Aset statis (css/js/gambar) ---------- */
if (strpos($path, '/assets/') === 0) {
    serve_asset($path);
}

/* ---------- Halaman ---------- */
// URL => file di /public. Halaman yang belum dibuat otomatis menghasilkan 404.
$routes = [
    '/login'     => 'login.php',
    '/logout'    => 'logout.php',
    '/dashboard' => 'dashboard.php',
    '/transaksi' => 'transaksi.php',
    '/kalender'  => 'kalender.php',
];

if ($path === '/') {
    redirect(auth_check() ? '/dashboard' : '/login');
}

if (!isset($routes[$path])) {
    not_found();
}

$file = BASE_PATH . '/public/' . $routes[$path];
if (!is_file($file)) {
    not_found();
}

require $file;
exit;

/* ======================== HELPER ======================== */

function not_found(): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>404</title><body style="font-family:system-ui;text-align:center;padding:4rem 1rem">'
       . '<h1>404</h1><p>Halaman tidak ditemukan.</p><a href="/">Kembali</a></body></html>';
    exit;
}

function serve_asset(string $path): never
{
    $root = realpath(BASE_PATH . '/public/assets');
    $file = realpath(BASE_PATH . '/public' . $path);

    // Cegah path traversal: file harus berada di dalam public/assets
    if ($root === false || $file === false
        || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0
        || !is_file($file)) {
        not_found();
    }

    $types = [
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'webp'  => 'image/webp',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff2' => 'font/woff2',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!isset($types[$ext])) {
        not_found();
    }

    header('Content-Type: ' . $types[$ext]);
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=3600');
    readfile($file);
    exit;
}