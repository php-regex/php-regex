/* Section filter for the deep reference pages.
 *
 * The pages that list every rule, diagnostic, recipe, or symptom run ten
 * to fifty thousand pixels tall — a reader arrives with one identifier
 * and one question. The filter narrows the page in place: each block
 * (a heading plus its content up to the next heading) is shown or hidden
 * by a case-insensitive substring match over the block's own text, a
 * section's heading stays as long as any of its subsections (or its own
 * intro) matches, and a live count says how much of the page is left.
 * Everything is progressive: without the input the page is exactly what
 * it was.
 *
 * The unit of the page is its finest heading: h3 where sections break
 * into per-item rules (the rule reference), h2 where the sections are
 * the items (cheatsheets, cookbooks, symptom guides). The counted noun
 * comes from the input's data-unit attribute.
 *
 * Visibility is a class, not the hidden attribute: the stylesheet gives
 * `main table` a display of its own, and an author rule beats the
 * hidden attribute's user-agent rule whatever the specificity says.
 */
(function () {
  'use strict';

  var input = document.querySelector('.page-filter input');
  if (!input) return;

  var main = document.getElementById('main');
  var count = document.querySelector('.page-count');
  var empty = document.querySelector('.page-empty');
  var HIDDEN = 'page-filter-hidden';

  /* A block is a heading plus every following sibling up to the next
     heading of any level. Anything before the first heading (title,
     lede, filter row) is the preamble and never filters. An h3 block
     carries its section's heading text too, so a query naming a section
     keeps every item under it. */
  var blocks = [];
  var current = null;
  var lastH2 = null;

  Array.prototype.forEach.call(main.children, function (node) {
    var tag = node.tagName;
    if (tag === 'H2' || tag === 'H3') {
      current = { heading: node, nodes: [node], text: node.textContent };
      if (tag === 'H2') {
        lastH2 = blocks.length;
      } else if (lastH2 !== null) {
        current.section = lastH2;
        current.text += ' ' + blocks[lastH2].heading.textContent;
      }
      blocks.push(current);
    } else if (current) {
      current.nodes.push(node);
      current.text += ' ' + node.textContent;
    }
  });

  /* The unit is the finest heading the page actually has, unless the
     page says otherwise: a mixed reference - sections of tables beside
     sections of subsections - counts every heading, because its answers
     live at both levels. */
  var h3Units = blocks.filter(function (b) {
    return b.heading.tagName === 'H3';
  });
  var anyLevel = input.dataset.unitLevel === 'any';
  var units = anyLevel ? blocks : (h3Units.length ? h3Units : blocks);
  var noun = input.dataset.unit || (h3Units.length ? 'rules' : 'sections');

  function apply(query) {
    var q = query.trim().toLowerCase();
    var i;

    /* Direct hits: the query's own matches, before any propagation. */
    var direct = blocks.map(function (block) {
      return !q || block.text.toLowerCase().indexOf(q) !== -1;
    });
    var match = direct.slice();

    if (h3Units.length) {
      /* A section whose own block matches reveals everything under it,
         up to the next section: the reader asked for that section, and
         a heading alone above hidden content answers nobody. */
      var revealed = true;
      for (i = 0; i < blocks.length; i++) {
        if (blocks[i].heading.tagName === 'H2') {
          revealed = match[i];
        } else if (revealed) {
          match[i] = true;
        }
      }

      /* An item that matches keeps its parent section's heading alive. */
      blocks.forEach(function (b, idx) {
        if (b.heading.tagName === 'H3' && match[idx] && b.section !== undefined) {
          match[b.section] = true;
        }
      });
    }

    /* The count speaks of the query's direct hits; what propagation
       revealed is context, not results. */
    var shown = 0;
    blocks.forEach(function (block, idx) {
      block.nodes.forEach(function (node) {
        node.classList.toggle(HIDDEN, !match[idx]);
      });
      if (direct[idx] && units.indexOf(block) !== -1) shown++;
    });

    if (count) {
      count.textContent = q ? shown + ' of ' + units.length + ' ' + noun : '';
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
