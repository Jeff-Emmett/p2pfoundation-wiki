<?php
/**
 * Guarantee 06 — reversible.
 *
 * Every applied item recorded the revision id it created. This walks a whole
 * committed batch back out, newest first, through MediaWiki's own `undo`,
 * which refuses cleanly if somebody has edited the page since rather than
 * clobbering them.
 *
 * It is deliberately not one button on the commit screen: undoing is as real
 * an act as committing, so it gets its own page, its own confirmation and the
 * same rate limit, chunking and stop handle.
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
$stop = br_stop_state();

/**
 * Applied items that created a revision and have not already been undone.
 *
 * $onlyRev scopes this to a single revision, which is what the per-row Undo
 * button on the item list asks for. Note it scopes by REVISION and not by
 * item: guarantee 04 merges every item touching one page into a single edit,
 * so undoing one row necessarily reverses its siblings in that edit. There is
 * no way to undo half a revision, and pretending otherwise would be a lie.
 */
function br_undoable( array $b, $onlyRev = null ) {
	$out = [];
	foreach ( $b['items'] as $it ) {
		$r = $it['result'] ?? null;
		if ( !$r || ( $r['status'] ?? '' ) !== 'ok' || empty( $r['revid'] ) ) {
			continue;
		}
		if ( $onlyRev !== null && (int)$r['revid'] !== (int)$onlyRev ) {
			continue;
		}
		if ( !empty( $it['undo'] ) && ( $it['undo']['status'] ?? '' ) === 'ok' ) {
			continue;
		}
		$out[] = $it;
	}
	// Newest revision first: undoing in reverse order is what makes a
	// multi-item page come apart cleanly.
	usort( $out, static function ( $a, $x ) {
		return (int)$x['result']['revid'] <=> (int)$a['result']['revid'];
	} );
	return $out;
}

// ?only=<n> — the per-row Undo button. Resolved to the revision that item
// created, so the scope is honest about what will actually be reversed.
$only    = trim( (string)( $_REQUEST['only'] ?? '' ) );
$onlyRev = null;
if ( $only !== '' ) {
	foreach ( $b['items'] as $it ) {
		if ( (string)$it['n'] === $only ) {
			$onlyRev = (int)( $it['result']['revid'] ?? 0 );
			break;
		}
	}
	if ( !$onlyRev ) {
		$onlyRev = -1;   // asked for a row that never created a revision: undo nothing
	}
}

$todo   = br_undoable( $b, $onlyRev );
$report = [];
$ran    = false;

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	if ( !br_check_csrf( $user['name'], $b['id'], $_POST['csrf'] ?? '' ) ) {
		br_deny( 'That form has expired. Reload and try again.' );
	}
	if ( empty( $_POST['confirm'] ) && empty( $_POST['resume'] ) ) {
		br_deny( 'The confirmation box was not ticked.' );
	}
	if ( $stop['halted'] ) {
		br_audit( 'undo.halted', [ 'user' => $user['name'], 'batch' => $b['id'] ] );
	} else {
		$byN = [];
		foreach ( $b['items'] as $i => $it ) { $byN[(string)$it['n']] = $i; }

		// One revision per page still holds on the way back: the group was one
		// edit, so one undo reverses all of its items. Undo the revision once
		// and mark every item that shares it.
		$byRev = [];
		foreach ( $todo as $it ) { $byRev[(int)$it['result']['revid']][] = $it; }

		$deadline = microtime( true ) + (float)$cfg['commit_budget_seconds'];
		$n = 0;
		foreach ( $byRev as $revid => $items ) {
			if ( $n >= (int)$cfg['commit_chunk_pages'] || microtime( true ) > $deadline ) {
				break;
			}
			if ( $n > 0 ) { usleep( (int)$cfg['edit_delay_us'] ); }
			$res = br_undo_revision(
				$items[0]['target'], $revid,
				'[' . $cfg['summary_prefix'] . ' ' . $b['id'] . '] undo of rev ' . $revid . ', by ' . $user['name']
			);
			$res['at'] = gmdate( 'c' );
			$res['by'] = $user['name'];
			foreach ( $items as $it ) {
				$i = $byN[(string)$it['n']] ?? null;
				if ( $i !== null ) { $b['items'][$i]['undo'] = $res; }
				$report[] = [ 'n' => $it['n'], 'target' => $it['target'], 'revid' => $revid, 'res' => $res ];
			}
			$n++;
			$ran = true;
		}
		br_save_batch( $b );
		br_audit( 'undo.chunk', [ 'user' => $user['name'], 'batch' => $b['id'], 'revisions' => $n ] );
		$b    = br_load_batch( $id );
		$todo = br_undoable( $b, $onlyRev );
	}
}

$csrf = br_csrf( $user['name'], $b['id'] );
br_head( 'Undo · ' . ( $b['title'] ?? $b['id'] ), $user );
?>
<h2>Undo: <?= br_h( $b['title'] ?? $b['id'] ) ?></h2>
<p class="sub"><a href="commit.php?id=<?= br_h( rawurlencode( $b['id'] ) ) ?>">← back to the commit record</a></p>

<?php if ( $stop['halted'] ): ?>
<div class="banner live"><b>Stopped.</b> <code><?= br_h( $stop['page'] ) ?></code> is not blank, which
halts every write this tool makes, undos included.</div>
<?php endif; ?>

<?php if ( $ran ):
	$ok = $err = 0;
	foreach ( $report as $r ) { if ( ( $r['res']['status'] ?? '' ) === 'ok' ) { $ok++; } else { $err++; } } ?>
<div class="banner<?= $err ? ' live' : '' ?>">This pass undid <b><?= $ok ?></b> revision<?= $ok === 1 ? '' : 's' ?>,
<b><?= $err ?></b> refused. <?= count( $todo ) ?> left.</div>
<div class="scroll"><table>
<thead><tr><th class="num">#</th><th>Page</th><th class="num">rev</th><th>Result</th></tr></thead>
<tbody><?php foreach ( $report as $r ):
	$s = $r['res']['status'] ?? '?'; ?>
<tr><td class="num"><?= (int)$r['n'] ?></td>
    <td class="mono"><?= br_h( $r['target'] ) ?></td>
    <td class="num"><?= (int)$r['revid'] ?></td>
    <td><span class="pill p-<?= $s === 'ok' ? 'ready' : 'error' ?>"><?= br_h( $s ) ?></span>
        <div class="ev"><?= br_h( $r['res']['msg'] ?? '' ) ?></div></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>

<?php if ( !$todo ): ?>
<p class="sub">Nothing left to undo<?= $only !== '' ? ' for that row' : ' in this batch' ?>. An item is undoable only if it actually created a
revision — dry runs, skips and failures created none.</p>
<?php else: ?>
<?php if ( $only !== '' ): ?>
<div class="banner"><b>One revision.</b> You asked to undo row <?= br_h( $only ) ?>, which is
revision <?= (int)$onlyRev ?>.
<?php $sib = 0; foreach ( $b['items'] as $x ) { if ( (int)( $x['result']['revid'] ?? 0 ) === (int)$onlyRev ) { $sib++; } }
      if ( $sib > 1 ): ?>
That single edit carried <b><?= $sib ?></b> items of this batch, because everything touching one page
is written as one revision — so undoing it reverses all <?= $sib ?>, not just the row you clicked.
There is no way to undo half a revision.
<?php else: ?>
That edit carried only this item, so nothing else is affected.
<?php endif; ?>
<a href="undo.php?id=<?= br_h( rawurlencode( $b['id'] ) ) ?>">Undo the whole batch instead →</a></div>
<?php endif; ?>
<?php $tn = count( $todo ); ?>
<p class="sub">These <b><?= $tn ?></b> revision<?= $tn === 1 ? '' : 's' ?> <?= $tn === 1 ? 'was' : 'were' ?>
created by this batch and can be walked back. MediaWiki's own undo is used, so any page somebody has edited since will refuse
rather than lose their work — a refusal here is the tool behaving, not failing.</p>
<div class="scroll"><table>
<thead><tr><th class="num">#</th><th>Page</th><th class="num">rev</th><th>What it did</th><th>Approved by</th></tr></thead>
<tbody><?php foreach ( array_slice( $todo, 0, 200 ) as $it ): ?>
<tr><td class="num"><?= (int)$it['n'] ?></td>
    <td><a class="mono" href="<?= br_h( br_target_link( $it ) ) ?>" target="_blank" rel="noopener"><?= br_h( $it['target'] ) ?></a></td>
    <td class="num"><?= (int)$it['result']['revid'] ?></td>
    <td class="mono"><?= br_h( br_item_phrase( $it ) ) ?></td>
    <td class="ev"><?= br_h( $it['decided_by'] ?? '' ) ?></td></tr>
<?php endforeach; ?></tbody></table></div>

<form method="post">
<input type="hidden" name="csrf" value="<?= br_h( $csrf ) ?>">
<input type="hidden" name="id" value="<?= br_h( $b['id'] ) ?>">
<?php if ( $only !== '' ): ?><input type="hidden" name="only" value="<?= br_h( $only ) ?>"><?php endif; ?>
<div class="banner live"><label><input type="checkbox" name="confirm" value="1">
I want <?= $tn === 1 ? 'this revision' : 'these ' . $tn . ' revisions' ?> undone on wiki.p2pfoundation.net as
<b><?= br_h( $user['name'] ) ?></b>.</label></div>
<div class="bar">
  <button class="btn danger" type="submit"<?= $stop['halted'] ? ' disabled' : '' ?>>Undo <?= $tn ?> revision<?= $tn === 1 ? '' : 's' ?></button>
  <a class="btn" href="commit.php?id=<?= br_h( rawurlencode( $b['id'] ) ) ?>">Cancel</a>
</div>
</form>
<?php endif; ?>
<?php br_foot();
