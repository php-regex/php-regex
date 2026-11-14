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
<article class="article-featured" data-toc-skip>
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
<h2 class="blog-section-title" data-toc-skip>All articles</h2>
{% comment %}
  One entry per article: the date in a column of its own on a wide
  screen, so the titles start on one vertical line and the eye runs
  down the dates like a table of contents; on a phone the date sits
  above the title. The whole entry is the target — the title's link
  stretches over it — and the entry answers a hover with one wash.
{% endcomment %}
<ol class="post-list" data-toc-skip>
  {% for article in articles offset:1 %}
  {% capture entry_minutes %}{% include reading-minutes.html html=article.content %}{% endcapture %}
  <li class="post">
    <time class="post-date" datetime="{{ article.date | date_to_xmlschema }}">{{ article.date | date: '%-d %B %Y' }}</time>
    <div class="post-body">
      <h3 class="post-title"><a class="post-link" href="{{ article.url }}">{{ article.title }}</a></h3>
      <p class="post-desc">{{ article.description }}</p>
      <p class="post-meta">
        {% if article.published == false %}<span class="draft-tag">Draft</span>{% endif %}
        <span>{{ entry_minutes }} min read</span>
        {% for tag in article.tags %}<span class="article-tag">{{ tag }}</span>{% endfor %}
        <span class="post-cta" aria-hidden="true">Read <span class="post-cta-arrow">→</span></span>
      </p>
    </div>
  </li>
  {% endfor %}
</ol>
{% endif %}
{% endif %}
