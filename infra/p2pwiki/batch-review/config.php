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
	// WHO MAY USE THIS AT ALL.
	//
	// Enforced on every entry point, before anything is read or written.
	// A name not in this list gets 403 and nothing else — no batch list, no
	// item counts, no hint that the tool exists.
	//
	// Add Bryan's username here once he has registered an account. Nothing
	// else needs to change; the list is re-read on every request.
	// ---------------------------------------------------------------------
	'reviewers' => [
		'JeffEmmett',
		'Mbauwens',
		// 'BryanX',   // <- Bryan, once his account exists
	],

	// ---------------------------------------------------------------------
	// THE SAFETY SWITCH.
	//
	// false  = approvals are recorded and the exact wikitext diff is shown,
	//          but NOTHING is written to the wiki. This is the test mode.
	// true   = approvals edit the wiki, attributed to the approver.
	//
	// Flip this only once the three of you are happy with what the previews
	// show. Everything else about the flow is identical either way, so the
	// dry run is a true rehearsal and not a different code path.
	// ---------------------------------------------------------------------
	'live_writes' => false,

	// Refuse to apply more than this many items in one commit, whatever the
	// browser sends. A runaway batch is the main way this could go wrong.
	'max_items_per_commit' => 250,

	// Pause between successive wiki edits, microseconds. Keeps a large
	// approved batch from flooding RecentChanges and the job queue.
	'edit_delay_us' => 400000,

	// Every edit carries this prefix so a batch is greppable and revertable.
	'summary_prefix' => 'batch-review',

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
];
