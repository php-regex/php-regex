---
description: "In-depth articles on PHP regular expressions: every example run against the engine it names, every release claim checked against that release's own sources."
permalink: /blog/
---
# Articles

Long-form pieces on PHP regular expressions. Every example runs against the
engine the article names, every measurement carries its engine and settings,
and every claim about a PHP or PCRE2 release is checked against that
release's own sources before it is published — the method section of each
article says exactly what was run, where.

{% assign articles = site.articles | sort: date | reverse %}
{% for article in articles %}
### [{{ article.title }}]({{ article.url }})

{{ article.description }}

<p class="article-index-date">{{ article.date | date: '%-d %B %Y' }}</p>
{% endfor %}
