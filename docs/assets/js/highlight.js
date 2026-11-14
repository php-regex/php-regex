'use strict';

// Syntax colors where the build's highlighter has none. The site is
// highlighted at build time by Rouge, pinned by GitHub Pages; this script
// fills what Rouge leaves plain, after the page has loaded, and only ever
// wraps text in spans — the text of a block, which the copy and run
// buttons read, never changes.
//
//   - a regex is tokenized as PCRE reads it (delimiters, groups and their
//     names, lookarounds, classes, escapes, quantifiers, anchors, flags):
//     in a block that holds only patterns, in a PHP string that is a
//     pattern, and wherever a captured output names one;
//   - captured output (the CLI's reports, the AST drawn as a tree, the
//     ✓ / ✗ lists) gets its verdicts, steps, keys, paths and tree lines;
//   - NEON and JSON5, which Rouge has no lexer for;
//   - shell: the command at the head of each line, which Rouge leaves
//     unmarked;
//   - the PHP lexer's errors on namespace separators and the JSON lexer's
//     errors on the "..." of an excerpt are given the role they play.

(function () {
  var main = document.querySelector('main');
  if (!main) {
    return;
  }

  // ---- Token lists --------------------------------------------------------
  // A token is [className, text]; a null class is plain text. Rendering
  // replaces an element's children with text nodes and spans holding the
  // same characters.

  function render(target, tokens) {
    var fragment = document.createDocumentFragment();
    tokens.forEach(function (token) {
      if (token[1] === '') {
        return;
      }
      if (token[0] === null) {
        fragment.appendChild(document.createTextNode(token[1]));
      } else {
        var span = document.createElement('span');
        span.className = token[0];
        span.textContent = token[1];
        fragment.appendChild(span);
      }
    });
    target.textContent = '';
    target.appendChild(fragment);
  }

  // Splits every plain token by a global regular expression; each match is
  // handed to `paint`, which returns the tokens that replace it (or null to
  // leave the match plain).
  function apply(tokens, pattern, paint) {
    var out = [];
    tokens.forEach(function (token) {
      if (token[0] !== null) {
        out.push(token);
        return;
      }
      var text = token[1];
      var last = 0;
      var match;
      pattern.lastIndex = 0;
      while ((match = pattern.exec(text)) !== null) {
        if (match[0] === '') {
          pattern.lastIndex++;
          continue;
        }
        var painted = paint(match);
        if (painted === null) {
          continue;
        }
        if (match.index > last) {
          out.push([null, text.slice(last, match.index)]);
        }
        painted.forEach(function (t) {
          out.push(t);
        });
        last = match.index + match[0].length;
      }
      if (last < text.length) {
        out.push([null, text.slice(last)]);
      }
    });
    return out;
  }

  function as(cls) {
    return function (match) {
      return [[cls, match[0]]];
    };
  }

  // ---- PCRE ---------------------------------------------------------------

  var CLOSERS = { '(': ')', '[': ']', '{': '}', '<': '>' };
  var FLAGS = /^[imsxuADSUXJnr]*/;
  var META = /[\\^$.|?*+()[\]{}]/;

  // Reads a delimited pattern at the start of `text`, the way PHP finds its
  // end: the first unescaped closing delimiter (bracket delimiters nest),
  // then the flags. Returns { close, end } — the index of the closing
  // delimiter and the index just past the flags — or null.
  function scan(text) {
    var open = text.charAt(0);
    if (!open || /[\w\s\\]/.test(open)) {
      return null;
    }
    var close = CLOSERS[open] || open;
    var depth = 0;
    for (var i = 1; i < text.length; i++) {
      var c = text.charAt(i);
      if (c === '\\') {
        i++;
        continue;
      }
      if (c === '\n') {
        return null;
      }
      if (close !== open && c === open) {
        depth++;
      } else if (c === close) {
        if (depth === 0) {
          return { close: i, end: i + 1 + FLAGS.exec(text.slice(i + 1))[0].length };
        }
        depth--;
      }
    }
    return null;
  }

  // The body of a pattern, token by token.
  function pcreBody(body, extended) {
    var tokens = [];
    var stack = [];
    var i = 0;
    var n = body.length;

    function push(cls, text) {
      tokens.push([cls, text]);
    }

    while (i < n) {
      var c = body.charAt(i);
      var rest = body.slice(i);
      var m;

      if (c === '\\') {
        if ((m = /^\\Q[\s\S]*?(\\E|$)/.exec(rest))) {
          push('rx-esc', '\\Q');
          var quoted = m[0].slice(2, m[1] ? -2 : undefined);
          push('rx-lit', quoted);
          if (m[1]) {
            push('rx-esc', '\\E');
          }
          i += m[0].length;
          continue;
        }
        m = /^\\(?:[pP](?:\{\^?[\w&]+\}|[A-Za-z])|[dDwWsShHvVRNX]|[bBAzZG]|K|x\{[0-9A-Fa-f]+\}|x[0-9A-Fa-f]{0,2}|o\{[0-7]+\}|0[0-7]{0,2}|[1-9]\d*|g\{-?[\w]+\}|g-?\d+|g<[\w-]+>|g'[\w-]+'|k<\w+>|k\{\w+\}|k'\w+'|c.|[\s\S])/.exec(rest);
        var esc = m ? m[0] : c;
        var k = esc.charAt(1);
        var cls = 'rx-esc';
        if (/[pPdDwWsShHvVRNX]/.test(k)) {
          cls = 'rx-type';
        } else if (/[bBAzZGK]/.test(k)) {
          cls = 'rx-anchor';
        } else if (/[1-9gk]/.test(k)) {
          cls = 'rx-ref';
        }
        push(cls, esc);
        i += esc.length;
        continue;
      }

      if (c === '[') {
        // A class runs to the first "]" that is not its first member.
        var j = i + 1;
        var head = '[';
        if (body.charAt(j) === '^') {
          head += '^';
          j++;
        }
        push('rx-set', head);
        var start = j;
        while (j < n) {
          var d = body.charAt(j);
          if (d === ']' && j > start) {
            break;
          }
          if (d === '\\') {
            var em = /^\\(?:[pP](?:\{\^?[\w&]+\}|[A-Za-z])|x\{[0-9A-Fa-f]+\}|x[0-9A-Fa-f]{0,2}|[\s\S])/.exec(body.slice(j));
            var e = em ? em[0] : '\\';
            push(/[pPdDwWsShHvV]/.test(e.charAt(1)) ? 'rx-type' : 'rx-esc', e);
            j += e.length;
            continue;
          }
          var posix = /^\[:\^?[a-z]+:\]/.exec(body.slice(j));
          if (posix) {
            push('rx-type', posix[0]);
            j += posix[0].length;
            continue;
          }
          if (d === '-' && j > start && body.charAt(j + 1) !== ']') {
            push('rx-set', '-');
          } else {
            push('rx-member', d);
          }
          j++;
        }
        if (j < n) {
          push('rx-set', ']');
          j++;
        }
        i = j;
        continue;
      }

      if (c === '(') {
        if ((m = /^\(\*[A-Z_]+(?::[^)]*)?\)/.exec(rest))) {
          push('rx-verb', m[0]);
          i += m[0].length;
          continue;
        }
        if ((m = /^\(\?#[^)]*\)/.exec(rest))) {
          push('rx-comment', m[0]);
          i += m[0].length;
          continue;
        }
        if ((m = /^\(\?(?:R|[+-]?\d+|&\w+|P>\w+|P=\w+)\)/.exec(rest))) {
          push('rx-ref', m[0]);
          i += m[0].length;
          continue;
        }
        if ((m = /^\(\?(?:<=|<!|=|!)/.exec(rest)) || (m = /^\(\*(?:positive_lookahead|negative_lookahead|positive_lookbehind|negative_lookbehind|pla|nla|plb|nlb):/.exec(rest))) {
          push('rx-look', m[0]);
          stack.push('rx-look');
          i += m[0].length;
          continue;
        }
        if ((m = /^\(\?(?:P?<([A-Za-z_]\w*)>|'([A-Za-z_]\w*)')/.exec(rest))) {
          var name = m[1] || m[2];
          var open = m[0].slice(0, m[0].length - name.length - 1);
          push('rx-group', open);
          push('rx-name', name);
          push('rx-group', m[0].charAt(m[0].length - 1));
          stack.push('rx-group');
          i += m[0].length;
          continue;
        }
        if ((m = /^\(\?\(/.exec(rest))) {
          push('rx-group', '(?');
          stack.push('rx-group');
          i += 2;
          continue;
        }
        if ((m = /^\(\?\^?[imnsxJUX-]*\)/.exec(rest))) {
          push('rx-flag', m[0]);
          i += m[0].length;
          continue;
        }
        if ((m = /^\(\?(?:\^?[imnsxJUX-]*:|[|>])/.exec(rest)) || (m = /^\(\*atomic:/.exec(rest))) {
          push('rx-group', m[0]);
          stack.push('rx-group');
          i += m[0].length;
          continue;
        }
        push('rx-group', '(');
        stack.push('rx-group');
        i++;
        continue;
      }

      if (c === ')') {
        push(stack.length ? stack.pop() : 'rx-group', ')');
        i++;
        continue;
      }

      if ((m = /^(?:[*+?]|\{\d*,?\d*\})[?+]?/.exec(rest)) && (c !== '{' || /^\{(?:\d+(?:,\d*)?|,\d+)\}/.test(rest))) {
        push('rx-quant', m[0]);
        i += m[0].length;
        continue;
      }

      if (c === '^' || c === '$') {
        push('rx-anchor', c);
        i++;
        continue;
      }
      if (c === '|') {
        push('rx-alt', c);
        i++;
        continue;
      }
      if (c === '.') {
        push('rx-type', c);
        i++;
        continue;
      }
      if (extended && c === '#') {
        m = /^#[^\n]*/.exec(rest);
        push('rx-comment', m[0]);
        i += m[0].length;
        continue;
      }

      m = /^[^\\[\]()|^$.*+?{#]+/.exec(rest);
      var plain = m ? m[0] : c;
      push('rx-lit', plain);
      i += plain.length;
    }
    return tokens;
  }

  // A whole delimited pattern, with its delimiters and its flags.
  function pcreLiteral(text) {
    var at = scan(text);
    var flags = text.slice(at.close + 1, at.end);
    return [['rx-delim', text.charAt(0)]]
      .concat(pcreBody(text.slice(1, at.close), flags.indexOf('x') !== -1))
      .concat([['rx-delim', text.charAt(at.close)], ['rx-flags', flags]]);
  }

  // A pattern is worth coloring when it parses whole and holds at least one
  // character that means something to PCRE — "/path/" is a path.
  function isPattern(text) {
    var at = scan(text);
    if (!at || at.end !== text.length) {
      return false;
    }
    var body = text.slice(1, at.close);
    return body.length > 0 && META.test(body);
  }

  // Patterns inside running text: a slash- or tilde-delimited pattern that
  // stands alone between spaces, quotes or punctuation.
  var INLINE = /(^|[\s"'(:=→])(([\/~])(?:\\.|(?!\3)[^\\\n])+\3[imsxuADSUXJn]*)(?=$|[\s"',;)\]:.])/g;

  function inlinePatterns(tokens) {
    return apply(tokens, INLINE, function (match) {
      var candidate = match[2];
      if (!isPattern(candidate)) {
        return null;
      }
      return [[null, match[1]]].concat(pcreLiteral(candidate));
    });
  }

  // ---- Captured output ----------------------------------------------------

  function output(text) {
    var tokens = [[null, text]];
    // Line-anchored rules run first, on whole lines, before anything has
    // cut a line into pieces.
    tokens = apply(tokens, /^([ \t]*)(\[\d+\/\d+\])([^\n]*)/gm, function (m) {
      return [[null, m[1]], ['out-step-n', m[2]], ['out-step', m[3]]];
    });
    tokens = apply(tokens, /^([ \t]*)([A-Z][A-Za-z][\w .()\/-]{0,22}?)([ \t]*:)(?=[ \t]|$)/gm, function (m) {
      return [[null, m[1]], ['out-key', m[2] + m[3]]];
    });
    // A ✓ / ✗ list: the mark, then the subjects it judges, then the aside.
    tokens = apply(tokens, /^([ \t]*)([✓✔✗✘])([ \t]+)([^\n]*?)([ \t]*\([^()\n]*\))?[ \t]*$/gm, function (m) {
      var good = /[✓✔]/.test(m[2]);
      return [[null, m[1]], [good ? 'out-ok' : 'out-bad', m[2]], [null, m[3]], ['out-str', m[4]], ['out-dim', m[5] || '']];
    });
    // pcre2test's trace: the subject after "--->", the offset, the carets.
    tokens = apply(tokens, /^(--->)([^\n]*)/gm, function (m) {
      return [['out-mark', m[1]], ['out-str', m[2]]];
    });
    tokens = apply(tokens, /^([ \t]*)(\+\d+)([ \t]+)(\^[ \t^]*\^|\^)/gm, function (m) {
      return [[null, m[1]], ['out-num', m[2]], [null, m[3]], ['out-warn', m[4]]];
    });
    tokens = apply(tokens, /^New match attempt$|^No match$/gm, as('out-step'));
    // A table's ruled line, "------ ------".
    tokens = apply(tokens, /^[ \t]*-{3,}(?:[ \t]+-{3,})*[ \t]*$/gm, as('out-tree'));
    tokens = inlinePatterns(tokens);
    tokens = apply(tokens, /::(?:error|warning|notice)\b/g, function (m) {
      return [[m[0].indexOf('error') !== -1 ? 'out-bad' : 'out-warn', m[0]]];
    });
    tokens = apply(tokens, /\b(?:PASS|OK|SAFE|VALID|Linear)\b/g, as('out-ok'));
    tokens = apply(tokens, /\b(?:FAIL|FAILED|ERROR|INVALID|UNSAFE|CRITICAL|HIGH|Error)\b|\bNOT ANALYZED\b/g, as('out-bad'));
    tokens = apply(tokens, /\b(?:WARN|WARNING|MEDIUM|Warning)\b/g, as('out-warn'));
    tokens = apply(tokens, /\b(?:INFO|NOTE|LOW|Note|OPTIMIZATION)\b/g, as('out-info'));
    // A closing aside, "(missing local part)", steps back; a value in
    // quotes keeps its color.
    tokens = apply(tokens, /\([^()"\n]*\)[ \t]*$/gm, function (m) {
      return [['out-dim', m[0]]];
    });
    tokens = apply(tokens, /[\w.\/-]+\.(?:php|json|neon|xml|ya?ml|phar|js|ts|py)(?::\d+){1,2}\b|[\w.\/-]+\.(?:php|json|neon|xml|ya?ml)\b/g, as('out-path'));
    tokens = apply(tokens, /[├└│┌┐┘┬┴┼─]+|\|--|\\--|`--/g, as('out-tree'));
    tokens = apply(tokens, /(^|\n)([ \t]*)(\|)(?=[ \t])/g, function (m) {
      return [[null, m[1] + m[2]], ['out-tree', m[3]]];
    });
    tokens = apply(tokens, /\\?\b[A-Z]\w*(?:\\[A-Z]\w*)+\b|\b[A-Z][A-Za-z]*(?:Node|Exception)\b/g, as('out-type'));
    tokens = apply(tokens, /\$[A-Za-z_]\w*/g, as('out-var'));
    tokens = apply(tokens, /\b(?:non-(?:empty|falsy)-(?:string|list)|list|array|string|int|bool|float|false|true|null|max|min)(?=[<{|,>}\s]|$)/g, as('out-type'));
    // An arrow, "->" or "-->", starts where a run of dashes does: the
    // tail of a longer one, like the trace's "--->", is not an arrow.
    tokens = apply(tokens, /[→↳⏎]|(?<!-)--?>|=>|\^{2,}|\.\.\./g, as('out-mark'));
    tokens = apply(tokens, /"(?:\\.|[^"\\\n])*"/g, as('out-str'));
    tokens = apply(tokens, /\b(?:int|string|bool|float|array|NULL)(?=\()/g, as('out-kw'));
    tokens = apply(tokens, /\b\d+(?:\.\d+)*\b/g, as('out-num'));
    return tokens;
  }

  // ---- NEON, JSON5 ----------------------------------------------------------

  function neon(text) {
    var tokens = [[null, text]];
    tokens = apply(tokens, /(^|[ \t])#[^\n]*/gm, function (m) {
      return [[null, m[1]], ['c1', m[0].slice(m[1].length)]];
    });
    tokens = apply(tokens, /'(?:[^'\n]|'')*'|"(?:\\.|[^"\\\n])*"/g, as('s'));
    tokens = apply(tokens, /^([ \t]*)(-[ \t]+)?([\w.@-]+)([ \t]*:)(?=[ \t]|$)/gm, function (m) {
      return [[null, m[1]], ['p', m[2] || ''], ['na', m[3]], ['p', m[4]]];
    });
    tokens = apply(tokens, /^([ \t]*)(-)(?=[ \t])/gm, function (m) {
      return [[null, m[1]], ['p', m[2]]];
    });
    tokens = apply(tokens, /%[\w.]+%/g, as('nv'));
    tokens = apply(tokens, /@[\w.\\]+/g, as('nf'));
    tokens = apply(tokens, /\b(?:true|false|yes|no|on|off|null)\b/g, as('kc'));
    tokens = apply(tokens, /\b\d+(?:\.\d+)?\b/g, as('mi'));
    tokens = apply(tokens, /[\[\]{}(),=]/g, as('p'));
    return tokens;
  }

  function json5(text) {
    var tokens = [[null, text]];
    tokens = apply(tokens, /\/\/[^\n]*|\/\*[\s\S]*?\*\//g, as('c1'));
    tokens = apply(tokens, /("(?:\\.|[^"\\\n])*"|'(?:\\.|[^'\\\n])*'|[A-Za-z_$][\w$]*)(\s*:)/g, function (m) {
      return [['nl', m[1]], ['p', m[2]]];
    });
    tokens = apply(tokens, /"(?:\\.|[^"\\\n])*"|'(?:\\.|[^'\\\n])*'/g, as('s2'));
    tokens = apply(tokens, /\b(?:true|false|null)\b/g, as('kc'));
    tokens = apply(tokens, /-?\b\d+(?:\.\d+)?\b/g, as('mi'));
    tokens = apply(tokens, /[\[\]{},]/g, as('p'));
    return tokens;
  }

  // ---- Shell ----------------------------------------------------------------

  var COMMAND = /^([ \t]*)((?:sudo[ \t]+)?(?!-)[\w.\/~-]*[\w~-])/;

  // Rouge marks a shell line's flags, strings and variables, never the
  // command at its head: the first word after a line start, a prompt, or a
  // pipe or && takes the call role.
  function shellCommands(code) {
    var atStart = true;
    Array.prototype.slice.call(code.childNodes).forEach(function (node) {
      if (node.nodeType === 1) {
        atStart = node.className === 'gp' || /\n[ \t]*$/.test(node.textContent);
        return;
      }
      var text = node.textContent;
      var tokens = [];
      var lines = text.split('\n');
      lines.forEach(function (line, index) {
        // A line that continues the one before it ("\" at its end) holds
        // arguments, not a command.
        var continued = index > 0 && /\\$/.test(lines[index - 1]);
        var start = (index > 0 || atStart) && !continued;
        var m = start ? COMMAND.exec(line) : null;
        if (m && !/^(?:then|do|else|fi|done)$/.test(m[2])) {
          tokens.push([null, m[1]], ['nf', m[2]]);
          line = line.slice(m[0].length);
        }
        tokens = tokens.concat(apply([[null, line]], /(\|\||&&|\|)([ \t]+)([\w.\/~-]+)/g, function (pm) {
          return [['o', pm[1]], [null, pm[2]], ['nf', pm[3]]];
        }));
        if (index < lines.length - 1) {
          tokens.push([null, '\n']);
        }
      });
      atStart = /\n[ \t]*$/.test(text) || (atStart && /^[ \t]*$/.test(text));
      var holder = document.createElement('span');
      render(holder, tokens);
      code.replaceChild(holder, node);
      while (holder.firstChild) {
        code.insertBefore(holder.firstChild, holder);
      }
      code.removeChild(holder);
    });
  }

  // The install box carries no highlighting at all: commands, then flags.
  function shell(text) {
    var tokens = [[null, text]];
    tokens = apply(tokens, /#[^\n]*/g, as('c'));
    tokens = apply(tokens, /'[^'\n]*'|"(?:\\.|[^"\\\n])*"/g, as('s1'));
    tokens = apply(tokens, /(^|\n)([ \t]*)([\w.\/~-]*[\w~-])/g, function (m) {
      return [[null, m[1] + m[2]], ['nf', m[3]]];
    });
    tokens = apply(tokens, /(^|[ \t])(--?[\w-]+)/g, function (m) {
      return [[null, m[1]], ['nt', m[2]]];
    });
    tokens = apply(tokens, /\$\{?\w+\}?/g, as('nv'));
    return tokens;
  }

  // ---- Choosing ----------------------------------------------------------------

  function languageOf(code) {
    var holder = code.closest('div.highlighter-rouge');
    var source = holder || code;
    var m = /(?:^|\s)language-([\w-]+)/.exec(source.className);
    return m ? m[1] : '';
  }

  function onlyPatterns(text) {
    var lines = text.split('\n').filter(function (line) {
      return line.trim() !== '';
    });
    return lines.length > 0 && lines.every(function (line) {
      return isPattern(line.trim());
    });
  }

  function patternLines(text) {
    var tokens = [];
    text.split('\n').forEach(function (line, index, all) {
      var lead = /^\s*/.exec(line)[0];
      var body = line.slice(lead.length).replace(/\s+$/, '');
      var tail = line.slice(lead.length + body.length);
      tokens.push([null, lead]);
      tokens = tokens.concat(body ? pcreLiteral(body) : []);
      tokens.push([null, tail + (index < all.length - 1 ? '\n' : '')]);
    });
    return tokens;
  }

  // In PHP, a string that is a whole pattern is colored as one.
  function phpPatterns(code) {
    code.querySelectorAll('span.s1, span.s2').forEach(function (span) {
      var text = span.textContent;
      var quote = text.charAt(0);
      if ((quote !== "'" && quote !== '"') || text.charAt(text.length - 1) !== quote || text.length < 4) {
        return;
      }
      var inner = text.slice(1, -1);
      if (!isPattern(inner) || (quote === '"' && /\$\w/.test(inner))) {
        return;
      }
      span.className = 'rx-string';
      render(span, [['rx-quote', quote]].concat(pcreLiteral(inner), [['rx-quote', quote]]));
    });
  }

  function repair(code, lang) {
    code.querySelectorAll('span.err').forEach(function (span) {
      var text = span.textContent;
      if (text === '\\') {
        span.className = 'nn';
      } else if (text === '...' || text === '…' || text === '→') {
        span.className = 'c';
      } else if ((lang === 'json' || lang === 'yaml') && /^[,:]$/.test(text)) {
        span.className = 'p';
      }
    });
    // Rouge splits a qualified name around the separator it failed on; the
    // names on both sides are the same type.
    code.querySelectorAll('span.nn').forEach(function (span) {
      [span.previousSibling, span.nextSibling].forEach(function (side) {
        if (side && side.nodeType === 1 && /^(?:n|nc|nf)$/.test(side.className) && /^[A-Z]/.test(side.textContent)) {
          side.className = 'nc';
        }
      });
    });
  }

  // A fence in a language Rouge does not know (NEON, JSON5) comes out as a
  // bare <pre>, without the wrapper Rouge gives every other fence — and
  // without the frame, the language strip and the copy chip's place that
  // hang on that wrapper. The block is given the wrapper Rouge would have
  // written (this script runs before the copy script, which then finds it).
  main.querySelectorAll('pre > code[class*="language-"]').forEach(function (code) {
    var pre = code.parentNode;
    if (pre.closest('div.highlighter-rouge') || pre.closest('.recipe-entry') || pre.closest('.playground')) {
      return;
    }
    var lang = /(?:^|\s)language-([\w-]+)/.exec(code.className)[1];
    var wrapper = document.createElement('div');
    wrapper.className = 'language-' + lang + ' highlighter-rouge';
    if (pre.hasAttribute('data-file')) {
      wrapper.setAttribute('data-file', pre.getAttribute('data-file'));
      pre.removeAttribute('data-file');
    }
    var inner = document.createElement('div');
    inner.className = 'highlight';
    pre.classList.add('highlight');
    pre.parentNode.insertBefore(wrapper, pre);
    wrapper.appendChild(inner);
    inner.appendChild(pre);
  });

  var blocks = main.querySelectorAll('pre > code, .install code');
  blocks.forEach(function (code) {
    if (code.closest('.playground') || code.closest('.terminal') || code.dataset.highlighted) {
      return;
    }
    code.dataset.highlighted = 'true';
    var lang = languageOf(code);
    var text = code.textContent;

    if (code.closest('.install')) {
      render(code, shell(text));
      return;
    }
    if (lang === 'regex' || lang === 'pcre') {
      render(code, patternLines(text));
      return;
    }
    if (lang === 'neon') {
      render(code, neon(text));
      return;
    }
    if (lang === 'json5') {
      render(code, json5(text));
      return;
    }
    if (lang === '' || lang === 'plaintext' || lang === 'text' || lang === 'output') {
      render(code, onlyPatterns(text) ? patternLines(text) : output(text));
      return;
    }
    if (lang === 'php') {
      repair(code, lang);
      phpPatterns(code);
      return;
    }
    if (lang === 'json' || lang === 'yaml') {
      repair(code, lang);
      return;
    }
    if (lang === 'bash' || lang === 'sh' || lang === 'shell' || lang === 'zsh' || lang === 'console') {
      shellCommands(code);
    }
  });
})();
