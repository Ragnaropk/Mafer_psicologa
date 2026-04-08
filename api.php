<?php


require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

session_name(SESSION_NAME);
session_set_cookie_params([
    'lifetime' => 86400 * 7,   // 7 días
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// --- Helpers ---
function ok(array $data = []): void {
    echo json_encode(['ok' => true] + $data);
    exit;
}

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function requireAuth(): void {
    if (empty($_SESSION['auth'])) {
        fail('No autorizado. Inicia sesión primero.', 401);
    }
}

function body(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

// --- Router ---
$action = $_GET['action'] ?? '';

match ($action) {
    'login'  => handleLogin(),
    'logout' => handleLogout(),
    'check'  => handleCheck(),
    'posts'  => handlePosts(),
    'create' => handleCreate(),
    'delete' => handleDelete(),
    default  => fail('Acción no reconocida.', 404),
};

// ============================================================
//  Handlers
// ============================================================

function handleLogin(): void {
    $data = body();
    $user = trim($data['user'] ?? '');
    $pass = $data['pass'] ?? '';

    if ($user !== BLOG_USER) {
        fail('Credenciales incorrectas.', 401);
    }

    // BLOG_PASS es un hash bcrypt generado en config.php
    if (!password_verify($pass, BLOG_PASS)) {
        fail('Credenciales incorrectas.', 401);
    }

    session_regenerate_id(true);
    $_SESSION['auth'] = true;
    ok(['message' => 'Sesión iniciada.']);
}

function handleLogout(): void {
    $_SESSION = [];
    session_destroy();
    ok(['message' => 'Sesión cerrada.']);
}

function handleCheck(): void {
    ok(['auth' => !empty($_SESSION['auth'])]);
}

function handlePosts(): void {
    $db = getDB();
    $stmt = $db->query(
    'SELECT id, title, content, tags, created_at
     FROM posts
     ORDER BY created_at DESC'
);
    $posts = $stmt->fetchAll();

    // Decodificar tags (guardados como JSON en la BD)
    foreach ($posts as &$p) {
        $p['tags'] = json_decode($p['tags'] ?? '[]', true);
        $p['date'] = formatDate($p['created_at']);
    }

    ok(['posts' => $posts]);
}

function handleCreate(): void {
    requireAuth();
    $data  = body();
    $title   = trim($data['title']   ?? '');
    $content = trim($data['content'] ?? '');
    $tags    = $data['tags'] ?? [];

    if ($title === '' && $content === '') {
        fail('El título o el contenido no pueden estar vacíos.');
    }

    if (!is_array($tags)) {
        $tags = array_map('trim', explode(',', (string)$tags));
    }
    $tags = array_values(array_filter($tags));

    $db = getDB();
    $stmt = $db->prepare(
        'INSERT INTO posts (title, content, tags, created_at)
         VALUES (:title, :content, :tags, NOW())'
    );
    $stmt->execute([
        ':title'   => $title ?: 'Sin título',
        ':content' => $content,
        ':tags'    => json_encode($tags, JSON_UNESCAPED_UNICODE),
    ]);

    $id = (int) $db->lastInsertId();
    ok(['id' => $id, 'message' => 'Entrada creada.']);
}

function handleDelete(): void {
    requireAuth();
    $data = body();
    $id   = (int) ($data['id'] ?? 0);

    if ($id <= 0) {
        fail('ID inválido.');
    }

    $db   = getDB();
    $stmt = $db->prepare('DELETE FROM posts WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) {
        fail('Entrada no encontrada.', 404);
    }

    ok(['message' => 'Entrada eliminada.']);
}



function formatDate(string $datetime): string {
    $months = [
        '01' => 'enero',    '02' => 'febrero',  '03' => 'marzo',
        '04' => 'abril',    '05' => 'mayo',      '06' => 'junio',
        '07' => 'julio',    '08' => 'agosto',    '09' => 'septiembre',
        '10' => 'octubre',  '11' => 'noviembre', '12' => 'diciembre',
    ];
    [$date, $time] = explode(' ', $datetime);
    [$y, $m, $d]   = explode('-', $date);
    $hour = substr($time, 0, 5);
    return "{$d} de {$months[$m]} de {$y} · {$hour}";
}
