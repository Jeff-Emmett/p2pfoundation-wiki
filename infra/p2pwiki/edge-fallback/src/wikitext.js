/**
 * Minimal MediaWiki-markup → HTML renderer, sized for a downtime fallback.
 *
 * The bar here is READABLE, not faithful. There is no template expansion, no
 * parser functions, no image rendering and no table layout, because doing those
 * properly means running MediaWiki — which is the thing that is unavailable when
 * this code runs. Anything it cannot render it degrades visibly rather than
 * silently dropping, so a reader can tell they are looking at a reduced page.
 *
 * Escaping happens FIRST, before any markup is interpreted. Everything after
 * that point emits tags into already-escaped text, so page content can never
 * introduce markup of its own.
 */

const ESCAPES = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" };

export function escapeHtml(s) {
  return String(s).replace(/[&<>"]/g, (c) => ESCAPES[c]);
}

/**
 * Wiki title → URL path, matching MediaWiki's own spaces-to-underscores rule.
 *
 * Encoded per path segment, so subpage slashes survive as slashes (MediaWiki
 * serves "Foo/Bar" that way) while everything else — crucially "%" — is escaped.
 * encodeURI() would have been the obvious one-liner and is wrong: it leaves "%"
 * alone, so a title containing a literal percent produces a path that decodes
 * back to a DIFFERENT title, and the snapshot lookup misses.
 */
export function titleToPath(title) {
  const underscored = title.trim().replace(/\s+/g, "_");
  return "/" + underscored.split("/").map(encodeURIComponent).join("/");
}

/** URL path → wiki title. Inverse of titleToPath, tolerant of odd input. */
export function pathToTitle(pathname) {
  let raw = pathname.replace(/^\/+/, "");
  if (!raw) return "Main_Page";
  try {
    raw = decodeURIComponent(raw);
  } catch {
    /* malformed percent-encoding: fall through with the raw form */
  }
  return raw.replace(/_/g, " ").trim();
}

/**
 * Strip {{templates}} innermost-first so nesting collapses cleanly.
 * Bounded iteration: a pathological page must not spin the isolate.
 */
function stripTemplates(text) {
  let out = text;
  for (let i = 0; i < 8; i++) {
    const next = out.replace(/\{\{[^{}]*\}\}/g, "");
    if (next === out) break;
    out = next;
  }
  return out;
}

function inline(text) {
  let s = text;

  s = s.replace(/&lt;ref[^&]*?&gt;[\s\S]*?&lt;\/ref&gt;/gi, "");
  s = s.replace(/&lt;\/?[a-zA-Z][^&]*?&gt;/g, "");

  // ''''' bold+italic ''''' , ''' bold ''' , '' italic ''
  s = s.replace(/'''''(.+?)'''''/g, "<strong><em>$1</em></strong>");
  s = s.replace(/'''(.+?)'''/g, "<strong>$1</strong>");
  s = s.replace(/''(.+?)''/g, "<em>$1</em>");

  // [[Target|Label]] and [[Target]] — internal links stay on this host, so a
  // click re-enters the Worker through the front door and fails over in turn.
  s = s.replace(/\[\[([^\]|]+)\|([^\]]+)\]\]/g, (_m, target, label) => {
    return `<a href="${escapeAttr(titleToPath(target))}">${label}</a>`;
  });
  s = s.replace(/\[\[([^\]]+)\]\]/g, (_m, target) => {
    return `<a href="${escapeAttr(titleToPath(target))}">${target}</a>`;
  });

  // [https://example.org Label] and bare [https://example.org]
  s = s.replace(/\[((?:https?|ftp):\/\/[^\s\]]+)\s+([^\]]+)\]/g, (_m, url, label) => {
    return `<a href="${escapeAttr(url)}" rel="nofollow noopener">${label}</a>`;
  });
  s = s.replace(/\[((?:https?|ftp):\/\/[^\s\]]+)\]/g, (_m, url) => {
    return `<a href="${escapeAttr(url)}" rel="nofollow noopener">${url}</a>`;
  });

  return s;
}

function escapeAttr(s) {
  return String(s).replace(/"/g, "%22").replace(/</g, "%3C").replace(/>/g, "%3E");
}

export function renderWikitext(source, title) {
  const text = stripTemplates(escapeHtml(source));
  const lines = text.split(/\r?\n/);
  const out = [];

  let para = [];
  let listStack = []; // 'ul' | 'ol'
  let inTable = false;

  const flushPara = () => {
    if (para.length) {
      out.push(`<p>${inline(para.join(" "))}</p>`);
      para = [];
    }
  };
  const closeLists = () => {
    while (listStack.length) out.push(`</${listStack.pop()}>`);
  };

  for (const line of lines) {
    const l = line.trimEnd();

    // Tables are dropped wholesale — a half-rendered table is worse than a note.
    if (/^\{\|/.test(l)) {
      flushPara();
      closeLists();
      inTable = true;
      out.push('<p class="omitted">[table omitted from the offline copy]</p>');
      continue;
    }
    if (inTable) {
      if (/^\|\}/.test(l)) inTable = false;
      continue;
    }

    if (!l.trim()) {
      flushPara();
      closeLists();
      continue;
    }

    const heading = l.match(/^(={2,6})\s*(.+?)\s*\1$/);
    if (heading) {
      flushPara();
      closeLists();
      const level = Math.min(heading[1].length, 6);
      out.push(`<h${level}>${inline(heading[2])}</h${level}>`);
      continue;
    }

    if (/^-{4,}$/.test(l)) {
      flushPara();
      closeLists();
      out.push("<hr>");
      continue;
    }

    const list = l.match(/^([*#]+)\s*(.*)$/);
    if (list) {
      flushPara();
      const depth = list[1].length;
      const kind = list[1][depth - 1] === "#" ? "ol" : "ul";
      while (listStack.length > depth) out.push(`</${listStack.pop()}>`);
      while (listStack.length < depth) {
        out.push(`<${kind}>`);
        listStack.push(kind);
      }
      out.push(`<li>${inline(list[2])}</li>`);
      continue;
    }

    if (/^[:;]/.test(l)) {
      flushPara();
      closeLists();
      out.push(`<blockquote>${inline(l.replace(/^[:;]+\s*/, ""))}</blockquote>`);
      continue;
    }

    closeLists();
    para.push(l.trim());
  }

  flushPara();
  closeLists();

  return out.join("\n");
}
