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
