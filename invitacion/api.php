<?php
header('Content-Type: application/json; charset=utf-8');

/* Contraseña del panel (config.html / res.html). Cámbiala o define INVITE_ADMIN_PASS en el servidor. */
$ADMIN_PASS = getenv('INVITE_ADMIN_PASS') ?: 'cambiame';

/* Zona horaria de las fechas de respuestas y visitas */
date_default_timezone_set(getenv('INVITE_TZ') ?: 'America/Cancun');

$dataDir   = __DIR__ . '/data';
$uploadDir = __DIR__ . '/uploads';

/* Los datos van en .php que empiezan con exit: el servidor nunca los sirve en crudo
   (contienen códigos de acceso y comentarios privados). */
const GUARD = "<?php exit; ?>\n";

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function requireAdmin() {
    global $ADMIN_PASS;
    if (!hash_equals($ADMIN_PASS, $_SERVER['HTTP_X_ADMIN_PASS'] ?? '')) out(['ok' => false, 'error' => 'auth'], 401);
}

function str($v, $max) {
    /* Recorte por caracteres UTF-8 sin depender de mbstring */
    return is_string($v) && preg_match('/^.{0,' . $max . '}/su', trim($v), $m) ? $m[0] : '';
}

function slugOk($s) {
    return is_string($s) && preg_match('/^[a-z0-9-]{1,60}$/', $s);
}

function path($kind, $slug) {
    global $dataDir;
    return "$dataDir/$kind-$slug.php";
}

function readData($file) {
    return is_file($file) ? json_decode(substr(file_get_contents($file), strlen(GUARD)), true) : null;
}

function writeData($file, $data) {
    global $dataDir;
    if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
    return file_put_contents($file, GUARD . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/* Leer-modificar-escribir con lock para no perder datos con visitas/respuestas simultáneas */
function updateData($file, $fn) {
    global $dataDir;
    if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
    $fh = fopen($file, 'c+');
    flock($fh, LOCK_EX);
    $raw  = stream_get_contents($fh);
    $data = $fn($raw ? (json_decode(substr($raw, strlen(GUARD)), true) ?: []) : []);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, GUARD . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    flock($fh, LOCK_UN);
    fclose($fh);
}

function eventSlugs() {
    global $dataDir;
    $files = glob("$dataDir/evento-*.php") ?: [];
    sort($files);
    return array_map(fn($f) => substr(basename($f, '.php'), 7), $files);
}

/* Devuelve el invitado (o null si es el link general); corta con 404/403 si no hay acceso */
function checkAccess($ev, $para, $codigo) {
    $guest = null;
    if ($para !== '') {
        foreach ($ev['invitados'] ?? [] as $g) if (($g['slug'] ?? '') === $para) $guest = $g;
        if (!$guest) out(['ok' => false, 'error' => 'not_found'], 404);
    }
    $need = (string)(($guest['codigo'] ?? '') !== '' ? $guest['codigo'] : ($ev['codigo'] ?? ''));
    if ($need !== '' && !hash_equals($need, (string)$codigo)) {
        out(['ok' => false, 'error' => 'codigo', 'intento' => $codigo !== '',
             'tema' => $ev['tema'] ?? [], 'fondo' => $ev['fondo'] ?? null, 'meta' => $ev['meta'] ?? []], 403);
    }
    return $guest;
}

$method = $_SERVER['REQUEST_METHOD'];
$e      = $_GET['e'] ?? '';

/* ══════════ ADMIN ══════════ */

/* Lista de eventos (también sirve para validar la contraseña) */
if (isset($_GET['eventos'])) {
    requireAdmin();
    out(array_map(function ($s) {
        $ev = readData(path('evento', $s));
        return ['slug' => $s, 'titulo' => $ev['meta']['titulo'] ?? $s, 'invitados' => count($ev['invitados'] ?? [])];
    }, eventSlugs()));
}

/* Resumen de todos los eventos para el dashboard de respuestas */
if (isset($_GET['resumen'])) {
    requireAdmin();
    out(array_map(function ($s) {
        $ev    = readData(path('evento', $s)) ?: [];
        $resp  = readData(path('respuestas', $s)) ?: [];
        $vis   = readData(path('visitas', $s)) ?: [];
        $slugs = array_column($ev['invitados'] ?? [], 'slug');
        $rsvp  = array_filter($resp, fn($r) => ($r['tipo'] ?? '') === 'rsvp');
        $answered = array_unique(array_filter(array_column($rsvp, 'para'), fn($p) => in_array($p, $slugs, true)));
        return [
            'slug'      => $s,
            'titulo'    => $ev['meta']['titulo'] ?? $s,
            'invitados' => count($slugs),
            'abrieron'  => count(array_intersect($slugs, array_keys($vis))),
            'visitas_general' => $vis['_general']['veces'] ?? 0,
            'si'        => count(array_filter($rsvp, fn($r) => $r['choice'] === 'yes')),
            'no'        => count(array_filter($rsvp, fn($r) => $r['choice'] === 'no')),
            'pendientes'=> count($slugs) - count($answered),
            'votos'     => count($resp) - count($rsvp),
            'ultima'    => $resp ? max(array_column($resp, 'fecha')) : null,
        ];
    }, eventSlugs()));
}

/* Leer / guardar / borrar evento completo */
if (isset($_GET['admin'])) {
    requireAdmin();
    if (!slugOk($e)) out(['ok' => false, 'error' => 'Nombre de evento inválido'], 400);
    $file = path('evento', $e);
    if ($method === 'POST' && isset($_GET['delete'])) {
        @unlink($file);
        @unlink(path('respuestas', $e));
        @unlink(path('reset', $e));
        @unlink(path('visitas', $e));
        out(['ok' => true]);
    }
    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) out(['ok' => false], 400);
        out(['ok' => (bool)writeData($file, $data)]);
    }
    $ev = readData($file);
    $ev === null ? out(['ok' => false, 'error' => 'not_found'], 404) : out($ev);
}

/* Respuestas de un evento: ver / borrar */
if (isset($_GET['respuestas'])) {
    requireAdmin();
    if (!slugOk($e)) out(['ok' => false], 400);
    if ($method === 'POST' && isset($_GET['reset'])) {
        @unlink(path('respuestas', $e));
        /* Marca del borrado: los dispositivos que ya respondieron la comparan y vuelven a permitir responder */
        writeData(path('reset', $e), time());
        out(['ok' => true]);
    }
    out(readData(path('respuestas', $e)) ?: []);
}

/* Visitas de un evento */
if (isset($_GET['visitas'])) {
    requireAdmin();
    if (!slugOk($e)) out(['ok' => false], 400);
    out((object)(readData(path('visitas', $e)) ?: []));
}

/* Subir imagen o video */
if (isset($_GET['upload'])) {
    requireAdmin();
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) out(['ok' => false, 'error' => 'Archivo no recibido o demasiado grande'], 400);

    /* Sin SVG: puede llevar scripts */
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'webm', 'ogv', 'mov'];
    $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!in_array($ext, $allowed, true) || !preg_match('#^(image|video)/#', $mime)) {
        out(['ok' => false, 'error' => 'Solo imágenes o videos'], 400);
    }
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$uploadDir/$name")) out(['ok' => false], 500);
    out(['ok' => true, 'url' => "uploads/$name"]);
}

/* ══════════ PÚBLICO ══════════ */

if ($e === '') $e = eventSlugs()[0] ?? '';
$ev = slugOk($e) ? readData(path('evento', $e)) : null;
if (!$ev) out(['ok' => false, 'error' => 'not_found'], 404);

/* Invitación para un invitado: sin códigos ni lista de invitados */
if ($method === 'GET') {
    $guest = checkAccess($ev, $_GET['para'] ?? '', $_GET['codigo'] ?? '');

    /* Registrar visita (las del navegador del admin no cuentan) */
    if (!isset($_GET['nocount'])) {
        $key = $guest ? $guest['slug'] : '_general';
        updateData(path('visitas', $e), function ($v) use ($key) {
            $now = date('Y-m-d H:i:s');
            $v[$key] = ['primera' => $v[$key]['primera'] ?? $now, 'ultima' => $now, 'veces' => ($v[$key]['veces'] ?? 0) + 1];
            return $v;
        });
    }
    unset($ev['invitados'], $ev['codigo']);
    $ev['slug'] = $e;
    $ev['reset'] = readData(path('reset', $e)) ?: 0;
    $ev['invitado'] = $guest ? ['nombre' => $guest['nombre'] ?? '', 'segundo' => $guest['segundo'] ?? '', 'slug' => $guest['slug']] : null;
    out($ev);
}

/* Nueva respuesta: RSVP o voto de encuesta */
$data  = json_decode(file_get_contents('php://input'), true) ?: [];
$para  = str($data['para'] ?? '', 60);
checkAccess($ev, $para, $data['codigo'] ?? '');

$tipo = $data['tipo'] ?? '';

/* Si el evento solo acepta respuestas positivas, no se admite un "no" aunque llegue directo a la API */
foreach ($ev['secciones'] ?? [] as $s) {
    if (($s['tipo'] ?? '') === 'rsvp' && ($s['modo_no'] ?? 'permitir') !== 'permitir' && $tipo === 'rsvp' && ($data['choice'] ?? '') === 'no') {
        out(['ok' => false, 'error' => 'solo_positivas'], 400);
    }
}
if ($tipo === 'rsvp' && in_array($data['choice'] ?? '', ['yes', 'no'], true)) {
    $entry = ['tipo' => 'rsvp', 'choice' => $data['choice'],
              'nombre' => str($data['nombre'] ?? '', 100), 'comment' => str($data['comment'] ?? '', 2000)];
} elseif ($tipo === 'encuesta' && is_array($data['respuesta'] ?? null) && $data['respuesta']) {
    $entry = ['tipo' => 'encuesta', 'id' => str($data['id'] ?? '', 40),
              'respuesta' => array_map(fn($r) => str($r, 200), array_slice($data['respuesta'], 0, 20))];
} else {
    out(['ok' => false], 400);
}
$entry['para']  = $para;
$entry['fecha'] = date('Y-m-d H:i:s');

updateData(path('respuestas', $e), function ($list) use ($entry) {
    $list[] = $entry;
    return $list;
});
out(['ok' => true]);
