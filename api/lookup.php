<?php
declare(strict_types=1);

header('Content-Type: application/json');

function respond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
}

$raw = $_GET['word'] ?? '';
$word = trim((string) $raw);

if ($word === '') {
    respond(400, ['error' => 'Enter a word to look up.']);
}

if (!preg_match('/^[A-Za-z]{1,15}$/', $word)) {
    respond(400, ['error' => 'Words must be 1-15 letters, A-Z only.']);
}

$word = strtolower($word);

$dictionaryPath = dirname(__DIR__) . '/.data/nwl2023.txt';
$lines = file($dictionaryPath, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    respond(500, ['error' => 'Dictionary is temporarily unavailable.']);
}

$words = array_flip($lines);

respond(200, [
    'word' => strtoupper($word),
    'valid' => isset($words[$word]),
]);
