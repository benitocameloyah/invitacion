<?php
header('Content-Type: application/json; charset=utf-8');

$respFile   = __DIR__ . '/respuesta.json';
$configFile = __DIR__ . '/config.json';

$isConfig = isset($_GET['config']);
$isReset  = isset($_GET['reset']);

/* ── Borrar respuesta ── */
if ($isReset) {
    if (file_exists($respFile)) unlink($respFile);
    echo json_encode(['ok' => true]);
    exit;
}

/* ── Config ── */
if ($isConfig) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if ($data) {
            file_put_contents($configFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            echo json_encode(['ok' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['ok' => false]);
        }
    } else {
        $defaults = ['nombre' => '', 'texto1' => '', 'texto2' => '', 'terminos' => ''];
        echo file_exists($configFile) ? file_get_contents($configFile) : json_encode($defaults);
    }
    exit;
}

/* ── Respuesta ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if ($data && isset($data['choice'])) {
        $data['fecha'] = date('Y-m-d H:i:s');
        file_put_contents($respFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false]);
    }
} else {
    echo file_exists($respFile) ? file_get_contents($respFile) : json_encode(['done' => false]);
}
