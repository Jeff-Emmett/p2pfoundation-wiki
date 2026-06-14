<?php
/**
 * Public "request editor access" form.
 * Collects name + email, stores a pending request, and emails the approver
 * a one-click approve/deny link (sent via the MediaWiki CLI mailer).
 */
require __DIR__ . '/lib.php';

function er_rate_ok( $ip ) {
	$dir = er_mkdir( er_data_dir() . '/rate' );
	$f = $dir . '/' . md5( $ip ) . '.json';
	$now = time();
	$window = 3600;
	$max = (int)er_config()['rate_per_hour'];
	$arr = is_file( $f ) ? ( json_decode( (string)file_get_contents( $f ), true ) ?: [] ) : [];
	$arr = array_values( array_filter( $arr, static fn ( $t ) => $t > $now - $window ) );
	if ( count( $arr ) >= $max ) {
		return false;
	}
	$arr[] = $now;
	file_put_contents( $f, json_encode( $arr ) );
	return true;
}

$errors = [];
$done = false;
$name = '';
$email = '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	$name = trim( (string)( $_POST['name'] ?? '' ) );
	$email = trim( (string)( $_POST['email'] ?? '' ) );
	$honeypot = trim( (string)( $_POST['website'] ?? '' ) );
	$ip = er_client_ip();

	if ( $honeypot !== '' ) {
		// Bot filled the hidden field — pretend success, do nothing.
		$done = true;
	} elseif ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 120 ) {
		$errors[] = 'Please enter your name (2–120 characters).';
	} elseif ( !filter_var( $email, FILTER_VALIDATE_EMAIL ) || mb_strlen( $email ) > 200 ) {
		$errors[] = 'Please enter a valid email address.';
	} elseif ( !er_rate_ok( $ip ) ) {
		$errors[] = 'Too many requests from your network. Please try again later.';
	} else {
		$id = bin2hex( random_bytes( 16 ) );
		er_save( $id, [
			'id'       => $id,
			'name'     => $name,
			'email'    => $email,
			'username' => er_username_from_name( $name ),
			'ip'       => $ip,
			'ua'       => mb_substr( (string)( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 300 ),
			'ts'       => time(),
			'status'   => 'pending',
		] );

		$cli = er_config()['wiki_ip'] . '/editor-request/er_cli.php';
		$cmd = 'php ' . escapeshellarg( $cli ) . ' notify-approver ' . escapeshellarg( $id ) . ' 2>&1';
		shell_exec( $cmd );

		$rec = er_load( $id );
		if ( ( $rec['notify_status'] ?? '' ) === 'sent' ) {
			$done = true;
		} else {
			$errors[] = 'Sorry, we could not submit your request right now. Please try again later.';
		}
	}
}

require __DIR__ . '/_header.php';
?>
<h1>Request editor access</h1>
<?php if ( $done ): ?>
	<div class="ok">
		<p><strong>Thanks — your request has been sent.</strong></p>
		<p>A wiki administrator will review it. If approved, you'll receive an
		email with your login details. This usually takes a little while.</p>
	</div>
<?php else: ?>
	<p>The P2P Foundation Wiki is openly readable, but editing requires an
	approved account. Tell us who you are and we'll review your request.</p>
	<?php foreach ( $errors as $e ): ?>
		<div class="err"><?= er_h( $e ) ?></div>
	<?php endforeach; ?>
	<form method="post" action="">
		<label>Your name
			<input type="text" name="name" value="<?= er_h( $name ) ?>" required maxlength="120" autocomplete="name">
		</label>
		<label>Your email
			<input type="email" name="email" value="<?= er_h( $email ) ?>" required maxlength="200" autocomplete="email">
		</label>
		<!-- honeypot: humans never see/fill this -->
		<div class="hp" aria-hidden="true">
			<label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
		</div>
		<button type="submit">Request access</button>
	</form>
	<p class="muted">We use your email only to set up your account and notify
	you of the decision.</p>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php';
