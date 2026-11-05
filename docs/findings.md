---
title: Findings
description: "What PHPRegex's linter reports on the regular expressions of real open-source PHP projects, each judged for the PHP and PCRE2 its own composer.json requires."
permalink: /findings/
---

# Findings

What the linter reports on the regular expressions of real open-source PHP projects: proven catastrophic backtracking, useless flags, redundant quantifiers, overlapping classes. Each project is judged for the PHP its own composer.json asks for (its require.php floor, or the platform PHP it pins; PHP 8.1 when it names none), on the PCRE2 that PHP bundles, and each finding opens in the playground with the code it lives in.

The list is the latest run over the corpus: the repositories are cloned again, linted again, and the findings rewritten. A finding that is gone from a run is simply gone, whether its code changed, moved or was rewritten.

{%- assign projects = site.findings | sort: "findings_rank" %}

| Project | Findings | FAIL | WARN | Judged for |
|---|---:|---:|---:|---|
{% for finding in projects -%}
| [{{ finding.findings_repo }}]({{ finding.url }}) | {{ finding.findings_count }} | {{ finding.findings_fail }} | {{ finding.findings_warn }} | PHP {{ finding.findings_php }} · PCRE2 {{ finding.findings_pcre }} |
{% endfor %}

Want a project in the corpus? [Propose a project](https://github.com/php-regex/php-regex/issues/new?template=propose-project.yml).
