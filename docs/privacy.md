---
title: Privacy
head_title: "Privacy: what php-regex.com measures"
description: "What php-regex.com measures, and what it never sees: Google Analytics counts visits with its cookies; nothing typed in the playground leaves your browser."
permalink: /privacy/
---

# Privacy

**Nothing you type leaves your browser.** The playground runs PHP inside your browser: the pattern and the text you test are never sent anywhere. A link you share carries them after the `#`, the part of an address browsers never send to a server. The search of the documentation is the one place where typed words leave: the section [What the search sends](#what-the-search-sends) says which.

**Google Analytics, with its cookies.** The site counts visits with the standard Google Analytics tag, which stores two cookies on your device, `_ga` and `_ga_QSYBM5HXPS`, for up to two years, to tell one visitor from another. No banner asks first. Blocking cookies or the tag in your browser, or [Google's opt-out add-on](https://tools.google.com/dlpage/gaoptout), turns the measure off, and the site works the same without it. For each page, a request reaches Google with the page address up to the `#`, never what you type: the tag leaves out what follows the `#`, where the playground keeps your pattern and your text. The one exception is a shared search address, told with the search. An automated browser is not measured: one a script drives, or a crawler that runs the page. The tag is not configured for either, so a visitor is what the figures count.

Besides page views, it counts a few gestures in the playground and around the documentation, with the details named here and nothing else:

- `run`: the Run button or <kbd>Ctrl</kbd>/<kbd>⌘</kbd> <kbd>Enter</kbd>, with `trigger` (button or keyboard)
- `example_loaded`: an example opened, with its `example_name`
- `finding_opened`: a finding opened, with its `finding_id`
- `share_clicked`: a finding's link copied or shared, with its `finding_id` and the `method` (copy or the device's share sheet)
- `voice_toggled`: the tree switched to plain English or back, with the `voice` chosen
- `version_changed`: another PHP version picked, with that `php_version`
- `install_copied`: an install command copied, with the Composer `package` it names
- `github_clicked`: a link to the project on GitHub followed, with the `kind` of page it opens: the repository, its issues, or another
- `view_search_results`: a result of the documentation's search opened, with the `search_term`, the words that were looked for

None of them carries a pattern or a text of the playground. The search alone sends its words, as the next section says.

## What the search sends

When you open a result of the documentation's search, the words you search for in the documentation go to Google Analytics, as the `search_term` of the `view_search_results` event, kept to 150 characters. They tell what the documentation is looked for, so that it can be written for it. A search whose result you do not open sends nothing.

While the search dialog is open, the words also stand in the address of the page, after `?q=`, so that a search can be shared. A page opened from such a shared address carries them in the address the tag reports, up to the `#`.

**Retention and the rest.** Google Analytics keeps event-level data 14 months, then deletes it; its aggregated reports (how many visits, how many runs) are not bound by that setting. Google Signals is off, so nothing is joined to a Google account. The site has no account, no form and no newsletter.

The playground downloads PHP, compiled to WebAssembly, from cdn.jsdelivr.net the first time you ask it for an answer: typing a pattern or a text, pressing Run, opening an example or a finding. PHP 8.6 comes from this site instead. The step trace downloads PCRE2's own module when you open its tab. jsDelivr sees those requests like any download.

Questions: [open an issue](https://github.com/php-regex/php-regex/issues).
