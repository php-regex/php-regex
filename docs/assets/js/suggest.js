'use strict';

// Suggestions on the 404 page: the address that failed to match is read
// from the location, the search index is fetched lazily (the same JSON
// the search dialog reads) and the closest pages are proposed. The search
// engine itself is not needed — closeness is scored by matching address
// segments against page URLs and titles. When the index cannot be
// fetched, the block stays hidden and the page stands complete.

(function () {
  var host = document.getElementById('error-suggest');
  var list = document.getElementById('error-suggest-list');
  if (!host || !list || !window.fetch) {
    return;
  }

  var decoder = document.createElement('textarea');

  // Index entries arrive as JSON with HTML-escaped titles; decode once so
  // a title renders as the text it is. Setting innerHTML on a detached
  // textarea never parses markup, so the text stays inert.
  function decode(value) {
    decoder.innerHTML = value;
    return decoder.value;
  }

  // Index URLs are absolute; the site path is what links and comparisons
  // want (dev serve and production hosts differ).
  function pathOf(url) {
    var match = /^https?:\/\/[^/]+(\/.*)$/.exec(url);
    return match ? match[1] : url;
  }

  function unescaped(text) {
    try {
      return decodeURIComponent(text);
    } catch (e) {
      return text;
    }
  }

  // The meaningful pieces of the failed address, lowercased.
  function segmentsOf(text) {
    var seen = Object.create(null);
    var segments = [];
    unescaped(text).toLowerCase().split(/[^a-z0-9]+/).forEach(function (piece) {
      if (piece.length > 1 && !seen[piece]) {
        seen[piece] = true;
        segments.push(piece);
      }
    });
    return segments;
  }

  // A page scores higher when an address segment names one of its own URL
  // segments exactly, then when the segment appears inside its URL or
  // title at all. Substring scoring on purpose: a page that nearly matches
  // still surfaces, and everything else falls through to the landmarks.
  function score(page, wanted) {
    var path = pathOf(page.url);
    var title = decode(page.title || path).toLowerCase();
    var total = 0;
    wanted.forEach(function (piece) {
      var exact = path.split('/').some(function (part) {
        return part === piece;
      });
      if (exact) {
        total += 3;
      } else if (path.indexOf(piece) !== -1) {
        total += 2;
      }
      if (title.indexOf(piece) !== -1) {
        total += 2;
      }
    });
    return total;
  }

  function show(pages, wanted) {
    if (wanted.length === 0) {
      return;
    }
    pages
      .map(function (page) {
        return { page: page, points: score(page, wanted) };
      })
      .filter(function (row) {
        return row.points > 0;
      })
      .sort(function (a, b) {
        return b.points - a.points || pathOf(a.page.url).length - pathOf(b.page.url).length;
      })
      .slice(0, 3)
      .forEach(function (row) {
        var page = row.page;
        var item = document.createElement('li');
        var link = document.createElement('a');
        link.href = pathOf(page.url);
        var section = page.section ? decode(page.section) : '';
        if (section) {
          var crumb = document.createElement('small');
          crumb.className = 'error-suggest-section';
          crumb.textContent = section + ' ›';
          link.appendChild(crumb);
          link.appendChild(document.createTextNode(' '));
        }
        link.appendChild(document.createTextNode(decode(page.title || pathOf(page.url))));
        item.appendChild(link);
        list.appendChild(item);
      });
    if (list.firstChild) {
      host.hidden = false;
    }
  }

  fetch('/search-index.json')
    .then(function (response) {
      if (!response.ok) {
        throw new Error('index HTTP ' + response.status);
      }
      return response.json();
    })
    .then(function (pages) {
      if (Array.isArray(pages)) {
        show(pages, segmentsOf(window.location.pathname));
      }
    })
    .catch(function () {
      // The index never arrived: no suggestions, no broken page.
    });
})();
