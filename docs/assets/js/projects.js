/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Row filter for the projects directory. The deep pages' filter narrows
 * heading blocks; this one narrows rows — a directory is searched by
 * name, not read by section. The count says how much of the directory
 * is left, the empty line says it in words, and Escape gives the whole
 * list back. Without the input the page is exactly what it was.
 */
(function () {
  'use strict';

  var input = document.querySelector('.page-filter input');
  if (!input || !document.querySelector('.projects-index')) {
    return;
  }

  var rows = Array.prototype.slice.call(document.querySelectorAll('.projects-index > li'));
  var count = document.querySelector('.page-count');
  var empty = document.querySelector('.page-empty');
  var total = rows.length;

  var apply = function () {
    var query = input.value.trim().toLowerCase();
    var shown = 0;
    rows.forEach(function (row) {
      // The directory narrows by the project's name, not by the whole
      // row: a PHP version is not a filter, and every row carries one.
      var name = row.querySelector('.projects-index-name');
      var match = !query || (name && name.textContent.toLowerCase().indexOf(query) !== -1);
      row.hidden = !match;
      shown += match ? 1 : 0;
    });
    if (count) {
      count.textContent = query ? shown + ' of ' + total + ' projects' : total + ' projects';
    }
    if (empty) {
      empty.hidden = shown !== 0;
    }
  };

  input.addEventListener('input', apply);
  input.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && input.value) {
      input.value = '';
      apply();
    }
  });

  apply();
})();
