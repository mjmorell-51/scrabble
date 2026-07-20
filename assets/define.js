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

  // Appends a definition line for `word` to `container`, fetching it asynchronously.
  // Shows a subtle placeholder first, then the definition (or a "no definition" note).
  // Guards against races: if `container` has been re-rendered (a token mismatch) by the
  // time the fetch resolves, the stale result is dropped.
  function show(container, word) {
    if (!container || !word) return;
    const token = String(Date.now()) + Math.random();
    container.dataset.defToken = token;

    const el = document.createElement('p');
    el.className = 'definition loading';
    el.textContent = 'Looking up definition…';
    container.appendChild(el);

    fetchDefinition(word).then((def) => {
      if (container.dataset.defToken !== token || !el.isConnected) return;
      const label = escapeHtml(word.toUpperCase());
      if (def) {
        el.className = 'definition';
        el.innerHTML = `<strong>${label}</strong> &mdash; ${escapeHtml(def)}`;
      } else {
        el.className = 'definition none';
        el.innerHTML = `No definition on file for <strong>${label}</strong>.`;
      }
    });
  }

  window.Definitions = { fetch: fetchDefinition, show };
})();
