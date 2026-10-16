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
{% if articles.size == 0 %}
<p class="article-soon">The first articles are on their way — each one measured, run against the engines it names, and checked line by line before it lands here.</p>
{% else %}
<ul class="article-list">
  {% for article in articles %}
  <li class="article-entry">
    <div class="article-entry-head">
      <a class="article-entry-title" href="{{ article.url }}">{{ article.title }}</a>
      <time class="article-entry-date" datetime="{{ article.date | date_to_xmlschema }}">{{ article.date | date: '%-d %B %Y' }}</time>
    </div>
    <p class="article-entry-desc">{{ article.description }}</p>
  </li>
  {% endfor %}
</ul>
{% endif %}
