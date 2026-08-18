# Broken-redirect fix proposal — 14 remaining (2026-07-22)

Status: 15 originally; `User talk:RhebaVanwycks` (spam) already **deleted**.
Each below points at a page that does not exist. Recommended action per item.
None applied yet — awaiting sign-off (Michel for content calls).

Legend: **RETARGET** = repoint to a real page (high confidence) · **DELETE** =
remove the dangling redirect · **ASK** = needs Michel's judgment.

| # | Broken redirect | Current (dead) target | Recommendation | Retarget to |
|---|---|---|---|---|
| 1 | Mode of exchange | Mode of Eexchange | **RETARGET** ✅ | **Modes of Exchange** (exists) |
| 2 | Category:P2P Wiki Projects | :Category:P2P Resource Collections | **RETARGET** (likely) | P2P Foundation Resource Collections (exists; confirm intent) |
| 3 | WikiSprint - 20M/es | Form:WikiSprint20M-es | **RETARGET** or DELETE | WikiSprint (exists) — or 2013 Spanish-Language Wikisprint page |
| 4 | WikiSprint - 20M-es | Form:WikiSprint20M-es | **DELETE** (dup of #3) | — |
| 5 | Drieghe, Geert | Geert Drieghe | **DELETE** | no "Geert Drieghe" page exists; "Lastname, First" convention unused |
| 6 | Common Resources in a P2P Network | Common Resource | **ASK** | closest live: Common Pool Resource / Commons |
| 7 | Common Resources Distribution Pool | P2P Resource Distribution Pool | **ASK** | closest live: Commitment Pooling / Commons |
| 8 | P2P Property Development and Management | P2P Development and Management of Common Resources | **ASK** | no clear live target |
| 9 | P2P Foundation Knowledge Commons Peer Property Channel | P2P Foundation Channels | **ASK** | closest: P2P Foundation IRC Channel |
| 10 | Posting and Publishing Feeds | OccupyWeb Posting and Publishing Feeds | **DELETE** | OccupyWeb content gone; no live target |
| 11 | Template:Deliciousfeed | Help:Adding RSS Feeds | **DELETE** | del.icio.us defunct; template unused |
| 12 | P2P Foundation Wiki:Netconfirmed | Help:Become A Confirmed Wiki Editor | **ASK** | help page missing; may want to recreate help page |
| 13 | Open Source Medical Imaging | :Category:Medical Imaging | **RETARGET?** | InVesalius (top match) or recreate category |
| 14 | Category:Open Intelligence | :Category:Open Decision-Support | **ASK** | closest: Open Source Everything Engineering |

## Safe-to-apply now (high confidence)
- **#1 Mode of exchange → Modes of Exchange** — pure typo fix, target exists.
- **#4 WikiSprint - 20M-es** — delete (exact duplicate of #3).
- **#5 Drieghe, Geert** — delete (naming-convention artifact, no target).
- **#10, #11** — delete (source sites/features defunct).

## Mechanics (when approved)
Retarget: overwrite page with `#REDIRECT [[Target]]` via API edit (bot login)
or `docker exec p2pwiki php maintenance/edit.php`. Delete:
`printf 'Title\n' | docker exec -i p2pwiki php maintenance/deleteBatch.php -r "reason"`.
