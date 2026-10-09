'use strict';

// Navigation behavior: the mobile sidebar drawer, the on-this-page list
// built from the content headings, and the per-heading anchor links.

(function () {
  var toggle = document.getElementById('sidebar-toggle');
  var sidebar = document.getElementById('site-sidebar');
  if (!toggle || !sidebar || !window.matchMedia) {
    return;
  }

  // 1024px matches the stylesheet breakpoint; a px breakpoint keeps the
  // drawer choice tied to the real viewport, whatever the user's font
  // size preference is.
  var desktop = window.matchMedia('(min-width: 1024px)');
  var overlay = null;

  function isOpen() {
    return sidebar.classList.contains('open');
  }

  function setHidden(hidden) {
    // Drawer semantics only exist below the desktop breakpoint; the same
    // element is a static sidebar above it and stays exposed there.
    if (hidden) {
      sidebar.setAttribute('aria-hidden', 'true');
      if ('inert' in sidebar) {
        sidebar.inert = true;
      }
    } else {
      sidebar.removeAttribute('aria-hidden');
      if ('inert' in sidebar) {
        sidebar.inert = false;
      }
    }
  }

  function openDrawer() {
    if (isOpen()) {
      return;
    }
    setHidden(false);
    sidebar.classList.add('open');
    document.body.style.overflow = 'hidden';
    toggle.setAttribute('aria-expanded', 'true');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.className = 'drawer-overlay';
      // Minimal fallback so the overlay still blocks the page before the
      // stylesheet ships its own .drawer-overlay styling.
      overlay.style.position = 'fixed';
      overlay.style.inset = '0';
      overlay.addEventListener('click', function () { closeDrawer(true); });
      document.body.appendChild(overlay);
    }
    var first = focusables()[0];
    if (first) {
      first.focus();
    } else {
      sidebar.setAttribute('tabindex', '-1');
      sidebar.focus();
    }
  }

  // While the drawer is open, Tab stays inside it: the scrim hides the page
  // behind, so focus must not walk into content the user cannot see.
  function focusables() {
    return Array.prototype.filter.call(
      sidebar.querySelectorAll('button, a[href]'),
      function (el) {
        return el.offsetParent !== null || el === document.activeElement;
      }
    );
  }

  sidebar.addEventListener('keydown', function (event) {
    if (event.key !== 'Tab' || !isOpen() || desktop.matches) {
      return;
    }
    var items = focusables();
    if (items.length === 0) {
      return;
    }
    var first = items[0];
    var last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  function closeDrawer(returnFocus) {
    if (!isOpen()) {
      return;
    }
    sidebar.classList.remove('open');
    document.body.style.overflow = '';
    toggle.setAttribute('aria-expanded', 'false');
    if (overlay) {
      overlay.remove();
      overlay = null;
    }
    setHidden(!desktop.matches);
    if (returnFocus) {
      toggle.focus();
    }
  }

  toggle.addEventListener('click', function () {
    if (isOpen()) {
      closeDrawer(true);
    } else {
      openDrawer();
    }
  });

  var closeButton = document.getElementById('sidebar-close');
  if (closeButton) {
    closeButton.addEventListener('click', function () {
      closeDrawer(true);
    });
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && isOpen()) {
      closeDrawer(true);
    }
  });

  function onBreakpoint(event) {
    if (event.matches) {
      closeDrawer(false);
    } else {
      setHidden(!isOpen());
    }
  }

  if (typeof desktop.addEventListener === 'function') {
    desktop.addEventListener('change', onBreakpoint);
  } else if (typeof desktop.addListener === 'function') {
    desktop.addListener(onBreakpoint);
  }

  setHidden(!desktop.matches);
})();

(function () {
  var nav = document.getElementById('toc-nav');
  var main = document.getElementById('main');
  if (!nav || !main) {
    return;
  }

  var headings = [];
  main.querySelectorAll('h2, h3').forEach(function (heading) {
    if (heading.closest('.terminal, [data-toc-skip]')) {
      return;
    }
    headings.push(heading);
  });

  if (headings.length === 0) {
    var emptyAside = nav.closest('aside');
    if (emptyAside) {
      emptyAside.hidden = true;
    }
    return;
  }

  var usedIds = Array.prototype.map.call(document.querySelectorAll('[id]'), function (el) {
    return el.id;
  });

  function slug(text) {
    var base = text.trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'section';
    var candidate = base;
    var n = 2;
    while (usedIds.indexOf(candidate) !== -1) {
      candidate = base + '-' + n;
      n += 1;
    }
    usedIds.push(candidate);
    return candidate;
  }

  headings.forEach(function (heading) {
    if (!heading.id) {
      heading.id = slug(heading.textContent);
    }
  });

  // Build the list before appending the anchor glyphs, so link labels only
  // carry the heading text.
  var list = document.createElement('ul');
  var lastTop = null;
  var subList = null;

  headings.forEach(function (heading) {
    var link = document.createElement('a');
    link.href = '#' + heading.id;
    link.textContent = heading.textContent.trim();
    var item = document.createElement('li');
    item.appendChild(link);
    if (heading.tagName === 'H2') {
      lastTop = item;
      subList = null;
      list.appendChild(item);
    } else if (lastTop) {
      if (!subList) {
        subList = document.createElement('ul');
        lastTop.appendChild(subList);
      }
      subList.appendChild(item);
    } else {
      list.appendChild(item);
    }
  });
  nav.appendChild(list);

  headings.forEach(function (heading) {
    var anchor = document.createElement('a');
    anchor.className = 'anchor';
    anchor.href = '#' + heading.id;
    anchor.setAttribute('aria-label', 'Link to this section');
    anchor.textContent = '#';
    heading.appendChild(anchor);
  });

  if (!('IntersectionObserver' in window)) {
    return;
  }

  var links = Array.prototype.slice.call(nav.querySelectorAll('a'));
  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (!entry.isIntersecting) {
        return;
      }
      var index = headings.indexOf(entry.target);
      if (index === -1) {
        return;
      }
      links.forEach(function (link) {
        link.removeAttribute('aria-current');
      });
      links[index].setAttribute('aria-current', 'location');
    });
  }, { rootMargin: '-80px 0px -70% 0px' });

  headings.forEach(function (heading) {
    observer.observe(heading);
  });
})();
