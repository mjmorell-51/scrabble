#!/usr/bin/env python3
"""Build .data/definitions.tsv from a Wiktextract English dump.

Filters the dump down to just the NWL 2023 words, producing a sorted
`word<TAB>definition` file for api/define.php's byte-offset binary search.

Definition = up to a few concise glosses (one per part of speech), each prefixed
with a short POS tag, joined with ' / ', capped in length. Wiktionary text, so the
output is CC-BY-SA and needs attribution wherever it's shown.

Usage:
    wget -O wikt-en.jsonl \\
      https://kaikki.org/dictionary/English/kaikki.org-dictionary-English.jsonl
    python3 .tools/build-definitions.py .data/nwl2023.txt wikt-en.jsonl .data/definitions.tsv

Lives under .tools/ (dot-prefixed) so the Apache DirectoryMatch rule keeps it out of
HTTP; it's a dev-only build tool, not something to serve.
"""
import json
import sys

NWL_PATH = sys.argv[1]
JSONL_PATH = sys.argv[2]
OUT_PATH = sys.argv[3]

POS_ABBR = {
    "noun": "n.", "verb": "v.", "adj": "adj.", "adv": "adv.", "name": "n.",
    "pron": "pron.", "prep": "prep.", "conj": "conj.", "det": "det.",
    "num": "num.", "intj": "interj.", "article": "art.", "particle": "part.",
    "prefix": "pref.", "suffix": "suf.", "phrase": "phr.", "character": "char.",
    "symbol": "sym.", "punct": "punct.", "contraction": "contr.",
}

MAX_GLOSSES = 3
MAX_LEN = 260

# Glosses that are cross-references / grammar notes rather than real definitions. When a
# word has a genuine definition available, these shouldn't lead (e.g. "dog" should read
# "The species Canis familiaris", not "Initialism of digital on-screen graphic").
META_PREFIXES = (
    "initialism of", "abbreviation of", "acronym of", "alternative form of",
    "alternative spelling of", "alternative letter-case form of", "misspelling of",
    "synonym of", "obsolete form of", "obsolete spelling of", "archaic form of",
    "archaic spelling of", "dated form of", "dated spelling of", "eye dialect of",
    "clipping of", "contraction of", "inflection of", "plural of", "past tense of",
    "past participle of", "present participle of", "gerund of", "nonstandard form of",
    "nonstandard spelling of", "informal form of", "informal spelling of",
    "superseded spelling of", "rare form of", "rare spelling of", "used other than",
)


def is_meta(gloss):
    g = gloss.lower()
    return any(g.startswith(p) for p in META_PREFIXES)

with open(NWL_PATH, encoding="utf-8") as f:
    nwl = set(line.strip() for line in f if line.strip())

# word -> list of (pos, gloss); we keep insertion order and dedupe glosses.
defs = {}
kept = 0
scanned = 0

with open(JSONL_PATH, encoding="utf-8") as f:
    for line in f:
        line = line.strip()
        if not line:
            continue
        scanned += 1
        try:
            obj = json.loads(line)
        except json.JSONDecodeError:
            continue
        w = obj.get("word")
        if not isinstance(w, str):
            continue
        wl = w.lower()
        if wl not in nwl:
            continue
        senses = obj.get("senses") or []
        gloss = None
        fallback = None
        for s in senses:
            # Wiktextract's `glosses` is a hierarchy from broad category down to the
            # specific sense, e.g. ["Terms relating to animals.", "A mammal of the family
            # Felidae."] -- the LAST element is the actual definition, not the first.
            g = s.get("glosses") or s.get("raw_glosses")
            if not (g and isinstance(g, list) and isinstance(g[-1], str) and g[-1].strip()):
                continue
            leaf = g[-1].strip()
            if is_meta(leaf):
                if fallback is None:
                    fallback = leaf
                continue
            gloss = leaf
            break
        if gloss is None:
            gloss = fallback  # only cross-reference senses existed for this POS
        if not gloss:
            continue
        pos = obj.get("pos") or ""
        tag = POS_ABBR.get(pos, (pos + ".") if pos else "")
        entry = defs.setdefault(wl, [])
        # dedupe on gloss text (ignore case)
        if any(existing_gloss == gloss for _, existing_gloss in entry):
            continue
        if len(entry) < MAX_GLOSSES:
            entry.append((tag, gloss))
            kept += 1

# Format + write sorted.
lines_out = []
for wl in sorted(defs):
    # Stable-sort real definitions ahead of cross-reference ones so the lead gloss is
    # a genuine definition whenever the word has one across its parts of speech.
    ordered = sorted(defs[wl], key=lambda tg: is_meta(tg[1]))
    parts = []
    for tag, gloss in ordered:
        parts.append(f"{tag} {gloss}".strip())
    text = " / ".join(parts)
    # collapse whitespace/newlines, cap length
    text = " ".join(text.split())
    if len(text) > MAX_LEN:
        text = text[:MAX_LEN - 1].rstrip() + "…"  # ellipsis
    # tabs/newlines can't appear in a TSV value
    text = text.replace("\t", " ")
    lines_out.append(f"{wl}\t{text}")

with open(OUT_PATH, "w", encoding="utf-8") as f:
    f.write("\n".join(lines_out))
    f.write("\n")

sys.stderr.write(
    f"scanned {scanned} entries; matched {len(defs)} of {len(nwl)} NWL words "
    f"({100*len(defs)/len(nwl):.1f}% coverage)\n"
)
