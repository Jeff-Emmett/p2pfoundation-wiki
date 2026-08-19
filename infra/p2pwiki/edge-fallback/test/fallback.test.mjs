/**
 * Run: node --test test/
 *
 * The important test here is the key-scheme one. Everything else in this Worker
 * fails loudly; a key-scheme drift between build-snapshot.py and worker.js fails
 * GREEN — every R2 lookup misses, readers silently get the generic offline page
 * instead of the article, and nothing anywhere reports an error.
 */

import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

import {
  isOriginFailure,
  isDynamicPath,
  isAssetPath,
  snapshotKey,
  looksLikeBareProxy404,
} from "../src/worker.js";
import { renderWikitext, titleToPath, pathToTitle, escapeHtml } from "../src/wikitext.js";

const here = dirname(fileURLToPath(import.meta.url));
const fixture = JSON.parse(readFileSync(join(here, "key-fixture.json"), "utf8"));

test("key scheme matches build-snapshot.py for every fixture title", () => {
  assert.ok(fixture.length > 500, "fixture should be substantial");
  const mismatches = [];
  for (const { title, expected } of fixture) {
    const got = snapshotKey(title);
    if (got !== `pages/${expected}`) mismatches.push({ title, expected: `pages/${expected}`, got });
  }
  assert.deepEqual(mismatches, [], `${mismatches.length} key mismatches`);
});

test("title survives the full URL round trip title -> path -> title -> key", () => {
  const mismatches = [];
  for (const { title, expected } of fixture) {
    const recovered = pathToTitle(titleToPath(title));
    const got = snapshotKey(recovered);
    if (got !== `pages/${expected}`) mismatches.push({ title, recovered, expected: `pages/${expected}`, got });
  }
  assert.deepEqual(mismatches, [], `${mismatches.length} round-trip mismatches`);
});

test("percent and slash stay distinguishable", () => {
  // If these ever collide, two different articles map to one object.
  assert.notEqual(snapshotKey("Foo/Bar"), snapshotKey("Foo%2FBar"));
  assert.equal(snapshotKey("A/B/C"), "pages/A%2FB%2FC");
  assert.equal(snapshotKey("Foo%Bar"), "pages/Foo%25Bar");
});

test("origin failure detection covers the tunnel case and nothing extra", () => {
  assert.equal(isOriginFailure(530), true, "530/1033 is the tunnel-down case this exists for");
  for (const s of [520, 521, 522, 523, 524, 525, 526, 527]) {
    assert.equal(isOriginFailure(s), true, `${s} should be a failure`);
  }
  // A host that answers is a host that is up — different failure, different response.
  for (const s of [200, 301, 404, 429, 500, 502, 503, 504]) {
    assert.equal(isOriginFailure(s), false, `${s} must NOT trigger the fallback`);
  }
});

test("dynamic paths are never served from a static snapshot", () => {
  assert.equal(isDynamicPath("/Special:Search", ""), true);
  assert.equal(isDynamicPath("/special:recentchanges", ""), true);
  assert.equal(isDynamicPath("/index.php", "?title=Foo&action=edit"), true);
  assert.equal(isDynamicPath("/api.php", ""), true);
  assert.equal(isDynamicPath("/load.php", "?modules=skins"), true);
  assert.equal(isDynamicPath("/Peer_to_Peer", "?action=edit"), true);
  assert.equal(isDynamicPath("/Peer_to_Peer", ""), false);
  assert.equal(isDynamicPath("/Commons_Transition", ""), false);
});

test("assets are recognised so they never receive the HTML offline page", () => {
  for (const p of [
    "/images/a/ab/Logo.png",
    "/skins/Vector/print.css",
    "/resources/lib/jquery.js",
    "/dumps/p2pwiki-latest-current.xml.bz2",
    "/favicon.ico",
    "/fonts/x.woff2",
  ]) {
    assert.equal(isAssetPath(p), true, `${p} should be an asset`);
  }
  for (const p of ["/Peer_to_Peer", "/Commons_Transition", "/Main_Page", "/Category:Commons"]) {
    assert.equal(isAssetPath(p), false, `${p} is a document, not an asset`);
  }
});

test("wikitext renders the constructs that carry the page", () => {
  const html = renderWikitext(
    [
      "==Short Definition==",
      "",
      "'''Peer to peer''' is a ''relational dynamic'' in [[Distributed Networks]].",
      "",
      "* first",
      "* second",
      "",
      "See [[Peer Production|peer production]] and [https://p2pfoundation.net the site].",
    ].join("\n"),
    "Peer to Peer"
  );

  assert.match(html, /<h2>Short Definition<\/h2>/);
  assert.match(html, /<strong>Peer to peer<\/strong>/);
  assert.match(html, /<em>relational dynamic<\/em>/);
  assert.match(html, /<a href="\/Distributed_Networks">Distributed Networks<\/a>/);
  assert.match(html, /<a href="\/Peer_Production">peer production<\/a>/);
  assert.match(html, /<a href="https:\/\/p2pfoundation\.net"[^>]*>the site<\/a>/);
  assert.match(html, /<li>first<\/li>/);
});

test("page content cannot inject markup", () => {
  const html = renderWikitext(
    `<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\n[[A<b>B]]`,
    "Nasty"
  );
  assert.ok(!/<script/i.test(html), "no script tag may survive");
  assert.ok(!/onerror=/i.test(html), "no inline handler may survive");
  assert.ok(!/<img/i.test(html), "no raw img may survive");
});

test("templates and tables degrade visibly rather than silently", () => {
  const html = renderWikitext("{{Infobox|a=1}}Body text here.", "T");
  assert.match(html, /Body text here\./);
  assert.ok(!/Infobox/.test(html), "template markup should not leak into the page");

  const table = renderWikitext('{|\n|a\n|b\n|}\n\nAfter the table.', "T");
  assert.match(table, /table omitted/);
  assert.match(table, /After the table\./);
});

test("escapeHtml is applied before markup interpretation", () => {
  assert.equal(escapeHtml('<a href="x">&'), "&lt;a href=&quot;x&quot;&gt;&amp;");
});

// 2026-08-19: the wiki served a hard 404 to every reader for ~40 minutes while
// the origin was healthy and the fallback sat idle, because a revived tunnel
// put a Traefik with no matching router in front of it. 404 was not a failure
// status, so the Worker forwarded the proxy's own 404 straight through.
test("a bare reverse-proxy 404 counts as an unreachable origin", () => {
  const bare = {
    status: 404,
    contentType: "text/plain; charset=utf-8",
    contentLength: "19",
    body: "404 page not found\n",
  };
  assert.ok(looksLikeBareProxy404(bare), "cloudflared/Traefik http.NotFound must trigger the fallback");
  assert.ok(
    looksLikeBareProxy404({ ...bare, body: "404 page not found" }),
    "the same body without the trailing newline is the same failure"
  );
});

test("a real MediaWiki 404 is never mistaken for a dead origin", () => {
  // A missing article is a real answer. Serving a snapshot over it would be a
  // downgrade, so every property that distinguishes it must be load-bearing.
  assert.ok(
    !looksLikeBareProxy404({
      status: 404,
      contentType: "text/html; charset=UTF-8",
      contentLength: "4334",
      body: "<!DOCTYPE html><html>...there is currently no text in this page...",
    }),
    "MediaWiki's HTML 404 must pass through"
  );
  assert.ok(
    !looksLikeBareProxy404({
      status: 404,
      contentType: "text/plain; charset=utf-8",
      contentLength: "220",
      body: "404 page not found\n",
    }),
    "an oversized body is not the 19-byte signature"
  );
  assert.ok(
    !looksLikeBareProxy404({
      status: 404,
      contentType: "text/plain; charset=utf-8",
      contentLength: null,
      body: "404 page not found\n",
    }),
    "no content-length means no bounded read, so no classification"
  );
  assert.ok(
    !looksLikeBareProxy404({
      status: 200,
      contentType: "text/plain; charset=utf-8",
      contentLength: "19",
      body: "404 page not found\n",
    }),
    "an article whose text happens to be that string is still a 200"
  );
});
