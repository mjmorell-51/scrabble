const LETTER_POINTS = {
  A: 1, B: 3, C: 3, D: 2, E: 1, F: 4, G: 2, H: 4, I: 1, J: 8,
  K: 5, L: 1, M: 3, N: 1, O: 1, P: 3, Q: 10, R: 1, S: 1, T: 1,
  U: 1, V: 4, W: 4, X: 8, Y: 4, Z: 10, _: 0,
};

const rackEl = document.getElementById('rack');
const resultEl = document.getElementById('result');
const newRackBtn = document.getElementById('new-rack-btn');
const hintBtn = document.getElementById('hint-btn');
const modeBtns = document.querySelectorAll('.mode-btn');
const guessForm = document.getElementById('guess-form');
const guessInput = document.getElementById('guess-input');
const guessBtn = document.getElementById('guess-btn');

let mode = 'random';
let currentRack = [];

function ordinal(n) {
  const suffixes = ['th', 'st', 'nd', 'rd'];
  const v = n % 100;
  return n + (suffixes[(v - 20) % 10] || suffixes[v] || suffixes[0]);
}

function tileHtml(letter) {
  const isBlank = letter === '_';
  const display = isBlank ? '' : letter;
  const pts = LETTER_POINTS[letter] ?? 0;
  return `<span class="tile${isBlank ? ' blank' : ''}">${display}<span class="pts">${pts}</span></span>`;
}

function renderRack(rack) {
  rackEl.innerHTML = rack.map(tileHtml).join('');
}

function setControlsEnabled(enabled) {
  newRackBtn.disabled = !enabled;
  hintBtn.disabled = !enabled;
  guessBtn.disabled = !enabled;
  guessInput.disabled = !enabled;
}

async function dealRack() {
  setControlsEnabled(false);
  resultEl.innerHTML = '';
  guessInput.value = '';
  rackEl.innerHTML = '';
  resultEl.innerHTML = '<p class="hint">Drawing tiles&hellip;</p>';

  try {
    const response = await fetch(`/api/bestword.php?action=deal&mode=${encodeURIComponent(mode)}`);
    const data = await response.json();

    if (!response.ok) {
      resultEl.innerHTML = `<span class="verdict error">${data.error || 'Something went wrong.'}</span>`;
      return;
    }

    currentRack = data.rack;
    renderRack(currentRack);
    resultEl.innerHTML = '';
  } catch (err) {
    resultEl.innerHTML = '<span class="verdict error">Could not reach the server. Try again.</span>';
  } finally {
    setControlsEnabled(true);
  }
}

async function showHint() {
  setControlsEnabled(false);
  resultEl.innerHTML = '<p class="hint">Thinking&hellip;</p>';

  try {
    const rackParam = currentRack.join('');
    const response = await fetch(`/api/bestword.php?action=hint&rack=${encodeURIComponent(rackParam)}`);
    const data = await response.json();

    if (!response.ok) {
      resultEl.innerHTML = `<span class="verdict error">${data.error || 'Something went wrong.'}</span>`;
      return;
    }

    if (!data.word) {
      resultEl.innerHTML = '<span class="verdict invalid">No valid word found for this rack.</span>';
      return;
    }

    const bingoNote = data.bingo ? ' <span class="badge">Bingo! +50</span>' : '';
    resultEl.innerHTML = `<span class="verdict valid">${data.word} &mdash; ${data.score} pts${bingoNote}</span>`;
    Definitions.show(resultEl, data.word);
  } catch (err) {
    resultEl.innerHTML = '<span class="verdict error">Could not reach the server. Try again.</span>';
  } finally {
    setControlsEnabled(true);
  }
}

async function submitGuess(event) {
  event.preventDefault();
  const word = guessInput.value.trim();
  if (!word) return;

  setControlsEnabled(false);
  resultEl.innerHTML = renderGuessTiles(word) + '<p class="hint">Checking&hellip;</p>';

  try {
    const rackParam = currentRack.join('');
    const response = await fetch(
      `/api/bestword.php?action=guess&rack=${encodeURIComponent(rackParam)}&word=${encodeURIComponent(word)}`
    );
    const data = await response.json();

    if (!response.ok) {
      resultEl.innerHTML = renderGuessTiles(word) +
        `<span class="verdict error">${data.error || 'Something went wrong.'}</span>`;
      return;
    }

    resultEl.innerHTML = renderGuessTiles(word) + buildGuessMessage(data);
    // Define whichever word was reported as best (that's the player's own word when
    // their guess was the top play).
    const reported = data.bestWord || (data.valid ? data.word : null);
    if (reported) {
      Definitions.show(resultEl, reported);
    }
  } catch (err) {
    resultEl.innerHTML = renderGuessTiles(word) +
      '<span class="verdict error">Could not reach the server. Try again.</span>';
  } finally {
    setControlsEnabled(true);
  }
}

function renderGuessTiles(word) {
  const tiles = [...word.toUpperCase()]
    .map((letter) => {
      const pts = LETTER_POINTS[letter] ?? '';
      return `<span class="tile">${letter}<span class="pts">${pts}</span></span>`;
    })
    .join('');
  return `<div class="result-tiles">${tiles}</div>`;
}

function buildGuessMessage(data) {
  if (data.totalValid === 0) {
    return '<span class="verdict invalid">There were no valid words from this rack.</span>';
  }

  if (!data.valid) {
    return `<span class="verdict invalid">${data.word} isn't a valid play from this rack.</span>` +
      `<p class="hint">There were ${data.totalValid} valid words. The best was ` +
      `${data.bestWord} for ${data.bestScore} points.</p>`;
  }

  if (data.rank === 1) {
    return `<span class="verdict valid">${data.word} scored ${data.score} points &mdash; ` +
      `that's the best possible word out of ${data.totalValid} valid words!</span>`;
  }

  return `<span class="verdict valid">${data.word} scored ${data.score} points.</span>` +
    `<p class="hint">There were ${data.totalValid} valid words. You picked the ${ordinal(data.rank)} best. ` +
    `The best word was ${data.bestWord} for ${data.bestScore} points.</p>`;
}

modeBtns.forEach((btn) => {
  btn.addEventListener('click', () => {
    if (btn.classList.contains('active')) return;
    modeBtns.forEach((b) => b.classList.remove('active'));
    btn.classList.add('active');
    mode = btn.dataset.mode;
    dealRack();
  });
});

newRackBtn.addEventListener('click', dealRack);
hintBtn.addEventListener('click', showHint);
guessForm.addEventListener('submit', submitGuess);

dealRack();
