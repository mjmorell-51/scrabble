<?php
declare(strict_types=1);

header('Content-Type: application/json');

function respond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
}

const DISTRIBUTION = [
    'A' => 9, 'B' => 2, 'C' => 2, 'D' => 4, 'E' => 12, 'F' => 2, 'G' => 3,
    'H' => 2, 'I' => 9, 'J' => 1, 'K' => 1, 'L' => 4, 'M' => 2, 'N' => 6,
    'O' => 8, 'P' => 2, 'Q' => 1, 'R' => 6, 'S' => 4, 'T' => 6, 'U' => 4,
    'V' => 2, 'W' => 2, 'X' => 1, 'Y' => 2, 'Z' => 1, '_' => 2,
];

const POINTS = [
    'A' => 1, 'B' => 3, 'C' => 3, 'D' => 2, 'E' => 1, 'F' => 4, 'G' => 2,
    'H' => 4, 'I' => 1, 'J' => 8, 'K' => 5, 'L' => 1, 'M' => 3, 'N' => 1,
    'O' => 1, 'P' => 3, 'Q' => 10, 'R' => 1, 'S' => 1, 'T' => 1, 'U' => 1,
    'V' => 4, 'W' => 4, 'X' => 8, 'Y' => 4, 'Z' => 10, '_' => 0,
];

function buildBag(): array {
    $bag = [];
    foreach (DISTRIBUTION as $letter => $count) {
        for ($i = 0; $i < $count; $i++) {
            $bag[] = $letter;
        }
    }
    return $bag;
}

function loadDictionary(): array {
    $path = dirname(__DIR__) . '/.data/nwl2023.txt';
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        respond(500, ['error' => 'Dictionary is temporarily unavailable.']);
    }
    return $lines;
}

function letterCounts(string $word): array {
    $counts = [];
    foreach (str_split($word) as $ch) {
        $counts[$ch] = ($counts[$ch] ?? 0) + 1;
    }
    return $counts;
}

function fitsDistribution(array $counts): bool {
    foreach ($counts as $letter => $n) {
        if ((DISTRIBUTION[$letter] ?? 0) < $n) {
            return false;
        }
    }
    return true;
}

/**
 * Returns the score for playing $word from a rack described by $rackCounts,
 * preferring real tiles over blanks, or null if the rack cannot form the word.
 */
function scoreWord(string $word, array $rackCounts): ?int {
    $available = $rackCounts;
    $blanks = $available['_'] ?? 0;
    unset($available['_']);
    $score = 0;

    foreach (str_split($word) as $ch) {
        if (($available[$ch] ?? 0) > 0) {
            $available[$ch]--;
            $score += POINTS[$ch] ?? 0;
        } elseif ($blanks > 0) {
            $blanks--;
        } else {
            return null;
        }
    }

    if (strlen($word) === 7) {
        $score += 50;
    }

    return $score;
}

/**
 * Returns [WORD => score] for every dictionary word (length 1-7) that can be
 * formed from the given rack.
 */
function computeValidWords(array $rackCounts, array $dictionary): array {
    $valid = [];
    foreach ($dictionary as $word) {
        $len = strlen($word);
        if ($len === 0 || $len > 7) {
            continue;
        }
        $upper = strtoupper($word);
        $score = scoreWord($upper, $rackCounts);
        if ($score !== null) {
            $valid[$upper] = $score;
        }
    }
    return $valid;
}

function parseRackParam(): array {
    $rackStr = strtoupper(trim((string) ($_GET['rack'] ?? '')));
    if (!preg_match('/^[A-Z_]{7}$/', $rackStr)) {
        respond(400, ['error' => 'Rack must be exactly 7 letters (use _ for a blank).']);
    }
    return letterCounts($rackStr);
}

$action = $_GET['action'] ?? 'deal';

if ($action === 'deal') {
    $mode = $_GET['mode'] ?? 'random';
    if (!in_array($mode, ['random', 'guaranteed'], true)) {
        respond(400, ['error' => 'Invalid mode.']);
    }

    if ($mode === 'random') {
        $bag = buildBag();
        shuffle($bag);
        $rack = array_slice($bag, 0, 7);
    } else {
        $dictionary = loadDictionary();
        $candidates = [];
        foreach ($dictionary as $word) {
            if (strlen($word) !== 7) {
                continue;
            }
            $upper = strtoupper($word);
            if (fitsDistribution(letterCounts($upper))) {
                $candidates[] = $upper;
            }
        }
        if (empty($candidates)) {
            respond(500, ['error' => 'Could not find a bingo word.']);
        }
        $word = $candidates[array_rand($candidates)];
        $rack = str_split($word);
        shuffle($rack);

        // A bingo rack is the exact letters of a real word, so it would otherwise never
        // contain a blank. Give one the same ~13.6% per-rack chance it has in a random
        // 7-tile draw (2 blanks in a 100-tile bag), rolled independently each deal. The
        // blank stands in for the tile it replaces, so the rack still spells the bingo.
        if (random_int(1, 1000) <= 136) {
            $rack[random_int(0, 6)] = '_';
        }
    }

    respond(200, ['mode' => $mode, 'rack' => $rack]);
}

if ($action === 'hint') {
    $rackCounts = parseRackParam();
    $validWords = computeValidWords($rackCounts, loadDictionary());

    if (empty($validWords)) {
        respond(200, ['word' => null, 'score' => 0, 'bingo' => false]);
    }

    $bestScore = max($validWords);
    $bestWord = array_search($bestScore, $validWords, true);

    respond(200, [
        'word' => $bestWord,
        'score' => $bestScore,
        'bingo' => strlen($bestWord) === 7,
    ]);
}

if ($action === 'guess') {
    $rackCounts = parseRackParam();

    $userWord = strtoupper(trim((string) ($_GET['word'] ?? '')));
    if (!preg_match('/^[A-Z]{1,15}$/', $userWord)) {
        respond(400, ['error' => 'Enter a word using letters A-Z only.']);
    }

    $validWords = computeValidWords($rackCounts, loadDictionary());
    $totalValid = count($validWords);

    if ($totalValid === 0) {
        respond(200, [
            'valid' => false, 'word' => $userWord, 'score' => 0, 'rank' => null,
            'totalValid' => 0, 'bestWord' => null, 'bestScore' => 0,
        ]);
    }

    $bestScore = max($validWords);
    $bestWord = array_search($bestScore, $validWords, true);

    if (!array_key_exists($userWord, $validWords)) {
        respond(200, [
            'valid' => false, 'word' => $userWord, 'score' => 0, 'rank' => null,
            'totalValid' => $totalValid, 'bestWord' => $bestWord, 'bestScore' => $bestScore,
        ]);
    }

    $userScore = $validWords[$userWord];
    $better = 0;
    foreach ($validWords as $score) {
        if ($score > $userScore) {
            $better++;
        }
    }

    respond(200, [
        'valid' => true, 'word' => $userWord, 'score' => $userScore, 'rank' => $better + 1,
        'totalValid' => $totalValid, 'bestWord' => $bestWord, 'bestScore' => $bestScore,
    ]);
}

respond(400, ['error' => 'Unknown action.']);
