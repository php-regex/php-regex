'use strict';

// Navigation behavior: the mobile sidebar drawer, the on-this-page list
// built from the content headings, and the per-heading anchor links.

(function () {
  // The documentation rail. A page that carries no rail (the landing and
  // error layouts) has no drawer either; the script stands down whole.
  var toggle = document.getElementById('sidebar-toggle');
  var sidebar = document.getElementById('site-sidebar');
  if (!sidebar) {
    return;
  }

  // 1024px matches the stylesheet breakpoint; a px breakpoint keeps the
  // drawer choice tied to the real viewport, whatever the user's font
  // size preference is. Where matchMedia is missing the rail is simply
  // always hidden and always expanded: there is no drawer to run.
  var desktop = window.matchMedia ? window.matchMedia('(min-width: 1024px)') : null;
  var overlay = null;

  // Opening the drawer owns a history entry, so the Back button closes
  // what was opened instead of leaving the page. The entry carries a
  // marker: landing back on it (Back then Forward) reopens the drawer.
  var ownsEntry = false;

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

  function openDrawer(push) {
    if (isOpen()) {
      return;
    }
    if (push !== false) {
      try {
        window.history.pushState({ overlay: 'drawer' }, '');
        ownsEntry = true;
      } catch (e) {}
    }
    setHidden(false);
    sidebar.classList.add('open');
    document.body.classList.add('drawer-open');
    toggle.setAttribute('aria-expanded', 'true');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.className = 'drawer-overlay';
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
    document.body.classList.remove('drawer-open');
    toggle.setAttribute('aria-expanded', 'false');
    if (overlay) {
      overlay.remove();
      overlay = null;
    }
    setHidden(!desktop.matches);
    if (returnFocus) {
      toggle.focus();
    }
    if (ownsEntry) {
      ownsEntry = false;
      window.history.back();
    }
  }

  window.addEventListener('popstate', function (event) {
    var state = event.state;
    if (state && state.overlay) {
      // Landing on an overlay entry (the drawer's own, or the search
      // dialog's stacked above it) never closes the drawer: its entry is
      // still in the chain. Restore only when the entry is the drawer's.
      if (state.overlay === 'drawer') {
        ownsEntry = true;
        if (!isOpen() && !desktop.matches) {
          openDrawer(false);
        }
      }
      return;
    }
    if (isOpen()) {
      ownsEntry = false;
      closeDrawer(false);
    }
  });

  // The header's own toggle is the control the reader sees; a page that
  // carries the rail without it (the error layout) has no drawer to open.
  if (toggle) {
    toggle.addEventListener('click', function () {
      if (isOpen()) {
        closeDrawer(true);
      } else {
        openDrawer();
      }
    });
  }

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

  if (desktop) {
    if (typeof desktop.addEventListener === 'function') {
      desktop.addEventListener('change', onBreakpoint);
    } else if (typeof desktop.addListener === 'function') {
      desktop.addListener(onBreakpoint);
    }
    setHidden(!desktop.matches);
  }
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

  // A fragment jump should also move the keyboard with it: the element the
  // reader lands on takes focus, so Tab continues from the place on screen
  // instead of from the link that was followed. The scripted focus draws
  // no ring for pointer users; keyboard users keep theirs.
  function focusTarget() {
    var id = window.location.hash.slice(1);
    if (!id) {
      return;
    }
    var el = document.getElementById(id);
    if (!el || !main.contains(el)) {
      return;
    }
    el.setAttribute('tabindex', '-1');
    el.focus({ preventScroll: true });
  }

  window.addEventListener('hashchange', focusTarget);
  focusTarget();

  if (!('IntersectionObserver' in window)) {
    return;
  }

  var links = Array.prototype.slice.call(nav.querySelectorAll('a'));
  var rail = nav.closest('.toc');
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
      var current = links[index];
      current.setAttribute('aria-current', 'location');
      // A long page overflows the rail: nudge the rail so the marked entry
      // stays inside it. Offset arithmetic scrolls the rail only, never the
      // page behind it, and moves the minimum distance that shows the entry.
      if (rail) {
        var railBox = rail.getBoundingClientRect();
        var linkBox = current.getBoundingClientRect();
        if (linkBox.top < railBox.top) {
          rail.scrollTop += linkBox.top - railBox.top;
        } else if (linkBox.bottom > railBox.bottom) {
          rail.scrollTop += linkBox.bottom - railBox.bottom;
        }
      }
    });
  }, { rootMargin: '-80px 0px -70% 0px' });

  headings.forEach(function (heading) {
    observer.observe(heading);
  });
})();
