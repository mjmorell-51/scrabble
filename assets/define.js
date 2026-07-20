// Shared word-definition lookup, used by Look Up a Word, Best Word, and Best Play.
// Definitions are served by api/define.php from a pre-built local file derived from
// English Wiktionary (CC BY-SA) -- see the attribution note each page carries.
(function () {
  const cache = new Map();

  async function fetchDefinition(word) {
    word = (word || '').toLowerCase();
    if (!/^[a-z]{1,15}$/.test(word)) return null;
    if (cache.has(word)) return cache.get(word);
    let def = null;
    try {
      const res = await fetch(`/api/define.php?word=${encodeURIComponent(word)}`);
      if (res.ok) {
        const data = await res.json();
        def = data.definition || null;
      }
    } catch (e) {
      def = null;
    }
    cache.set(word, def);
    return def;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  // When a definition is just a cross-reference to another word ("plural of erbium",
  // "simple past ... of run", "synonym of zymology", "alternative form of ..."), pull
  // out that root word so we can define it too. Deliberately excludes initialism/
  // abbreviation/acronym forms -- those expand to a phrase, not a single lookup-able root.
  const XREF_RE = new RegExp(
    '(?:^|\\s)(?:' +
    'plurals?|' +
    'past tense|past participle|present participle|' +
    'simple past(?: tense)?(?: and past participle)?|' +
    'gerund|comparative|superlative|' +
    'alternative (?:form|spelling|letter-case form)|' +
    'obsolete (?:form|spelling)|archaic (?:form|spelling)|dated (?:form|spelling)|' +
    'nonstandard (?:form|spelling)|informal (?:form|spelling)|rare (?:form|spelling)|' +
    'superseded spelling|' +
    'misspelling|synonym|clipping|contraction|inflection|' +
    'third-person singular[^.]*?' +
    ') of ([a-z][a-z’\'-]*)', 'i');

  function rootOf(defText, word) {
    if (!defText) return null;
    const m = defText.match(XREF_RE);
    if (!m) return null;
    const root = m[1].toLowerCase().replace(/[^a-z]/g, '');
    if (!root || root === (word || '').toLowerCase() || !/^[a-z]{1,15}$/.test(root)) return null;
    return root;
  }

  function makeBlock() {
    const el = document.createElement('p');
    el.className = 'definition loading';
    el.textContent = 'Looking up definition…';
    return el;
  }

  function fill(el, word, def, isRoot) {
    const label = escapeHtml(word.toUpperCase());
    const arrow = isRoot ? '↳ ' : '';
    if (def) {
      el.className = 'definition' + (isRoot ? ' root' : '');
      el.innerHTML = `${arrow}<strong>${label}</strong> &mdash; ${escapeHtml(def)}`;
    } else {
      el.className = 'definition none' + (isRoot ? ' root' : '');
      el.innerHTML = `${arrow}No definition on file for <strong>${label}</strong>.`;
    }
  }

  // Appends a definition line for each of `words` to `container`, in order, fetching
  // asynchronously. When a word's definition is only a cross-reference ("plural of X"),
  // the root word X is defined too, indented right beneath it. Duplicates (including a
  // root that's already shown) are skipped. Guards against races: if `container` has been
  // re-rendered by the time a fetch resolves (token mismatch), the stale result is dropped.
  function showWords(container, words) {
    if (!container) return;
    const token = String(Date.now()) + Math.random();
    container.dataset.defToken = token;

    const seen = new Set();
    const list = [];
    (Array.isArray(words) ? words : [words]).forEach((w) => {
      w = (w || '').toLowerCase();
      if (/^[a-z]{1,15}$/.test(w) && !seen.has(w)) {
        seen.add(w);
        list.push(w);
      }
    });
    if (!list.length) return;

    list.forEach((word) => {
      const block = makeBlock();
      container.appendChild(block);
      fetchDefinition(word).then((def) => {
        if (container.dataset.defToken !== token || !block.isConnected) return;
        fill(block, word, def, false);
        const root = rootOf(def, word);
        if (root && !seen.has(root)) {
          seen.add(root);
          const rootBlock = makeBlock();
          block.after(rootBlock);
          fetchDefinition(root).then((rdef) => {
            if (container.dataset.defToken !== token || !rootBlock.isConnected) return;
            fill(rootBlock, root, rdef, true);
          });
        }
      });
    });
  }

  window.Definitions = {
    fetch: fetchDefinition,
    show: (container, word) => showWords(container, [word]),
    showWords,
  };
})();
