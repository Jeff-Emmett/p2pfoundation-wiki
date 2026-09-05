<?php
/** Shared page chrome. Deliberately plain — this is an operator tool. */

// Not a web entry point. `.htaccess` cannot enforce this on wiki.p2pfoundation.net —
// block-bots.conf carries a site-wide `<Location "/"> Require all granted`, and a
// <Location> is merged after every <Directory> and .htaccess, so it overrides them.
// Guard in PHP instead, which no server config can undo.
if ( !defined( 'BR_ENTRY' ) && PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit;
}

function br_head( $title, array $user ) {
	$cfg  = br_config();
	$live = $cfg['live_writes'];
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'Referrer-Policy: same-origin' );
	?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= br_h( $title ) ?> · Batch review</title>
<style>
:root{
  --bg:#f7f8f6; --card:#fff; --ink:#1b2320; --ink2:#4d5a54; --muted:#77837c;
  --rule:#dfe4dd; --rule2:#eef1ec; --accent:#186052; --accent-bg:#e6f0ec;
  --warn:#8a5b12; --warn-bg:#fdf3de; --bad:#8f3620; --bad-bg:#fae9e3;
}
@media (prefers-color-scheme:dark){:root{
  --bg:#14180f; --card:#1b201a; --ink:#e6ebe3; --ink2:#b0b9ae; --muted:#8a938a;
  --rule:#2e352c; --rule2:#242a23; --accent:#6bc4ad; --accent-bg:#1c302b;
  --warn:#d8ae55; --warn-bg:#332a17; --bad:#e08a6c; --bad-bg:#33211b;
}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);
  font:14.5px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.wrap{max-width:1120px;margin:0 auto;padding:0 20px 80px}
header.top{border-bottom:1px solid var(--rule);padding:18px 0 14px;margin-bottom:0}
header.top .row{display:flex;flex-wrap:wrap;gap:12px;align-items:baseline;justify-content:space-between}
h1{font-size:1.25rem;margin:0;letter-spacing:-.01em}
h1 a{color:inherit;text-decoration:none}
.who{font-size:12.5px;color:var(--muted)}
.who b{color:var(--ink)}
a{color:var(--accent)}
.banner{border:1px solid var(--rule);border-left:4px solid var(--warn);
  background:var(--warn-bg);color:var(--warn);padding:11px 15px;margin:16px 0;font-size:13.5px}
.banner.live{border-left-color:var(--bad);background:var(--bad-bg);color:var(--bad)}
.banner b{font-weight:600}
h2{font-size:1.05rem;margin:26px 0 4px;letter-spacing:-.008em}
p.sub{color:var(--ink2);margin:0 0 14px;max-width:62rem}
table{width:100%;border-collapse:collapse;background:var(--card);
  border:1px solid var(--rule);font-size:13.5px}
th{text-align:left;font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;
  color:var(--muted);font-weight:600;padding:9px 12px;border-bottom:1px solid var(--rule);white-space:nowrap}
td{padding:8px 12px;border-bottom:1px solid var(--rule2);vertical-align:top}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:var(--rule2)}
.scroll{overflow-x:auto;margin:14px 0}
code,.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px}
.num{text-align:right;font-variant-numeric:tabular-nums;font-family:ui-monospace,monospace;white-space:nowrap}
.pill{display:inline-block;font-size:10.5px;font-weight:600;letter-spacing:.05em;
  text-transform:uppercase;padding:2px 7px;border-radius:2px;white-space:nowrap}
.p-open{background:var(--accent-bg);color:var(--accent)}
.p-committed{background:var(--rule2);color:var(--muted)}
.p-ready{background:var(--accent-bg);color:var(--accent)}
.p-already{background:var(--rule2);color:var(--muted)}
.p-missing,.p-error{background:var(--bad-bg);color:var(--bad)}
.p-dry,.p-stale{background:var(--warn-bg);color:var(--warn)}
.p-blocked{background:var(--bad-bg);color:var(--bad)}
.btn{display:inline-block;font:inherit;font-size:13px;font-weight:500;padding:7px 14px;
  border:1px solid var(--rule);background:var(--card);color:var(--ink);
  border-radius:3px;cursor:pointer;text-decoration:none}
.btn:hover{border-color:var(--accent);color:var(--accent)}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
.btn.primary:hover{opacity:.9;color:#fff}
.btn.danger{border-color:var(--bad);color:var(--bad)}
.bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:14px 0}
.bar .spacer{flex:1}
.why{color:var(--ink2);font-size:12.8px}
.ev{color:var(--muted);font-size:12px;font-family:ui-monospace,monospace}
.diff{font-family:ui-monospace,monospace;font-size:12.3px;white-space:pre-wrap;
  background:var(--bg);border:1px solid var(--rule);padding:7px 9px;margin:5px 0 0}
.diff .add{color:var(--accent)}
.diff .del{color:var(--bad)}
fieldset.dec{border:0;padding:0;margin:0;display:flex;gap:9px;white-space:nowrap}
fieldset.dec label{font-size:12px;color:var(--ink2);cursor:pointer;display:flex;gap:3px;align-items:center}
.counts{font-size:12.5px;color:var(--muted);font-variant-numeric:tabular-nums}
.counts b{color:var(--ink)}
footer{margin-top:44px;padding-top:16px;border-top:1px solid var(--rule);
  font-size:12.5px;color:var(--muted);max-width:62rem}
.pager{display:flex;gap:6px;flex-wrap:wrap;margin:14px 0;font-size:13px}
.pager a,.pager span{padding:3px 9px;border:1px solid var(--rule);border-radius:2px;text-decoration:none}
.pager span{color:var(--muted);background:var(--rule2)}
</style>
</head><body><div class="wrap">
<header class="top"><div class="row">
  <h1><a href="index.php">Batch review</a></h1>
  <div class="who">signed in as <b><?= br_h( $user['name'] ) ?></b> ·
    <a href="<?= br_h( $cfg['wiki_base'] ) ?>">back to the wiki</a></div>
</div></header>
<?php if ( $live ): ?>
<div class="banner live"><b>Live writes are ON.</b> Approving an item edits
wiki.p2pfoundation.net immediately, attributed to you. Every edit carries the batch id in
its summary, so a whole batch can be found and reverted from Special:Contributions.</div>
<?php else: ?>
<div class="banner"><b>Dry run.</b> Approvals are recorded and you will see the exact change
each one would make, but nothing is written to the wiki. Set <code>'live_writes' =&gt; true</code>
in <code>config.php</code> when you are ready to apply them for real.</div>
<?php endif;
}

/**
 * Names the people who can actually get in, read from the same config the gate
 * uses. This used to be the hardcoded sentence "Two accounts ... JeffEmmett and
 * Mbauwens", which silently became a lie the moment a third reviewer was added:
 * the footer told every reviewer that they were not one.
 */
function br_reviewer_sentence() {
	static $words = [ 1 => 'One account', 2 => 'Two accounts', 3 => 'Three accounts',
	                  4 => 'Four accounts', 5 => 'Five accounts' ];
	$names = array_column( br_config()['reviewers'] ?? [], 'name' );
	$n     = count( $names );
	if ( !$n ) {
		return 'Nobody';
	}
	$who = $n === 1
		? $names[0]
		: implode( ', ', array_slice( $names, 0, -1 ) ) . ' and ' . end( $names );
	return ( $words[$n] ?? ( $n . ' accounts' ) ) . ' — ' . $who . ' —';
}

function br_foot() {
	?>
<footer>
Closed testing. <?= br_h( br_reviewer_sentence() ) ?> can reach this page, pinned by
username <em>and</em> numeric user id. Everyone else gets a 403 with no detail, logged. Proposals are
generated offline by the scripts in <code>generate/</code>; this page only reviews and applies them,
never writes anything you have not approved, makes at most one edit per page however many items it
carries, and stops dead if anyone writes anything at all on
<code><?= br_h( br_config()['stop_page'] ) ?></code>.
</footer>
</div></body></html>
<?php
}
