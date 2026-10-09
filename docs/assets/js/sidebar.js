'use strict';

// Keep the current page in view in the documentation menu: scroll the
// sidebar so the active entry sits near the middle instead of leaving the
// menu at the top on deep pages.

(function () {
  var sidebar = document.getElementById('site-sidebar');
  if (!sidebar) {
    return;
  }
  var active = sidebar.querySelector('a[aria-current="page"]');
  if (!active) {
    return;
  }
  var box = sidebar.getBoundingClientRect();
  var item = active.getBoundingClientRect();
  sidebar.scrollTop += item.top - box.top - (sidebar.clientHeight - item.height) / 2;
})();
