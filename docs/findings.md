---
title: Projects
description: "What PHPRegex's linter reports on the regular expressions of real open-source PHP projects, each judged for the PHP and PCRE2 its own composer.json requires."
permalink: /findings/
---

# Projects

What the linter reports on the regular expressions of real open-source PHP projects: proven catastrophic backtracking, useless flags, redundant quantifiers, overlapping classes. Each project is judged for the PHP its own composer.json asks for (its require.php floor, or the platform PHP it pins; PHP 8.1 when it names none), on the PCRE2 that PHP bundles, and each finding opens in the playground with the code it lives in.

The list is the latest run over the corpus: the repositories are cloned again, linted again, and the findings rewritten. A finding that is gone from a run is simply gone, whether its code changed, moved or was rewritten.

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
