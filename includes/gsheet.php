<?php
/**
 * includes/gsheet.php
 * Konektor cURL: PHP (Vercel) -> Google Apps Script Web App -> Google Sheets
 *
 * Environment variable (Vercel > Settings > Environment Variables; lokal: file .env):
 *   GAS_URL   = https://script.google.com/macros/s/XXXXXXXX/exec
 *   GAS_TOKEN = nilai yang sama dengan Script Property API_TOKEN di Apps Script
 */

declare(strict_types=1);

/* ======================== KONFIGURASI ======================== */

function gs_env(string $key): string
{
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return (string) $v;
    }
    if (!empty($_ENV[$key])) {
        return (string) $_ENV[$key];
    }

    // Fallback untuk development lokal: baca .env di root proyek
    static $file = null;
    if ($file === null) {
        $file = [];
        $path = __DIR__ . '/../.env';
        if (is_readable($path)) {
            $parsed = parse_ini_file($path, false, INI_SCANNER_RAW);
            if (is_array($parsed)) {
                $file = $parsed;
            }
        }
    }
    return isset($file[$key]) ? trim((string) $file[$key], " \t\n\r\0\x0B\"'") : '';
}

/* ======================== HTTP CORE ======================== */

/**
 * Kirim request ke Apps Script.
 * @param string     $method  'GET' | 'POST'
 * @param array      $params  query string (GET) atau isi payload (POST)
 * @return array     selalu array berisi minimal key 'success'
 */
function gs_request(string $method, array $params = []): array
{
    $url   = gs_env('GAS_URL');
    $token = gs_env('GAS_TOKEN');

    if ($url === '' || $token === '') {
        error_log('[gsheet] GAS_URL / GAS_TOKEN belum diatur');
        return ['success' => false, 'message' => 'Konfigurasi server belum lengkap'];
    }

    $ch = curl_init();
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,   // Apps Script selalu redirect (302) ke googleusercontent
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => '',     // terima gzip
        CURLOPT_USERAGENT      => 'Dompetku/1.0',
    ];

    if (strtoupper($method) === 'POST') {
        $payload = $params + ['token' => $token];
        $opts[CURLOPT_URL]        = $url;
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        // CURLOPT_POSTREDIR sengaja dibiarkan default: setelah 302, cURL mengambil hasil via GET (perilaku yang benar untuk Apps Script)
    } else {
        $query = http_build_query($params + ['token' => $token]);
        $opts[CURLOPT_URL] = $url . (strpos($url, '?') === false ? '?' : '&') . $query;
    }

    curl_setopt_array($ch, $opts);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $body === false) {
        error_log("[gsheet] cURL error ($errno): $error");
        return ['success' => false, 'message' => 'Tidak dapat terhubung ke database'];
    }

    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        // Biasanya halaman HTML error/login Google (deployment salah / akses bukan "Anyone")
        error_log("[gsheet] Respons bukan JSON (HTTP $code): " . substr((string) $body, 0, 200));
        return ['success' => false, 'message' => 'Respons database tidak valid'];
    }

    return $json;
}

function gs_get(string $action, array $params = []): array
{
    return gs_request('GET', ['action' => $action] + $params);
}

function gs_post(string $action, array $payload = []): array
{
    return gs_request('POST', ['action' => $action] + $payload);
}

/* ======================== AUTENTIKASI ======================== */

/**
 * Verifikasi login. Hash diambil dari Sheet Users, dicocokkan di PHP dengan password_verify().
 * Setelah sukses, caller sebaiknya: session_regenerate_id(true); lalu set $_SESSION.
 */
function gs_login(string $username, string $password): array
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['success' => false, 'message' => 'Username dan password wajib diisi'];
    }

    $res = gs_post('get_user', ['username' => $username]);

    // Gagal koneksi/konfigurasi (bukan "user tidak ditemukan")
    if (!($res['success'] ?? false) && !isset($res['message'])) {
        return ['success' => false, 'message' => 'Gagal menghubungi server'];
    }
    if (!($res['success'] ?? false) && ($res['message'] ?? '') !== 'User tidak ditemukan') {
        return ['success' => false, 'message' => $res['message']];
    }

    // Pesan generik agar tidak membocorkan username mana yang valid
    $hash = (string) ($res['password_hash'] ?? '');
    if (!($res['success'] ?? false) || $hash === '' || !password_verify($password, $hash)) {
        return ['success' => false, 'message' => 'Username atau password salah'];
    }

    return [
        'success'  => true,
        'user_id'  => $res['user_id'],
        'username' => $res['username'],
    ];
}

/* ======================== DATA (READ) ======================== */

/** Satu request untuk semua kebutuhan halaman. $month format 'YYYY-MM' (opsional, memfilter transactions). */
function gs_get_all(?string $month = null): array
{
    return gs_get('get_all', $month ? ['month' => $month] : []);
}

function gs_get_summary(): array
{
    return gs_get('get_summary');
}

function gs_get_transactions(?string $month = null): array
{
    return gs_get('get_transactions', $month ? ['month' => $month] : []);
}

/** Daftar kantong + kategori (untuk dropdown form). */
function gs_get_master(): array
{
    return gs_get('get_master');
}

function gs_get_budget(): array
{
    return gs_get('get_budget');
}

/* ======================== TRANSAKSI (WRITE) ======================== */

function gs_add_transaction(array $in): array
{
    return gs_post('add_transaction', gs_clean_transaction($in));
}

function gs_update_transaction(string $id, array $in): array
{
    return gs_post('update_transaction', ['id' => trim($id)] + gs_clean_transaction($in));
}

function gs_delete_transaction(string $id): array
{
    return gs_post('delete_transaction', ['id' => trim($id)]);
}

/** Whitelist & normalisasi field (validasi akhir tetap di Apps Script). */
function gs_clean_transaction(array $in): array
{
    // Terima "1.500.000" atau "1500000"
    $nominal = preg_replace('/[^\d.,-]/', '', (string) ($in['nominal'] ?? '0'));
    if (strpos($nominal, ',') !== false) {            // format Indonesia: titik ribuan, koma desimal
        $nominal = str_replace('.', '', $nominal);
        $nominal = str_replace(',', '.', $nominal);
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $nominal)) {
        $nominal = str_replace('.', '', $nominal);    // "1.500.000" -> 1500000
    }

    return [
        'tanggal'     => trim((string) ($in['tanggal'] ?? '')),
        'tipe'        => trim((string) ($in['tipe'] ?? '')),
        'kantong_id'  => trim((string) ($in['kantong_id'] ?? '')),
        'kategori_id' => trim((string) ($in['kategori_id'] ?? '')),
        'nominal'     => (float) $nominal,
        'catatan'     => mb_substr(trim((string) ($in['catatan'] ?? '')), 0, 200),
    ];
}

/* ======================== HELPER TAMPILAN ======================== */

function format_rupiah($n): string
{
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}