/* The thank-you rotation: every list marked data-shuffle (the sponsor frame
 * on pages without a spot of their own, the footer's "Backed by" strip) is
 * dealt in a fresh random order on each visit, so no company owns the first
 * place by the accident of the data file's order. Without the script the
 * lists keep that order: nothing is hidden, only unshuffled. */
(function () {
  'use strict';

  document.querySelectorAll('[data-shuffle]').forEach(function (list) {
    var items = Array.prototype.slice.call(list.children);
    for (var i = items.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var swap = items[i];
      items[i] = items[j];
      items[j] = swap;
    }
    items.forEach(function (item) {
      list.appendChild(item);
    });
  });
})();
