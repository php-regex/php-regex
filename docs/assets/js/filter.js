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
 * into per-item rules, h2 where the sections are the items (cheatsheets,
 * cookbooks, symptom guides). The counted noun comes from the input's
 * data-unit attribute.
 *
 * An index of pages (the rule reference) names in data-items the rows it
 * lists, one per page: those are filtered instead, by their own text, and
 * a section hides with its last row.
 *
 * Visibility is a class, not the hidden attribute: the stylesheet gives
 * `main table` a display of its own, and an author rule beats the
 * hidden attribute's user-agent rule whatever the specificity says.
 */
(function () {
  'use strict';

  var input = document.querySelector('.page-filter input');
  /* The section filter is for pages whose unit is a heading block, named
     by data-unit; a page that filters another unit (the projects
     directory filters rows) owns its input alone. */
  if (!input || !input.hasAttribute('data-unit')) return;

  var main = document.getElementById('main');
  var count = document.querySelector('.page-count');
  var empty = document.querySelector('.page-empty');
  var HIDDEN = 'page-filter-hidden';

  function listen(apply) {
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
  }

  var rowsSelector = input.dataset.items;
  if (rowsSelector) {
    /* The rows the page lists, each under the h2 it stands in: a row is
       shown or hidden by its own text, a section whose every row is hidden
       hides with them, and a section with no row hides under any query. */
    var sections = [];
    var sectionOfChild = [];
    var section = null;
    Array.prototype.forEach.call(main.children, function (node, index) {
      if (node.tagName === 'H2') {
        section = { nodes: [], shown: 0 };
        sections.push(section);
      }
      sectionOfChild[index] = section;
      if (section) {
        section.nodes.push(node);
      }
    });
    var rows = Array.prototype.map.call(main.querySelectorAll(rowsSelector), function (row) {
      var top = row;
      while (top.parentNode !== main) {
        top = top.parentNode;
      }
      return { node: row, section: sectionOfChild[Array.prototype.indexOf.call(main.children, top)] };
    });
    listen(function (query) {
      var q = query.trim().toLowerCase();
      var shown = 0;
      sections.forEach(function (one) {
        one.shown = 0;
      });
      rows.forEach(function (row) {
        var match = !q || row.node.textContent.toLowerCase().indexOf(q) !== -1;
        row.node.classList.toggle(HIDDEN, !match);
        if (match) {
          shown += 1;
          row.section.shown += 1;
        }
      });
      sections.forEach(function (one) {
        var hide = !!q && one.shown === 0;
        one.nodes.forEach(function (node) {
          node.classList.toggle(HIDDEN, hide);
        });
      });
      if (count) {
        count.textContent = q ? shown + ' of ' + rows.length + ' ' + (input.dataset.unit || 'rows') : '';
      }
      if (empty) {
        empty.hidden = shown !== 0 || !q;
      }
    });
    return;
  }

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
      /* A table of contents contains the name of every section, so it
         matches every query and answers none of them: while a query is
         active it is noise wearing the first screen. Tagged blocks never
         match, and the empty query brings them back. */
      if (node.classList.contains('filter-toc')) {
        current.toc = true;
      }
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
      return !q || (!block.toc && block.text.toLowerCase().indexOf(q) !== -1);
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

  listen(apply);
})();
