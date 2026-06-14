<?php
/**
 * Approver endpoint for the editor-request flow.
 *
 * GET  (the link in the email): validates the token and shows a confirm page
 *      with Approve/Deny buttons. No state change — this keeps mail-scanner
 *      link prefetching from auto-approving anything.
 * POST (the confirm button): performs the action via the MediaWiki CLI, which
 *      creates the account (approve) and emails the requester.
 */
require __DIR__ . '/lib.php';

$id     = $_REQUEST['id'] ?? '';
$token  = $_REQUEST['token'] ?? '';
$action = $_REQUEST['action'] ?? '';

function er_page( $title, $html, $code = 200 ) {
	http_response_code( $code );
	require __DIR__ . '/_header.php';
	echo '<h1>' . er_h( $title ) . '</h1>' . $html;
	require __DIR__ . '/_footer.php';
	exit;
}

if ( !er_valid_id( $id ) || !in_array( $action, [ 'approve', 'deny' ], true )
	|| !er_verify_token( $id, $token )
) {
	er_page( 'Invalid link', '<div class="err">This link is invalid or has expired.</div>', 400 );
}

$rec = er_load( $id );
if ( !$rec ) {
	er_page( 'Not found', '<div class="err">That request could not be found.</div>', 404 );
}

if ( ( $rec['status'] ?? '' ) !== 'pending' ) {
	er_page(
		'Already handled',
		'<div class="info">This request has already been <strong>' . er_h( $rec['status'] ) . '</strong>'
			. ( !empty( $rec['final_username'] ) ? ' (account: ' . er_h( $rec['final_username'] ) . ')' : '' )
			. '.</div>'
	);
}

// Summary block reused by confirm + result pages.
$summary = '<table class="kv">'
	. '<tr><th>Name</th><td>' . er_h( $rec['name'] ) . '</td></tr>'
	. '<tr><th>Email</th><td>' . er_h( $rec['email'] ) . '</td></tr>'
	. '<tr><th>Proposed username</th><td>' . er_h( $rec['username'] ) . '</td></tr>'
	. '<tr><th>Requested</th><td>' . er_h( gmdate( 'Y-m-d H:i', (int)$rec['ts'] ) ) . ' UTC</td></tr>'
	. '</table>';

// --- GET: show confirmation page (no mutation) ---
if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ( $_POST['confirm'] ?? '' ) !== '1' ) {
	$verb = $action === 'approve' ? 'Approve' : 'Deny';
	$cls = $action === 'approve' ? '' : 'danger';
	$note = $action === 'approve'
		? '<p>Approving will create a wiki account for this person and email them a login.</p>'
		: '<p>Denying will discard this request and notify the person by email.</p>';
	$form = '<form method="post" action="decide.php">'
		. '<input type="hidden" name="id" value="' . er_h( $id ) . '">'
		. '<input type="hidden" name="token" value="' . er_h( $token ) . '">'
		. '<input type="hidden" name="action" value="' . er_h( $action ) . '">'
		. '<input type="hidden" name="confirm" value="1">'
		. '<button type="submit" class="' . $cls . '">Confirm: ' . $verb . '</button>'
		. '</form>';
	er_page( $verb . ' editor request?', $summary . $note . $form );
}

// --- POST confirm: perform the action ---
$sub = $action === 'approve' ? 'approve' : 'deny';
$cli = er_config()['wiki_ip'] . '/editor-request/er_cli.php';
$cmd = 'php ' . escapeshellarg( $cli ) . ' ' . $sub . ' ' . escapeshellarg( $id ) . ' 2>&1';
$out = shell_exec( $cmd );

$rec = er_load( $id );
$st = $rec['status'] ?? 'pending';

if ( $action === 'approve' && $st === 'approved' ) {
	er_page( 'Approved',
		'<div class="ok"><p><strong>Account created.</strong> '
		. er_h( $rec['name'] ) . ' (' . er_h( $rec['final_username'] ?? '' )
		. ') has been emailed their login details.</p></div>' . $summary );
} elseif ( $action === 'deny' && $st === 'denied' ) {
	er_page( 'Denied',
		'<div class="info"><p>The request was denied and the person has been notified.</p></div>' . $summary );
}

// Something went wrong — show CLI output for diagnosis (approver-only link).
er_page( 'Could not complete',
	'<div class="err"><p>The action did not complete. Status: <strong>' . er_h( $st )
	. '</strong>.</p></div><pre style="white-space:pre-wrap;font-size:12px;color:#6b7785">'
	. er_h( (string)$out ) . '</pre>', 500 );
