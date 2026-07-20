<?php
declare(strict_types=1);

header('Content-Type: application/json');

function respond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
}

// Definitions live in a sorted "word<TAB>definition" text file (one line per word,
// sorted identically to nwl2023.txt -- plain ASCII order over lowercase a-z). It's far
// larger than the word list, so rather than reading the whole thing into memory per
// request we binary-search it by byte offset: seek to a midpoint, align forward to the
// next line boundary, compare that line's key, and narrow the [lo, hi) byte window.
// Returns the definition string, '' if the word is present with an empty definition, or
// null if the word isn't in the file.
function lookupDefinition(string $path, string $word): ?string {
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return null;
    }
    $stat = fstat($fh);
    $size = $stat['size'] ?? 0;

    // Byte-granular binary search (the classic `look`(1) approach): converge $lo to a
    // byte position at/just before the first line whose key >= $word, aligning to line
    // boundaries by discarding the partial line each probe lands in. Keys are
    // non-decreasing as byte position advances, so this predicate is monotone.
    $lo = 0;
    $hi = $size;
    while ($lo < $hi) {
        $mid = intdiv($lo + $hi, 2);
        fseek($fh, $mid);
        if ($mid !== 0) {
            fgets($fh); // discard the partial line the midpoint landed in
        }
        $line = fgets($fh);
        // A false read means EOF (no full line here) -- treat its key as "> target".
        $keyBelow = false;
        if ($line !== false) {
            $tabPos = strpos($line, "\t");
            $key = $tabPos === false ? rtrim($line, "\r\n") : substr($line, 0, $tabPos);
            $keyBelow = strcmp($key, $word) < 0;
        }
        if ($keyBelow) {
            $lo = $mid + 1;
        } else {
            $hi = $mid;
        }
    }

    // $lo is now byte-close to the target line; scan forward from the aligned position.
    // Keys only increase, so stop at the first line whose key >= $word.
    fseek($fh, $lo);
    if ($lo !== 0) {
        fgets($fh); // align to the next line boundary
    }
    $result = null;
    while (($line = fgets($fh)) !== false) {
        $tabPos = strpos($line, "\t");
        $key = $tabPos === false ? rtrim($line, "\r\n") : substr($line, 0, $tabPos);
        $cmp = strcmp($key, $word);
        if ($cmp < 0) {
            continue; // still short of the target (rare off-by-one from alignment)
        }
        if ($cmp === 0) {
            $result = $tabPos === false ? '' : rtrim(substr($line, $tabPos + 1), "\r\n");
        }
        break; // key >= target: found it, or it's absent
    }
    fclose($fh);
    return $result;
}

if (defined('DEFINE_LIB_ONLY')) {
    return;
}

$word = strtolower(trim((string) ($_GET['word'] ?? '')));
if (!preg_match('/^[a-z]{1,15}$/', $word)) {
    respond(400, ['error' => 'Invalid word.']);
}

$path = dirname(__DIR__) . '/.data/definitions.tsv';
$definition = lookupDefinition($path, $word);

respond(200, ['word' => $word, 'definition' => $definition]);
