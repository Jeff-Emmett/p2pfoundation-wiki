<?php
/**
 * Batch review configuration.
 *
 * Served from /p2pwiki-custom/batch-review/ (bind mount ./extensions, read-only).
 * State lives in the writable editor-request-data mount, outside the docroot.
 */

// Not a web entry point. `.htaccess` cannot enforce this on wiki.p2pfoundation.net —
// block-bots.conf carries a site-wide `<Location "/"> Require all granted`, and a
// <Location> is merged after every <Directory> and .htaccess, so it overrides them.
// Guard in PHP instead, which no server config can undo.
if ( !defined( 'BR_ENTRY' ) && PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit;
}
return [
	// ---------------------------------------------------------------------
	// WHO MAY USE THIS AT ALL.  Two people. Nobody else.
	//
	// Enforced on every entry point, before anything is read or written.
	// A name not in this list gets 403 and nothing else — no batch list, no
	// item counts, no hint that the tool exists.
	//
	// Each entry pins BOTH the username and the numeric user id, and both
	// must match. The id is what makes this safe over time: usernames on
	// MediaWiki can be renamed, and both of these accounts hold `bureaucrat`,
	// which is the right that does renaming. Without the id, renaming an
	// account and registering a fresh one under the freed name would hand
	// that name's access to a stranger. With it, the gate follows the
	// account, not the string.
	//
	// Ids read from the live wiki on 4 September 2026:
	//   JeffEmmett  = 2943   (registered 2026-01-31, bureaucrat, sysop)
	//   Mbauwens    = 9      (95,890 edits, bureaucrat, sysop, interface-admin)
	//   Bryan       = 2951   (created 2026-09-04, plain user)
	//
	// DEPLOY NOTE — Bryan (id 2951) added 4 September 2026 at Jeff's explicit
	// request, in this session. He was named as one of the three testers when
	// this tool was commissioned; the list stood at two only because he had no
	// account yet. Account created the same day by maintenance script (this
	// wiki has anonymous creation off and a captcha on the form), email set to
	// his own address, and a temporary password mailed to him by MediaWiki, so
	// nobody else has ever held his credential.
	//
	// 'Claude bot' (id 2950) exists on this wiki and is deliberately NOT here.
	// It is an AI assistant's account, and an approval has to be traceable to
	// a person — the same reason 'MBauwens bot' is excluded. It is used to
	// check that the tool renders and that this gate refuses a logged-in
	// stranger; both of those are things it can do from the wrong side of the
	// gate, which is the point.
	//
	// Note 'MBauwens bot' (id 2946) is a DIFFERENT account and is deliberately
	// not here: a bot password can be handed around, and an approval has to be
	// traceable to a person.
	//
	// Adding anyone else is a deliberate act, not a configuration tweak. It
	// needs the exact username, the numeric id from
	//   api.php?action=query&list=users&ususers=<name>&usprop=groups
	// and a line in the deploy notes saying who asked for it.
	// ---------------------------------------------------------------------
	'reviewers' => [
		[ 'name' => 'JeffEmmett', 'id' => 2943 ],
		[ 'name' => 'Mbauwens',   'id' => 9 ],
		[ 'name' => 'Bryan',      'id' => 2951 ],
	],

	// Refuse a reviewer whose account is currently blocked on the wiki, even
	// if they are on the list above. A block is the wiki's own answer to
	// "should this person be editing right now", and this tool should not be
	// a way around it.
	'refuse_blocked' => true,

	// ---------------------------------------------------------------------
	// THE SAFETY SWITCH.
	//
	// false  = approvals are recorded and the exact wikitext diff is shown,
	//          but NOTHING is written to the wiki. This is the test mode.
	// true   = approvals edit the wiki, attributed to the approver.
	//
	// Flip this only once both of you are happy with what the previews show.
	// Everything else about the flow is identical either way, so the dry run
	// is a true rehearsal and not a different code path.
	//
	// TURNED ON 2026-09-05, at Jeff's explicit request, after he reviewed the
	// merge-spelling batch end to end as JeffEmmett and its dry run came back
	// 14 of 14 with nothing failed. Set it back to false when a run is done,
	// and remember this file is the deployed copy — the repo copy must match or
	// the next deploy silently reverts whichever value it holds.
	// ---------------------------------------------------------------------
	'live_writes' => true,

	// ---------------------------------------------------------------------
	// The applier's guarantees, as numbers.
	// From "Suggested links for the P2P wiki", §What the applier will not do.
	// ---------------------------------------------------------------------

	// Guarantee 07 — rate limited. One edit every five seconds, 200 per run,
	// so a session of approvals cannot look like an attack or flood a
	// watchlist. 5,000,000 µs = 5 s.
	'edit_delay_us' => 5000000,
	'max_items_per_commit' => 200,

	// A commit therefore takes 200 x 5 s = ~17 minutes of wall clock, which no
	// single HTTP request survives — Cloudflare gives up at 100 s and returns
	// a 524. So a commit runs in chunks: each request applies what it can
	// inside this budget, saves, and hands back a Continue button (which
	// auto-submits). The batch only freezes when every approved item has a
	// result, so an abandoned commit is resumable rather than half-done and
	// forgotten.
	'commit_budget_seconds' => 45,
	'commit_chunk_pages'    => 40,   // hard cap on pages touched per request

	// Guarantee 08 — stoppable by anyone. Any text at all on this page halts
	// every commit, immediately, for everybody. It is an ordinary wiki page,
	// so pulling the handle needs no credentials of ours and no access to this
	// tool: an editor who sees something going wrong can stop it themselves.
	// Blank or absent = go.
	//
	// The prefix is 'P2P Foundation Wiki:', which is namespace 4 here — NOT
	// 'P2P Foundation:', which this wiki does not define and which therefore
	// resolves to an ordinary article. Getting that wrong is not cosmetic: an
	// editor pulling the handle would create the project page they can see in
	// the sidebar, the tool would go on reading a different page in the
	// article namespace, and the commit would not stop.
	'stop_page' => 'P2P Foundation Wiki:Batch review/STOP',

	// Guarantee 05 — every edit names its approver. Edits are made AS the
	// approver, so their name is on the revision anyway; carrying it in the
	// summary too means the record survives being copied out of the history.
	'summary_prefix' => 'batch-review',

	// Where the public, on-wiki record of a committed batch is posted. Not
	// automatic: it is a button on a committed batch. The point (from the
	// same document) is that if every piece of our infrastructure disappears,
	// the record of what was decided is still there, on the wiki, in public.
	'record_page_prefix' => 'P2P Foundation Wiki:Batch review/',

	// Where batches and the commit log live. Must be writable by www-data and
	// must NOT be under the docroot. Reuses the existing editor-request mount.
	'data_dir' => getenv( 'BR_DATA_DIR' ) ?: '/var/editor-request-data/batch-review',

	// Internal API endpoint. The app runs inside the wiki container, so this
	// is a loopback call into the same Apache that is serving this page.
	'api_url'  => getenv( 'BR_API_URL' ) ?: 'http://127.0.0.1/api.php',
	'api_host' => getenv( 'BR_API_HOST' ) ?: 'wiki.p2pfoundation.net',

	// Public base, used to build links back into the wiki.
	'wiki_base' => getenv( 'BR_WIKI_BASE' ) ?: 'https://wiki.p2pfoundation.net',

	// Namespace for synthesised drafts. 118 = Draft.
	'draft_prefix' => 'Draft:',

	// ---------------------------------------------------------------------
	// Diigo release gate.
	//
	// A `bookmark-visibility` batch decides, one bookmark at a time, whether
	// a private Diigo bookmark may be released. Approve = public: the URL
	// becomes eligible for the link and article generators, and for the
	// re-import that flips `shared` on Diigo itself. Reject = private: it
	// stays out of the wiki, out of the LLM pipeline and out of the export,
	// permanently.
	//
	// Deciding writes to this ledger and NOTHING else. Nothing is released to
	// any pipeline until someone runs the export explicitly, and the export
	// reads the ledger, never the batch file — so an undecided bookmark can
	// never be swept along by an "approve all".
	// ---------------------------------------------------------------------
	'diigo_ledger' => getenv( 'BR_DIIGO_LEDGER' ) ?: null,   // null = data_dir/diigo/decisions.jsonl
];
