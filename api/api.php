<?php
declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
]);
session_start();

const SESSION_IDLE_TIMEOUT = 1800;
const SESSION_ABSOLUTE_TIMEOUT = 28800;
const GLOBAL_STATE_KEY = 'global';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config.php';

function responseJson(bool $success, $data = null, string $message = '', int $status = 200): void {
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function clearSession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }
    session_destroy();
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function currentUser(): ?array {
    if (!isset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['login_at'])) return null;

    $now = time();
    $last = (int)($_SESSION['last_activity'] ?? $_SESSION['login_at']);
    $loginAt = (int)$_SESSION['login_at'];

    if (($now - $last) > SESSION_IDLE_TIMEOUT || ($now - $loginAt) > SESSION_ABSOLUTE_TIMEOUT) {
        clearSession();
        return null;
    }

    $_SESSION['last_activity'] = $now;
    return [
        'id' => (int)$_SESSION['user_id'],
        'nama' => (string)$_SESSION['user_name'],
        'role' => 'guru',
        'csrf' => csrfToken()
    ];
}

function requireAuth(): array {
    $user = currentUser();
    if (!$user) responseJson(false, null, 'Sesi login tidak valid atau sudah berakhir. Silakan login kembali.', 401);
    return $user;
}

function requireCsrf(): void {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';
    if (!$stored || !$sent || !hash_equals((string)$stored, (string)$sent)) {
        responseJson(false, null, 'Token keamanan tidak valid. Silakan muat ulang dan login kembali.', 419);
    }
}

function ensureSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        nama VARCHAR(100) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'guru',
        aktif TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_users_nama (nama)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS app_state (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        state_key VARCHAR(50) NOT NULL,
        data_json LONGTEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_state_key (state_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $hash = '$2y$12$W7pnMS..FHS7DleT5Xvq7u070QbvigvC0SgJ3xybNcJcLOCoOCsI6';
    $stmt = $pdo->prepare("INSERT INTO users (nama, password_hash, role, aktif)
                           SELECT :nama, :hash, 'guru', 1
                           WHERE NOT EXISTS (SELECT 1 FROM users WHERE nama = :nama2)");
    $stmt->execute(['nama' => 'Guru', 'hash' => $hash, 'nama2' => 'Guru']);
}

function defaultState(): array {
    return [
        'user' => null,
        'kelas' => [
            ['id'=>'paud','nama'=>'PAUD','wali'=>'Ibu Sari'],
            ['id'=>'sda','nama'=>'SD A','wali'=>'Bapak Andi'],
            ['id'=>'sdb','nama'=>'SD B','wali'=>'Ibu Rina'],
            ['id'=>'praremaja','nama'=>'Pra Remaja','wali'=>'Bapak Joko'],
            ['id'=>'mudamudi','nama'=>'Muda-Mudi','wali'=>'Ibu Dewi']
        ],
        'siswa' => [],
        'absensi' => (object)[],
        'materi' => [],
        'tugas' => [],
        'catatan' => [],
        'aktivitas' => []
    ];
}

function normalizeState(array $data): array {
    $base = defaultState();
    foreach (['kelas','siswa','materi','tugas','catatan','aktivitas'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) $data[$key] = $base[$key];
    }
    if (!isset($data['absensi']) || !is_array($data['absensi'])) $data['absensi'] = [];
    // Absensi adalah map tanggal -> map siswa -> status.
    // Cast ke object agar JSON kosong menjadi {} (bukan []), sehingga JavaScript
    // tidak menerimanya sebagai Array yang mengabaikan property tanggal saat JSON.stringify().
    $data['absensi'] = (object)$data['absensi'];
    $data['user'] = null;
    return $data;
}

function loadGlobalState(PDO $pdo): array {
    $stmt = $pdo->prepare('SELECT data_json FROM app_state WHERE state_key = :state_key LIMIT 1');
    $stmt->execute(['state_key' => GLOBAL_STATE_KEY]);
    $row = $stmt->fetch();

    if (!$row) {
        $data = defaultState();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $insert = $pdo->prepare('INSERT INTO app_state (state_key, data_json) VALUES (:state_key, :data_json)');
        $insert->execute(['state_key' => GLOBAL_STATE_KEY, 'data_json' => $json]);
        return $data;
    }

    $decoded = json_decode((string)$row['data_json'], true);
    if (!is_array($decoded)) throw new RuntimeException('Data database rusak.');
    return normalizeState($decoded);
}

function saveGlobalState(PDO $pdo, array $data): array {
    $data = normalizeState($data);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    // Satu transaksi untuk memastikan satu snapshot utuh tersimpan.
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM app_state WHERE state_key = :state_key LIMIT 1 FOR UPDATE');
        $lock->execute(['state_key' => GLOBAL_STATE_KEY]);
        $row = $lock->fetch();

        if ($row) {
            $stmt = $pdo->prepare('UPDATE app_state SET data_json = :data_json, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute(['data_json' => $json, 'id' => (int)$row['id']]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO app_state (state_key, data_json) VALUES (:state_key, :data_json)');
            $stmt->execute(['state_key' => GLOBAL_STATE_KEY, 'data_json' => $json]);
        }

        // Baca kembali baris yang baru disimpan sebelum commit.
        $verify = $pdo->prepare('SELECT data_json FROM app_state WHERE state_key = :state_key LIMIT 1');
        $verify->execute(['state_key' => GLOBAL_STATE_KEY]);
        $savedRow = $verify->fetch();
        if (!$savedRow || (string)$savedRow['data_json'] !== $json) {
            throw new RuntimeException('Verifikasi penyimpanan database gagal.');
        }

        $pdo->commit();
        return $data;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

try {
    $pdo = db();
    ensureSchema($pdo);
    $action = $_GET['action'] ?? '';

    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($input)) $input = [];
        $nama = trim((string)($input['nama'] ?? ''));
        $password = (string)($input['password'] ?? '');
        if ($nama === '' || $password === '') responseJson(false, null, 'Nama guru dan password wajib diisi.', 422);
        if (strlen($password) < 8) responseJson(false, null, 'Password minimal 8 karakter.', 422);

        $stmt = $pdo->prepare("SELECT id, nama, password_hash, role, aktif FROM users WHERE nama = :nama AND role = 'guru' LIMIT 1");
        $stmt->execute(['nama' => $nama]);
        $user = $stmt->fetch();
        if (!$user || !(int)$user['aktif'] || !password_verify($password, (string)$user['password_hash'])) {
            usleep(250000);
            responseJson(false, null, 'Nama guru atau password salah.', 401);
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_name'] = (string)$user['nama'];
        $_SESSION['user_role'] = 'guru';
        $_SESSION['login_at'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        responseJson(true, currentUser(), 'Login berhasil.');
    }

    if ($action === 'auth' && $_SERVER['REQUEST_METHOD'] === 'GET') responseJson(true, currentUser());

    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        clearSession();
        responseJson(true, null, 'Logout berhasil.');
    }

    $authUser = requireAuth();

    if ($action === 'load' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $data = loadGlobalState($pdo);
        $data['user'] = $authUser;
        responseJson(true, $data, 'Data berhasil dimuat dari MySQL.');
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        $data = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($data) || !isset($data['siswa']) || !is_array($data['siswa'])) {
            responseJson(false, null, 'Payload data tidak valid.', 422);
        }

        $saved = saveGlobalState($pdo, $data);
        $saved['user'] = $authUser;
        responseJson(true, $saved, 'Data berhasil disimpan dan diverifikasi di MySQL.');
    }

    responseJson(false, null, 'Aksi tidak dikenali.', 404);
} catch (Throwable $e) {
    error_log('Absensi API: ' . $e->getMessage());
    responseJson(false, null, 'Server/database bermasalah: ' . $e->getMessage(), 500);
}
