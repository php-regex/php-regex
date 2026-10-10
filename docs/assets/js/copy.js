'use strict';

// Copy buttons for code blocks. They are opt-in: install blocks always
// carry one (the command is the whole reason the reader is here), any
// other block only when its fence is marked with a data-copy attribute
// (kramdown IAL `{: data-copy="" }`, the same convention as data-file
// captions). The outcome is announced through a page-level polite live
// region that is dedicated to this feature.

(function () {
  var blocks = document.querySelectorAll('main pre > code, .install code');
  if (blocks.length === 0) {
    return;
  }

  var status = document.createElement('p');
  status.className = 'visually-hidden';
  status.setAttribute('role', 'status');
  status.setAttribute('aria-live', 'polite');
  document.body.appendChild(status);


  blocks.forEach(function (code) {
    // The chip is a child of the block, never of the scrolling pre. Inside
    // an install panel that is the panel itself — the panel reserves four
    // rem of padding for the chip, the pre inside it has none. And when
    // kramdown wrapped the fence, the wrapper is the block: a mask on the
    // pre (see the cut-edge rules in the stylesheet) would fade a chip
    // that lived inside it.
    var container = code.closest('.install') || code.closest('.highlighter-rouge') || code.closest('pre');
    if (!container || container.querySelector('.copy-btn')) {
      return;
    }
    // Only install blocks and explicitly marked fences earn a button;
    // the attribute may sit on the highlighting wrapper or the pre.
    if (!container.closest('.install') && !container.closest('[data-copy]')) {
      return;
    }
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'copy-btn';
    button.setAttribute('aria-label', 'Copy code');
    button.textContent = 'Copy';
    button.addEventListener('click', function () {
      run(code, button);
    });
    container.classList.add('has-copy');
    container.appendChild(button);
  });

  function run(code, button) {
    var text = code.innerText;
    var done = function (copied) {
      button.textContent = copied ? 'Copied' : 'Select manually';
      if (copied) {
        button.setAttribute('data-copied', '');
        announce('Copied to clipboard');
      } else {
        announce('Copy is unavailable, select the code manually.');
      }
      // One reset timer per button: copying a second block must not cut
      // the first button's "Copied" state short.
      window.clearTimeout(button._resetTimer);
      button._resetTimer = window.setTimeout(function () {
        button.textContent = 'Copy';
        button.removeAttribute('data-copied');
      }, 2000);
    };

    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      // Non-secure contexts and denied permissions reject the promise.
      navigator.clipboard.writeText(text).then(function () {
        done(true);
      }, function () {
        done(false);
      });
    } else {
      done(false);
    }
  }

  function announce(message) {
    status.textContent = '';
    window.setTimeout(function () {
      status.textContent = message;
    }, 50);
  }

  // "Copy as Markdown": fetch the page's own source and put it on the
  // clipboard. The markdown sources are served next to their pages.
  document.addEventListener('click', function (event) {
    var button = event.target.closest('.copy-source');
    if (!button) {
      return;
    }
    var source = button.getAttribute('data-source');
    fetch(source).then(function (response) {
      if (!response.ok) {
        throw new Error('source HTTP ' + response.status);
      }
      return response.text();
    }).then(function (text) {
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        return navigator.clipboard.writeText(text).then(function () {
          announce('Page copied as Markdown.');
        });
      }
      throw new Error('clipboard unavailable');
    }).catch(function () {
      announce('Copy is unavailable, the source is at ' + source + '.');
    });
  });
})();
