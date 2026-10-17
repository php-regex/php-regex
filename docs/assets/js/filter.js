/* Section filter for the rule reference.
 *
 * The page lists every lint rule under twenty headings — fifty thousand
 * pixels of scroll for a reader who arrives with one identifier. The
 * filter narrows it in place: each h3 rule block (heading plus content
 * up to the next heading) is shown or hidden by a case-insensitive
 * substring match over the block's own text, a section's heading stays
 * as long as any of its rules (or its own intro) matches, and a live
 * count says how much of the page is left. Everything is progressive:
 * without the input the page is exactly what it was.
 *
 * Visibility is a class, not the hidden attribute: the stylesheet gives
 * `main table` a display of its own, and an author rule beats the
 * hidden attribute's user-agent rule whatever the specificity says.
 */
(function () {
  'use strict';

  var input = document.querySelector('.rules-filter input');
  if (!input) return;

  var main = document.getElementById('main');
  var count = document.querySelector('.rules-count');
  var empty = document.querySelector('.rules-empty');
  var HIDDEN = 'rules-hidden';

  /* A block is a heading plus every following sibling up to the next
     heading of any level. Anything before the first heading (title,
     lede, table of contents, filter row) is the preamble and never
     filters. An h3 block carries its section's heading text too, so a
     query naming a section keeps every rule under it. */
  var blocks = [];
  var current = null;
  var lastH2 = null;

  Array.prototype.forEach.call(main.children, function (node) {
    var tag = node.tagName;
    if (tag === 'H2' || tag === 'H3') {
      current = { heading: node, nodes: [node], text: node.textContent };
      if (tag === 'H2') {
        lastH2 = node;
      } else if (lastH2) {
        current.section = lastH2;
        current.text += ' ' + lastH2.textContent;
      }
      blocks.push(current);
    } else if (current) {
      current.nodes.push(node);
      current.text += ' ' + node.textContent;
    }
  });

  var rules = blocks.filter(function (b) {
    return b.heading.tagName === 'H3';
  });

  function apply(query) {
    var q = query.trim().toLowerCase();
    var shown = 0;

    blocks.forEach(function (block) {
      var match = !q || block.text.toLowerCase().indexOf(q) !== -1;
      block.nodes.forEach(function (node) {
        node.classList.toggle(HIDDEN, !match);
      });
      if (match && block.heading.tagName === 'H3') shown++;
    });

    /* A section heading survives while its own block or any of its
       rules is still visible. */
    blocks.forEach(function (block) {
      if (block.heading.tagName !== 'H2') return;
      var hasVisible = !block.heading.classList.contains(HIDDEN);
      if (!hasVisible) {
        hasVisible = rules.some(function (r) {
          return r.section === block.heading &&
            !r.heading.classList.contains(HIDDEN);
        });
      }
      block.heading.classList.toggle(HIDDEN, !hasVisible);
    });

    if (count) {
      count.textContent = q ? shown + ' of ' + rules.length + ' rules' : '';
    }
    if (empty) {
      empty.hidden = shown !== 0 || !q;
    }
  }

  input.addEventListener('input', function () {
    apply(input.value);
  });
  /* The native clear control of a search field dispatches `search` on the
     engines where it does not dispatch `input`. */
  input.addEventListener('search', function () {
    apply(input.value);
  });
  /* Firefox's search fields carry no clear control, so Escape clears for
     everyone what the native button clears where it exists. */
  input.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && input.value !== '') {
      input.value = '';
      apply('');
      event.preventDefault();
    }
  });
})();
