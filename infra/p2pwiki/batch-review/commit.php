<?php
/**
 * Final confirmation and application.
 *
 * GET  — plan every approved item against the wiki as it stands and show the
 *        exact change each would make. Nothing is written.
 * POST — apply them, in order, with a pause between edits. In dry-run mode the
 *        planning is identical and the writes are simply not sent.
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
$frozen = ( $b['status'] ?? 'open' ) !== 'open';
$done   = false;
$report = [];

// -------------------------------------------------------------------------
// apply
// -------------------------------------------------------------------------
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && !$frozen ) {
	if ( !br_check_csrf( $user['name'], $b['id'], $_POST['csrf'] ?? '' ) ) {
		br_deny( 'That form has expired. Reload and try again.' );
	}
	if ( $cfg['live_writes'] && empty( $_POST['confirm'] ) ) {
		br_deny( 'Live writes are on and the confirmation box was not ticked.' );
	}
	if ( count( $approved ) > $cfg['max_items_per_commit'] ) {
		br_deny( 'This batch has ' . count( $approved ) . ' approved items, above the '
			. $cfg['max_items_per_commit'] . '-item cap in config.php. Reject some, or raise the cap deliberately.' );
	}

	$byN = [];
	foreach ( $b['items'] as $i => $it ) {
		$byN[(string)$it['n']] = $i;
	}
	$n = 0;
	foreach ( $approved as $it ) {
		$res = br_apply_item( $b, $it );
		$i = $byN[(string)$it['n']] ?? null;
		if ( $i !== null ) {
			$b['items'][$i]['result'] = $res;
		}
		$report[] = [ 'n' => $it['n'], 'target' => $it['target'], 'res' => $res ];
		$n++;
		if ( $cfg['live_writes'] && $n < count( $approved ) ) {
			usleep( (int)$cfg['edit_delay_us'] );
		}
	}
	$b['status']       = 'committed';
	$b['committed_by'] = $user['name'];
	$b['committed_at'] = gmdate( 'c' );
	$b['committed_live'] = (bool)$cfg['live_writes'];
	br_save_batch( $b );
	br_log_commit( [
		'batch' => $b['id'], 'by' => $user['name'], 'at' => $b['committed_at'],
		'live'  => (bool)$cfg['live_writes'], 'items' => $report,
	] );
	$done   = true;
	$frozen = true;
}

$csrf = br_csrf( $user['name'], $b['id'] );
br_head( 'Commit · ' . ( $b['title'] ?? $b['id'] ), $user );
?>
<h2><?= $done ? 'Committed' : 'Commit' ?>: <?= br_h( $b['title'] ?? $b['id'] ) ?></h2>
<p class="sub"><a href="batch.php?id=<?= br_h( rawurlencode( $b['id'] ) ) ?>">← back to the item list</a></p>

<?php if ( $done ):
	$ok = $err = $skip = 0;
	foreach ( $report as $r ) {
		$s = $r['res']['status'];
		if ( $s === 'ok' || $s === 'dry-run' ) { $ok++; }
		elseif ( $s === 'error' )              { $err++; }
		else                                   { $skip++; }
	} ?>
<div class="banner<?= $err ? ' live' : '' ?>">
  <b><?= $ok ?></b> <?= $cfg['live_writes'] ? 'applied' : 'would have been applied (dry run)' ?> ·
  <b><?= $skip ?></b> already done or not applicable ·
  <b><?= $err ?></b> failed.
  <?php if ( !$cfg['live_writes'] ): ?>
  Nothing was written to the wiki. Set <code>'live_writes' =&gt; true</code> in
  <code>config.php</code> and re-run the generator to queue a fresh batch when you want these
  changes for real.
  <?php else: ?>
  Every edit is in your contributions with <code>[<?= br_h( $cfg['summary_prefix'] ) ?>
  <?= br_h( $b['id'] ) ?>]</code> in the summary.
  <?php endif; ?>
</div>
<div class="scroll"><table>
<thead><tr><th class="num">#</th><th>Page</th><th>Result</th><th>Detail</th></tr></thead>
<tbody><?php foreach ( $report as $r ):
	$s = $r['res']['status'];
	$cls = $s === 'ok' ? 'ready' : ( $s === 'dry-run' ? 'dry' : ( $s === 'error' ? 'error' : 'already' ) ); ?>
<tr><td class="num"><?= (int)$r['n'] ?></td>
    <td><a class="mono" href="<?= br_h( br_wiki_link( $r['target'] ) ) ?>" target="_blank" rel="noopener"><?= br_h( $r['target'] ) ?></a></td>
    <td><span class="pill p-<?= $cls ?>"><?= br_h( $s ) ?></span></td>
    <td class="why"><?= br_h( $r['res']['msg'] ?? '' ) ?>
      <?php if ( !empty( $r['res']['diff'] ) ): ?>
      <div class="diff"><?php foreach ( $r['res']['diff'] as $l ) {
			$k = str_starts_with( $l, '+' ) ? 'add' : ( str_starts_with( $l, '-' ) ? 'del' : '' );
			echo '<span class="' . $k . '">' . br_h( $l ) . '</span>' . "\n";
	  } ?></div><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>

<?php elseif ( $frozen ): ?>
<div class="banner">This batch was committed
<?= br_h( substr( (string)( $b['committed_at'] ?? '' ), 0, 16 ) ) ?> by
<?= br_h( $b['committed_by'] ?? '?' ) ?>
<?= empty( $b['committed_live'] ) ? ' as a dry run' : ' with live writes' ?>. Decisions are frozen.</div>
<div class="scroll"><table>
<thead><tr><th class="num">#</th><th>Page</th><th>Result</th><th>Detail</th></tr></thead>
<tbody><?php foreach ( $b['items'] as $it ):
	if ( empty( $it['result'] ) ) { continue; }
	$s = $it['result']['status'];
	$cls = $s === 'ok' ? 'ready' : ( $s === 'dry-run' ? 'dry' : ( $s === 'error' ? 'error' : 'already' ) ); ?>
<tr><td class="num"><?= (int)$it['n'] ?></td>
    <td class="mono"><?= br_h( $it['target'] ) ?></td>
    <td><span class="pill p-<?= $cls ?>"><?= br_h( $s ) ?></span></td>
    <td class="why"><?= br_h( $it['result']['msg'] ?? '' ) ?></td></tr>
<?php endforeach; ?></tbody></table></div>

<?php elseif ( !$approved ): ?>
<p class="sub">Nothing is approved in this batch yet. Go back and mark some items
<em>yes</em> first.</p>

<?php else: ?>
<p class="sub">This is exactly what will happen, planned against the wiki as it stands right
now. Items marked <span class="pill p-already">already</span> or
<span class="pill p-missing">missing</span> will be skipped rather than forced.</p>
<?php
$plans = [];
$ready = 0;
foreach ( $approved as $it ) {
	$p = br_plan_item( $b, $it );
	$plans[] = [ 'it' => $it, 'p' => $p ];
	if ( $p['status'] === 'ready' ) { $ready++; }
}
?>
<p class="counts"><b><?= $ready ?></b> of <b><?= count( $approved ) ?></b> approved items will
actually change something.</p>
<div class="scroll"><table>
<thead><tr><th class="num">#</th><th>Page</th><th>State</th><th>Change</th></tr></thead>
<tbody><?php foreach ( $plans as $row ):
	$p = $row['p']; $it = $row['it']; ?>
<tr><td class="num"><?= (int)$it['n'] ?></td>
    <td><a class="mono" href="<?= br_h( br_wiki_link( $it['target'] ) ) ?>" target="_blank" rel="noopener"><?= br_h( $it['target'] ) ?></a></td>
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
  <button class="btn primary" type="submit">
    <?= $cfg['live_writes'] ? 'Apply ' . $ready . ' changes to the wiki' : 'Run the dry run on ' . count( $approved ) . ' items' ?>
  </button>
  <a class="btn" href="batch.php?id=<?= br_h( rawurlencode( $b['id'] ) ) ?>">Cancel</a>
</div>
</form>
<?php endif; ?>
<?php br_foot();
