---
title: Sponsorship
permalink: /sponsorship/
description: "How sponsoring PHPRegex works: what the money funds, what each sponsor gets, the spots a company can back for a whole release, and where each name appears."
sponsor: false
---

{% assign sp = site.data.sponsors %}

# Sponsorship

PHPRegex is nineteen packages — a PCRE2-true parser, a linter, a ReDoS analyzer, and integrations for PHPStan, Psalm, Rector, PHPUnit, Infection, Laravel and Symfony — kept honest by a test suite that runs thousands of patterns against the real PCRE2 engine before every release.

PHPRegex is built and maintained by [Younes ENNAJI](https://github.com/yoeunes) — issues read, bugs fixed, every new PCRE2 release followed, these docs maintained with care. Sponsorship is what makes that time possible, and what keeps the project alive and independent. If it parses your patterns, guards your builds or saves your team debugging time, backing it is the way to make sure it stays that way.

[❤ Sponsor PHPRegex on GitHub]({{ sp.github }}){: .sponsor-page-cta}

## In short

- **Sponsoring goes through [GitHub Sponsors]({{ sp.github }})**, once or on a recurring basis, for the amount you choose. GitHub takes care of the payment and the receipt.
- **A developer** gets their name and avatar among the backers on every documentation page.
- **A company** gets its logo in the "Backed by" footer of every page.
- **A company can also take a spot** — a part of PHPRegex it backs alone for a whole release. The first to ask takes it.
- **Once listed, always listed.** The page is empty today, which is exactly why backing it now is the better deal: the first sponsors are the most visible, and they stay visible for good.

There is no price list: any amount, one-time or monthly, counts the same.

## What you get {#what-you-get}

| You are | What you get |
|---|---|
| Anyone | Our thanks, and your name in [BACKERS.md]({{ sp.backers_md }}). GitHub adds a sponsor badge to your profile on its own. |
| A developer | Your name and avatar [among the backers](#backers) at the foot of every documentation page and in BACKERS.md, and a thank-you post on X. |
| A company | Your logo in the "Backed by" footer of every page, in the README and BACKERS.md, and a thank-you in the release notes. |
| A company with a spot | Everything above, plus [a spot](#spots): your company alone on that part's pages, and named in the README of its packages for the whole release. |

## Spots {#spots}

A spot is a part of PHPRegex that one company backs for a whole release — every version of PHPRegex {{ sp.release }}, from the first to the last patch. All spots for {{ sp.release }} are founding spots: empty today, and the first company to ask for one takes it. Sponsor on GitHub and tell us which spot you want, or [write first](mailto:{{ sp.email }}). While you hold a spot:

- **The sponsor frame at the foot of every page of the spot shows your company alone**, with your logo, your name and one line about what you do.
- **The README of each of the spot's packages names you.** That README ships with the package: Composer installs it into the `vendor/` folder of every project that requires it, and GitHub and Packagist show it on the package's page.
- **Your logo joins the "Backed by" footer** of every other page.

{% for spot in sp.spots %}
{% if spot.id == 'core' or spot.id == 'linter-redos' or spot.id == 'education' %}
{% assign holder = nil %}{% if spot.sponsor %}{% assign holder = sp.companies | where: 'id', spot.sponsor | first %}{% endif %}
### {{ spot.title }} {#spot-{{ spot.id }}}

{% if holder %}Backed by [{{ holder.name }}]({{ holder.url }}){: rel="sponsored noopener"} for PHPRegex {{ spot.held_for | default: sp.release }}.{% else %}**Open** for PHPRegex {{ sp.release }}.{% endif %}

{% if spot.pitch %}{{ spot.pitch }}{% else %}The {{ spot.title }} guide, and the README of the package Composer installs for it.{% endif %}

{% if spot.packages.size > 0 %}- Packages: {% for pkg in spot.packages %}`php-regex/{{ pkg }}`{% unless forloop.last %}, {% endunless %}{% endfor %}
{% endif %}{% if spot.pages.size > 0 %}- Pages: {% for prefix in spot.prefixes %}[every page under {{ prefix }}]({{ prefix }}), {% endfor %}{% for url in spot.pages %}[{{ url }}]({{ url }}){% unless forloop.last %}, {% endunless %}{% endfor %}
{% endif %}
{% endif %}
{% endfor %}
### Integration spots {#integrations}

One package and one guide each, held the same way — your company alone on the guide's pages and in the package's README:

{% for spot in sp.spots %}
{% unless spot.id == 'core' or spot.id == 'linter-redos' or spot.id == 'education' %}
{% assign holder = nil %}{% if spot.sponsor %}{% assign holder = sp.companies | where: 'id', spot.sponsor | first %}{% endif %}
- **{{ spot.title }}**{: #spot-{{ spot.id }}} — {% if holder %}backed by [{{ holder.name }}]({{ holder.url }}){: rel="sponsored noopener"}.{% else %}{% if spot.packages.size > 0 %}`php-regex/{{ spot.packages.first }}`, {% endif %}the [{{ spot.title }} guide]({{ spot.pages.first }}).{% endif %}
{% endunless %}
{% endfor %}

## Backers {#backers}

The developers who back PHPRegex line the foot of the sponsor frame on every documentation page, with their GitHub avatar, and are listed in [BACKERS.md]({{ sp.backers_md }}). When you join, we thank you in a post on X. Your name, your avatar, at the foot of every page of the documentation you use — that is the point.

## How long it lasts {#duration}

- **It lasts the whole release.** From the moment you join — all we need is a logo, a link, and for a spot one line about your company — your name rides every version of PHPRegex {{ sp.release }}, and the versions that ship keep it for good. The holder of a spot is asked first when the next release opens.
- **Nobody is ever taken off.** Names in BACKERS.md, avatars among the backers, logos in the footer stay, even after a sponsorship ends.

## What to send {#what-to-send}

Reply to the welcome email GitHub sends, or write to <{{ sp.email }}>, with:

- your logo as SVG, or PNG at least 400 pixels wide, and a variant for dark backgrounds if you have one;
- the address it should link to;
- for a spot, which spot, and one line about your company, 120 characters at most.

## Questions

Write to <{{ sp.email }}> — or [sponsor PHPRegex now]({{ sp.github }}), it takes a minute. For the people and companies who already back the project, see [BACKERS.md]({{ sp.backers_md }}).
