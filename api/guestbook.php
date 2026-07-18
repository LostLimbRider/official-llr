<?php
$storageDir = __DIR__ . '/../data';
$storageFile = $storageDir . '/guestbook.json';
$adminKey = getenv('GUESTBOOK_ADMIN_KEY') ?: '';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function send_json($payload, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_PRETTY_PRINT);
    exit;
}

function clean_text($value, $limit) {
    $value = trim(strip_tags((string) $value));
    $value = preg_replace('/\s+/', ' ', $value);
    return substr($value, 0, $limit);
}

function ensure_storage($storageDir, $storageFile) {
    if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true)) {
        send_json(['error' => 'Guest book storage is not writable.'], 500);
    }
    if (!file_exists($storageFile) && file_put_contents($storageFile, "[]") === false) {
        send_json(['error' => 'Guest book storage could not be created.'], 500);
    }
}

function read_entries($storageFile) {
    $contents = file_get_contents($storageFile);
    $entries = json_decode($contents ?: '[]', true);
    return is_array($entries) ? $entries : [];
}

function write_entries($storageFile, $entries) {
    $handle = fopen($storageFile, 'c+');
    if (!$handle) {
        send_json(['error' => 'Guest book storage is not writable.'], 500);
    }
    flock($handle, LOCK_EX);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($entries, JSON_PRETTY_PRINT));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function require_admin($adminKey) {
    if (!$adminKey || !hash_equals($adminKey, $_GET['key'] ?? '')) {
        send_json(['error' => 'Admin access required.'], 403);
    }
}

ensure_storage($storageDir, $storageFile);
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    send_json(['entries' => read_entries($storageFile)]);
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $entry = [
        'name' => clean_text($payload['name'] ?? '', 80),
        'location' => clean_text($payload['location'] ?? '', 120),
        'message' => clean_text($payload['message'] ?? '', 1200),
        'savedAt' => gmdate('c'),
    ];
    if ($entry['name'] === '' || $entry['message'] === '') {
        send_json(['error' => 'Name and message are required.'], 422);
    }
    $entries = read_entries($storageFile);
    array_unshift($entries, $entry);
    $entries = array_slice($entries, 0, 500);
    write_entries($storageFile, $entries);
    send_json(['entry' => $entry, 'entries' => $entries], 201);
}

if ($action === 'download') {
    require_admin($adminKey);
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="lost-limb-riders-guestbook.json"');
    readfile($storageFile);
    exit;
}

if ($action === 'clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin($adminKey);
    write_entries($storageFile, []);
    send_json(['entries' => []]);
}

send_json(['error' => 'Unsupported guest book action.'], 404);
