'use strict';

// Two reading aids that need no markup: a way back to the top of a long
// page, and a tell on the tables that scroll sideways. Both are opt-out by
// nature - the button hides itself at the top of the page, and a table is
// only marked once it really does overflow.

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
  button.innerHTML = '<svg class="icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M8 13V3M3.5 7.5 8 3l4.5 4.5"/></svg>';
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
  });

  window.addEventListener('scroll', place, { passive: true });
  window.addEventListener('resize', place);
  place();

  // ---- Wide tables -------------------------------------------------------
  // A table wider than its column scrolls, and a scroll box with no tell
  // reads as a table that is broken. Each table decides for itself whether
  // it overflows, and only the edge that is actually cut stays marked while
  // it scrolls; the stylesheet paints the veil from the attribute.
  var tables = document.querySelectorAll('main table');

  Array.prototype.forEach.call(tables, function (table) {
    function mark() {
      var room = table.scrollWidth - table.clientWidth;
      if (room <= 1) {
        table.removeAttribute('data-scroll');
        return;
      }
      var left = table.scrollLeft;
      if (left <= 1) {
        table.setAttribute('data-scroll', 'right');
      } else if (left >= room - 1) {
        table.setAttribute('data-scroll', 'left');
      } else {
        table.setAttribute('data-scroll', 'both');
      }
    }

    table.addEventListener('scroll', mark, { passive: true });
    window.addEventListener('resize', mark);
    mark();
  });
})();
