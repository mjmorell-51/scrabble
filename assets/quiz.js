// Quizzes -- an n-question "is this a word?" drill over m-letter tile sequences.
// Questions (with answers) come from api/quiz.php; grading happens here.

const LETTER_POINTS = {
  a: 1, b: 3, c: 3, d: 2, e: 1, f: 4, g: 2, h: 4, i: 1, j: 8,
  k: 5, l: 1, m: 3, n: 1, o: 1, p: 3, q: 10, r: 1, s: 1, t: 1,
  u: 1, v: 4, w: 4, x: 8, y: 4, z: 10,
};

const setupForm = document.getElementById('quiz-setup');
const lettersSelect = document.getElementById('letters-select');
const countSelect = document.getElementById('count-select');
const startBtn = document.getElementById('start-btn');

const playScreen = document.getElementById('quiz-play');
const progressEl = document.getElementById('progress');
const questionTilesEl = document.getElementById('question-tiles');
const yesBtn = document.getElementById('yes-btn');
const noBtn = document.getElementById('no-btn');
const feedbackEl = document.getElementById('feedback');

const doneScreen = document.getElementById('quiz-done');
const scoreResult = document.getElementById('score-result');
const againBtn = document.getElementById('again-btn');

let questions = [];
let current = 0;
let score = 0;
let advanceTimer = null;
const FEEDBACK_MS = 1100; // how long the correct/incorrect note lingers before the next Q

function renderTiles(word) {
  return [...word.toLowerCase()]
    .map((letter) => {
      const pts = LETTER_POINTS[letter] ?? '';
      return `<span class="tile">${letter}<span class="pts">${pts}</span></span>`;
    })
    .join('');
}

function showScreen(screen) {
  playScreen.hidden = screen !== 'play';
  doneScreen.hidden = screen !== 'done';
  setupForm.hidden = screen !== 'setup';
}

function renderQuestion() {
  const q = questions[current];
  progressEl.textContent = `Question ${current + 1} of ${questions.length}`;
  questionTilesEl.innerHTML = renderTiles(q.tiles);
  feedbackEl.innerHTML = '';
  yesBtn.disabled = false;
  noBtn.disabled = false;
  yesBtn.classList.remove('active');
  noBtn.classList.remove('active');
}

function answer(saidYes) {
  if (yesBtn.disabled) return; // already answered this question
  const q = questions[current];
  yesBtn.disabled = true;
  noBtn.disabled = true;
  (saidYes ? yesBtn : noBtn).classList.add('active');

  const correct = saidYes === q.isWord;
  if (correct) score++;

  const verdictClass = correct ? 'valid' : 'invalid';
  const mark = correct ? '✓' : '✗';
  const truth = q.isWord
    ? `<strong>${q.tiles}</strong> is a valid word`
    : `<strong>${q.tiles}</strong> is not a word`;
  const lead = correct ? 'Correct' : 'Not quite';
  feedbackEl.innerHTML =
    `<span class="verdict ${verdictClass}">${mark} ${lead} &mdash; ${truth}</span>`;

  // Let the feedback linger briefly, then move straight to the next question.
  advanceTimer = setTimeout(() => {
    current++;
    if (current >= questions.length) {
      finish();
    } else {
      renderQuestion();
    }
  }, FEEDBACK_MS);
}

function finish() {
  const total = questions.length;
  const pct = Math.round((score / total) * 100);
  let note;
  if (pct === 100) note = 'Perfect score!';
  else if (pct >= 80) note = 'Nicely done.';
  else if (pct >= 50) note = 'Not bad — keep studying.';
  else note = 'Room to grow. Try again!';

  scoreResult.innerHTML =
    `<span class="verdict ${pct >= 50 ? 'valid' : 'invalid'}">You scored ${score} / ${total} (${pct}%)</span>` +
    `<p class="hint">${note}</p>`;
  showScreen('done');
}

setupForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  const letters = lettersSelect.value;
  const count = countSelect.value;

  startBtn.disabled = true;
  startBtn.textContent = 'Building quiz…';

  try {
    const res = await fetch(`/api/quiz.php?action=new&letters=${letters}&count=${count}`);
    const data = await res.json();
    if (!res.ok) {
      startBtn.disabled = false;
      startBtn.textContent = 'Start Quiz';
      alert(data.error || 'Could not build the quiz.');
      return;
    }
    clearTimeout(advanceTimer);
    questions = data.questions;
    current = 0;
    score = 0;
    showScreen('play');
    renderQuestion();
  } catch (err) {
    alert('Could not reach the server. Try again.');
  } finally {
    startBtn.disabled = false;
    startBtn.textContent = 'Start Quiz';
  }
});

yesBtn.addEventListener('click', () => answer(true));
noBtn.addEventListener('click', () => answer(false));
againBtn.addEventListener('click', () => showScreen('setup'));
