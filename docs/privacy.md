---
title: Privacy
description: "What php-regex.com measures, and what it never sees: Google Analytics counts visits with its cookies, and nothing you type in the playground leaves your browser."
permalink: /privacy/
---

# Privacy

**Nothing you type leaves your browser.** The playground runs PHP inside your browser: the pattern and the text you test are never sent anywhere. A link you share carries them after the `#`, the part of an address browsers never send to a server.

**Google Analytics, with its cookies.** The site counts visits with the standard Google Analytics tag, which stores two cookies on your device, `_ga` and `_ga_QSYBM5HXPS`, for up to two years, to tell one visitor from another. No banner asks first. Blocking cookies or the tag in your browser, or [Google's opt-out add-on](https://tools.google.com/dlpage/gaoptout), turns the measure off, and the site works the same without it. For each page, a request reaches Google with the page address up to the `#`, never what you type: the tag leaves out what follows the `#`, where the playground keeps your pattern and your text.

Besides page views, it counts a few gestures in the playground, with the details named here and nothing else:

- `run`: the Run button or <kbd>Ctrl</kbd>/<kbd>⌘</kbd> <kbd>Enter</kbd>, with `trigger` (button or keyboard)
- `example_loaded`: an example opened, with its `example_name`
- `finding_opened`: a finding opened, with its `finding_id`
- `share_clicked`: a finding's link copied or shared, with its `finding_id` and the `method` (copy or the device's share sheet)
- `voice_toggled`: the tree switched to plain English or back, with the `voice` chosen
- `version_changed`: another PHP version picked, with that `php_version`

None of them carries a pattern, a text or anything else you typed.

**Retention and the rest.** Google Analytics keeps event-level data 14 months, then deletes it; its aggregated reports (how many visits, how many runs) are not bound by that setting. Google Signals is off, so nothing is joined to a Google account. The site has no account, no form and no newsletter.

The playground downloads PHP, compiled to WebAssembly, from cdn.jsdelivr.net the first time you ask it for an answer: typing a pattern or a text, pressing Run, opening an example or a finding. The step trace downloads PCRE2's own module when you open its tab. jsDelivr sees those requests like any download.

Questions: [open an issue](https://github.com/php-regex/php-regex/issues).
