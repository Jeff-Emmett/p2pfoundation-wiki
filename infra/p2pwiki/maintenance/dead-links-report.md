# P2P Foundation Wiki — Dead-Link Report (2026-07-22)

Live site health: **all core nav 200 OK** (Main_Page, RecentChanges, Search,
Category:Languages, Peer_Production, Commons_Transition, Michel_Bauwens,
Special:Version, /dumps/).

Scope: internal redirect integrity (full), external link-rot (top-400 of 27,778
domains by link count — high-impact sample, not exhaustive).

---

## 1. Double redirects — 13 (SAFELY FIXABLE, mechanical)

A→B→C chains. Every one resolves to a real final page. Fix = retarget source
straight to final target. Standard `maintenance/fixDoubleRedirects.php`.

| Source | Final target (exists) |
|---|---|
| Bux | Buxxb → BUXXB (chain, needs 2 passes) |
| BUX | Buxxb → BUXXB |
| BUXB | BUXXB |
| Changement global du regime de valeurs | Délicate transition – De l’émergence à la convergence |
| Commons Based Reciprocity Licenses | Commons-Based Reciprocity Licenses |
| Economie des communautes mediatees | Economie des communautés médiatées |
| Eugene Rosenstock-Huessy on the Boby, Soul, and Spirit | Eugen Rosenstock-Huessy on the Body, Soul, and Spirit |
| Network resource planning and contribution accounting | Network Resource Planning |
| NRP | Network Resource Planning |
| P2P TheCommonsManifesto | Peer to Peer: The Commons Manifesto |
| Peter Slodertijk on Allotechnologies vs Homeotechnologies | Peter Sloterdijk on Allotechnologies vs Homeotechnologies |
| Talk:BUX | Talk:Buxxb → Talk:BUXXB |
| Talk:BUXB | Talk:BUXXB |

---

## 2. Broken redirects — 15 (target does NOT exist; need judgment, NOT auto-fixable)

Each points at a page that no longer exists. Corrected-typo guesses also 404,
so targets were deleted/renamed. Per-item decision: retarget to real page, or delete.

| Broken redirect | Points to (missing) | Note |
|---|---|---|
| Mode of exchange | Mode of Eexchange | typo target; "Mode of Exchange" also absent |
| Common Resources in a P2P Network | Common Resource | plural/singular both absent |
| Common Resources Distribution Pool | P2P Resource Distribution Pool | target absent |
| Drieghe, Geert | Geert Drieghe | target absent |
| Posting and Publishing Feeds | OccupyWeb Posting and Publishing Feeds | target absent |
| P2P Property Development and Management | P2P Development and Management of Common Resources | target absent |
| P2P Property Development and Management (dup entry) | — | — |
| P2P Foundation Knowledge Commons Peer Property Channel | P2P Foundation Channels | target absent |
| Open Source Medical Imaging | :Category:Medical Imaging | category absent |
| Category:Open Intelligence | :Category:Open Decision-Support | category absent |
| Category:P2P Wiki Projects | :Category:P2P Resource Collections | category absent |
| WikiSprint - 20M/es | Form:WikiSprint20M-es | Form namespace target absent |
| WikiSprint - 20M-es | Form:WikiSprint20M-es | duplicate of above |
| Template:Deliciousfeed | Help:Adding RSS Feeds | help page absent |
| P2P Foundation Wiki:Netconfirmed | Help:Become A Confirmed Wiki Editor | help page absent |
| **User talk:RhebaVanwycks** | **User talk:アダルト** | **SPAM** (Japanese "adult"); delete both |

---

## 3. External dead domains — top-400 sample (report-only; Wayback-fixable)

**Genuinely dead** (DNS no longer resolves, or HTTP 410 Gone). Fix path =
rewrite to `web.archive.org` snapshot. Link counts = occurrences across wiki.

| Links | Domain | Signal |
|---|---|---|
| 222 | www.osbr.ca | DNSFAIL |
| 206 | www.worldchanging.com | dead host |
| 146 | web.mae.cornell.edu | DNSFAIL |
| 106 | www.re-public.gr | HTTP 410 Gone |
| 96 | blogs.salon.com | DNSFAIL |
| 95 | wikis.fu-berlin.de | dead host |
| 81 | medialab-prado.es | DNSFAIL |
| 72 | opensource.mit.edu | dead host |
| 64 | www.ctheory.net | DNSFAIL |
| 61 | www.didiy.eu | dead host |
| 57 | www.culturemagic.org | dead host |
| 51 | www.thenextlayer.org | DNSFAIL |
| 51 | cadelllast.files.wordpress.com | HTTP 404 |
| 47 | commonsabundance.net | HTTP 404 |
| 47 | guerrillatranslation.com | DNSFAIL |
| 45 | paigrain.debatpublic.net | DNSFAIL |
| 44 | antimatters2.files.wordpress.com | HTTP 404 |
| 42 | www.cooperationcommons.com | DNSFAIL |
| 41 | www.taller-commons.com | DNSFAIL |
| 40 | fair.coop | DNSFAIL |
| 39 | kruufm.com | dead host |
| 39 | www.firstmondaypodcast.org | DNSFAIL |
| 39 | blog.futurestreetconsulting.com | DNSFAIL |
| 36 | info.interactivist.net | DNSFAIL |
| 34 | webcast.oii.ox.ac.uk | HTTP 404 |
| 34 | rccs.usfca.edu | DNSFAIL |
| 32 | www.adciv.org | DNSFAIL |
| 32 | cspp.oekonux.org | DNSFAIL |
| 31 | autonomo.us | DNSFAIL |
| 29 | www.guerrillatranslation.es | HTTP 410 Gone |
| 29 | www.civilizingtheeconomy.com | DNSFAIL |
| 29 | surprisinglyfree.com | DNSFAIL |

**FALSE POSITIVES (bot-blocked, NOT dead — leave alone):**
www.amazon.com / www.amazon.co.uk (503), www.washingtonpost.com,
online.wsj.com (404 to bots) — these serve real content to browsers.

Caveat: only the 400 most-linked domains were probed. ~27,000 long-tail
domains unscanned. Domain-alive ≠ every URL alive (deep-link rot not checked).
