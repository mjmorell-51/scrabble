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

let mode = 'random';
let currentRack = [];

function tileHtml(letter) {
  const isBlank = letter === '_';
  const display = isBlank ? '' : letter;
  const pts = LETTER_POINTS[letter] ?? 0;
  return `<span class="tile${isBlank ? ' blank' : ''}">${display}<span class="pts">${pts}</span></span>`;
}

function renderRack(rack) {
  rackEl.innerHTML = rack.map(tileHtml).join('');
}

async function dealRack() {
  hintBtn.disabled = true;
  newRackBtn.disabled = true;
  resultEl.innerHTML = '';
  rackEl.innerHTML = '<p class="hint">Drawing tiles&hellip;</p>';

  try {
    const response = await fetch(`/api/bestword.php?action=deal&mode=${encodeURIComponent(mode)}`);
    const data = await response.json();

    if (!response.ok) {
      rackEl.innerHTML = '';
      resultEl.innerHTML = `<span class="verdict error">${data.error || 'Something went wrong.'}</span>`;
      return;
    }

    currentRack = data.rack;
    renderRack(currentRack);
  } catch (err) {
    rackEl.innerHTML = '';
    resultEl.innerHTML = '<span class="verdict error">Could not reach the server. Try again.</span>';
  } finally {
    newRackBtn.disabled = false;
    hintBtn.disabled = false;
  }
}

async function showHint() {
  hintBtn.disabled = true;
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
  } catch (err) {
    resultEl.innerHTML = '<span class="verdict error">Could not reach the server. Try again.</span>';
  } finally {
    hintBtn.disabled = false;
  }
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

dealRack();
