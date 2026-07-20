const LETTER_POINTS = {
  a: 1, b: 3, c: 3, d: 2, e: 1, f: 4, g: 2, h: 4, i: 1, j: 8,
  k: 5, l: 1, m: 3, n: 1, o: 1, p: 3, q: 10, r: 1, s: 1, t: 1,
  u: 1, v: 4, w: 4, x: 8, y: 4, z: 10,
};

const form = document.getElementById('lookup-form');
const input = document.getElementById('word-input');
const button = document.getElementById('lookup-btn');
const result = document.getElementById('result');

function renderTiles(word) {
  const tiles = [...word.toLowerCase()]
    .map((letter) => {
      const pts = LETTER_POINTS[letter] ?? '';
      const wide = String(pts).length > 1 ? ' pts-wide' : '';
      return `<span class="tile">${letter}<span class="pts${wide}">${pts}</span></span>`;
    })
    .join('');
  return `<div class="result-tiles">${tiles}</div>`;
}

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  const word = input.value.trim();
  if (!word) return;

  button.disabled = true;
  result.innerHTML = renderTiles(word) + '<p class="hint">Checking&hellip;</p>';

  try {
    const response = await fetch(`/api/lookup.php?word=${encodeURIComponent(word)}`);
    const data = await response.json();

    if (!response.ok) {
      result.innerHTML = renderTiles(word) +
        `<span class="verdict error">${data.error || 'Something went wrong.'}</span>`;
      return;
    }

    const verdictClass = data.valid ? 'valid' : 'invalid';
    const verdictText = data.valid
      ? `${data.word} is a valid word`
      : `${data.word} is not in the dictionary`;

    result.innerHTML = renderTiles(word) +
      `<span class="verdict ${verdictClass}">${data.valid ? '✓' : '✗'} ${verdictText}</span>`;
    if (data.valid) {
      Definitions.show(result, data.word);
    }
  } catch (err) {
    result.innerHTML = renderTiles(word) +
      '<span class="verdict error">Could not reach the server. Try again.</span>';
  } finally {
    button.disabled = false;
  }
});
