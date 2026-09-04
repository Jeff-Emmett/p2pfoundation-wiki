<?php
/**
 * Final confirmation and application.
 *
 * GET  — plan every approved item against the wiki as it stands and show the
 *        exact change each would make. Nothing is written.
 * POST — apply them, one edit per page, with a pause between edits. In dry-run
 *        mode the planning is identical and the writes are simply not sent.
 *
 * A commit runs in CHUNKS. At one edit every five seconds, 200 items is about
 * seventeen minutes, and no HTTP request survives that — Cloudflare gives up at
 * 100 seconds. So each request applies what fits inside its budget, saves, and
 * hands back a Continue button. The batch freezes only when every approved item
 * has a result, which makes an interrupted commit resumable rather than
 * half-done and forgotten.
 */
define( 'BR_ENTRY', 1 );
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/_chrome.php';

$user = br_require_reviewer();
$cfg  = br_config();
$id   = $_REQUEST['id'] ?? '';
$b    = br_load_batch( $id );
if ( !$b ) {
	http_response_code( 404 );
	br_head( 'Not found', $user );
	echo '<h2>No such batch</h2>';
	br_foot();
	exit;
}

$approved = array_values( array_filter( $b['items'], static function ( $it ) {
	return ( $it['decision'] ?? null ) === 'approve';
} ) );
$frozen  = ( $b['status'] ?? 'open' ) !== 'open';
$stop    = br_stop_state();
$report  = [];
$ran     = false;
$halted  = false;
$remaining = 0;

/** Items of this run that still have no result. */
function br_pending_groups( array $b, array $approved, $runId ) {
	$pending = [];
	foreach ( br_group_items( $approved ) as $key => $items ) {
		$todo = [];
		foreach ( $items as $it ) {
			$r = null;
			foreach ( $b['items'] as $row ) {
				if ( (int)$row['n'] === (int)$it['n'] ) { $r = $row['result'] ?? null; break; }
			}
			if ( !$r || ( $r['run'] ?? null ) !== $runId ) {
				$todo[] = $it;
			}
		}
		if ( $todo ) { $pending[$key] = $todo; }
	}
	return $pending;
}

// -------------------------------------------------------------------------
// apply
// -------------------------------------------------------------------------
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && !$frozen && ( $_POST['action'] ?? '' ) !== 'record' ) {
	if ( !br_check_csrf( $user['name'], $b['id'], $_POST['csrf'] ?? '' ) ) {
		br_deny( 'That form has expired. Reload and try again.' );
	}
	if ( $cfg['live_writes'] && empty( $_POST['confirm'] ) && empty( $_POST['resume'] ) ) {
		br_deny( 'Live writes are on and the confirmation box was not ticked.' );
	}
	// The cap and the delay are the rate-limit guarantee, and a rate limit is
	// about EDITS. A bookmark decision writes one line to a local ledger and
	// touches neither the wiki nor the network, so counting those against an
	// edit budget would only make a privacy review needlessly slow.
	$writing = array_values( array_filter( $approved, static function ( $it ) {
		return ( $it['op'] ?? '' ) !== 'classify-bookmark';
	} ) );
	if ( count( $writing ) > $cfg['max_items_per_commit'] ) {
		br_deny( 'This batch has ' . count( $writing ) . ' approved items that would edit the wiki, '
			. 'above the ' . $cfg['max_items_per_commit'] . '-item cap in config.php. That cap is the '
			. 'rate-limit guarantee, not a performance setting — reject some, split the batch, or '
			. 'raise it deliberately.' );
	}

	// Guarantee 08 — anyone can stop this, from an ordinary wiki page.
	if ( $stop['halted'] ) {
		$halted = true;
		br_audit( 'commit.halted', [ 'user' => $user['name'], 'batch' => $b['id'],
			'unreadable' => $stop['unreadable'] ] );
	} else {
		$new = empty( $b['commit'] ) || !empty( $b['commit']['done'] );
		if ( $new ) {
			$b['commit'] = [
				'run'     => gmdate( 'Ymd-His' ) . '-' . substr( bin2hex( random_bytes( 3 ) ), 0, 6 ),
				'by'      => $user['name'],
				'started' => gmdate( 'c' ),
				'live'    => (bool)$cfg['live_writes'],
				'done'    => false,
			];
		}
		$runId = $b['commit']['run'];

		$byN = [];
		foreach ( $b['items'] as $i => $it ) {
			$byN[(string)$it['n']] = $i;
		}

		$pending = br_pending_groups( $b, $approved, $runId );
		$deadline = microtime( true ) + (float)$cfg['commit_budget_seconds'];
		$pages = 0;
		$first = true;

		foreach ( $pending as $key => $items ) {
			$isWrite = ( $items[0]['op'] ?? '' ) !== 'classify-bookmark';
			if ( ( $isWrite && $pages >= (int)$cfg['commit_chunk_pages'] ) || microtime( true ) > $deadline ) {
				break;
			}
			// Guarantee 07 — one edit every five seconds. The pause goes BEFORE
			// each edit but the first, so a chunk boundary never skips it. It
			// applies to wiki edits only; a ledger line is not an edit.
			if ( !$first && $isWrite && $cfg['live_writes'] ) {
				usleep( (int)$cfg['edit_delay_us'] );
			}
			$first = $first && !$isWrite;

			$g = br_apply_group( $b, $items, $user['name'] );
			foreach ( $g['results'] as $n => $res ) {
				$res['run'] = $runId;
				$i = $byN[(string)$n] ?? null;
				if ( $i !== null ) {
					$b['items'][$i]['result'] = $res;
				}
				$report[] = [ 'n' => $n, 'target' => $items[0]['target'], 'res' => $res ];
			}
			if ( $isWrite ) { $pages++; }
			$ran = true;
		}

		br_save_batch( $b );
		$b = br_load_batch( $id );
		$remaining = count( br_pending_groups( $b, $approved, $runId ) );

		if ( $remaining === 0 ) {
			$b['commit']['done']      = true;
			$b['commit']['finished']  = gmdate( 'c' );
			// A dry run is a rehearsal, not a commit: it records what would have
			// happened but leaves the batch open, so the same batch can be
			// rehearsed as often as you like and applied for real once
			// live_writes is on. Only a real write freezes the batch.
			if ( $cfg['live_writes'] ) {
				$b['status']         = 'committed';
				$b['committed_by']   = $user['name'];
				$b['committed_at']   = gmdate( 'c' );
				$b['committed_live'] = true;
			} else {
				$b['last_dry_run']    = gmdate( 'c' );
				$b['last_dry_run_by'] = $user['name'];
			}
			br_save_batch( $b );
			br_log_commit( [
				'batch' => $b['id'], 'by' => $user['name'], 'at' => gmdate( 'c' ),
				'run'   => $runId,
				'live'  => (bool)$cfg['live_writes'],
				'items' => array_map( static function ( $it ) {
					return [ 'n' => $it['n'], 'target' => $it['target'], 'res' => $it['result'] ?? null ];
				}, array_values( array_filter( $b['items'], static function ( $it ) use ( $runId ) {
					return ( $it['result']['run'] ?? null ) === $runId;
				} ) ) ),
			] );
		}
		br_audit( 'commit.chunk', [ 'user' => $user['name'], 'batch' => $b['id'],
			'run' => $runId, 'pages' => $pages, 'remaining' => $remaining,
			'live' => (bool)$cfg['live_writes'] ] );
		$frozen = ( $b['status'] ?? 'open' ) !== 'open';
	}
}

// -------------------------------------------------------------------------
// publish the on-wiki record of a committed batch
// -------------------------------------------------------------------------
$recordNotice = '';
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['action'] ?? '' ) === 'record' ) {
	if ( !br_check_csrf( $user['name'], $b['id'], $_POST['csrf'] ?? '' ) ) {
		br_deny( 'That form has expired. Reload and try again.' );
	}
	$page = rtrim( $cfg['record_page_prefix'], '/' ) . '/' . $b['id'];
	$res  = br_edit_page( $page, br_record_wikitext( $b, $cfg ),
		'[' . $cfg['summary_prefix'] . ' ' . $b['id'] . '] decision record, published by ' . $user['name'],
		null, null, false );
	$recordNotice = $res['status'] === 'ok' || $res['status'] === 'already'
		? 'Record published to ' . $page . '.'
		: 'Could not publish the record: ' . ( $res['msg'] ?? '?' );
	br_audit( 'record.publish', [ 'user' => $user['name'], 'batch' => $b['id'],
		'page' => $page, 'status' => $res['status'] ] );
	$b = br_load_batch( $id );
	$frozen = ( $b['status'] ?? 'open' ) !== 'open';
}

/**
 * The public record: what was proposed, who decided what, and what happened.
 * The argument for it is not tidiness — it is that if every piece of our
 * infrastructure disappears, the record of what was decided is still there, on
 * the wiki, in public, and the wiki's own history says who wrote it.
 */
function br_record_wikitext( array $b, array $cfg ) {
	$c = br_batch_counts( $b );
	$out  = "''This page is a record, written by the batch-review tool. It is not a proposal and "
		. "nothing on it is pending.''\n\n";
	$out .= "== " . ( $b['title'] ?? $b['id'] ) . " ==\n";
	$out .= "* Batch: <code>" . $b['id'] . "</code> (" . ( $b['kind'] ?? '?' ) . ")\n";
	$out .= "* Generated: " . substr( (string)( $b['created'] ?? '' ), 0, 19 ) . "\n";
	$out .= "* Committed: " . substr( (string)( $b['committed_at'] ?? '' ), 0, 19 )
		. " by [[User:" . ( $b['committed_by'] ?? '?' ) . "|" . ( $b['committed_by'] ?? '?' ) . "]]"
		. ( empty( $b['committed_live'] ) ? " ''(dry run — nothing was written)''" : "" ) . "\n";
	$out .= "* " . $c['approve'] . " approved · " . $c['reject'] . " rejected · "
		. $c['undecided'] . " left undecided\n\n";
	$out .= ( $b['rationale'] ?? '' ) . "\n\n";
	$out .= "{| class=\"wikitable sortable\"\n! # !! page !! change !! decision !! by !! result\n";
	foreach ( $b['items'] as $it ) {
		$t = (string)$it['target'];
		$link = preg_match( '#^https?://#i', $t ) ? $t : '[[:' . $t . ']]';
		$out .= "|-\n| " . (int)$it['n'] . " || " . $link . " || <code>"
			. str_replace( [ '[', ']', '|' ], [ '&#91;', '&#93;', '&#124;' ], br_item_phrase( $it ) ) . "</code> || "
			. ( $it['decision'] ?? '—' ) . " || " . ( $it['decided_by'] ?? '' ) . " || "
			. ( $it['result']['status'] ?? '' ) . "\n";
	}
	$out .= "|}\n\n[[Category:P2P Foundation Wiki]]\n";
	return $out;
}

$csrf = br_csrf( $user['name'], $b['id'] );
$c    = br_batch_counts( $b );
$run  = $b['commit'] ?? null;
$inFlight = $run && empty( $run['done'] );

br_head( 'Commit · ' . ( $b['title'] ?? $b['id'] ), $user );
?>
<h2><?= $ran ? ( $remaining ? 'Committing' : 'Committed' ) : 'Commit' ?>: <?= br_h( $b['title'] ?? $b['id'] ) ?></h2>
<p class="sub"><a href="batch.php?id=<?= br_h( rawurlencode( $b['id'] ) ) ?>">← back to the item list</a></p>

<?php if ( $recordNotice ): ?><div class="banner"><?= br_h( $recordNotice ) ?></div><?php endif; ?>

<?php if ( $stop['halted'] ): ?>
<div class="banner live">
  <b>Stopped.</b>
  <?php if ( $stop['unreadable'] ): ?>
  The stop page <code><?= br_h( $stop['page'] ) ?></code> could not be read, and with live writes on
  that counts as stopped — a handle that fails silent is not a handle. Try again when the wiki API
  is answering.
  <?php else: ?>
  Somebody has written to <a href="<?= br_h( br_wiki_link( $stop['page'] ) ) ?>"><code><?= br_h( $stop['page'] ) ?></code></a>,
  which halts every commit for everyone until that page is blanked. It says:
  <em><?= br_h( mb_substr( $stop['text'], 0, 400 ) ) ?></em>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ( $ran ):
	$ok = $err = $skip = 0;
	foreach ( $report as $r ) {
		$s = $r['res']['status'];
		if ( $s === 'ok' || $s === 'dry-run' ) { $ok++; }
		elseif ( $s === 'error' )              { $err++; }
		else                                   { $skip++; }
	} ?>
<div class="banner<?= $err ? ' live' : '' ?>">
  This pass: <b><?= $ok ?></b> <?= $cfg['live_writes'] ? 'applied' : 'would have been applied (dry run)' ?> ·
  <b><?= $skip ?></b> already done or not applicable ·
  <b><?= $err ?></b> failed.
  <?php if ( $remaining ): ?>
  <b><?= $remaining ?> pages still to go.</b> Each request stops after
  <?= (int)$cfg['commit_budget_seconds'] ?> seconds so the connection does not time out; press
  Continue (or leave the box ticked and it goes on by itself).
  <?php elseif ( !$cfg['live_writes'] ): ?>
  Nothing was written to the wiki, and <b>this batch is still open</b> — a dry run is a
  rehearsal, not a commit. Run it as often as you like, change your decisions and run it
  again. When <code>'live_writes' =&gt; true</code> is set in <code>config.php</code>,
  pressing the button on this same batch applies it for real.
  <?php else: ?>
  Every edit is in your contributions with <code>[<?= br_h( $cfg['summary_prefix'] ) ?>
  <?= br_h( $b['id'] ) ?>]</code> in the summary.
  <?php endif; ?>
</div>

<?php if ( $remaining && !$stop['halted'] ): ?>
<form method="post" id="resume">
<input type="hidden" name="csrf" value="<?= br_h( $csrf ) ?>">
<input type="hidden" name="id" value="<?= br_h( $b['id'] ) ?>">
<input type="hidden" name="resume" value="1">
<div class="bar">
  <button class="btn primary" type="submit">Continue — <?= (int)$remaining ?> pages left</button>
  <label class="why"><input type="checkbox" id="auto" checked> keep going by itself</label>
  <a class="btn" href="batch.php?id=<?= br_h( rawurlencode( $b['id'] ) ) ?>">Stop here</a>
</div>
</form>
<script>
(function(){
  var f=document.getElementById('resume'), a=document.getElementById('auto');
  if(!f||!a) return;
  var t=setTimeout(function(){ if(a.checked) f.submit(); }, 2000);
  a.addEventListener('change',function(){ if(!a.checked) clearTimeout(t); });
})();
</script>
<?php endif; ?>

<div class="scroll"><table>
<thead><tr><th class="num">#</th><th>Page</th><th>Result</th><th>Detail</th></tr></thead>
<tbody><?php foreach ( $report as $r ):
	$s = $r['res']['status'];
	$cls = $s === 'ok' ? 'ready' : ( $s === 'dry-run' ? 'dry' : ( $s === 'error' ? 'error' : 'already' ) ); ?>
<tr><td class="num"><?= (int)$r['n'] ?></td>
    <td><a class="mono" href="<?= br_h( br_target_link( [ 'target' => $r['target'] ] ) ) ?>" target="_blank" rel="noopener"><?= br_h( $r['target'] ) ?></a></td>
    <td><span class="pill p-<?= $cls ?>"><?= br_h( $s ) ?></span></td>
    <td class="why"><?= br_h( $r['res']['msg'] ?? '' ) ?>
      <?php if ( !empty( $r['res']['diff'] ) ): ?>
      <div class="diff"><?php foreach ( array_slice( $r['res']['diff'], 0, 14 ) as $l ) {
			$k = str_starts_with( $l, '+' ) ? 'add' : ( str_starts_with( $l, '-' ) ? 'del' : '' );
			echo '<span class="' . $k . '">' . br_h( $l ) . '</span>' . "\n";
	  } ?></div><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>

<?php elseif ( $frozen ): ?>
<div class="banner">This batch was committed
<?= br_h( substr( (string)( $b['committed_at'] ?? '' ), 0, 16 ) ) ?> by
<?= br_h( $b['committed_by'] ?? '?' ) ?>
<?= empty( $b['committed_live'] ) ? ' as a dry run' : ' with live writes' ?>. Decisions are frozen.</div>

<div class="bar">
  <form method="post" style="display:inline">
    <input type="hidden" name="csrf" value="<?= br_h( $csrf ) ?>">
    <input type="hidden" name="id" value="<?= br_h( $b['id'] ) ?>">
    <input type="hidden" name="action" value="record">
    <button class="btn" type="submit">Publish the decision record to the wiki</button>
  </form>
  <span class="why">→ <code><?= br_h( rtrim( $cfg['record_page_prefix'], '/' ) . '/' . $b['id'] ) ?></code></span>
  <span class="spacer"></span>
  <a class="btn danger" href="undo.php?id=<?= br_h( rawurlencode( $b['id'] ) ) ?>">Undo this batch →</a>
</div>

<div class="scroll"><table>
<thead><tr><th class="num">#</th><th>Page</th><th>Result</th><th>Detail</th></tr></thead>
<tbody><?php foreach ( $b['items'] as $it ):
	if ( empty( $it['result'] ) ) { continue; }
	$s = $it['result']['status'];
	$cls = $s === 'ok' ? 'ready' : ( $s === 'dry-run' ? 'dry' : ( $s === 'error' ? 'error' : 'already' ) ); ?>
<tr><td class="num"><?= (int)$it['n'] ?></td>
    <td class="mono"><?= br_h( $it['target'] ) ?></td>
    <td><span class="pill p-<?= $cls ?>"><?= br_h( $s ) ?></span>
        <?php if ( !empty( $it['result']['revid'] ) ): ?><div class="ev">rev <?= (int)$it['result']['revid'] ?></div><?php endif; ?></td>
    <td class="why"><?= br_h( $it['result']['msg'] ?? '' ) ?></td></tr>
<?php endforeach; ?></tbody></table></div>

<?php elseif ( !$approved ): ?>
<p class="sub">Nothing is approved in this batch yet. Go back and mark some items
<em>yes</em> first.</p>

<?php else: ?>
<p class="sub">This is exactly what will happen, planned against the wiki as it stands right
now. Items marked <span class="pill p-already">already</span>,
<span class="pill p-stale">stale</span> or <span class="pill p-missing">missing</span> will be
skipped rather than forced. Items that touch the same page are applied in
<strong>one edit</strong>, so a page gains one revision however many of its items you approved.</p>
<?php
$groups = br_group_items( $approved );
$plans  = [];
$ready  = 0;
$budget = 0;
foreach ( $groups as $key => $items ) {
	// Planning re-reads every page, so a very large preview is itself slow.
	// Show the first 120 pages and say so, rather than time out.
	if ( $budget++ >= 120 ) { break; }
	$p = br_plan_group( $b, $items, $user['name'] );
	$plans[] = [ 'items' => $items, 'p' => $p ];
	if ( $p['status'] === 'ready' ) { $ready += count( $p['ready'] ); }
}
?>
<p class="counts"><b><?= $ready ?></b> of <b><?= count( $approved ) ?></b> approved items will
actually change something, in <b><?= count( $groups ) ?></b> edit<?= count( $groups ) === 1 ? '' : 's' ?>
<?php if ( count( $groups ) > 120 ): ?> · previewing the first 120<?php endif; ?>
<?php if ( $cfg['live_writes'] ): ?> · about
<?= ceil( count( $groups ) * ( $cfg['edit_delay_us'] / 1000000 ) / 60 ) ?> minutes at one edit every
<?= (int)( $cfg['edit_delay_us'] / 1000000 ) ?>s<?php endif; ?></p>

<div class="scroll"><table>
<thead><tr><th class="num">#</th><th>Page</th><th>State</th><th>Change</th></tr></thead>
<tbody><?php foreach ( $plans as $row ):
	$p = $row['p']; ?>
<tr><td class="num"><?= br_h( implode( ',', array_map( static function ( $i ) { return (int)$i['n']; }, $row['items'] ) ) ) ?></td>
    <td><a class="mono" href="<?= br_h( br_target_link( $row['items'][0] ) ) ?>" target="_blank" rel="noopener"><?= br_h( $p['title'] ?? $row['items'][0]['target'] ) ?></a></td>
    <td><span class="pill p-<?= br_h( $p['status'] ) ?>"><?= br_h( $p['status'] ) ?></span>
        <?php if ( !empty( $p['msg'] ) ): ?><div class="ev"><?= br_h( $p['msg'] ) ?></div><?php endif; ?></td>
    <td><?php if ( !empty( $p['diff'] ) ): ?>
        <div class="diff"><?php
			$lines = array_slice( $p['diff'], 0, 14 );
			foreach ( $lines as $l ) {
				$k = str_starts_with( $l, '+' ) ? 'add' : ( str_starts_with( $l, '-' ) ? 'del' : '' );
				echo '<span class="' . $k . '">' . br_h( $l ) . '</span>' . "\n";
			}
			if ( count( $p['diff'] ) > 14 ) { echo '… ' . ( count( $p['diff'] ) - 14 ) . ' more lines'; }
        ?></div><?php else: ?><span class="ev">no change</span><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>

<form method="post">
<input type="hidden" name="csrf" value="<?= br_h( $csrf ) ?>">
<input type="hidden" name="id" value="<?= br_h( $b['id'] ) ?>">
<?php if ( $cfg['live_writes'] ): ?>
<div class="banner live">
  <label><input type="checkbox" name="confirm" value="1">
  I have read the changes above and want them written to wiki.p2pfoundation.net as
  <b><?= br_h( $user['name'] ) ?></b>.</label>
</div>
<?php endif; ?>
<div class="bar">
  <button class="btn primary" type="submit"<?= $stop['halted'] ? ' disabled' : '' ?>>
    <?= $cfg['live_writes'] ? 'Apply ' . $ready . ' changes to the wiki' : 'Run the dry run on ' . count( $approved ) . ' items' ?>
  </button>
  <a class="btn" href="batch.php?id=<?= br_h( rawurlencode( $b['id'] ) ) ?>">Cancel</a>
  <span class="spacer"></span>
  <span class="why">Anyone can halt this by writing anything at all on
    <a href="<?= br_h( br_wiki_link( $stop['page'] ) ) ?>"><code><?= br_h( $stop['page'] ) ?></code></a>.</span>
</div>
</form>
<?php endif; ?>
<?php br_foot();
