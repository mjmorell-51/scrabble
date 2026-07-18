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

const BOARD_SIZE = 15;
const CENTER = 7;

// . = normal, 2/3 = double/triple word, d/t = double/triple letter, * = center (double word)
const PREMIUM_ROWS = [
    "3..d...3...d..3",
    ".2...t...t...2.",
    "..2...d.d...2..",
    "d..2...d...2..d",
    "....2.....2....",
    ".t...t...t...t.",
    "..d...d.d...d..",
    "3..d...*...d..3",
    "..d...d.d...d..",
    ".t...t...t...t.",
    "....2.....2....",
    "d..2...d...2..d",
    "..2...d.d...2..",
    ".2...t...t...2.",
    "3..d...3...d..3",
];

function premiumAt(int $r, int $c): string {
    return PREMIUM_ROWS[$r][$c];
}

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

// $dict must already be sorted (loadDictionary()'s file() read preserves the
// dictionary file's own alphabetical order) -- these are plain binary searches,
// standing in for a trie/GADDAG without needing to build or cache one.
function wordExists(array $dict, string $word): bool {
    $lo = 0;
    $hi = count($dict) - 1;
    while ($lo <= $hi) {
        $mid = intdiv($lo + $hi, 2);
        $cmp = strcmp($dict[$mid], $word);
        if ($cmp === 0) {
            return true;
        }
        if ($cmp < 0) {
            $lo = $mid + 1;
        } else {
            $hi = $mid - 1;
        }
    }
    return false;
}

function hasWordWithPrefix(array $dict, string $prefix): bool {
    $lo = 0;
    $hi = count($dict);
    while ($lo < $hi) {
        $mid = intdiv($lo + $hi, 2);
        if ($dict[$mid] < $prefix) {
            $lo = $mid + 1;
        } else {
            $hi = $mid;
        }
    }
    return $lo < count($dict) && str_starts_with($dict[$lo], $prefix);
}

// Extending a word LEFTWARD from an anchor (prepending a letter in front of whatever's
// already been chosen) means trying up to 26 candidate first-letters at every step --
// with 2 blank tiles in rack that branching compounds badly, since a blank can stand
// in for any letter. $dict is only ever prefix-searchable from its own first
// character, so "which letters can precede this suffix" can't be answered by a normal
// binary search the way "which letters can follow this prefix" can.
//
// The fix: index the dictionary by each word rotated one character left (so the
// original first letter moves to the end). Words sharing the same suffix-so-far
// become adjacent in that ordering regardless of their first letter, so one binary
// search plus a short scan of the matching range reads off every viable first letter
// directly -- no need to try all 26 and prefix-check each one.
function buildLeftDict(array $dict): array {
    $left = [];
    foreach ($dict as $word) {
        if ($word === '') {
            continue;
        }
        $left[] = substr($word, 1) . $word[0];
    }
    sort($left);
    return $left;
}

// Returns the set of legal next-prepend letters (uppercase) for the given lowercase
// suffix, or null if the matching range was too large to be worth enumerating (in
// which case the caller should fall back to checking candidates individually).
function viableLeftLetters(array $leftDict, string $suffix): ?array {
    $count = count($leftDict);
    $lo = 0;
    $hi = $count;
    while ($lo < $hi) {
        $mid = intdiv($lo + $hi, 2);
        if ($leftDict[$mid] < $suffix) {
            $lo = $mid + 1;
        } else {
            $hi = $mid;
        }
    }
    $letters = [];
    $i = $lo;
    $scanned = 0;
    while ($i < $count && str_starts_with($leftDict[$i], $suffix)) {
        if (++$scanned > 300) {
            return null;
        }
        $letters[strtoupper($leftDict[$i][strlen($leftDict[$i]) - 1])] = true;
        $i++;
    }
    return $letters;
}

// Forward analog of viableLeftLetters: which letters can legally come right after
// (append to) $prefix, read directly off the already-forward-sorted $dict instead of
// prefix-checking all 26 individually. Same "give up past a scan cap" fallback.
function viableRightLetters(array $dict, string $prefix): ?array {
    $count = count($dict);
    $lo = 0;
    $hi = $count;
    while ($lo < $hi) {
        $mid = intdiv($lo + $hi, 2);
        if ($dict[$mid] < $prefix) {
            $lo = $mid + 1;
        } else {
            $hi = $mid;
        }
    }
    $letters = [];
    $i = $lo;
    $scanned = 0;
    $prefixLen = strlen($prefix);
    while ($i < $count && str_starts_with($dict[$i], $prefix)) {
        if (++$scanned > 300) {
            return null;
        }
        if (strlen($dict[$i]) > $prefixLen) {
            $letters[strtoupper($dict[$i][$prefixLen])] = true;
        }
        $i++;
    }
    return $letters;
}

function inBounds(int $r, int $c): bool {
    return $r >= 0 && $r < BOARD_SIZE && $c >= 0 && $c < BOARD_SIZE;
}

// Steps one cell along $dir ('across' moves through columns, 'down' through rows).
function stepPos(int $r, int $c, string $dir, int $delta = 1): array {
    return $dir === 'across' ? [$r, $c + $delta] : [$r + $delta, $c];
}

// Steps one cell along the axis perpendicular to $dir.
function perpStepPos(int $r, int $c, string $dir, int $delta = 1): array {
    return $dir === 'across' ? [$r + $delta, $c] : [$r, $c + $delta];
}

function buildEmptyBoard(): array {
    $board = [];
    for ($r = 0; $r < BOARD_SIZE; $r++) {
        $board[$r] = array_fill(0, BOARD_SIZE, null);
    }
    return $board;
}

function boardFromPlacedTiles(array $tiles): array {
    $board = buildEmptyBoard();
    foreach ($tiles as $t) {
        if (!isset($t['r'], $t['c'], $t['letter'])) {
            respond(400, ['error' => 'Malformed board tile.']);
        }
        $r = (int) $t['r'];
        $c = (int) $t['c'];
        if (!inBounds($r, $c)) {
            respond(400, ['error' => 'Board tile position out of range.']);
        }
        $letter = strtoupper((string) $t['letter']);
        if (!preg_match('/^[A-Z]$/', $letter)) {
            respond(400, ['error' => 'Invalid board tile letter.']);
        }
        $board[$r][$c] = ['letter' => $letter, 'blank' => (bool) ($t['blank'] ?? false)];
    }
    return $board;
}

function flattenBoard(array $board): array {
    $tiles = [];
    for ($r = 0; $r < BOARD_SIZE; $r++) {
        for ($c = 0; $c < BOARD_SIZE; $c++) {
            if ($board[$r][$c] !== null) {
                $tiles[] = [
                    'r' => $r, 'c' => $c,
                    'letter' => $board[$r][$c]['letter'], 'blank' => $board[$r][$c]['blank'],
                ];
            }
        }
    }
    return $tiles;
}

function boardIsEmpty(array $board): bool {
    for ($r = 0; $r < BOARD_SIZE; $r++) {
        for ($c = 0; $c < BOARD_SIZE; $c++) {
            if ($board[$r][$c] !== null) {
                return false;
            }
        }
    }
    return true;
}

function findAnchors(array $board): array {
    if (boardIsEmpty($board)) {
        return [[CENTER, CENTER]];
    }
    $anchors = [];
    for ($r = 0; $r < BOARD_SIZE; $r++) {
        for ($c = 0; $c < BOARD_SIZE; $c++) {
            if ($board[$r][$c] !== null) {
                continue;
            }
            foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dr, $dc]) {
                $nr = $r + $dr;
                $nc = $c + $dc;
                if (inBounds($nr, $nc) && $board[$nr][$nc] !== null) {
                    $anchors[] = [$r, $c];
                    break;
                }
            }
        }
    }
    return $anchors;
}

// Legal letters for the empty cell ($r,$c) such that the PERPENDICULAR word it
// would complete (given whatever's already on the board) stays a real word. Null
// means unconstrained (no perpendicular neighbor at all).
function crossCheckSet(array $board, int $r, int $c, string $dir, array $dict): ?array {
    [$pr, $pc] = perpStepPos($r, $c, $dir, -1);
    $prefix = '';
    while (inBounds($pr, $pc) && $board[$pr][$pc] !== null) {
        $prefix = $board[$pr][$pc]['letter'] . $prefix;
        [$pr, $pc] = perpStepPos($pr, $pc, $dir, -1);
    }
    [$nr, $nc] = perpStepPos($r, $c, $dir, 1);
    $suffix = '';
    while (inBounds($nr, $nc) && $board[$nr][$nc] !== null) {
        $suffix .= $board[$nr][$nc]['letter'];
        [$nr, $nc] = perpStepPos($nr, $nc, $dir, 1);
    }
    if ($prefix === '' && $suffix === '') {
        return null;
    }
    $legal = [];
    foreach (range('A', 'Z') as $letter) {
        if (wordExists($dict, strtolower($prefix . $letter . $suffix))) {
            $legal[$letter] = true;
        }
    }
    return $legal;
}

function precomputeCrossChecks(array $board, string $dir, array $dict): array {
    $result = [];
    for ($r = 0; $r < BOARD_SIZE; $r++) {
        for ($c = 0; $c < BOARD_SIZE; $c++) {
            if ($board[$r][$c] === null) {
                $result[$r][$c] = crossCheckSet($board, $r, $c, $dir, $dict);
            }
        }
    }
    return $result;
}

// Every usable next rack letter, paired with the rack-counts array reflecting its use.
// A blank standing in for a letter you also hold as a real tile is skipped: it can
// never score higher than just using the real tile (blanks always score 0), so this
// is a correctness-preserving prune, not a heuristic.
function rackCandidates(array $rackCounts): array {
    $out = [];
    foreach ($rackCounts as $letter => $count) {
        if ($letter === '_' || $count <= 0) {
            continue;
        }
        $next = $rackCounts;
        $next[$letter]--;
        $out[] = [$letter, false, $next];
    }
    $blanks = $rackCounts['_'] ?? 0;
    if ($blanks > 0) {
        foreach (range('A', 'Z') as $letter) {
            if (($rackCounts[$letter] ?? 0) > 0) {
                continue;
            }
            $next = $rackCounts;
            $next['_']--;
            $out[] = [$letter, true, $next];
        }
    }
    return $out;
}

function recordIfComplete(array $cells, string $word, array $dict, array &$rawMoves): void {
    if (count($cells) < 2) {
        return;
    }
    if (!wordExists($dict, $word)) {
        return;
    }
    $rawMoves[] = $cells;
}

// $word tracks the lowercase word built by $cells so far, threaded through the
// recursion instead of rebuilt (via implode+array_map) at every node -- with a couple
// hundred thousand recursive calls in the worst (multi-blank) case, that reconstruction
// was itself a meaningful share of the cost.
function extendRight(
    array $board, array $rackCounts, array $dict, array $crossChecks,
    int $r, int $c, string $dir, array $cells, string $word, array &$rawMoves, bool $mustPlace = false
): void {
    if (!inBounds($r, $c)) {
        recordIfComplete($cells, $word, $dict, $rawMoves);
        return;
    }

    if ($board[$r][$c] !== null) {
        $cells[] = [
            'r' => $r, 'c' => $c,
            'letter' => $board[$r][$c]['letter'], 'blank' => $board[$r][$c]['blank'], 'isNew' => false,
        ];
        $word .= strtolower($board[$r][$c]['letter']);
        if (!hasWordWithPrefix($dict, $word)) {
            return;
        }
        [$nr, $nc] = stepPos($r, $c, $dir, 1);
        extendRight($board, $rackCounts, $dict, $crossChecks, $nr, $nc, $dir, $cells, $word, $rawMoves);
        return;
    }

    // Empty cell: the word so far may end here (leave this cell unplayed) -- unless
    // this IS the anchor cell itself, which every move from this search must cover
    // (that's the only thing tying the word back to the existing board).
    if (!$mustPlace) {
        recordIfComplete($cells, $word, $dict, $rawMoves);
    }

    // ...or grow into it with a rack tile, subject to the perpendicular cross-check.
    $legal = $crossChecks[$r][$c] ?? null;
    $viableNext = viableRightLetters($dict, $word);
    foreach (rackCandidates($rackCounts) as [$letter, $isBlank, $nextRackCounts]) {
        if ($legal !== null && !isset($legal[$letter])) {
            continue;
        }
        $lower = strtolower($letter);
        if ($viableNext !== null) {
            if (!isset($viableNext[$letter])) {
                continue;
            }
        } elseif (!hasWordWithPrefix($dict, $word . $lower)) {
            continue;
        }
        $newCells = array_merge($cells, [['r' => $r, 'c' => $c, 'letter' => $letter, 'blank' => $isBlank, 'isNew' => true]]);
        [$nr, $nc] = stepPos($r, $c, $dir, 1);
        extendRight($board, $nextRackCounts, $dict, $crossChecks, $nr, $nc, $dir, $newCells, $word . $lower, $rawMoves);
    }
}

function extendLeft(
    array $board, array $rackCounts, array $dict, array $leftDict, array $crossChecks,
    int $ar, int $ac, string $dir, array $cells, string $word, int $limit, array &$rawMoves
): void {
    extendRight($board, $rackCounts, $dict, $crossChecks, $ar, $ac, $dir, $cells, $word, $rawMoves, true);

    $newCount = 0;
    foreach ($cells as $cell) {
        if ($cell['isNew']) {
            $newCount++;
        }
    }
    if ($newCount >= $limit) {
        return;
    }

    if (empty($cells)) {
        [$pr, $pc] = stepPos($ar, $ac, $dir, -1);
    } else {
        [$pr, $pc] = stepPos($cells[0]['r'], $cells[0]['c'], $dir, -1);
    }

    $viable = viableLeftLetters($leftDict, $word);
    foreach (rackCandidates($rackCounts) as [$letter, $isBlank, $nextRackCounts]) {
        $lower = strtolower($letter);
        if ($viable !== null) {
            if (!isset($viable[$letter])) {
                continue;
            }
        } elseif (!hasWordWithPrefix($dict, $lower . $word)) {
            continue;
        }
        $newCells = array_merge([['r' => $pr, 'c' => $pc, 'letter' => $letter, 'blank' => $isBlank, 'isNew' => true]], $cells);
        extendLeft($board, $nextRackCounts, $dict, $leftDict, $crossChecks, $ar, $ac, $dir, $newCells, $lower . $word, $limit, $rawMoves);
    }
}

// One anchor's search: gather whatever's already fixed immediately to its left, work
// out how many further (empty, non-anchor) cells rack tiles may still occupy beyond
// that -- stopping before another anchor square, so that every final placement is
// generated exactly once, from the leftmost anchor it covers -- then recurse.
function searchFromAnchor(
    array $board, array $rackCounts, array $dict, array $leftDict, array $crossChecks, array $anchorSet,
    int $ar, int $ac, string $dir, array &$rawMoves
): void {
    $fixedCells = [];
    [$pr, $pc] = stepPos($ar, $ac, $dir, -1);
    while (inBounds($pr, $pc) && $board[$pr][$pc] !== null) {
        array_unshift($fixedCells, [
            'r' => $pr, 'c' => $pc,
            'letter' => $board[$pr][$pc]['letter'], 'blank' => $board[$pr][$pc]['blank'], 'isNew' => false,
        ]);
        [$pr, $pc] = stepPos($pr, $pc, $dir, -1);
    }

    $maxLeft = 0;
    [$fr, $fc] = [$pr, $pc];
    while (inBounds($fr, $fc) && $board[$fr][$fc] === null && !isset($anchorSet["$fr,$fc"])) {
        $maxLeft++;
        [$fr, $fc] = stepPos($fr, $fc, $dir, -1);
    }

    $word = strtolower(implode('', array_map(fn($c) => $c['letter'], $fixedCells)));
    extendLeft($board, $rackCounts, $dict, $leftDict, $crossChecks, $ar, $ac, $dir, $fixedCells, $word, $maxLeft, $rawMoves);
}

/**
 * @return array<array{newTiles: array, score: int, bingo: bool, words: array}>
 */
function legalMoves(array $board, array $rackCounts, array $dict, array $leftDict): array {
    $anchors = findAnchors($board);
    $anchorSet = [];
    foreach ($anchors as [$ar, $ac]) {
        $anchorSet["$ar,$ac"] = true;
    }

    $rawMoves = [];
    foreach (['across', 'down'] as $dir) {
        $crossChecks = precomputeCrossChecks($board, $dir, $dict);
        foreach ($anchors as [$ar, $ac]) {
            searchFromAnchor($board, $rackCounts, $dict, $leftDict, $crossChecks, $anchorSet, $ar, $ac, $dir, $rawMoves);
        }
    }

    // A single-tile placement can be found once from the 'across' search and once
    // from 'down' (it may complete a word in both axes); a 2+-tile placement can't,
    // since its tiles are only collinear along one axis. Dedupe by the new-tile set.
    $seen = [];
    $moves = [];
    foreach ($rawMoves as $cells) {
        $newTiles = array_values(array_filter($cells, fn($c) => $c['isNew']));
        $sig = implode('|', array_map(fn($t) => "{$t['r']},{$t['c']},{$t['letter']},{$t['blank']}", $newTiles));
        if (isset($seen[$sig])) {
            continue;
        }
        $seen[$sig] = true;

        $scored = scorePlacement($board, $dict, $newTiles);
        if ($scored === null) {
            continue;
        }
        $moves[] = [
            'newTiles' => $newTiles,
            'score' => $scored['total'],
            'bingo' => $scored['bingo'],
            'words' => $scored['words'],
        ];
    }
    return $moves;
}

/**
 * Scores every newly-formed run (either axis, length >= 2, touching a new tile) that
 * a set of new tiles creates against the board it's being added to. There's no single
 * "main word vs. cross words" here -- a one-tile placement dropped into a gap can
 * complete two words at once with neither one being more primary than the other.
 * Returns null if any formed run isn't a real dictionary word.
 */
function scorePlacement(array $board, array $dict, array $newTiles): ?array {
    $temp = $board;
    foreach ($newTiles as $t) {
        $temp[$t['r']][$t['c']] = ['letter' => $t['letter'], 'blank' => $t['blank']];
    }
    $newSet = [];
    foreach ($newTiles as $t) {
        $newSet["{$t['r']},{$t['c']}"] = true;
    }

    $runsSeen = [];
    $words = [];
    $total = 0;

    foreach ($newTiles as $t) {
        foreach (['across', 'down'] as $axis) {
            [$r, $c] = [$t['r'], $t['c']];
            [$pr, $pc] = stepPos($r, $c, $axis, -1);
            while (inBounds($pr, $pc) && $temp[$pr][$pc] !== null) {
                [$r, $c] = [$pr, $pc];
                [$pr, $pc] = stepPos($pr, $pc, $axis, -1);
            }
            $runKey = "$r,$c,$axis";
            if (isset($runsSeen[$runKey])) {
                continue;
            }
            $runsSeen[$runKey] = true;

            $cells = [];
            [$cr, $cc] = [$r, $c];
            while (inBounds($cr, $cc) && $temp[$cr][$cc] !== null) {
                $cells[] = [
                    'r' => $cr, 'c' => $cc,
                    'letter' => $temp[$cr][$cc]['letter'], 'blank' => $temp[$cr][$cc]['blank'],
                    'isNew' => isset($newSet["$cr,$cc"]),
                ];
                [$cr, $cc] = stepPos($cr, $cc, $axis, 1);
            }
            if (count($cells) < 2) {
                continue;
            }

            $word = strtolower(implode('', array_map(fn($x) => $x['letter'], $cells)));
            if (!wordExists($dict, $word)) {
                return null;
            }

            $wordScore = 0;
            $wordMult = 1;
            foreach ($cells as $cell) {
                $pts = $cell['blank'] ? 0 : POINTS[$cell['letter']];
                if ($cell['isNew']) {
                    $sq = premiumAt($cell['r'], $cell['c']);
                    $pts *= $sq === 'd' ? 2 : ($sq === 't' ? 3 : 1);
                    $wordMult *= ($sq === '2' || $sq === '*') ? 2 : ($sq === '3' ? 3 : 1);
                }
                $wordScore += $pts;
            }
            $wordScore *= $wordMult;
            $total += $wordScore;
            $words[] = ['word' => strtoupper($word), 'score' => $wordScore];
        }
    }

    if (empty($words)) {
        return null;
    }

    $bingo = count($newTiles) === 7;
    if ($bingo) {
        $total += 50;
    }

    return ['total' => $total, 'bingo' => $bingo, 'words' => $words];
}

// Builds a plausible mid-game board by literally playing 4 turns against the same
// move-generator used to score the user's turn -- drawing a random rack from a fresh
// bag each time and picking uniformly at random among whatever legal plays are found,
// rather than favoring the best one. Guarantees every seed board is a real, fully
// legal sequence of dictionary plays.
function generateStartingBoard(array $dict, array $leftDict): array {
    $bag = buildBag();
    shuffle($bag);
    $board = buildEmptyBoard();

    $wordsPlaced = 0;
    $attempts = 0;
    while ($wordsPlaced < 4 && $attempts < 30) {
        $attempts++;
        $drawCount = min(7, count($bag));
        if ($drawCount === 0) {
            break;
        }
        $tiles = array_slice($bag, 0, $drawCount);
        $rackCounts = letterCounts(implode('', $tiles));
        $moves = legalMoves($board, $rackCounts, $dict, $leftDict);
        if (empty($moves)) {
            shuffle($bag);
            continue;
        }
        array_splice($bag, 0, $drawCount);
        $chosen = $moves[array_rand($moves)];
        foreach ($chosen['newTiles'] as $t) {
            $board[$t['r']][$t['c']] = ['letter' => $t['letter'], 'blank' => $t['blank']];
        }
        $wordsPlaced++;
    }

    if ($wordsPlaced < 4) {
        respond(500, ['error' => 'Could not generate a starting board.']);
    }

    $rackCount = min(7, count($bag));
    $rack = array_slice($bag, 0, $rackCount);

    return [$board, $rack];
}

function validatePlacementGeometry(array $board, array $placements): void {
    if (empty($placements)) {
        respond(400, ['error' => 'No tiles placed.']);
    }

    $seenKeys = [];
    $rows = [];
    $cols = [];
    foreach ($placements as $p) {
        if (!isset($p['r'], $p['c'], $p['letter'])) {
            respond(400, ['error' => 'Malformed placement.']);
        }
        $r = (int) $p['r'];
        $c = (int) $p['c'];
        if (!inBounds($r, $c)) {
            respond(400, ['error' => 'Tile position out of range.']);
        }
        if ($board[$r][$c] !== null) {
            respond(400, ['error' => 'That square is already occupied.']);
        }
        $key = "$r,$c";
        if (isset($seenKeys[$key])) {
            respond(400, ["error" => "Two tiles can't occupy the same square."]);
        }
        $seenKeys[$key] = true;
        $rows[$r] = true;
        $cols[$c] = true;
    }

    if (count($placements) > 1 && count($rows) > 1 && count($cols) > 1) {
        respond(400, ['error' => 'Tiles must all be in one row or one column.']);
    }

    $temp = $board;
    foreach ($placements as $p) {
        $temp[(int) $p['r']][(int) $p['c']] = ['letter' => strtoupper((string) $p['letter']), 'blank' => (bool) ($p['blank'] ?? false)];
    }

    if (count($placements) > 1) {
        $dir = count($rows) === 1 ? 'across' : 'down';
        if ($dir === 'across') {
            $row = array_key_first($rows);
            $cVals = array_map(fn($p) => (int) $p['c'], $placements);
            for ($c = min($cVals); $c <= max($cVals); $c++) {
                if ($temp[$row][$c] === null) {
                    respond(400, ['error' => "There's a gap in your word."]);
                }
            }
        } else {
            $col = array_key_first($cols);
            $rVals = array_map(fn($p) => (int) $p['r'], $placements);
            for ($r = min($rVals); $r <= max($rVals); $r++) {
                if ($temp[$r][$col] === null) {
                    respond(400, ['error' => "There's a gap in your word."]);
                }
            }
        }
    }

    $touchesExisting = false;
    foreach ($placements as $p) {
        $r = (int) $p['r'];
        $c = (int) $p['c'];
        foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dr, $dc]) {
            $nr = $r + $dr;
            $nc = $c + $dc;
            if (inBounds($nr, $nc) && $board[$nr][$nc] !== null) {
                $touchesExisting = true;
                break 2;
            }
        }
    }
    if (!$touchesExisting) {
        respond(400, ['error' => 'Your play must connect to a tile already on the board.']);
    }
}

function primaryWord(array $words): ?string {
    if (empty($words)) {
        return null;
    }
    usort($words, fn($a, $b) => strlen($b['word']) <=> strlen($a['word']));
    return $words[0]['word'];
}

$action = $_GET['action'] ?? '';

if ($action === 'new') {
    $dict = loadDictionary();
    $leftDict = buildLeftDict($dict);
    [$board, $rack] = generateStartingBoard($dict, $leftDict);
    respond(200, ['board' => flattenBoard($board), 'rack' => $rack]);
}

if ($action === 'score') {
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        respond(400, ['error' => 'Malformed request body.']);
    }

    $boardTiles = $input['board'] ?? null;
    $rackRaw = $input['rack'] ?? null;
    $placements = $input['placements'] ?? null;
    if (!is_array($boardTiles) || !is_array($rackRaw) || !is_array($placements)) {
        respond(400, ['error' => 'Malformed request body.']);
    }

    $rack = array_map(fn($l) => strtoupper((string) $l), $rackRaw);
    if (count($rack) < 1 || count($rack) > 7 || !preg_match('/^[A-Z_]+$/', implode('', $rack))) {
        respond(400, ['error' => 'Invalid rack.']);
    }
    $rackCounts = letterCounts(implode('', $rack));

    $usedCounts = [];
    foreach ($placements as $p) {
        if (!isset($p['r'], $p['c'], $p['letter'])) {
            respond(400, ['error' => 'Malformed placement.']);
        }
        $letter = ($p['blank'] ?? false) ? '_' : strtoupper((string) $p['letter']);
        $usedCounts[$letter] = ($usedCounts[$letter] ?? 0) + 1;
    }
    foreach ($usedCounts as $letter => $n) {
        if (($rackCounts[$letter] ?? 0) < $n) {
            respond(400, ['error' => 'You can only use tiles from your rack.']);
        }
    }

    $board = boardFromPlacedTiles($boardTiles);
    validatePlacementGeometry($board, $placements);

    $newTiles = array_map(fn($p) => [
        'r' => (int) $p['r'], 'c' => (int) $p['c'],
        'letter' => strtoupper((string) $p['letter']), 'blank' => (bool) ($p['blank'] ?? false),
    ], $placements);

    $dict = loadDictionary();
    $leftDict = buildLeftDict($dict);
    $result = scorePlacement($board, $dict, $newTiles);

    $moves = legalMoves($board, $rackCounts, $dict, $leftDict);
    $totalValid = count($moves);
    $bestScore = 0;
    $bestWord = null;
    foreach ($moves as $m) {
        if ($m['score'] > $bestScore) {
            $bestScore = $m['score'];
            $bestWord = primaryWord($m['words']);
        }
    }

    if ($result === null) {
        respond(200, [
            'valid' => false, 'error' => "That doesn't form a valid word.", 'score' => 0, 'rank' => null,
            'totalValid' => $totalValid, 'bestWord' => $bestWord, 'bestScore' => $bestScore,
        ]);
    }

    $userScore = $result['total'];
    $better = 0;
    foreach ($moves as $m) {
        if ($m['score'] > $userScore) {
            $better++;
        }
    }

    respond(200, [
        'valid' => true, 'words' => $result['words'], 'score' => $userScore, 'bingo' => $result['bingo'],
        'rank' => $better + 1, 'totalValid' => $totalValid, 'bestWord' => $bestWord, 'bestScore' => $bestScore,
    ]);
}

if ($action === 'best') {
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        respond(400, ['error' => 'Malformed request body.']);
    }

    $boardTiles = $input['board'] ?? null;
    $rackRaw = $input['rack'] ?? null;
    if (!is_array($boardTiles) || !is_array($rackRaw)) {
        respond(400, ['error' => 'Malformed request body.']);
    }

    $rack = array_map(fn($l) => strtoupper((string) $l), $rackRaw);
    if (count($rack) < 1 || count($rack) > 7 || !preg_match('/^[A-Z_]+$/', implode('', $rack))) {
        respond(400, ['error' => 'Invalid rack.']);
    }
    $rackCounts = letterCounts(implode('', $rack));
    $board = boardFromPlacedTiles($boardTiles);

    $dict = loadDictionary();
    $leftDict = buildLeftDict($dict);
    $moves = legalMoves($board, $rackCounts, $dict, $leftDict);

    if (empty($moves)) {
        respond(200, ['found' => false]);
    }

    $best = $moves[0];
    foreach ($moves as $m) {
        if ($m['score'] > $best['score']) {
            $best = $m;
        }
    }

    respond(200, [
        'found' => true, 'newTiles' => $best['newTiles'], 'words' => $best['words'],
        'score' => $best['score'], 'bingo' => $best['bingo'],
    ]);
}

respond(400, ['error' => 'Unknown action.']);
