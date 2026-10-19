---
title: Articles
description: "In-depth articles on PHP regular expressions: every example run against the engine it names, every release claim checked against that release's own sources."
permalink: /blog/
---
<div class="blog-hero">
  <p class="blog-kicker">Articles</p>
  <h1 class="blog-title">Measured writing on PHP regular expressions.</h1>
  <p class="blog-lead">Long-form pieces on PHP regular expressions. Every example runs against the engine the article names, every measurement carries its engine and settings, and every claim about a PHP or PCRE2 release is checked against that release's own sources before it is published — the method section of each article says exactly what was run, where.</p>
  {% assign articles = site.articles | sort: date | reverse %}
  <div class="blog-meta">
    <span>{{ articles.size }} article{% if articles.size != 1 %}s{% endif %}</span>
    <span class="blog-meta-sep" aria-hidden="true">·</span>
    <a href="/feed/articles.xml">RSS feed</a>
  </div>
</div>

{% if articles.size == 0 %}
<p class="article-soon">The first articles are on their way — each one measured, run against the engines it names, and checked line by line before it lands here.</p>
{% else %}
{% assign featured = articles | first %}
<article class="article-featured">
  <div class="article-featured-head">
    <p class="article-featured-kicker">
      {% if featured.published == false %}<span class="draft-tag">Draft</span> {% endif %}Latest
    </p>
    <h2 class="article-featured-title"><a href="{{ featured.url }}">{{ featured.title }}</a></h2>
  </div>
  <p class="article-featured-desc">{{ featured.description }}</p>
  <div class="article-featured-meta">
    <time datetime="{{ featured.date | date_to_xmlschema }}">{{ featured.date | date: '%-d %B %Y' }}</time>
    {% capture featured_minutes %}{% include reading-minutes.html html=featured.content %}{% endcapture %}
    <span class="article-meta-sep" aria-hidden="true">·</span>
    <span>{{ featured_minutes }} min read</span>
    {% if featured.tags %}
    <span class="article-meta-sep" aria-hidden="true">·</span>
    <span>{% for tag in featured.tags %}<span class="article-tag">{{ tag }}</span>{% endfor %}</span>
    {% endif %}
    {% if featured.last_modified_at and featured.last_modified_at != featured.date %}
    <span class="article-meta-sep" aria-hidden="true">·</span>
    <span>updated {{ featured.last_modified_at | date: '%-d %B %Y' }}</span>
    {% endif %}
  </div>
  <a class="article-featured-link" href="{{ featured.url }}">Read the article →</a>
</article>

{% if articles.size > 1 %}
<h2 class="blog-section-title">All articles</h2>
<ul class="article-list">
  {% for article in articles offset:1 %}
  <li class="article-entry">
    <div class="article-entry-head">
      <a class="article-entry-title" href="{{ article.url }}">{{ article.title }}</a>{% if article.published == false %} <span class="draft-tag">Draft</span>{% endif %}
      <span class="article-entry-meta">
        <time class="article-entry-date" datetime="{{ article.date | date_to_xmlschema }}">{{ article.date | date: '%-d %B %Y' }}</time>
        {% capture entry_minutes %}{% include reading-minutes.html html=article.content %}{% endcapture %}
        <span class="article-meta-sep" aria-hidden="true">·</span>
        <span class="article-entry-reading">{{ entry_minutes }} min</span>
        {% if article.tags %}
        <span class="article-meta-sep" aria-hidden="true">·</span>
        <span>{% for tag in article.tags %}<span class="article-tag">{{ tag }}</span>{% endfor %}</span>
        {% endif %}
      </span>
    </div>
    <p class="article-entry-desc">{{ article.description }}</p>
  </li>
  {% endfor %}
</ul>
{% endif %}
{% endif %}
