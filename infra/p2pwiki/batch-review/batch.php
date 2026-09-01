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

		if ( $action === 'save' ) {
			$dec = (array)( $_POST['d'] ?? [] );
			$n = 0;
			foreach ( $b['items'] as $i => $it ) {
				$k = (string)$it['n'];
				if ( !array_key_exists( $k, $dec ) ) {
					continue;
				}
				$v = $dec[$k] === 'approve' ? 'approve' : ( $dec[$k] === 'reject' ? 'reject' : null );
				if ( ( $b['items'][$i]['decision'] ?? null ) !== $v ) {
					$n++;
				}
				$b['items'][$i]['decision']   = $v;
				$b['items'][$i]['decided_by'] = $v ? $user['name'] : null;
				$b['items'][$i]['decided_at'] = $v ? $now : null;
			}
			br_save_batch( $b );
			$notice = $n . ' decision' . ( $n === 1 ? '' : 's' ) . ' saved.';

		} elseif ( $action === 'bulk' ) {
			$to    = (string)( $_POST['to'] ?? '' );
			$scope = (string)( $_POST['scope'] ?? 'page' );
			$v = $to === 'approve' ? 'approve' : ( $to === 'reject' ? 'reject' : null );
			$n = 0;
			foreach ( $b['items'] as $i => $it ) {
				if ( $scope === 'page' && ( $i < $offset || $i >= $offset + BR_PER_PAGE ) ) {
					continue;
				}
				$b['items'][$i]['decision']   = $v;
				$b['items'][$i]['decided_by'] = $v ? $user['name'] : null;
				$b['items'][$i]['decided_at'] = $v ? $now : null;
				$n++;
			}
			br_save_batch( $b );
			$notice = $n . ' item' . ( $n === 1 ? '' : 's' ) . ' set to ' . ( $v ?: 'undecided' ) . '.';

		} elseif ( $action === 'verify' ) {
			// Pre-flight the visible page against the wiki as it stands right now.
			$n = 0;
			foreach ( $b['items'] as $i => $it ) {
				if ( $i < $offset || $i >= $offset + BR_PER_PAGE ) {
					continue;
				}
				$plan = br_plan_item( $b, $it );
				$b['items'][$i]['check'] = [
					'status' => $plan['status'],
					'msg'    => $plan['msg'] ?? '',
					'diff'   => $plan['diff'] ?? [],
					'at'     => gmdate( 'c' ),
				];
				$n++;
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

br_head( $b['title'] ?? $b['id'], $user );
?>
<h2><?= br_h( $b['title'] ?? $b['id'] ) ?></h2>
<p class="sub"><?= br_h( $b['rationale'] ?? '' ) ?></p>
<p class="counts">
  <b><?= $total ?></b> items ·
  <b><?= $c['approve'] ?></b> approved ·
  <b><?= $c['reject'] ?></b> rejected ·
  <b><?= $c['undecided'] ?></b> undecided
  <?php if ( $c['applied'] ): ?> · <b><?= $c['applied'] ?></b> applied<?php endif; ?>
  <?php if ( $c['error'] ): ?> · <b><?= $c['error'] ?></b> failed<?php endif; ?>
  · kind <code><?= br_h( $b['kind'] ?? '—' ) ?></code>
</p>

<?php if ( $notice ): ?><div class="banner"><?= br_h( $notice ) ?></div><?php endif; ?>

<form method="post" id="f">
<input type="hidden" name="csrf" value="<?= br_h( $csrf ) ?>">
<input type="hidden" name="id" value="<?= br_h( $b['id'] ) ?>">
<input type="hidden" name="p" value="<?= (int)$page ?>">

<?php if ( !$frozen ): ?>
<div class="bar">
  <button class="btn primary" type="submit" name="action" value="save">Save decisions</button>
  <button class="btn" type="submit" name="action" value="bulk"
    onclick="document.getElementById('to').value='approve';document.getElementById('scope').value='page'">Approve this page</button>
  <button class="btn" type="submit" name="action" value="bulk"
    onclick="document.getElementById('to').value='reject';document.getElementById('scope').value='page'">Reject this page</button>
  <button class="btn" type="submit" name="action" value="bulk"
    onclick="document.getElementById('to').value='approve';document.getElementById('scope').value='all'">Approve all <?= $total ?></button>
  <button class="btn" type="submit" name="action" value="bulk"
    onclick="document.getElementById('to').value='';document.getElementById('scope').value='all'">Clear all</button>
  <button class="btn" type="submit" name="action" value="verify">Check against the wiki</button>
  <span class="spacer"></span>
  <a class="btn primary" href="commit.php?id=<?= br_h( rawurlencode( $b['id'] ) ) ?>">
    Review &amp; commit <?= $c['approve'] ?> approved →</a>
</div>
<input type="hidden" name="to" id="to" value="">
<input type="hidden" name="scope" id="scope" value="page">
<?php else: ?>
<div class="bar">
  <span class="pill p-committed">committed <?= br_h( substr( (string)( $b['committed_at'] ?? '' ), 0, 16 ) ) ?>
    by <?= br_h( $b['committed_by'] ?? '?' ) ?></span>
  <span class="spacer"></span>
  <a class="btn" href="commit.php?id=<?= br_h( rawurlencode( $b['id'] ) ) ?>">See the commit log →</a>
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
  <th class="num">#</th><th>Page</th><th>Change</th><th>Why</th>
  <th>State</th><th>Decision</th>
</tr></thead>
<tbody>
<?php
$slice = array_slice( $b['items'], $offset, BR_PER_PAGE, true );
foreach ( $slice as $it ):
	$d     = $it['decision'] ?? null;
	$chk   = $it['check'] ?? null;
	$res   = $it['result'] ?? null;
	$title = (string)$it['target'];
?>
<tr>
  <td class="num"><?= (int)$it['n'] ?></td>
  <td><a class="mono" href="<?= br_h( br_wiki_link( $title ) ) ?>" target="_blank" rel="noopener"><?= br_h( $title ) ?></a></td>
  <td class="mono">
<?php
	if ( $it['op'] === 'append-category' ) {
		echo '<span class="diff add">+ [[Category:' . br_h( str_replace( '_', ' ', $it['arg'] ) ) . ']]</span>';
	} elseif ( $it['op'] === 'replace-category' ) {
		echo '<span class="diff"><span class="del">- [[Category:' . br_h( $it['from'] ) . ']]</span>'
		   . "\n" . '<span class="add">+ [[Category:' . br_h( $it['to'] ) . ']]</span></span>';
	} elseif ( $it['op'] === 'create-draft' ) {
		$len = strlen( (string)( $it['text'] ?? '' ) );
		echo '<span class="diff add">+ create draft, ' . number_format( $len ) . ' bytes</span>';
		if ( !empty( $it['sources'] ) ) {
			echo '<div class="ev">from ' . count( $it['sources'] ) . ' source articles</div>';
		}
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
<?php if ( $frozen ): ?>
    <span class="ev"><?= br_h( $d ?: '—' ) ?></span>
<?php else: ?>
    <fieldset class="dec">
      <label><input type="radio" name="d[<?= (int)$it['n'] ?>]" value="approve" <?= $d === 'approve' ? 'checked' : '' ?>>yes</label>
      <label><input type="radio" name="d[<?= (int)$it['n'] ?>]" value="reject"  <?= $d === 'reject'  ? 'checked' : '' ?>>no</label>
      <label><input type="radio" name="d[<?= (int)$it['n'] ?>]" value=""        <?= $d === null      ? 'checked' : '' ?>>—</label>
    </fieldset>
<?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>

<?php if ( !$frozen ): ?>
<div class="bar">
  <button class="btn primary" type="submit" name="action" value="save">Save decisions</button>
  <span class="spacer"></span>
  <a class="btn primary" href="commit.php?id=<?= br_h( rawurlencode( $b['id'] ) ) ?>">
    Review &amp; commit <?= $c['approve'] ?> approved →</a>
</div>
<?php endif; ?>
</form>
<?php br_foot();
