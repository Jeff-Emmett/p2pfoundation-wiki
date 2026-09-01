<?php
/**
 * Shared helpers for the batch-review flow.
 *
 * Plain PHP with no MediaWiki dependency: the app talks to the wiki over the
 * loopback API using the reviewer's own session cookies, so every edit is
 * attributed to the human who approved it and passes through the wiki's normal
 * permission checks. Nothing here runs with more authority than the reviewer.
 */

// Not a web entry point. `.htaccess` cannot enforce this on wiki.p2pfoundation.net —
// block-bots.conf carries a site-wide `<Location "/"> Require all granted`, and a
// <Location> is merged after every <Directory> and .htaccess, so it overrides them.
// Guard in PHP instead, which no server config can undo.
if ( !defined( 'BR_ENTRY' ) && PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit;
}

// --------------------------------------------------------------------------
// config + state
// --------------------------------------------------------------------------

function br_config() {
	static $c = null;
	if ( $c === null ) {
		$c = require __DIR__ . '/config.php';
	}
	return $c;
}

function br_mkdir( $dir ) {
	if ( !is_dir( $dir ) ) {
		@mkdir( $dir, 0770, true );
	}
	return $dir;
}

function br_data_dir()    { return br_mkdir( rtrim( br_config()['data_dir'], '/' ) ); }
function br_batches_dir() { return br_mkdir( br_data_dir() . '/batches' ); }
function br_log_dir()     { return br_mkdir( br_data_dir() . '/log' ); }

/** Shared HMAC secret for CSRF tokens, generated once on first use. */
function br_secret() {
	$f = br_data_dir() . '/secret.key';
	if ( !is_file( $f ) ) {
		$tmp = $f . '.tmp.' . getmypid();
		file_put_contents( $tmp, bin2hex( random_bytes( 32 ) ) );
		@chmod( $tmp, 0600 );
		@rename( $tmp, $f );
	}
	return trim( (string)file_get_contents( $f ) );
}

/** CSRF token bound to the reviewer and the batch they are acting on. */
function br_csrf( $user, $batchId ) {
	return hash_hmac( 'sha256', 'br:' . $user . ':' . $batchId, br_secret() );
}

function br_check_csrf( $user, $batchId, $tok ) {
	return is_string( $tok ) && hash_equals( br_csrf( $user, $batchId ), $tok );
}

function br_valid_batch_id( $id ) {
	return is_string( $id ) && (bool)preg_match( '/^[A-Za-z0-9._-]{3,80}$/', $id );
}

// --------------------------------------------------------------------------
// HTTP to the loopback API
// --------------------------------------------------------------------------

/**
 * Call the wiki API on loopback, forwarding the caller's cookies so the wiki
 * sees the request as coming from the logged-in reviewer.
 *
 * $method 'GET'|'POST'; $params associative. Returns decoded JSON or null.
 */
function br_api( $method, array $params, $cookieHeader = null ) {
	$cfg = br_config();
	$params['format'] = 'json';
	$params['formatversion'] = '2';

	if ( $cookieHeader === null ) {
		$cookieHeader = br_incoming_cookie_header();
	}

	$url  = $cfg['api_url'];
	$body = http_build_query( $params );
	$headers = [
		'Host: ' . $cfg['api_host'],
		'User-Agent: p2pwiki-batch-review/1.0 (loopback; operated by wiki staff)',
		'Accept: application/json',
	];
	if ( $cookieHeader !== '' ) {
		$headers[] = 'Cookie: ' . $cookieHeader;
	}

	if ( $method === 'GET' ) {
		$url .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . $body;
		$body = null;
	} else {
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
	}

	$raw = br_http( $method, $url, $headers, $body );
	if ( $raw === null ) {
		return null;
	}
	$j = json_decode( $raw, true );
	return is_array( $j ) ? $j : null;
}

function br_http( $method, $url, array $headers, $body ) {
	if ( function_exists( 'curl_init' ) ) {
		$ch = curl_init( $url );
		curl_setopt_array( $ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_FOLLOWLOCATION => false,
		] );
		if ( $method === 'POST' ) {
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
		}
		$out = curl_exec( $ch );
		curl_close( $ch );
		return $out === false ? null : $out;
	}
	$ctx = stream_context_create( [ 'http' => [
		'method'        => $method,
		'header'        => implode( "\r\n", $headers ),
		'content'       => $body,
		'timeout'       => 30,
		'ignore_errors' => true,
	] ] );
	$out = @file_get_contents( $url, false, $ctx );
	return $out === false ? null : $out;
}

/** Rebuild a Cookie header from whatever the browser sent us. */
function br_incoming_cookie_header() {
	if ( PHP_SAPI === 'cli' ) {
		return (string)getenv( 'BR_COOKIE' );
	}
	if ( !empty( $_SERVER['HTTP_COOKIE'] ) ) {
		return (string)$_SERVER['HTTP_COOKIE'];
	}
	$parts = [];
	foreach ( $_COOKIE as $k => $v ) {
		$parts[] = rawurlencode( $k ) . '=' . rawurlencode( (string)$v );
	}
	return implode( '; ', $parts );
}

// --------------------------------------------------------------------------
// identity + the allowlist
// --------------------------------------------------------------------------

/** The logged-in wiki user making this request, or null if anonymous. */
function br_current_user() {
	static $u = false;
	if ( $u !== false ) {
		return $u;
	}
	$r = br_api( 'GET', [
		'action' => 'query',
		'meta'   => 'userinfo',
		'uiprop' => 'groups|rights',
	] );
	$info = $r['query']['userinfo'] ?? null;
	if ( !$info || !empty( $info['anon'] ) || empty( $info['name'] ) ) {
		$u = null;
		return $u;
	}
	$u = [
		'name'   => (string)$info['name'],
		'groups' => (array)( $info['groups'] ?? [] ),
		'rights' => (array)( $info['rights'] ?? [] ),
	];
	return $u;
}

function br_is_reviewer( $name ) {
	foreach ( br_config()['reviewers'] as $allowed ) {
		// MediaWiki usernames are case-sensitive after the first character.
		if ( ucfirst( (string)$allowed ) === ucfirst( (string)$name ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Gate every entry point. Anonymous and non-allowlisted users get a flat 403
 * with no detail — they learn nothing about what is queued.
 */
function br_require_reviewer() {
	$u = br_current_user();
	if ( !$u ) {
		br_deny( 'You need to be logged in to the wiki to use this page.', true );
	}
	if ( !br_is_reviewer( $u['name'] ) ) {
		br_deny( 'This tool is in closed testing.' );
	}
	if ( !in_array( 'edit', $u['rights'], true ) ) {
		br_deny( 'Your account cannot edit this wiki.' );
	}
	return $u;
}

function br_deny( $msg, $offerLogin = false ) {
	$cfg = br_config();
	http_response_code( 403 );
	header( 'Content-Type: text/html; charset=utf-8' );
	$m = htmlspecialchars( $msg, ENT_QUOTES, 'UTF-8' );
	$login = '';
	if ( $offerLogin ) {
		$url = $cfg['wiki_base'] . '/index.php?title=Special:UserLogin&returnto=Main+Page';
		$login = '<p><a href="' . htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) . '">Log in</a></p>';
	}
	echo "<!doctype html><meta charset=utf-8><title>Not available</title>"
		. "<body style=\"font:15px/1.5 system-ui,sans-serif;max-width:34rem;margin:16vh auto;padding:0 1.5rem;color:#222\">"
		. "<h1 style=\"font-size:1.2rem\">Not available</h1><p>{$m}</p>{$login}</body>";
	exit;
}

// --------------------------------------------------------------------------
// batches
// --------------------------------------------------------------------------

function br_batch_path( $id ) {
	return br_batches_dir() . '/' . $id . '.json';
}

function br_load_batch( $id ) {
	if ( !br_valid_batch_id( $id ) ) {
		return null;
	}
	$f = br_batch_path( $id );
	if ( !is_file( $f ) ) {
		return null;
	}
	$j = json_decode( (string)file_get_contents( $f ), true );
	return is_array( $j ) ? $j : null;
}

function br_save_batch( array $b ) {
	$f   = br_batch_path( $b['id'] );
	$tmp = $f . '.tmp.' . getmypid();
	file_put_contents( $tmp, json_encode( $b, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	@chmod( $tmp, 0660 );
	rename( $tmp, $f );
}

function br_list_batches() {
	$out = [];
	foreach ( glob( br_batches_dir() . '/*.json' ) ?: [] as $f ) {
		$j = json_decode( (string)file_get_contents( $f ), true );
		if ( !is_array( $j ) || empty( $j['id'] ) ) {
			continue;
		}
		$out[] = $j;
	}
	usort( $out, static function ( $a, $b ) {
		return strcmp( (string)( $b['created'] ?? '' ), (string)( $a['created'] ?? '' ) );
	} );
	return $out;
}

function br_batch_counts( array $b ) {
	$c = [ 'total' => 0, 'approve' => 0, 'reject' => 0, 'undecided' => 0, 'applied' => 0, 'skipped' => 0, 'error' => 0 ];
	foreach ( $b['items'] as $it ) {
		$c['total']++;
		$d = $it['decision'] ?? null;
		if ( $d === 'approve' )      { $c['approve']++; }
		elseif ( $d === 'reject' )   { $c['reject']++; }
		else                         { $c['undecided']++; }
		$rs = $it['result']['status'] ?? null;
		if ( $rs === 'ok' || $rs === 'dry-run' ) { $c['applied']++; }
		elseif ( $rs === 'already' )             { $c['skipped']++; }
		elseif ( $rs === 'error' )               { $c['error']++; }
	}
	return $c;
}

// --------------------------------------------------------------------------
// reading and writing wiki pages
// --------------------------------------------------------------------------

/**
 * Current wikitext of a page plus the timestamps needed for conflict
 * detection. Returns ['exists'=>bool,'text'=>string,'base'=>ts,'start'=>ts].
 */
function br_fetch_page( $title ) {
	$r = br_api( 'GET', [
		'action'      => 'query',
		'prop'        => 'revisions',
		'rvprop'      => 'content|timestamp|ids',
		'rvslots'     => 'main',
		'rvlimit'     => 1,
		'titles'      => $title,
		'curtimestamp' => 1,
	] );
	$start = $r['curtimestamp'] ?? gmdate( 'c' );
	$pg = $r['query']['pages'][0] ?? null;
	if ( !$pg ) {
		return null;
	}
	if ( !empty( $pg['missing'] ) ) {
		return [ 'exists' => false, 'text' => '', 'base' => null, 'start' => $start, 'revid' => null ];
	}
	$rev = $pg['revisions'][0] ?? null;
	return [
		'exists' => true,
		'text'   => (string)( $rev['slots']['main']['content'] ?? '' ),
		'base'   => $rev['timestamp'] ?? null,
		'start'  => $start,
		'revid'  => $rev['revid'] ?? null,
	];
}

/** Write a page as the reviewer. Returns ['status'=>..,'revid'=>..,'msg'=>..]. */
function br_edit_page( $title, $text, $summary, $base, $start, $createOnly = false ) {
	$tok = br_api( 'GET', [ 'action' => 'query', 'meta' => 'tokens', 'type' => 'csrf' ] );
	$csrf = $tok['query']['tokens']['csrftoken'] ?? null;
	if ( !$csrf || $csrf === '+\\' ) {
		return [ 'status' => 'error', 'msg' => 'could not obtain an edit token (session expired?)' ];
	}
	$p = [
		'action'  => 'edit',
		'title'   => $title,
		'text'    => $text,
		'summary' => $summary,
		'token'   => $csrf,
		'nocreate' => $createOnly ? null : 1,
	];
	if ( $createOnly ) {
		unset( $p['nocreate'] );
		$p['createonly'] = 1;
	}
	if ( $base )  { $p['basetimestamp']  = $base; }
	if ( $start ) { $p['starttimestamp'] = $start; }
	$p = array_filter( $p, static function ( $v ) { return $v !== null; } );

	$r = br_api( 'POST', $p );
	if ( !$r ) {
		return [ 'status' => 'error', 'msg' => 'no response from the wiki API' ];
	}
	if ( isset( $r['error'] ) ) {
		return [ 'status' => 'error', 'msg' => (string)( $r['error']['code'] ?? '' ) . ': ' . (string)( $r['error']['info'] ?? '' ) ];
	}
	$e = $r['edit'] ?? [];
	if ( ( $e['result'] ?? '' ) !== 'Success' ) {
		return [ 'status' => 'error', 'msg' => 'unexpected API result: ' . json_encode( $e ) ];
	}
	if ( !empty( $e['nochange'] ) ) {
		return [ 'status' => 'already', 'msg' => 'no change needed' ];
	}
	return [ 'status' => 'ok', 'revid' => $e['newrevid'] ?? null, 'msg' => 'edited' ];
}

// --------------------------------------------------------------------------
// the operations
// --------------------------------------------------------------------------

/**
 * Regex fragment matching one category name as MediaWiki would resolve it:
 * underscores and spaces are the same character, runs of them collapse, and
 * the first letter is case-insensitive.
 *
 * Note preg_quote() does NOT escape a space, so the substitution below has to
 * look for a literal space. Getting that wrong makes every check silently fail
 * to notice an existing tag, which would double-tag pages on a live run.
 */
function br_cat_regex( $cat ) {
	$name = trim( str_replace( '_', ' ', $cat ) );
	$q    = str_replace( ' ', '[ _]+', preg_quote( $name, '/' ) );
	$first = substr( $name, 0, 1 );
	if ( preg_match( '/[A-Za-z]/', $first ) ) {
		// preg_quote leaves an ASCII letter alone, so it is exactly one char here.
		$q = '[' . strtoupper( $first ) . strtolower( $first ) . ']' . substr( $q, 1 );
	}
	return $q;
}

/** Does this wikitext already carry [[Category:$cat]]? Tolerates _ vs space, sortkeys, case. */
function br_has_category( $text, $cat ) {
	return (bool)preg_match(
		'/\[\[\s*[Cc]ategory\s*:\s*' . br_cat_regex( $cat ) . '\s*(\|[^\]]*)?\]\]/u',
		$text
	);
}

/** Append a category tag, keeping the wikitext tidy. */
function br_append_category( $text, $cat ) {
	$tag = '[[Category:' . str_replace( '_', ' ', trim( $cat ) ) . ']]';
	$t = rtrim( $text );
	// If the page already ends in a run of category tags, join that block.
	if ( preg_match( '/\[\[\s*[Cc]ategory\s*:[^\]]*\]\]\s*$/u', $t ) ) {
		return $t . "\n" . $tag . "\n";
	}
	return $t . "\n\n" . $tag . "\n";
}

/** Swap one category tag for another, preserving any sortkey. */
function br_replace_category( $text, $from, $to ) {
	$to = str_replace( '_', ' ', trim( $to ) );
	return preg_replace_callback(
		'/\[\[\s*[Cc]ategory\s*:\s*' . br_cat_regex( $from ) . '\s*(\|[^\]]*)?\]\]/u',
		static function ( $m ) use ( $to ) {
			return '[[Category:' . $to . ( $m[1] ?? '' ) . ']]';
		},
		$text
	);
}

/**
 * Work out what an item would do, without writing anything.
 *
 * Returns ['status'=>'ready|already|missing|error','title'=>..,'before'=>..,
 *          'after'=>..,'diff'=>[lines],'base'=>..,'start'=>..,'summary'=>..,'msg'=>..]
 */
function br_plan_item( array $batch, array $item ) {
	$cfg   = br_config();
	$title = (string)$item['target'];
	$op    = (string)$item['op'];
	$arg   = $item['arg'] ?? null;
	$sum   = '[' . $cfg['summary_prefix'] . ' ' . $batch['id'] . '] ' . ( $item['why'] ?? $op );

	if ( $op === 'create-draft' ) {
		$pg = br_fetch_page( $title );
		if ( $pg === null ) {
			return [ 'status' => 'error', 'msg' => 'could not read ' . $title ];
		}
		if ( $pg['exists'] ) {
			return [ 'status' => 'already', 'title' => $title, 'msg' => 'draft already exists', 'diff' => [] ];
		}
		$text = (string)( $item['text'] ?? '' );
		if ( $text === '' ) {
			return [ 'status' => 'error', 'msg' => 'proposal carries no draft text' ];
		}
		return [
			'status'  => 'ready', 'title' => $title, 'before' => '', 'after' => $text,
			'diff'    => array_map( static function ( $l ) { return '+ ' . $l; }, explode( "\n", $text ) ),
			'base'    => null, 'start' => $pg['start'], 'summary' => $sum, 'create' => true,
		];
	}

	$pg = br_fetch_page( $title );
	if ( $pg === null ) {
		return [ 'status' => 'error', 'msg' => 'could not read ' . $title ];
	}
	if ( !$pg['exists'] ) {
		return [ 'status' => 'missing', 'title' => $title, 'msg' => 'page does not exist', 'diff' => [] ];
	}

	if ( $op === 'append-category' ) {
		if ( br_has_category( $pg['text'], $arg ) ) {
			return [ 'status' => 'already', 'title' => $title, 'msg' => 'already in [[Category:' . $arg . ']]', 'diff' => [] ];
		}
		$after = br_append_category( $pg['text'], $arg );
		return [
			'status' => 'ready', 'title' => $title, 'before' => $pg['text'], 'after' => $after,
			'diff'   => [ '+ [[Category:' . str_replace( '_', ' ', $arg ) . ']]' ],
			'base'   => $pg['base'], 'start' => $pg['start'], 'summary' => $sum,
		];
	}

	if ( $op === 'replace-category' ) {
		$from = (string)( $item['from'] ?? '' );
		$to   = (string)( $item['to'] ?? '' );
		if ( $from === '' || $to === '' ) {
			return [ 'status' => 'error', 'msg' => 'replace-category needs from and to' ];
		}
		if ( !br_has_category( $pg['text'], $from ) ) {
			return [ 'status' => 'already', 'title' => $title, 'msg' => 'not in [[Category:' . $from . ']]', 'diff' => [] ];
		}
		$after = br_replace_category( $pg['text'], $from, $to );
		if ( $after === $pg['text'] ) {
			return [ 'status' => 'already', 'title' => $title, 'msg' => 'nothing matched', 'diff' => [] ];
		}
		return [
			'status' => 'ready', 'title' => $title, 'before' => $pg['text'], 'after' => $after,
			'diff'   => [ '- [[Category:' . $from . ']]', '+ [[Category:' . $to . ']]' ],
			'base'   => $pg['base'], 'start' => $pg['start'], 'summary' => $sum,
		];
	}

	return [ 'status' => 'error', 'msg' => 'unknown operation: ' . $op ];
}

/** Apply one item. In dry-run mode this plans but never writes. */
function br_apply_item( array $batch, array $item ) {
	$cfg  = br_config();
	$plan = br_plan_item( $batch, $item );

	if ( $plan['status'] !== 'ready' ) {
		return [
			'status' => $plan['status'] === 'error' ? 'error' : 'already',
			'msg'    => $plan['msg'] ?? $plan['status'],
			'at'     => gmdate( 'c' ),
		];
	}
	if ( !$cfg['live_writes'] ) {
		return [
			'status' => 'dry-run',
			'msg'    => 'would ' . $item['op'] . ' on ' . $plan['title'],
			'diff'   => $plan['diff'],
			'at'     => gmdate( 'c' ),
		];
	}
	$res = br_edit_page(
		$plan['title'], $plan['after'], $plan['summary'],
		$plan['base'] ?? null, $plan['start'] ?? null,
		!empty( $plan['create'] )
	);
	$res['at'] = gmdate( 'c' );
	return $res;
}

// --------------------------------------------------------------------------
// small view helpers
// --------------------------------------------------------------------------

function br_h( $s ) { return htmlspecialchars( (string)$s, ENT_QUOTES, 'UTF-8' ); }

function br_wiki_link( $title ) {
	return br_config()['wiki_base'] . '/index.php?title=' . rawurlencode( str_replace( ' ', '_', $title ) );
}

function br_log_commit( array $rec ) {
	$f = br_log_dir() . '/' . gmdate( 'Ymd-His' ) . '-' . $rec['batch'] . '.json';
	file_put_contents( $f, json_encode( $rec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	@chmod( $f, 0660 );
}
