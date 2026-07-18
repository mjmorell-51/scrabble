(function () {
  const LETTER_POINTS = {
    A: 1, B: 3, C: 3, D: 2, E: 1, F: 4, G: 2, H: 4, I: 1, J: 8, K: 5, L: 1, M: 3,
    N: 1, O: 1, P: 3, Q: 10, R: 1, S: 1, T: 1, U: 1, V: 4, W: 4, X: 8, Y: 4, Z: 10,
  };

  // . = normal, 2/3 = double/triple word, d/t = double/triple letter, * = center (double word)
  const PREMIUM_ROWS = [
    "3..d...3...d..3",
    ".2...t...t...2.",
    "..2...d.d...2..",
    "d..2...d...2..d",
    "....2.....2....",
    ".t...t...t...t.",
    "..d...d.d...d..",
    "3..d...*...d..3",
    "..d...d.d...d..",
    ".t...t...t...t.",
    "....2.....2....",
    "d..2...d...2..d",
    "..2...d.d...2..",
    ".2...t...t...2.",
    "3..d...3...d..3",
  ];

  const PREMIUM_LABEL = { d: 'DL', t: 'TL', 2: 'DW', 3: 'TW', '*': '★' };
  const PREMIUM_CLASS = { d: 'dl', t: 'tl', 2: 'dw', 3: 'tw', '*': 'star' };

  let board = {}; // "r,c" -> {r,c,letter,blank}
  let rack = [];
  let mode = 'type'; // 'type' | 'place'
  let direction = 'across';
  let selectedStart = null; // {r,c}
  let cursor = null; // {r,c} -- place mode's next empty slot to fill
  let pendingPlacements = []; // [{r,c,letter,blank}]
  let usedRackIndexes = new Set(); // place mode: which rack slots are already placed this turn
  let pendingBlankIndex = null;

  const boardEl = document.getElementById('board');
  const rackEl = document.getElementById('rack');
  const resultEl = document.getElementById('result');
  const typeForm = document.getElementById('type-form');
  const typeInput = document.getElementById('type-input');
  const typeSubmitBtn = document.getElementById('type-submit-btn');
  const placeActionsEl = document.getElementById('place-actions');
  const doneBtn = document.getElementById('done-btn');
  const clearBtn = document.getElementById('clear-btn');
  const newBoardBtn = document.getElementById('new-board-btn');
  const blankPickerEl = document.getElementById('blank-picker');
  const modeBtns = Array.from(document.querySelectorAll('.mode-btn[data-mode]'));
  const dirBtns = Array.from(document.querySelectorAll('.mode-btn[data-dir]'));

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, (ch) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[ch]));
  }

  function inBounds(r, c) {
    return r >= 0 && r < 15 && c >= 0 && c < 15;
  }

  function step(r, c, dir, delta) {
    return dir === 'across' ? [r, c + delta] : [r + delta, c];
  }

  function isOccupied(r, c) {
    if (board[`${r},${c}`]) return true;
    return pendingPlacements.some((p) => p.r === r && p.c === c);
  }

  function nextEmptyFrom(r, c, dir) {
    while (inBounds(r, c)) {
      if (!isOccupied(r, c)) {
        return { r, c };
      }
      [r, c] = step(r, c, dir, 1);
    }
    return null;
  }

  function tileAt(r, c) {
    return pendingPlacements.find((p) => p.r === r && p.c === c) || board[`${r},${c}`] || null;
  }

  // Only used for board tiles, which (unlike an unassigned '_' rack tile) always
  // have a specific letter chosen for them -- show it even when blank, so the
  // player can see what letter a blank on the board was played as.
  function tileHtml(letter, isBlank) {
    const pts = isBlank ? 0 : (LETTER_POINTS[letter] ?? 0);
    return `<span class="tile${isBlank ? ' blank' : ''}">${letter}<span class="pts">${pts}</span></span>`;
  }

  function renderBoard() {
    let html = '';
    for (let r = 0; r < 15; r++) {
      for (let c = 0; c < 15; c++) {
        const tile = tileAt(r, c);
        const isPending = pendingPlacements.some((p) => p.r === r && p.c === c);
        const code = PREMIUM_ROWS[r][c];
        const classes = ['cell'];
        if (!tile && code !== '.') {
          classes.push(PREMIUM_CLASS[code]);
        }
        if (isPending) {
          classes.push('pending');
        }
        if (selectedStart && selectedStart.r === r && selectedStart.c === c && !tile) {
          classes.push('selected');
        }
        let inner = '';
        if (tile) {
          inner = tileHtml(tile.letter, !!tile.blank);
        } else if (code !== '.') {
          inner = `<span class="premium-label">${PREMIUM_LABEL[code]}</span>`;
        }
        // Cells stay tappable (to re-pick a start square) until a tile has actually
        // been placed this turn -- after that, the word comes from the text input or
        // from tapping rack tiles, so further cell taps would be dead and confusing.
        const cellDisabled = !!tile || turnLocked();
        html += `<button type="button" class="${classes.join(' ')}" data-r="${r}" data-c="${c}" aria-label="Row ${r + 1}, column ${c + 1}" ${cellDisabled ? 'disabled' : ''}>${inner}</button>`;
      }
    }
    boardEl.innerHTML = html;
  }

  function renderRack() {
    rackEl.innerHTML = rack.map((letter, i) => {
      const isBlank = letter === '_';
      const used = usedRackIndexes.has(i);
      const classes = ['tile'];
      if (isBlank) classes.push('blank');
      if (used) classes.push('used');
      const canTap = mode === 'place' && !!selectedStart && !!cursor && !used;
      return `<button type="button" class="${classes.join(' ')}" data-i="${i}" ${canTap ? '' : 'disabled'}>${isBlank ? '' : letter}<span class="pts">${isBlank ? 0 : (LETTER_POINTS[letter] ?? 0)}</span></button>`;
    }).join('');
  }

  // Mode/direction only need to lock once a tile has actually been placed --
  // picking (or re-picking) a start square shouldn't require a Clear first.
  function turnLocked() {
    return pendingPlacements.length > 0;
  }

  function updateModeUI() {
    modeBtns.forEach((btn) => btn.classList.toggle('active', btn.dataset.mode === mode));
    dirBtns.forEach((btn) => btn.classList.toggle('active', btn.dataset.dir === direction));
    typeForm.hidden = mode !== 'type';
    placeActionsEl.hidden = mode !== 'place';
    modeBtns.forEach((btn) => { btn.disabled = turnLocked(); });
    dirBtns.forEach((btn) => { btn.disabled = turnLocked(); });
  }

  function render() {
    renderBoard();
    renderRack();
    updateModeUI();
  }

  function resetTurn() {
    selectedStart = null;
    cursor = null;
    pendingPlacements = [];
    usedRackIndexes = new Set();
    pendingBlankIndex = null;
    blankPickerEl.hidden = true;
    typeInput.value = '';
  }

  function setControlsEnabled(enabled) {
    [typeInput, typeSubmitBtn, doneBtn, clearBtn, newBoardBtn, ...modeBtns, ...dirBtns].forEach((el) => {
      el.disabled = !enabled;
    });
    if (enabled) {
      updateModeUI();
    }
  }

  function onCellClick(r, c) {
    if (turnLocked() || isOccupied(r, c)) {
      return;
    }
    selectedStart = { r, c };
    cursor = nextEmptyFrom(r, c, direction);
    render();
  }

  function openBlankPicker(rackIndex) {
    pendingBlankIndex = rackIndex;
    blankPickerEl.hidden = false;
    blankPickerEl.innerHTML = '<p class="hint" style="margin:0 0 0.5rem;">Blank tile — pick a letter:</p>' +
      'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('').map((letter) =>
        `<button type="button" class="blank-letter-btn" data-letter="${letter}">${letter}</button>`
      ).join('');
  }

  function placeTileAt(rackIndex, letter, isBlank) {
    if (!cursor) {
      return;
    }
    pendingPlacements.push({ r: cursor.r, c: cursor.c, letter, blank: isBlank });
    usedRackIndexes.add(rackIndex);
    const [nr, nc] = step(cursor.r, cursor.c, direction, 1);
    cursor = nextEmptyFrom(nr, nc, direction);
    blankPickerEl.hidden = true;
    pendingBlankIndex = null;
    render();
  }

  function onRackTileClick(i) {
    if (mode !== 'place' || !selectedStart || !cursor || usedRackIndexes.has(i)) {
      return;
    }
    const letter = rack[i];
    if (letter === '_') {
      openBlankPicker(i);
      return;
    }
    placeTileAt(i, letter, false);
  }

  function buildPlacementsFromTypedWord(word) {
    const upper = word.toUpperCase();
    if (!/^[A-Z]+$/.test(upper)) {
      return { error: 'Use letters A-Z only.' };
    }
    const counts = {};
    rack.forEach((l) => { counts[l] = (counts[l] || 0) + 1; });
    let blanks = counts['_'] || 0;
    delete counts['_'];

    let [r, c] = [selectedStart.r, selectedStart.c];
    const placements = [];
    for (const ch of upper) {
      if (!inBounds(r, c)) {
        return { error: 'That runs off the edge of the board.' };
      }
      const existing = board[`${r},${c}`];
      if (existing) {
        if (existing.letter !== ch) {
          return { error: `The board already has ${existing.letter} there, not ${ch}.` };
        }
      } else if ((counts[ch] || 0) > 0) {
        counts[ch]--;
        placements.push({ r, c, letter: ch, blank: false });
      } else if (blanks > 0) {
        blanks--;
        placements.push({ r, c, letter: ch, blank: true });
      } else {
        return { error: "You don't have the tiles to spell that." };
      }
      [r, c] = step(r, c, direction, 1);
    }
    if (placements.length === 0) {
      return { error: 'That play uses no new tiles.' };
    }
    return { placements };
  }

  function showResultError(message) {
    resultEl.innerHTML = `<div class="verdict error">${escapeHtml(message)}</div>`;
  }

  function ordinal(n) {
    const suffixes = ['th', 'st', 'nd', 'rd'];
    const v = n % 100;
    return n + (suffixes[(v - 20) % 10] || suffixes[v] || suffixes[0]);
  }

  function buildPlayMessage(data) {
    if (!data.valid) {
      let msg = `<div class="verdict invalid">${escapeHtml(data.error || "That doesn't form a valid word.")}</div>`;
      if (data.bestWord) {
        msg += `<p class="hint">The best possible play was <strong>${escapeHtml(data.bestWord)}</strong> for ${data.bestScore} points.</p>`;
      }
      return msg;
    }
    const wordsList = data.words.map((w) => `${w.word} (${w.score})`).join(', ');
    let msg = `<div class="verdict valid">Scored ${data.score} point${data.score === 1 ? '' : 's'}`;
    if (data.bingo) {
      msg += ' <span class="badge">BINGO +50</span>';
    }
    msg += '</div>';
    msg += `<p class="hint">Word${data.words.length === 1 ? '' : 's'} formed: ${escapeHtml(wordsList)}</p>`;
    if (data.rank === 1) {
      msg += '<p class="hint">That was the best possible play!</p>';
    } else {
      msg += `<p class="hint">${ordinal(data.rank)} best out of ${data.totalValid} possible plays. Best: <strong>${escapeHtml(data.bestWord)}</strong> for ${data.bestScore} points.</p>`;
    }
    return msg;
  }

  async function submitPlay(placements) {
    setControlsEnabled(false);
    resultEl.innerHTML = '<p class="hint">Scoring...</p>';
    try {
      const response = await fetch('/api/bestplay.php?action=score', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          board: Object.values(board),
          rack,
          placements,
        }),
      });
      const data = await response.json();
      if (!response.ok) {
        showResultError(data.error || 'Something went wrong.');
        return;
      }
      resultEl.innerHTML = buildPlayMessage(data);
      if (data.valid) {
        placements.forEach((p) => {
          board[`${p.r},${p.c}`] = { r: p.r, c: p.c, letter: p.letter, blank: p.blank };
        });
        resetTurn();
        render();
      }
    } catch (err) {
      showResultError('Could not reach the server. Try again.');
    } finally {
      setControlsEnabled(true);
    }
  }

  async function newBoard() {
    setControlsEnabled(false);
    resultEl.innerHTML = '<p class="hint">Dealing a new board...</p>';
    try {
      const response = await fetch('/api/bestplay.php?action=new');
      const data = await response.json();
      if (!response.ok) {
        showResultError(data.error || 'Could not deal a new board.');
        return;
      }
      board = {};
      data.board.forEach((t) => { board[`${t.r},${t.c}`] = t; });
      rack = data.rack;
      resetTurn();
      resultEl.innerHTML = '';
      render();
    } catch (err) {
      showResultError('Could not reach the server. Try again.');
    } finally {
      setControlsEnabled(true);
    }
  }

  boardEl.addEventListener('click', (e) => {
    const btn = e.target.closest('button.cell');
    if (!btn) return;
    onCellClick(parseInt(btn.dataset.r, 10), parseInt(btn.dataset.c, 10));
  });

  rackEl.addEventListener('click', (e) => {
    const btn = e.target.closest('button.tile');
    if (!btn) return;
    onRackTileClick(parseInt(btn.dataset.i, 10));
  });

  blankPickerEl.addEventListener('click', (e) => {
    const btn = e.target.closest('button.blank-letter-btn');
    if (!btn || pendingBlankIndex === null) return;
    placeTileAt(pendingBlankIndex, btn.dataset.letter, true);
  });

  modeBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      if (btn.classList.contains('active') || turnLocked()) return;
      mode = btn.dataset.mode;
      updateModeUI();
      renderRack();
    });
  });

  dirBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      if (btn.classList.contains('active') || turnLocked()) return;
      direction = btn.dataset.dir;
      if (selectedStart) {
        cursor = nextEmptyFrom(selectedStart.r, selectedStart.c, direction);
      }
      updateModeUI();
    });
  });

  typeForm.addEventListener('submit', (e) => {
    e.preventDefault();
    if (!selectedStart) {
      showResultError('Tap a square on the board to choose where to start.');
      return;
    }
    const word = typeInput.value.trim();
    if (!word) {
      return;
    }
    const built = buildPlacementsFromTypedWord(word);
    if (built.error) {
      showResultError(built.error);
      return;
    }
    submitPlay(built.placements);
  });

  doneBtn.addEventListener('click', () => {
    if (!selectedStart) {
      showResultError('Tap a square on the board to choose where to start.');
      return;
    }
    if (pendingPlacements.length === 0) {
      showResultError('Place at least one tile first.');
      return;
    }
    submitPlay(pendingPlacements.slice());
  });

  clearBtn.addEventListener('click', () => {
    resetTurn();
    render();
  });

  newBoardBtn.addEventListener('click', newBoard);

  newBoard();
})();
