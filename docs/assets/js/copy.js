'use strict';

// Copy buttons for code blocks in the main content (and install blocks).
// The outcome is announced through a page-level polite live region that is
// dedicated to this feature.

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
    var container = code.closest('pre') || code.closest('.install');
    if (!container || container.querySelector('.copy-btn')) {
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
})();
