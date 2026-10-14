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

	$ft = er_check_form_token( $_POST['ft'] ?? null );
	$silent = null; // set => pretend success, email nobody

	if ( $honeypot !== '' ) {
		$silent = 'honeypot';
	} elseif ( $ft === 'bad' || $ft === 'replay' ) {
		$silent = "token-$ft";
	} elseif ( $ft === 'fast' ) {
		// Autofill can be this quick too, so ask again rather than drop it.
		er_log_reject( 'too-fast', $ip );
		$errors[] = 'That was quick! Please check your details and press the button again.';
	} elseif ( $ft === 'stale' ) {
		$errors[] = 'This form expired. Please submit it again.';
	} elseif ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 120 ) {
		$errors[] = 'Please enter your name (2–120 characters).';
	} elseif ( er_name_looks_spammy( $name ) ) {
		er_log_reject( 'spammy-name', $ip );
		$errors[] = 'Please enter just your name, without links or symbols.';
	} elseif ( !filter_var( $email, FILTER_VALIDATE_EMAIL ) || mb_strlen( $email ) > 200 ) {
		$errors[] = 'Please enter a valid email address.';
	} elseif ( !er_turnstile_ok( $_POST['cf-turnstile-response'] ?? null, $ip ) ) {
		$errors[] = 'Please complete the verification check and try again.';
	} elseif ( !er_rate_ok( $ip ) ) {
		$errors[] = 'Too many requests from your network. Please try again later.';
	} elseif ( er_recent_requests( 7 * 86400, $email ) > 0 ) {
		// Already asked this week: don't email the editors (or this address) again.
		$silent = 'duplicate-email';
	} elseif ( er_recent_requests( 86400 ) >= (int)er_config()['max_per_day'] ) {
		er_log_reject( 'daily-cap', $ip );
		$errors[] = 'We are receiving a lot of requests right now. Please try again tomorrow.';
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
	if ( $silent !== null ) {
		er_log_reject( $silent, $ip );
		$done = true;
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
		<input type="hidden" name="ft" value="<?= er_h( er_form_token() ) ?>">
		<?php if ( er_turnstile_secret() !== '' ): ?>
			<div class="cf-turnstile" data-sitekey="<?= er_h( er_config()['turnstile_sitekey'] ) ?>" style="margin-top:18px"></div>
			<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
		<?php endif; ?>
		<button type="submit">Request access</button>
	</form>
	<p class="muted">We use your email only to set up your account and notify
	you of the decision.</p>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php';
