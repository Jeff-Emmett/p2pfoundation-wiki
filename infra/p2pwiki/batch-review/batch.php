<?php
/** Review one batch: decide items, pre-flight them, hand off to commit. */
define( 'BR_ENTRY', 1 );
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/_chrome.php';

const BR_PER_PAGE = 150;

$user = br_require_reviewer();
$id   = $_REQUEST['id'] ?? '';
$b    = br_load_batch( $id );
if ( !$b ) {
	http_response_code( 404 );
	br_head( 'Not found', $user );
	echo '<h2>No such batch</h2><p class="sub">Nothing queued under that id.</p>';
	br_foot();
	exit;
}

$page   = max( 0, (int)( $_REQUEST['p'] ?? 0 ) );
$offset = $page * BR_PER_PAGE;
$notice = '';

// -------------------------------------------------------------------------
// actions
// -------------------------------------------------------------------------
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	$action = (string)( $_POST['action'] ?? '' );
	if ( !br_check_csrf( $user['name'], $b['id'], $_POST['csrf'] ?? '' ) ) {
		br_deny( 'That form has expired. Reload the batch and try again.' );
	}
	if ( ( $b['status'] ?? 'open' ) !== 'open' ) {
		$notice = 'This batch is already committed; decisions are frozen.';
	} else {
		$now = gmdate( 'c' );

		// The radios are persisted on EVERY submission, whatever button was
		// pressed. They used to be read only by the 'save' branch, which meant
		// that pressing "Check against the wiki" — or walking to the commit
		// screen — silently threw away everything the reviewer had just ticked,
		// and the reviewer had no way of knowing. A tick is a decision; losing
		// one quietly is the worst thing this screen could do.
		$saved = 0;
		$dec   = (array)( $_POST['d'] ?? [] );
		if ( $dec ) {
			foreach ( $b['items'] as $i => $it ) {
				$k = (string)$it['n'];
				if ( !array_key_exists( $k, $dec ) ) {
					continue;
				}
				$v = $dec[$k] === 'approve' ? 'approve' : ( $dec[$k] === 'reject' ? 'reject' : null );
				if ( ( $b['items'][$i]['decision'] ?? null ) !== $v ) {
					$saved++;
				}
				$b['items'][$i]['decision']   = $v;
				$b['items'][$i]['decided_by'] = $v ? $user['name'] : null;
				$b['items'][$i]['decided_at'] = $v ? $now : null;
			}
			if ( $saved ) {
				br_save_batch( $b );
			}
		}

		if ( $action === 'save' ) {
			$notice = $saved . ' decision' . ( $saved === 1 ? '' : 's' ) . ' saved.';

		} elseif ( $action === 'save-commit' ) {
			// Save, then go to the commit screen. This is what the old
			// "Review & commit" control looked like it did, and did not: it was
			// an <a href> sitting in the button row, so it navigated away and
			// dropped the form.
			header( 'Location: commit.php?id=' . rawurlencode( $b['id'] ), true, 303 );
			exit;

		} elseif ( strpos( $action, 'bulk:' ) === 0 ) {
			// action is "bulk:<approve|reject|clear|suggest>:<page|all>" — carried by
			// the submit button itself, so the bulk controls work with JavaScript
			// turned off.
			[ , $to, $scope ] = array_pad( explode( ':', $action, 3 ), 3, '' );
			// 'clear' deliberately maps to null (undecided). Anything unrecognised must
			// NOT fall through to that, or a typo'd action silently wipes every decision.
			$verbs = [ 'approve' => 'approve', 'reject' => 'reject', 'clear' => null, 'suggest' => 'suggest' ];
			if ( !array_key_exists( $to, $verbs ) || !in_array( $scope, [ 'page', 'all' ], true ) ) {
				br_deny( 'Unrecognised bulk action.' );
			}
			$v = $verbs[$to];
			$n = 0;
			foreach ( $b['items'] as $i => $it ) {
				if ( $scope === 'page' && ( $i < $offset || $i >= $offset + BR_PER_PAGE ) ) {
					continue;
				}
				if ( $to === 'suggest' ) {
					// Adopt the generator's own suggestion as the decision. Items
					// with no suggestion are left alone rather than defaulted:
					// nothing here should ever turn an absence into an approval.
					$s = $it['suggest'] ?? null;
					if ( $s !== 'approve' && $s !== 'reject' ) {
						continue;
					}
					$v = $s;
				}
				$b['items'][$i]['decision']   = $v;
				$b['items'][$i]['decided_by'] = $v ? $user['name'] : null;
				$b['items'][$i]['decided_at'] = $v ? $now : null;
				$n++;
			}
			br_save_batch( $b );
			$notice = $n . ' item' . ( $n === 1 ? '' : 's' ) . ' set'
				. ( $to === 'suggest' ? ' from the suggested decision.' : ' to ' . ( $v ?: 'undecided' ) . '.' );

		} elseif ( $action === 'verify' ) {
			// Pre-flight the visible page against the wiki as it stands right now.
			// Grouped the same way a commit would group it, so what you see here
			// is what the applier will actually do — including two items on one
			// page turning into one edit.
			$slice = [];
			foreach ( $b['items'] as $i => $it ) {
				if ( $i >= $offset && $i < $offset + BR_PER_PAGE ) { $slice[] = $it; }
			}
			$byN = [];
			foreach ( $b['items'] as $i => $it ) { $byN[(string)$it['n']] = $i; }
			$n = 0;
			foreach ( br_group_items( $slice ) as $items ) {
				if ( ( $items[0]['op'] ?? '' ) === 'classify-bookmark' ) {
					foreach ( $items as $it ) {
						$i = $byN[(string)$it['n']];
						$prev = br_diigo_decisions()[(string)$it['target']]['visibility'] ?? null;
						$b['items'][$i]['check'] = [
							'status' => $prev ? 'already' : 'ready',
							'msg'    => $prev ? ( 'already recorded as ' . $prev ) : 'no decision recorded yet',
							'diff'   => [],
							'at'     => gmdate( 'c' ),
						];
						$n++;
					}
					continue;
				}
				$plan = br_plan_group( $b, $items, $user['name'] );
				foreach ( $items as $it ) {
					$i = $byN[(string)$it['n']];
					$p = $plan['items'][(int)$it['n']] ?? [ 'status' => $plan['status'], 'msg' => $plan['msg'] ?? '', 'diff' => [] ];
					$b['items'][$i]['check'] = [
						'status' => $p['status'],
						'msg'    => $p['msg'] ?? '',
						'diff'   => $p['diff'] ?? [],
						'at'     => gmdate( 'c' ),
					];
					$n++;
				}
			}
			br_save_batch( $b );
			$notice = 'Checked ' . $n . ' item' . ( $n === 1 ? '' : 's' ) . ' against the wiki as it stands now.';
		}
	}
	$b = br_load_batch( $id );
}

$c      = br_batch_counts( $b );
$total  = count( $b['items'] );
$pages  = (int)ceil( $total / BR_PER_PAGE );
$csrf   = br_csrf( $user['name'], $b['id'] );
$frozen = ( $b['status'] ?? 'open' ) !== 'open';
$hasSuggest = false;
foreach ( $b['items'] as $it ) {
	if ( !empty( $it['suggest'] ) ) { $hasSuggest = true; break; }
}
$isBookmarks = ( $b['kind'] ?? '' ) === 'bookmark-visibility';
// One place for the two verbs, so the row radios and the bulk buttons can never
// drift apart and say different words for the same decision.
$yesWord = $isBookmarks ? 'public'  : 'yes';
$noWord  = $isBookmarks ? 'private' : 'no';

br_head( $b['title'] ?? $b['id'], $user );
?>
<h2><?= br_h( $b['title'] ?? $b['id'] ) ?></h2>
<p class="sub"><?= br_h( $b['rationale'] ?? '' ) ?></p>
<p class="counts">
  <b><?= $total ?></b> items ·
  <b><?= $c['approve'] ?></b> <?= $isBookmarks ? 'to release' : 'approved' ?> ·
  <b><?= $c['reject'] ?></b> <?= $isBookmarks ? 'to keep private' : 'rejected' ?> ·
  <b><?= $c['undecided'] ?></b> undecided
  <?php if ( $c['applied'] ): ?> · <b><?= $c['applied'] ?></b> applied<?php endif; ?>
  <?php if ( $c['error'] ): ?> · <b><?= $c['error'] ?></b> failed<?php endif; ?>
  · kind <code><?= br_h( $b['kind'] ?? '—' ) ?></code>
</p>

<?php if ( $isBookmarks ): ?>
<div class="banner"><b>Undecided is not release.</b> Approve means this bookmark becomes public: its
URL becomes eligible for the link and article generators that write into the wiki. Reject means it
stays private and is never sent anywhere. A row left blank is neither — it stays private, and doing
nothing is always safe.</div>
<?php endif; ?>

<?php if ( $notice ): ?><div class="banner"><?= br_h( $notice ) ?></div><?php endif; ?>

<form method="post" id="f">
<input type="hidden" name="csrf" value="<?= br_h( $csrf ) ?>">
<input type="hidden" name="id" value="<?= br_h( $b['id'] ) ?>">
<input type="hidden" name="p" value="<?= (int)$page ?>">

<?php if ( !$frozen ): ?>
<div class="bar">
  <button class="btn primary" type="submit" name="action" value="save" id="br-save">Save decisions</button>
  <?php if ( $hasSuggest ): ?>
  <button class="btn" type="submit" name="action" value="bulk:suggest:page">Adopt suggestions on this page</button>
  <button class="btn" type="submit" name="action" value="bulk:suggest:all">Adopt all <?= $total ?> suggestions</button>
  <?php endif; ?>
  <button class="btn" type="submit" name="action" value="bulk:approve:page"><?= br_h( ucfirst( $yesWord ) ) ?> to all on this page</button>
  <button class="btn" type="submit" name="action" value="bulk:reject:page"><?= br_h( ucfirst( $noWord ) ) ?> to all on this page</button>
  <button class="btn" type="submit" name="action" value="bulk:approve:all"><?= br_h( ucfirst( $yesWord ) ) ?> to all <?= $total ?></button>
  <button class="btn" type="submit" name="action" value="bulk:reject:all"><?= br_h( ucfirst( $noWord ) ) ?> to all <?= $total ?></button>
  <button class="btn" type="submit" name="action" value="bulk:clear:all">Clear all</button>
  <button class="btn" type="submit" name="action" value="verify">Check against the wiki</button>
  <span class="spacer"></span>
  <button class="btn primary" type="submit" name="action" value="save-commit">Save &amp; review →</button>
</div>
<?php else: ?>
<div class="bar">
  <span class="pill p-committed">committed <?= br_h( substr( (string)( $b['committed_at'] ?? '' ), 0, 16 ) ) ?>
    by <?= br_h( $b['committed_by'] ?? '?' ) ?></span>
  <span class="spacer"></span>
  <a class="btn" href="commit.php?id=<?= br_h( rawurlencode( $b['id'] ) ) ?>">See the commit log →</a>
  <a class="btn danger" href="undo.php?id=<?= br_h( rawurlencode( $b['id'] ) ) ?>">Undo →</a>
</div>
<?php endif; ?>

<?php if ( $pages > 1 ): ?>
<div class="pager"><?php for ( $i = 0; $i < $pages; $i++ ) {
	$lo = $i * BR_PER_PAGE + 1;
	$hi = min( $total, ( $i + 1 ) * BR_PER_PAGE );
	if ( $i === $page ) { echo '<span>' . $lo . '–' . $hi . '</span>'; }
	else { echo '<a href="batch.php?id=' . br_h( rawurlencode( $b['id'] ) ) . '&p=' . $i . '">' . $lo . '–' . $hi . '</a>'; }
} ?></div>
<?php endif; ?>

<div class="scroll"><table>
<thead><tr>
  <th class="num">#</th><th><?= $isBookmarks ? 'Bookmark' : 'Page' ?></th>
  <th><?= $isBookmarks ? 'Proposed' : 'Change' ?></th><th>Why</th>
  <th>State</th><th>Decision</th>
</tr></thead>
<tbody>
<?php
$slice = array_slice( $b['items'], $offset, BR_PER_PAGE, true );

// A reviewer thinks in three piles, not one list: what still needs deciding,
// what has been approved, and what has been turned down. Partition on the
// DECISION — but colour on the RESULT, because "approved" and "on the wiki"
// are different claims and only the second one has earned green.
$piles = [ 'suggested' => [], 'approved' => [], 'denied' => [] ];
foreach ( $slice as $i => $it ) {
	$dd = $it['decision'] ?? null;
	$piles[ $dd === 'approve' ? 'approved' : ( $dd === 'reject' ? 'denied' : 'suggested' ) ][ $i ] = $it;
}
$sections = [
	'suggested' => [ 'Suggested updates for approval',
		'Nothing has been decided about these yet. They are proposals and nothing more.' ],
	'approved'  => [ 'Approved updates',
		'Green rows are on the wiki and carry an Undo. The rest are approved and waiting for a commit.' ],
	'denied'    => [ 'Denied updates',
		'Turned down. Nothing here will ever be written; it is kept so the decision is on the record.' ],
];

foreach ( $sections as $sKey => $sMeta ):
	if ( !$piles[$sKey] ) {
		continue;
	}
	$sCount = count( $piles[$sKey] );
	$sDone  = 0;
	foreach ( $piles[$sKey] as $x ) {
		if ( ( $x['result']['status'] ?? '' ) === 'ok' && ( $x['undo']['status'] ?? '' ) !== 'ok' ) { $sDone++; }
	}
?>
<tr class="sec sec-<?= br_h( $sKey ) ?>"><td colspan="6">
  <b><?= br_h( $sMeta[0] ) ?></b>
  <span class="pill p-<?= $sKey === 'denied' ? 'error' : ( $sKey === 'suggested' ? 'dry' : 'ready' ) ?>"><?= $sCount ?><?php
	if ( $sKey === 'approved' ) { echo ', ' . $sDone . ' completed'; } ?></span>
  <div class="ev"><?= br_h( $sMeta[1] ) ?></div>
</td></tr>
<?php
foreach ( $piles[$sKey] as $it ):
	$d     = $it['decision'] ?? null;
	$sg    = $it['suggest'] ?? null;
	$chk   = $it['check'] ?? null;
	$res   = $it['result'] ?? null;
	$title = (string)$it['target'];
	// "Completed" means a revision exists on the wiki and has not been walked
	// back. A dry run is not completed: it wrote nothing.
	$rDone   = ( $res['status'] ?? '' ) === 'ok' && !empty( $res['revid'] );
	$rUndone = ( $it['undo']['status'] ?? '' ) === 'ok';
	$rowCls  = $sKey === 'denied'    ? 's-denied'
	         : ( $sKey === 'suggested' ? 's-suggested'
	         : ( $rUndone ? 's-undone' : ( $rDone ? 's-done' : 's-approved' ) ) );
?>
<tr class="<?= $rowCls ?>">
  <td class="num"><?= (int)$it['n'] ?></td>
  <td><a class="mono" href="<?= br_h( br_target_link( $it ) ) ?>" target="_blank" rel="noopener"><?= br_h( $it['title'] ?? $title ) ?></a>
      <?php if ( !empty( $it['title'] ) ): ?><div class="ev"><?= br_h( $title ) ?></div><?php endif; ?></td>
  <td class="mono">
<?php
	if ( $it['op'] === 'append-category' ) {
		echo '<span class="diff add">+ [[Category:' . br_h( str_replace( '_', ' ', $it['arg'] ) ) . ']]</span>';
	} elseif ( $it['op'] === 'replace-category' ) {
		echo '<span class="diff"><span class="del">- [[Category:' . br_h( $it['from'] ) . ']]</span>'
		   . "\n" . '<span class="add">+ [[Category:' . br_h( $it['to'] ) . ']]</span></span>';
	} elseif ( $it['op'] === 'link-mention' ) {
		echo '<span class="diff add">+ [[' . br_h( str_replace( '_', ' ', $it['arg'] ) ) . ']]</span>';
		if ( !empty( $it['sentence'] ) ) {
			echo '<div class="ev">' . br_h( mb_substr( (string)$it['sentence'], 0, 200 ) ) . '</div>';
		}
	} elseif ( $it['op'] === 'create-draft' || $it['op'] === 'write-page' ) {
		$len = strlen( (string)( $it['text'] ?? '' ) );
		echo '<span class="diff add">+ ' . ( $it['op'] === 'create-draft' ? 'create draft' : 'write page' )
			. ', ' . number_format( $len ) . ' bytes</span>';
		if ( !empty( $it['sources'] ) ) {
			echo '<div class="ev">from ' . count( $it['sources'] ) . ' source articles</div>';
		}
	} elseif ( $it['op'] === 'classify-bookmark' ) {
		echo '<span class="diff ' . ( ( $sg ?? '' ) === 'reject' ? 'del' : 'add' ) . '">'
			. br_h( ( $sg ?? '' ) === 'reject' ? 'keep private' : 'make public' ) . '</span>';
	} else {
		echo br_h( $it['op'] );
	}
?>
  </td>
  <td class="why"><?= br_h( $it['why'] ?? '' ) ?>
      <?php if ( !empty( $it['evidence'] ) ): ?>
      <div class="ev"><?php
		$bits = [];
		foreach ( $it['evidence'] as $k => $v ) { $bits[] = br_h( $k ) . '=' . br_h( is_scalar( $v ) ? $v : json_encode( $v ) ); }
		echo implode( ' · ', $bits );
      ?></div><?php endif; ?></td>
  <td>
<?php
	if ( $res ) {
		$cls = $res['status'] === 'ok' ? 'ready' : ( $res['status'] === 'dry-run' ? 'dry' : ( $res['status'] === 'error' ? 'error' : 'already' ) );
		echo '<span class="pill p-' . $cls . '">' . br_h( $res['status'] ) . '</span>';
		if ( $rUndone ) { echo ' <span class="pill p-already">undone</span>'; }
		if ( !empty( $res['msg'] ) ) { echo '<div class="ev">' . br_h( $res['msg'] ) . '</div>'; }
	} elseif ( $chk ) {
		echo '<span class="pill p-' . br_h( $chk['status'] ) . '">' . br_h( $chk['status'] ) . '</span>';
		if ( !empty( $chk['msg'] ) ) { echo '<div class="ev">' . br_h( $chk['msg'] ) . '</div>'; }
	} else {
		echo '<span class="ev">not checked</span>';
	}
?>
  </td>
  <td>
<?php if ( $rDone && !$rUndone ): ?>
    <span class="pill p-ready">completed</span>
    <div class="ev">rev <?= (int)$res['revid'] ?><?php if ( !empty( $it['decided_by'] ) ): ?> · by <?= br_h( $it['decided_by'] ) ?><?php endif; ?></div>
    <a class="btn danger" href="undo.php?id=<?= br_h( rawurlencode( $b['id'] ) ) ?>&amp;only=<?= (int)$it['n'] ?>">Undo</a>
<?php elseif ( $frozen ): ?>
    <span class="ev"><?= br_h( $d ?: '—' ) ?></span>
<?php else: ?>
    <fieldset class="dec">
      <label><input type="radio" name="d[<?= (int)$it['n'] ?>]" value="approve" <?= $d === 'approve' ? 'checked' : '' ?>><?= br_h( $yesWord ) ?></label>
      <label><input type="radio" name="d[<?= (int)$it['n'] ?>]" value="reject"  <?= $d === 'reject'  ? 'checked' : '' ?>><?= br_h( $noWord ) ?></label>
      <label><input type="radio" name="d[<?= (int)$it['n'] ?>]" value=""        <?= $d === null      ? 'checked' : '' ?>>—</label>
    </fieldset>
    <?php if ( $sg && $d === null ): ?><div class="ev">suggested: <?= br_h( $sg === 'approve' ? ( $isBookmarks ? 'public' : 'yes' ) : ( $isBookmarks ? 'private' : 'no' ) ) ?></div><?php endif; ?>
<?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
<?php endforeach; ?>
</tbody></table></div>

<?php if ( !$frozen ): ?>
<div class="bar">
  <button class="btn primary" type="submit" name="action" value="save">Save decisions</button>
  <span class="spacer"></span>
  <button class="btn primary" type="submit" name="action" value="save-commit">Save &amp; review →</button>
</div>
<?php endif; ?>
</form>
<script>
// The primary button says what it will actually do, counted from the radios as
// they stand right now. Deliberately an enhancement and nothing more: with
// JavaScript off the button still reads "Save decisions" and still works, and
// the bulk buttons below are all server-side round trips for the same reason.
// It is never rendered from the saved-on-disk count — that is what made the old
// control read "0 approved" while the screen was full of ticks.
(function () {
	var f = document.getElementById('f');
	var b = document.getElementById('br-save');
	if (!f || !b) { return; }
	var YES = <?= json_encode( $yesWord ) ?>, NO = <?= json_encode( $noWord ) ?>;
	function count(v) {
		return f.querySelectorAll('input[type=radio][value="' + v + '"]:checked').length;
	}
	function plural(n) { return n === 1 ? ' change' : ' changes'; }
	function update() {
		var a = count('approve'), r = count('reject');
		if (a && r)      { b.textContent = 'Approve ' + a + ', reject ' + r; }
		else if (a)      { b.textContent = 'Approve ' + a + plural(a); }
		else if (r)      { b.textContent = 'Reject ' + r + plural(r); }
		else             { b.textContent = 'Save decisions'; }
	}
	f.addEventListener('change', function (e) {
		if (e.target && e.target.type === 'radio') { update(); }
	});
	update();
})();
</script>
<?php br_foot();
