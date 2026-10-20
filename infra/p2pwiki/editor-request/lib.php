<?php
/**
 * Shared helpers for the editor-request flow.
 * Plain PHP (no MediaWiki dependency) so it can be included by both the
 * public web endpoints and the MediaWiki-bootstrapped CLI.
 */

function er_config() {
	static $c = null;
	if ( $c === null ) {
		$c = require __DIR__ . '/config.php';
	}
	return $c;
}

function er_data_dir() {
	return rtrim( er_config()['data_dir'], '/' );
}

function er_mkdir( $dir ) {
	if ( !is_dir( $dir ) ) {
		@mkdir( $dir, 0770, true );
	}
	return $dir;
}

function er_requests_dir() {
	return er_mkdir( er_data_dir() . '/requests' );
}

/** Shared HMAC secret, generated once on first use (0600, www-data owned). */
function er_secret() {
	$f = er_data_dir() . '/secret.key';
	if ( !is_file( $f ) ) {
		er_mkdir( er_data_dir() );
		$tmp = $f . '.tmp.' . getmypid();
		file_put_contents( $tmp, bin2hex( random_bytes( 32 ) ) );
		@chmod( $tmp, 0600 );
		@rename( $tmp, $f );
	}
	return trim( (string)file_get_contents( $f ) );
}

/** Per-request, single-use-ish authorization token for approve/deny links. */
function er_token( $id ) {
	return hash_hmac( 'sha256', 'editor-request:' . $id, er_secret() );
}

function er_verify_token( $id, $tok ) {
	return is_string( $tok ) && hash_equals( er_token( $id ), $tok );
}

function er_valid_id( $id ) {
	return is_string( $id ) && (bool)preg_match( '/^[a-f0-9]{32}$/', $id );
}

function er_load( $id ) {
	if ( !er_valid_id( $id ) ) {
		return null;
	}
	$f = er_requests_dir() . '/' . $id . '.json';
	if ( !is_file( $f ) ) {
		return null;
	}
	$j = json_decode( (string)file_get_contents( $f ), true );
	return is_array( $j ) ? $j : null;
}

function er_save( $id, array $rec ) {
	$f = er_requests_dir() . '/' . $id . '.json';
	$tmp = $f . '.tmp.' . getmypid();
	file_put_contents( $tmp, json_encode( $rec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	@chmod( $tmp, 0660 );
	rename( $tmp, $f );
}

function er_h( $s ) {
	return htmlspecialchars( (string)$s, ENT_QUOTES, 'UTF-8' );
}

/** Derive a MediaWiki-safe username candidate from a free-text name. */
function er_username_from_name( $name ) {
	$n = trim( preg_replace( '/\s+/u', ' ', (string)$name ) );
	// Strip characters MediaWiki forbids in titles/usernames.
	// (delimiter '!' chosen so it doesn't collide with '#' inside the class)
	$n = preg_replace( '![#<>\[\]\|{}/@:~]+!u', '', (string)$n );
	$n = trim( (string)$n );
	if ( $n === '' ) {
		$n = 'Editor';
	}
	return mb_substr( $n, 0, 80 );
}

/** Strong random temporary password that satisfies common password policies. */
function er_gen_password() {
	$sets = [
		'ABCDEFGHJKLMNPQRSTUVWXYZ',
		'abcdefghijkmnpqrstuvwxyz',
		'23456789',
		'-_@#%+=',
	];
	$pw = '';
	foreach ( $sets as $s ) {
		$pw .= $s[random_int( 0, strlen( $s ) - 1 )];
	}
	$all = implode( '', $sets );
	while ( strlen( $pw ) < 16 ) {
		$pw .= $all[random_int( 0, strlen( $all ) - 1 )];
	}
	return str_shuffle( $pw );
}

function er_client_ip() {
	foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $k ) {
		if ( !empty( $_SERVER[$k] ) ) {
			$ip = trim( explode( ',', $_SERVER[$k] )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
	}
	return '0.0.0.0';
}

// ---------------------------------------------------------------------------
// Bot defences. Each layer is cheap and independent; a submission must pass
// all of them before anyone is emailed.
// ---------------------------------------------------------------------------

/** Signed, timestamped form token: proves the form was fetched before the POST. */
function er_form_token() {
	$ts = time();
	$nonce = bin2hex( random_bytes( 8 ) );
	return $ts . '.' . $nonce . '.' . hash_hmac( 'sha256', "form:$ts:$nonce", er_secret() );
}

/**
 * Verify a form token: genuine, filled in no faster than a person could
 * (min_fill_seconds), not stale, and never used before.
 */
function er_check_form_token( $tok ) {
	$cfg = er_config();
	if ( !is_string( $tok ) || !preg_match( '/^(\d{10})\.([a-f0-9]{16})\.([a-f0-9]{64})$/', $tok, $m ) ) {
		return 'bad';
	}
	[ , $ts, $nonce, $mac ] = $m;
	if ( !hash_equals( hash_hmac( 'sha256', "form:$ts:$nonce", er_secret() ), $mac ) ) {
		return 'bad';
	}
	$age = time() - (int)$ts;
	if ( $age < $cfg['min_fill_seconds'] ) {
		return 'fast';
	}
	if ( $age > $cfg['form_ttl_seconds'] ) {
		return 'stale';
	}
	// Single use: exclusive-create a marker named after the nonce.
	$dir = er_mkdir( er_data_dir() . '/used' );
	$fh = @fopen( "$dir/$nonce", 'x' );
	if ( !$fh ) {
		return 'replay';
	}
	fclose( $fh );
	// Opportunistic sweep of markers older than the token lifetime.
	if ( random_int( 1, 50 ) === 1 ) {
		foreach ( glob( "$dir/*" ) ?: [] as $f ) {
			if ( filemtime( $f ) < time() - $cfg['form_ttl_seconds'] ) {
				@unlink( $f );
			}
		}
	}
	return 'ok';
}

/** Names that carry links or markup are spam, not people. */
function er_name_looks_spammy( $name ) {
	return (bool)preg_match( '~https?:|www\.|[<>@\[\]{}]|\.(com|net|org|ru|cn|xyz|top|io|info|biz)\b~i', $name );
}

/** Requests saved in the last $secs seconds, optionally only those from $email. */
function er_recent_requests( $secs, $email = null ) {
	$n = 0;
	$cut = time() - $secs;
	foreach ( glob( er_requests_dir() . '/*.json' ) ?: [] as $f ) {
		if ( filemtime( $f ) < $cut ) {
			continue;
		}
		if ( $email !== null ) {
			$r = json_decode( (string)file_get_contents( $f ), true );
			if ( !is_array( $r ) || strcasecmp( (string)( $r['email'] ?? '' ), $email ) !== 0 ) {
				continue;
			}
		}
		$n++;
	}
	return $n;
}

/** Turnstile is on only when both halves exist; then it fails closed. */
function er_turnstile_secret() {
	$f = er_data_dir() . '/turnstile.secret';
	return ( er_config()['turnstile_sitekey'] !== '' && is_file( $f ) )
		? trim( (string)file_get_contents( $f ) ) : '';
}

function er_turnstile_ok( $response, $ip ) {
	$secret = er_turnstile_secret();
	if ( $secret === '' ) {
		return true;
	}
	if ( !is_string( $response ) || $response === '' || strlen( $response ) > 2048 ) {
		return false;
	}
	$ctx = stream_context_create( [ 'http' => [
		'method'  => 'POST',
		'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
		'content' => http_build_query( [ 'secret' => $secret, 'response' => $response, 'remoteip' => $ip ] ),
		'timeout' => 8,
	] ] );
	$raw = @file_get_contents( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx );
	$j = is_string( $raw ) ? json_decode( $raw, true ) : null;
	return is_array( $j ) && ( $j['success'] ?? false ) === true
		&& ( $j['hostname'] ?? '' ) === parse_url( er_config()['base_url'], PHP_URL_HOST );
}

/** Append one line per rejected submission, so abuse is visible after the fact. */
function er_log_reject( $why, $ip ) {
	$dir = er_mkdir( er_data_dir() . '/log' );
	@file_put_contents( "$dir/rejects.log", gmdate( 'c' ) . " $ip $why\n", FILE_APPEND | LOCK_EX );
}
