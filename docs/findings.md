---
title: Projects
description: "What PHPRegex's linter reports on the regular expressions of real open-source PHP projects, each judged for the PHP and PCRE2 its own composer.json requires."
permalink: /findings/
projects_index: true
---

# Projects

<p class="projects-lead">What the linter reports on the regular expressions of real open-source PHP projects.</p>

<p class="projects-facts">Judged for each project's own PHP, on the PCRE2 that PHP bundles · every finding opens in the playground, on the code it lives in · the list is the latest corpus run: a finding that is gone is gone.</p>

<div class="page-filter">
  <label class="visually-hidden" for="projects-filter-input">Filter the projects</label>
  <input type="search" id="projects-filter-input" placeholder="Filter by owner or repository — e.g. symfony, moodle" autocomplete="off" spellcheck="false">
  <span class="page-count"></span>
</div>
<p class="page-empty" role="status" hidden>No project matches — try a shorter fragment, or press Escape to see them all again.</p>

{%- assign projects = site.findings | sort: "findings_rank" %}

<ul class="projects-index">
{% for finding in projects -%}
{%- assign owner = finding.findings_repo | split: '/' | first %}
  <li>
    <a href="{{ finding.url }}">
      <img class="projects-index-avatar" src="/assets/findings/avatars/{{ owner }}.png" alt="" width="28" height="28" loading="lazy">
      <span class="projects-index-name">{{ finding.findings_repo }}</span>
      <span class="projects-index-counts"><span class="projects-index-fail{% if finding.findings_fail == 0 %} is-zero{% endif %}">{{ finding.findings_fail }} FAIL</span><span class="projects-index-warn{% if finding.findings_warn == 0 %} is-zero{% endif %}">{{ finding.findings_warn }} WARN</span></span>
      <span class="projects-index-engine">PHP {{ finding.findings_php }} · PCRE2 {{ finding.findings_pcre }}</span>
    </a>
  </li>
{% endfor %}
</ul>

Want a project in the corpus? [Propose a project](https://github.com/php-regex/php-regex/issues/new?template=propose-project.yml).
