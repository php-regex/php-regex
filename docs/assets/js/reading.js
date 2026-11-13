'use strict';

// Two reading aids that need no markup: a way back to the top of a long
// page, and a tell on the tables that scroll sideways. Both are opt-out by
// nature - the button hides itself at the top of the page, and a table is
// only marked once it really does overflow.

(function () {
  // ---- Support marks -----------------------------------------------------
  // A support matrix answers one question per cell - Yes, Partial or No -
  // and a column of the same three words in body text reads as prose. Each
  // answer becomes a mark whose glyph carries the answer as much as its
  // colour does (a full dot, a half dot, an empty ring), and the
  // qualifier after it - "(possessive only)" - moves to a quiet line of its
  // own. The words stay the words: without the script the table reads as
  // written. This runs before the wide-box tell below measures the tables.
  var STATES = { Yes: 'yes', Partial: 'partial', No: 'no' };

  function mark(word) {
    var badge = document.createElement('span');
    badge.className = 'support support-' + STATES[word];
    badge.textContent = word;
    return badge;
  }

  function trimText(node, pattern) {
    if (node && node.nodeType === 3) {
      node.nodeValue = node.nodeValue.replace(pattern, '');
    }
  }

  var cells = document.querySelectorAll('table.support-matrix td:not(:first-child)');
  Array.prototype.forEach.call(cells, function (cell) {
    var first = cell.firstChild;
    if (!first || first.nodeType !== 3) {
      return;
    }
    var answer = /^\s*(Yes|Partial|No)\b\s*/.exec(first.nodeValue);
    if (!answer) {
      return;
    }
    first.nodeValue = first.nodeValue.slice(answer[0].length);
    trimText(cell.firstChild, /^\(/);
    trimText(cell.lastChild, /\)\s*$/);

    var note = document.createElement('span');
    note.className = 'support-note';
    while (cell.firstChild) {
      note.appendChild(cell.firstChild);
    }
    cell.appendChild(mark(answer[1]));
    if (note.textContent.trim() !== '') {
      cell.appendChild(note);
    }
    cell.setAttribute('data-support', STATES[answer[1]]);
  });

  // The legend speaks with the same marks: "Yes: fully supported" shows
  // the mark the reader will meet in the table.
  var keys = document.querySelectorAll('.support-legend li strong:first-child');
  Array.prototype.forEach.call(keys, function (key) {
    var word = key.textContent.trim();
    if (!STATES.hasOwnProperty(word)) {
      return;
    }
    trimText(key.nextSibling, /^:\s*/);
    key.parentNode.replaceChild(mark(word), key);
  });
})();

(function () {
  // ---- Back to top -------------------------------------------------------
  // The sidebar and the on-this-page rail serve the long pages above
  // 1280px; below that neither exists, so a ten-screen chapter needs its
  // own way up. The button appears past one viewport of scrolling and
  // leaves again at the top.
  var button = document.createElement('button');
  button.type = 'button';
  button.className = 'to-top';
  button.setAttribute('aria-label', 'Back to top');
  button.hidden = true;
  // Lucide's arrow, vendored under /assets/icons/lucide/ as every icon of
  // the site is.
  var ICONS = { top: '<path d="m5 12 7-7 7 7" /><path d="M12 19V5" />' };
  var parsed = new DOMParser().parseFromString('<svg xmlns="http://www.w3.org/2000/svg">' + ICONS.top + '</svg>', 'image/svg+xml');
  var icon = document.importNode(parsed.documentElement, true);
  icon.setAttribute('class', 'icon');
  icon.setAttribute('viewBox', '0 0 24 24');
  icon.setAttribute('aria-hidden', 'true');
  icon.setAttribute('focusable', 'false');
  button.appendChild(icon);
  document.body.appendChild(button);

  var shown = false;

  function place() {
    var wanted = window.scrollY > window.innerHeight * 0.9;
    if (wanted === shown) {
      return;
    }
    shown = wanted;
    button.hidden = !wanted;
  }

  button.addEventListener('click', function () {
    var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top: 0, behavior: still ? 'auto' : 'smooth' });
    // The button hides itself at the top, so the keyboard must travel with
    // the page: focus moves to the content, and Tab continues from the top
    // instead of from wherever the reader was when they scrolled down.
    var main = document.getElementById('main');
    if (main) {
      main.focus({ preventScroll: true });
    }
  });

  window.addEventListener('scroll', place, { passive: true });
  window.addEventListener('resize', place);
  place();

  // ---- Wide boxes --------------------------------------------------------
  // A table wider than its column scrolls, and so does a code line longer
  // than its panel; a scroll box with no tell reads as a box that is
  // broken. Each box decides for itself whether it overflows, and only the
  // edge that is actually cut stays marked while it scrolls; the tell
  // itself is the cut-edge mask in the stylesheet, which dissolves
  // whatever the box paints toward whatever is behind it.
  var boxes = document.querySelectorAll('main table, main pre');

  Array.prototype.forEach.call(boxes, function (box) {
    function mark() {
      var room = box.scrollWidth - box.clientWidth;
      if (room <= 1) {
        box.removeAttribute('data-scroll');
        return;
      }
      var left = box.scrollLeft;
      if (left <= 1) {
        box.setAttribute('data-scroll', 'right');
      } else if (left >= room - 1) {
        box.setAttribute('data-scroll', 'left');
      } else {
        box.setAttribute('data-scroll', 'both');
      }
    }

    box.addEventListener('scroll', mark, { passive: true });
    window.addEventListener('resize', mark);
    mark();
  });
})();

(function () {
  var bar = document.querySelector('.reading-progress-bar');
  if (!bar) {
    return;
  }
  function update() {
    var doc = document.documentElement;
    var scrolled = doc.scrollTop || document.body.scrollTop;
    var total = (doc.scrollHeight || document.body.scrollHeight) - (window.innerHeight || doc.clientHeight);
    var pct = total > 0 ? Math.min(100, Math.max(0, (scrolled / total) * 100)) : 0;
    bar.style.width = pct + '%';
  }
  window.addEventListener('scroll', update, { passive: true });
  window.addEventListener('resize', update);
  update();
})();
