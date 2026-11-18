---
title: Sponsorship
permalink: /sponsorship/
description: "How sponsoring PHPRegex works: what each sponsor gets, the spots a company can back for a whole release, where each name appears, and what sponsorship never buys."
sponsor: false
---

{% assign sp = site.data.sponsors %}

# Sponsorship

Sponsorship funds the time that goes into PHPRegex: issues read, bugs fixed, PCRE2 releases followed, these docs kept true. This page says who can sponsor, what each sponsor gets, where it shows and for how long — and what sponsorship never buys.

## In short

- **Sponsoring goes through [GitHub Sponsors]({{ sp.github }})**, once or on a recurring basis, for the amount you choose. GitHub takes care of the payment and the receipt, and can invoice an organization directly.
- **A developer** gets their name and avatar among the backers on every documentation page.
- **A company** gets its logo on the site, in the thank-you rotation and the footer.
- **A company can also take a spot** — a part of PHPRegex it backs alone for a whole release, the way each Symfony component names the company that backs it for a version. The first to ask takes it.
- **Once listed, always listed.** PHPRegex is young: whoever backs it early stays visible.

## What you get {#what-you-get}

| You are | What you get |
|---|---|
| Anyone | Our thanks, and your name in [BACKERS.md]({{ sp.backers_md }}). GitHub adds a sponsor badge to your profile on its own. |
| A developer | Your name and avatar [among the backers](#backers) at the foot of every documentation page and in BACKERS.md, and a thank-you post on X. |
| A company | Your logo in [the thank-you rotation](#rotation) and in the "Backed by" footer of every page, in the README and BACKERS.md, and a thank-you in the release notes. |
| A company with a spot | Everything above, plus [a spot](#spots): your company alone on that part's pages, and named in the README of its packages for the whole release. |

The amount is yours to choose. There is no minimum and no price list: every sponsor counts.

## Spots {#spots}

A spot is a part of PHPRegex that one company backs for a whole release — every version of PHPRegex {{ sp.release }}, from the first to the last patch. The first company to ask for a spot takes it: sponsor on GitHub and tell us which spot you want, or [write first](mailto:{{ sp.email }}). While you hold a spot:

- **The sponsor frame at the foot of every page of the spot shows your company alone**, with your logo, your name and one line about what you do.
- **The README of each of the spot's packages names you**, in its Sponsors section. That README ships with the package: Composer installs it into the `vendor/` folder of every project that requires it, and GitHub and Packagist show it on the package's page.
- **You are in the thank-you rotation too**, on every page that belongs to no spot.

The spots, for PHPRegex {{ sp.release }}:

{% for spot in sp.spots %}
{% assign holder = nil %}{% if spot.sponsor %}{% assign holder = sp.companies | where: 'id', spot.sponsor | first %}{% endif %}
### {{ spot.title }} {#spot-{{ spot.id }}}

{% if holder %}Backed by [{{ holder.name }}]({{ holder.url }}){: rel="sponsored noopener"} for PHPRegex {{ spot.held_for | default: sp.release }}.{% else %}**Open** for PHPRegex {{ sp.release }}.{% endif %}

{% if spot.pitch %}{{ spot.pitch }}{% else %}The {{ spot.title }} guide, and the README of the package Composer installs for it.{% endif %}

{% if spot.packages.size > 0 %}- Packages: {% for pkg in spot.packages %}`php-regex/{{ pkg }}`{% unless forloop.last %}, {% endunless %}{% endfor %}
{% endif %}- Pages: {% for prefix in spot.prefixes %}[every page under {{ prefix }}]({{ prefix }}){% if spot.pages.size > 0 %}, {% endif %}{% endfor %}{% for url in spot.pages %}[{{ url }}]({{ url }}){% unless forloop.last %}, {% endunless %}{% endfor %}
{% endfor %}

## The thank-you rotation {#rotation}

Every page that belongs to no spot — the quick start, the documentation index, the package chooser, troubleshooting, the reference indexes — thanks all the companies at once, in a random order drawn on each visit, so that no company owns the first place by the accident of a list. The footer of every page, the home page included, carries the same companies in a small "Backed by" strip.

Every company that sponsors is in the rotation, spot holders and past spot holders included.

## Backers {#backers}

The developers who back PHPRegex line the foot of the sponsor frame on every documentation page, with their GitHub avatar, and are listed in [BACKERS.md]({{ sp.backers_md }}). When you join, we thank you in a post on X — tell us your handle if you want it in the post. That is the whole of it, and for most backers it is the point: a public thank-you, not a perk.

## How long it lasts {#duration}

- **It starts within seven days** of the sponsorship, once we have what we need: a logo, a link, and for a spot one line about your company.
- **A spot lasts the whole release it was taken for.** Every version of that release names you in the README of the spot's packages, and those versions keep your name for good. The site shows you on the spot's pages for as long as that release is the current one.
- **The holder of a spot is asked first** when the next release opens.
- **Nobody is taken off.** Names in BACKERS.md, avatars among the backers, logos in the rotation and the footer stay, even after a sponsorship ends.

## What to send {#what-to-send}

Reply to the welcome email GitHub sends, or write to <{{ sp.email }}>, with:

- your logo as SVG, or PNG at least 400 pixels wide, and a variant for dark backgrounds if you have one;
- the address it should link to;
- for a spot, which spot, and one line about your company, 120 characters at most.

Logos are served from php-regex.com itself: no image is loaded from your servers, and nothing about a visitor reaches you through it. Every sponsor link carries `rel="sponsored"`.

## What sponsorship does not buy {#limits}

Sponsoring funds maintenance. It does not buy:

- calls or meetings, talks or workshops;
- reviews of your code or your patterns, consulting, or a support contract;
- priority on issues or pull requests: they are read the same way for everyone;
- a say in technical decisions, the roadmap, or what the docs recommend. Sponsors appear in the frame, the footer, the READMEs, BACKERS.md and release notes — never in the text of the documentation, never in an example.

## What the project never does {#never}

- No message from a sponsor in a terminal: not in Composer's output, not in the CLI, not in an error message, a report or generated code.
- No sponsor we would not recommend to a PHP developer. If a sponsor cannot be shown, we say so before anything is published.

## Questions

Write to <{{ sp.email }}>. For the people and companies who already sponsor PHPRegex, see [BACKERS.md]({{ sp.backers_md }}).
