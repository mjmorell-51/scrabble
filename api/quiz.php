<?php
declare(strict_types=1);

// "Quizzes" -- generate an n-question "is this a word?" quiz over m-letter sequences.
// Each question is either a real NWL word of length m or a plausible fake (a real word
// with one letter mutated so it's no longer in the dictionary). Answers ride along with
// the questions and the client grades locally -- this is a self-quiz, not a scored exam,
// and the app holds no server sessions, consistent with the other endpoints here.

header('Content-Type: application/json');

function respond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
}

$action = $_GET['action'] ?? 'new';
if ($action !== 'new') {
    respond(400, ['error' => 'Unknown action.']);
}

$letters = (int) ($_GET['letters'] ?? 0);
$count   = (int) ($_GET['count'] ?? 0);

if ($letters < 2 || $letters > 8) {
    respond(400, ['error' => 'Letters must be between 2 and 8.']);
}
if ($count < 1 || $count > 25) {
    respond(400, ['error' => 'Number of questions must be between 1 and 25.']);
}

$dictionaryPath = dirname(__DIR__) . '/.data/nwl2023.txt';
$lines = file($dictionaryPath, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    respond(500, ['error' => 'Dictionary is temporarily unavailable.']);
}

$dictSet = array_flip($lines);

// Pool of real words of exactly this length.
$pool = [];
foreach ($lines as $w) {
    if (strlen($w) === $letters) {
        $pool[] = $w;
    }
}
unset($lines);

if (count($pool) < 4) {
    respond(400, ['error' => "Not enough {$letters}-letter words for a quiz."]);
}

// Make a real word of length $letters into a plausible non-word by changing one letter.
// The replacement stays in the same class as the letter it replaces -- vowel for vowel,
// consonant for consonant -- so the fake keeps a word-like shape (SCRABBLE -> SCRIBBLE,
// not SCR9BBLE). Falls back to random letters if mutation keeps landing on real words.
function makeFake(array $pool, array $dictSet, int $letters): string {
    $vowels = 'aeiou';
    $consonants = 'bcdfghjklmnpqrstvwxyz'; // y treated as a consonant here
    for ($attempt = 0; $attempt < 25; $attempt++) {
        $word = $pool[array_rand($pool)];
        $pos = random_int(0, $letters - 1);
        $orig = $word[$pos];
        $set = strpos($vowels, $orig) !== false ? $vowels : $consonants;
        $new = $set[random_int(0, strlen($set) - 1)];
        if ($new === $orig) {
            continue;
        }
        $word[$pos] = $new;
        if (!isset($dictSet[$word])) {
            return $word;
        }
    }
    // Last resort: random letters until it isn't a word.
    do {
        $s = '';
        for ($i = 0; $i < $letters; $i++) {
            $s .= chr(random_int(ord('a'), ord('z')));
        }
    } while (isset($dictSet[$s]));
    return $s;
}

// Each question is an independent 50/50 coin flip between a real word and a fake, so the
// count of "yes" answers isn't fixed in advance -- it averages half but varies per quiz.
$used = [];       // tile-strings already asked, to avoid duplicates within a quiz
$questions = [];

for ($i = 0; $i < $count; $i++) {
    $isWord = random_int(0, 1) === 1;
    $tiles = null;
    for ($tries = 0; $tries < 40; $tries++) {
        $candidate = $isWord ? $pool[array_rand($pool)] : makeFake($pool, $dictSet, $letters);
        if (!isset($used[$candidate])) {
            $tiles = $candidate;
            break;
        }
    }
    if ($tiles === null) {
        continue; // couldn't find a fresh one; skip rather than repeat
    }
    $used[$tiles] = true;
    $questions[] = [
        'tiles'  => strtoupper($tiles),
        'isWord' => (bool) $isWord,
    ];
}

respond(200, [
    'letters'   => $letters,
    'count'     => count($questions),
    'questions' => $questions,
]);
