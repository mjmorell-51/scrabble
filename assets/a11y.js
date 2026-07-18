(function () {
  const STORAGE_KEY = 'scrabble-text-scale';
  const SCALES = [1, 1.25, 1.5];
  const LABELS = { 1: 'Text: Normal', 1.25: 'Text: Large', 1.5: 'Text: X-Large' };
  const SIZE_KEYS = { 1: 'normal', 1.25: 'large', 1.5: 'xlarge' };

  function getStoredScale() {
    const raw = parseFloat(localStorage.getItem(STORAGE_KEY));
    return SCALES.includes(raw) ? raw : 1;
  }

  function applyScale(scale) {
    document.documentElement.style.setProperty('--font-scale', String(scale));
    // Lets CSS give tile letters/numbers a disproportionately bigger share of
    // the tile at larger sizes, not just scale everything up uniformly.
    document.documentElement.dataset.textSize = SIZE_KEYS[scale];
  }

  // Apply immediately (before DOMContentLoaded) so there's no flash of the wrong size.
  applyScale(getStoredScale());

  document.addEventListener('DOMContentLoaded', () => {
    const header = document.querySelector('header.site');
    if (!header) return;

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'text-size-btn';
    btn.setAttribute('aria-label', 'Change text size');
    btn.textContent = LABELS[getStoredScale()];

    btn.addEventListener('click', () => {
      const current = getStoredScale();
      const next = SCALES[(SCALES.indexOf(current) + 1) % SCALES.length];
      localStorage.setItem(STORAGE_KEY, String(next));
      applyScale(next);
      btn.textContent = LABELS[next];
    });

    header.appendChild(btn);
  });
})();
