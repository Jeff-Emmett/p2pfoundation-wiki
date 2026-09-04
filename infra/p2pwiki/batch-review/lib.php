<?php
/**
 * Shared helpers for the batch-review flow.
 *
 * Plain PHP with no MediaWiki dependency: the app talks to the wiki over the
 * loopback API using the reviewer's own session cookies, so every edit is
 * attributed to the human who approved it and passes through the wiki's normal
 * permission checks. Nothing here runs with more authority than the reviewer.
 *
 * The applier's eight guarantees, and where each one lives:
 *
 *   01 dry run by default          config 'live_writes', honoured in br_apply_page_group()
 *   02 re-verified at write time   br_plan_group() re-fetches the live page every time
 *   03 idempotent                  every op reports 'already' rather than repeating itself
 *   04 one revision per article    items are grouped by target; a group is ONE edit
 *   05 every edit names approver   br_group_summary()
 *   06 reversible                  revision ids recorded per item; undo.php walks them back
 *   07 rate limited                config 'edit_delay_us' + 'max_items_per_commit'
 *   08 stoppable by anyone         br_stop_state(), an ordinary wiki page
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

/**
 * Append-only access log. Every denial and every write-shaped action lands
 * here, so "who opened this, and when" is answerable without the wiki's own
 * logs — including for the people the gate turned away, who leave no trace
 * anywhere else.
 */
function br_audit( $event, array $fields = [] ) {
	$rec = array_merge( [
		'at'    => gmdate( 'c' ),
		'event' => $event,
		'ip'    => $_SERVER['REMOTE_ADDR'] ?? ( PHP_SAPI === 'cli' ? 'cli' : '?' ),
		'uri'   => $_SERVER['REQUEST_URI'] ?? ( $_SERVER['SCRIPT_NAME'] ?? '' ),
	], $fields );
	$f = br_log_dir() . '/access-' . gmdate( 'Ym' ) . '.jsonl';
	@file_put_contents(
		$f,
		json_encode( $rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n",
		FILE_APPEND | LOCK_EX
	);
	@chmod( $f, 0660 );
}

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

function br_http( $method, $url, array $headers, $body, $timeout = 30 ) {
	// $timeout defaults to 30s, which is right for the wiki's own API. A local
	// model generating a thousand words takes minutes, so the synthesis
	// generator passes its own, far larger, value.
	if ( function_exists( 'curl_init' ) ) {
		$ch = curl_init( $url );
		curl_setopt_array( $ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => (int)$timeout,
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
		'timeout'       => (int)$timeout,
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
		'uiprop' => 'groups|rights|blockinfo',
	] );
	$info = $r['query']['userinfo'] ?? null;
	if ( !$info || !empty( $info['anon'] ) || empty( $info['name'] ) ) {
		$u = null;
		return $u;
	}
	$u = [
		'id'      => (int)( $info['id'] ?? 0 ),
		'name'    => (string)$info['name'],
		'groups'  => (array)( $info['groups'] ?? [] ),
		'rights'  => (array)( $info['rights'] ?? [] ),
		// blockid is present only while the account is actually blocked.
		'blocked' => isset( $info['blockid'] ),
		'blockreason' => (string)( $info['blockreason'] ?? '' ),
	];
	return $u;
}

/**
 * Normalise a username the way MediaWiki does before comparing: underscores
 * are spaces, runs of whitespace collapse, and only the FIRST character is
 * case-insensitive. Getting this wrong in either direction is a bug — too
 * loose lets a stranger in, too strict locks a reviewer out of their own tool.
 */
function br_norm_username( $name ) {
	$n = trim( preg_replace( '/[\s_]+/u', ' ', (string)$name ) );
	if ( $n === '' ) {
		return '';
	}
	return mb_strtoupper( mb_substr( $n, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $n, 1, null, 'UTF-8' );
}

/**
 * Is this account one of the two reviewers?
 *
 * Both the name and the numeric id must match. See the long comment on
 * 'reviewers' in config.php for why the id is not belt-and-braces: these
 * accounts hold `bureaucrat`, which is the right that renames accounts, so a
 * name alone is a mutable key.
 */
function br_is_reviewer( array $user ) {
	$name = br_norm_username( $user['name'] ?? '' );
	$id   = (int)( $user['id'] ?? 0 );
	if ( $name === '' || $id <= 0 ) {
		return false;
	}
	foreach ( br_config()['reviewers'] as $allowed ) {
		// A bare string is accepted for compatibility, but pins nothing.
		if ( is_string( $allowed ) ) {
			if ( br_norm_username( $allowed ) === $name ) {
				return true;
			}
			continue;
		}
		if ( br_norm_username( $allowed['name'] ?? '' ) !== $name ) {
			continue;
		}
		$want = (int)( $allowed['id'] ?? 0 );
		if ( $want > 0 && $want !== $id ) {
			// Right name, wrong account. This is the case the id exists to
			// catch, so it is worth a line in the log rather than a silent no.
			br_audit( 'deny.id-mismatch', [ 'user' => $user['name'], 'id' => $id, 'expected_id' => $want ] );
			return false;
		}
		return true;
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
		br_audit( 'deny.anon' );
		br_deny( 'You need to be logged in to the wiki to use this page.', true );
	}
	if ( !br_is_reviewer( $u ) ) {
		br_audit( 'deny.not-reviewer', [ 'user' => $u['name'], 'id' => $u['id'] ] );
		br_deny( 'This tool is in closed testing.' );
	}
	if ( !empty( $u['blocked'] ) && !empty( br_config()['refuse_blocked'] ) ) {
		br_audit( 'deny.blocked', [ 'user' => $u['name'], 'id' => $u['id'] ] );
		br_deny( 'Your wiki account is currently blocked.' );
	}
	if ( !in_array( 'edit', $u['rights'], true ) ) {
		br_audit( 'deny.no-edit-right', [ 'user' => $u['name'], 'id' => $u['id'] ] );
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
	$c = [ 'total' => 0, 'approve' => 0, 'reject' => 0, 'undecided' => 0,
	       'applied' => 0, 'skipped' => 0, 'error' => 0, 'pending' => 0 ];
	foreach ( $b['items'] as $it ) {
		$c['total']++;
		$d = $it['decision'] ?? null;
		if ( $d === 'approve' )      { $c['approve']++; }
		elseif ( $d === 'reject' )   { $c['reject']++; }
		else                         { $c['undecided']++; }
		$rs = $it['result']['status'] ?? null;
		if ( $rs === 'ok' || $rs === 'dry-run' )     { $c['applied']++; }
		elseif ( $rs === 'already' || $rs === 'stale' || $rs === 'missing' ) { $c['skipped']++; }
		elseif ( $rs === 'error' )                   { $c['error']++; }
		if ( $d === 'approve' && $rs === null )      { $c['pending']++; }
	}
	return $c;
}

/**
 * Guarantee 04 — one revision per article.
 *
 * Group the given items by the page they touch, keeping first-appearance
 * order. Two approved items that both add a category to the same article
 * become one edit, not two, so a page's history stays readable.
 *
 * Bookmark decisions are not wiki pages, so they get their own key space and
 * are never merged with an edit.
 */
function br_group_key( array $item ) {
	if ( ( $item['op'] ?? '' ) === 'classify-bookmark' ) {
		return 'bookmark:' . (string)( $item['target'] ?? '' );
	}
	return 'page:' . br_norm_title( (string)( $item['target'] ?? '' ) );
}

function br_norm_title( $t ) {
	$t = trim( preg_replace( '/[\s_]+/u', ' ', (string)$t ) );
	if ( $t === '' ) {
		return '';
	}
	// Only the first character of a title is case-insensitive in MediaWiki,
	// and for a namespaced title that is the first character after the colon.
	if ( preg_match( '/^([^:]{1,32}):\s*(.*)$/u', $t, $m ) && $m[2] !== '' ) {
		return $m[1] . ':' . mb_strtoupper( mb_substr( $m[2], 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $m[2], 1, null, 'UTF-8' );
	}
	return mb_strtoupper( mb_substr( $t, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $t, 1, null, 'UTF-8' );
}

function br_group_items( array $items ) {
	$groups = [];
	foreach ( $items as $it ) {
		$groups[br_group_key( $it )][] = $it;
	}
	return $groups;
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

function br_csrf_token() {
	$tok = br_api( 'GET', [ 'action' => 'query', 'meta' => 'tokens', 'type' => 'csrf' ] );
	$t = $tok['query']['tokens']['csrftoken'] ?? null;
	return ( !$t || $t === '+\\' ) ? null : $t;
}

/** Write a page as the reviewer. Returns ['status'=>..,'revid'=>..,'msg'=>..]. */
function br_edit_page( $title, $text, $summary, $base, $start, $createOnly = false ) {
	$csrf = br_csrf_token();
	if ( !$csrf ) {
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

	return br_edit_result( br_api( 'POST', $p ) );
}

/**
 * Guarantee 06 — reversible. Undo one recorded revision through MediaWiki's
 * own undo, which refuses cleanly if somebody has edited the page since.
 */
function br_undo_revision( $title, $revid, $summary ) {
	$csrf = br_csrf_token();
	if ( !$csrf ) {
		return [ 'status' => 'error', 'msg' => 'could not obtain an edit token (session expired?)' ];
	}
	return br_edit_result( br_api( 'POST', [
		'action'  => 'edit',
		'title'   => $title,
		'undo'    => (int)$revid,
		'summary' => $summary,
		'token'   => $csrf,
	] ) );
}

function br_edit_result( $r ) {
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
// guarantee 08 — the stop handle
// --------------------------------------------------------------------------

/**
 * Any text at all on the stop page halts every commit. It is an ordinary wiki
 * page, so anyone who can edit the wiki can pull the handle without access to
 * this tool and without asking us.
 *
 * A read failure fails CLOSED for live writes — a safety handle that stops
 * working when the wiki is unwell is not a safety handle — and open for a dry
 * run, which writes nothing either way.
 */
function br_stop_state() {
	static $s = null;
	if ( $s !== null ) {
		return $s;
	}
	$cfg  = br_config();
	$page = (string)( $cfg['stop_page'] ?? '' );
	if ( $page === '' ) {
		return $s = [ 'halted' => false, 'text' => '', 'unreadable' => false, 'page' => '' ];
	}
	$pg = br_fetch_page( $page );
	if ( $pg === null ) {
		return $s = [
			'halted'     => (bool)$cfg['live_writes'],
			'text'       => '',
			'unreadable' => true,
			'page'       => $page,
		];
	}
	$text = trim( (string)( $pg['text'] ?? '' ) );
	return $s = [
		'halted'     => $pg['exists'] && $text !== '',
		'text'       => $text,
		'unreadable' => false,
		'page'       => $page,
	];
}

// --------------------------------------------------------------------------
// text operations
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

/** Does the wikitext already link to this page, piped or not? */
function br_has_link( $text, $title ) {
	$t = trim( str_replace( '_', ' ', (string)$title ) );
	$q = str_replace( ' ', '[ _]+', preg_quote( $t, '/' ) );
	$first = substr( $t, 0, 1 );
	if ( preg_match( '/[A-Za-z]/', $first ) ) {
		$q = '[' . strtoupper( $first ) . strtolower( $first ) . ']' . substr( $q, 1 );
	}
	return (bool)preg_match( '/\[\[\s*' . $q . '\s*(\|[^\]]*)?\]\]/u', $text );
}

/**
 * Spans of wikitext a suggested link must never be planted inside: existing
 * links, templates, headings, tables, external links, refs, comments and
 * verbatim blocks. Returns a list of [start, end) byte offsets.
 */
function br_protected_spans( $text ) {
	$spans = [];
	$patterns = [
		'/\[\[[^\[\]]*\]\]/u',              // wikilinks (incl. File:, Category:)
		'/\{\{[^{}]*\}\}/u',                // templates, innermost first
		'/\{\|.*?\|\}/su',                  // tables
		'/^[\|!].*$/mu',                    // stray table rows
		'/^=+.*=+\s*$/mu',                  // headings
		'/\[https?:\/\/[^\]]*\]/u',         // external links
		'/https?:\/\/\S+/u',                // bare urls
		'/<!--.*?-->/su',
		'/<nowiki>.*?<\/nowiki>/su',
		'/<pre>.*?<\/pre>/su',
		'/<ref[^>]*>.*?<\/ref>/su',
		'/<gallery[^>]*>.*?<\/gallery>/su',
	];
	foreach ( $patterns as $p ) {
		if ( preg_match_all( $p, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$spans[] = [ $hit[1], $hit[1] + strlen( $hit[0] ) ];
			}
		}
	}
	// Nested templates: one more pass now that the innermost are known.
	for ( $pass = 0; $pass < 2; $pass++ ) {
		if ( preg_match_all( '/\{\{.*?\}\}/su', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$spans[] = [ $hit[1], $hit[1] + strlen( $hit[0] ) ];
			}
		}
	}
	return $spans;
}

function br_in_span( $offset, array $spans ) {
	foreach ( $spans as $s ) {
		if ( $offset >= $s[0] && $offset < $s[1] ) {
			return true;
		}
	}
	return false;
}

/**
 * Wrap the FIRST unlinked occurrence of $phrase in brackets, and only that
 * one.
 *
 * The first version of this was not idempotent: having linked the first
 * occurrence, a second run found the next unlinked one and linked that too. 24
 * of the first 60 articles mention their target more than once, so left on a
 * schedule it would quietly have linked every occurrence of a phrase in an
 * article — precisely the overlinking the whole design exists to avoid. The
 * fix is also the convention: first occurrence only, and never at all if the
 * article already links there.
 *
 * Returns [ text, changed(bool), reason ].
 */
function br_link_first_mention( $text, $phrase ) {
	$phrase = trim( str_replace( '_', ' ', (string)$phrase ) );
	if ( $phrase === '' ) {
		return [ $text, false, 'no phrase given' ];
	}
	if ( br_has_link( $text, $phrase ) ) {
		return [ $text, false, 'the article already links to [[' . $phrase . ']]' ];
	}
	$spans = br_protected_spans( $text );
	// Exact case, and word-bounded on both sides. Case matters: "…integrate the
	// information" is not a reference to [[Information]]. Multi-word-ness is
	// enforced by the generator, not here.
	$re = '/(?<![\p{L}\p{N}_])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}_])/u';
	if ( !preg_match_all( $re, $text, $m, PREG_OFFSET_CAPTURE ) ) {
		return [ $text, false, 'the phrase no longer appears in the article' ];
	}
	foreach ( $m[0] as $hit ) {
		$off = $hit[1];
		if ( br_in_span( $off, $spans ) ) {
			continue;
		}
		return [
			substr( $text, 0, $off ) . '[[' . $phrase . ']]' . substr( $text, $off + strlen( $hit[0] ) ),
			true,
			'',
		];
	}
	return [ $text, false, 'every occurrence is inside a link, template, heading or table' ];
}

/**
 * A small line diff for previewing a whole-page proposal. Trims the common
 * head and tail, then runs an LCS over what is left; above a size where that
 * would cost real memory it falls back to showing the whole replacement,
 * which is honest rather than clever.
 */
function br_line_diff( $before, $after, $cap = 300 ) {
	$a = $before === '' ? [] : explode( "\n", $before );
	$b = $after  === '' ? [] : explode( "\n", $after );
	$head = 0;
	while ( $head < count( $a ) && $head < count( $b ) && $a[$head] === $b[$head] ) { $head++; }
	$tail = 0;
	while ( $tail < count( $a ) - $head && $tail < count( $b ) - $head
		&& $a[count( $a ) - 1 - $tail] === $b[count( $b ) - 1 - $tail] ) { $tail++; }
	$am = array_slice( $a, $head, count( $a ) - $head - $tail );
	$bm = array_slice( $b, $head, count( $b ) - $head - $tail );

	if ( !$am && !$bm ) {
		return [];
	}
	if ( count( $am ) > $cap || count( $bm ) > $cap ) {
		$out = [];
		foreach ( $am as $l ) { $out[] = '- ' . $l; }
		foreach ( $bm as $l ) { $out[] = '+ ' . $l; }
		return $out;
	}
	// Plain LCS on the changed middle.
	$n = count( $am ); $mn = count( $bm );
	$L = array_fill( 0, $n + 1, array_fill( 0, $mn + 1, 0 ) );
	for ( $i = $n - 1; $i >= 0; $i-- ) {
		for ( $j = $mn - 1; $j >= 0; $j-- ) {
			$L[$i][$j] = $am[$i] === $bm[$j] ? $L[$i + 1][$j + 1] + 1 : max( $L[$i + 1][$j], $L[$i][$j + 1] );
		}
	}
	$out = [];
	$i = $j = 0;
	while ( $i < $n && $j < $mn ) {
		if ( $am[$i] === $bm[$j] ) { $out[] = '  ' . $am[$i]; $i++; $j++; }
		elseif ( $L[$i + 1][$j] >= $L[$i][$j + 1] ) { $out[] = '- ' . $am[$i]; $i++; }
		else { $out[] = '+ ' . $bm[$j]; $j++; }
	}
	while ( $i < $n )  { $out[] = '- ' . $am[$i++]; }
	while ( $j < $mn ) { $out[] = '+ ' . $bm[$j++]; }
	return $out;
}

// --------------------------------------------------------------------------
// planning and applying, one page at a time
// --------------------------------------------------------------------------

/** Ops that rewrite a whole page, and so can never share an edit with another item. */
function br_is_page_op( $op ) {
	return $op === 'create-draft' || $op === 'write-page';
}

/** One line describing what an item does, for the edit summary. */
function br_item_phrase( array $item ) {
	switch ( $item['op'] ?? '' ) {
		case 'append-category':
			return '+[[Category:' . str_replace( '_', ' ', (string)$item['arg'] ) . ']]';
		case 'replace-category':
			return '[[Category:' . $item['from'] . ']] -> [[Category:' . $item['to'] . ']]';
		case 'link-mention':
			return 'link [[' . str_replace( '_', ' ', (string)$item['arg'] ) . ']]';
		case 'create-draft':
			return 'create draft from ' . count( (array)( $item['sources'] ?? [] ) ) . ' sources';
		case 'write-page':
			return (string)( $item['what'] ?? 'write page' );
	}
	return (string)( $item['op'] ?? '?' );
}

/**
 * Guarantee 05 — every edit names its approver.
 *
 * `[batch-review <batch> #3,#7] +[[Category:Commons]] · +[[Category:Money]] — approved by Mbauwens`
 *
 * The approver comes from the item's own decision, not from whoever happens to
 * be pressing Commit: an approval nobody's name is on cannot be audited, and
 * the two are not always the same person.
 */
function br_group_summary( array $batch, array $items, $committer ) {
	$cfg = br_config();
	$ns = $phr = $by = [];
	foreach ( $items as $it ) {
		$ns[]  = '#' . (int)$it['n'];
		$phr[] = br_item_phrase( $it );
		$who   = (string)( $it['decided_by'] ?? '' );
		if ( $who !== '' ) { $by[$who] = true; }
	}
	if ( !$by ) { $by[(string)$committer] = true; }
	$what = implode( ' · ', array_slice( $phr, 0, 4 ) );
	if ( count( $phr ) > 4 ) {
		$what .= ' · +' . ( count( $phr ) - 4 ) . ' more';
	}
	$s = '[' . $cfg['summary_prefix'] . ' ' . $batch['id'] . ' ' . implode( ',', $ns ) . '] '
		. $what . ' — approved by ' . implode( ', ', array_keys( $by ) );
	// MediaWiki truncates summaries at 500 characters; do it here so the
	// truncation lands somewhere we chose.
	return mb_strlen( $s ) > 480 ? ( mb_substr( $s, 0, 477 ) . '...' ) : $s;
}

/**
 * Work out what a group of items would do to one page, without writing.
 *
 * Guarantee 02 — the live page is re-fetched every time. Offsets and text
 * recorded when the batch was generated are never trusted; if the article has
 * moved on, the item is reported stale and skipped rather than forced.
 */
function br_plan_group( array $batch, array $items, $committer = '' ) {
	$first = $items[0];
	$title = br_norm_title( (string)$first['target'] );

	// A whole-page write can never share an edit. If a generator ever emits two
	// for one page, honour the first and refuse the rest loudly — the refusals
	// are carried through to the end rather than dropped, or the extra items
	// would silently report whatever the surviving one did.
	$refused = [];
	$hasPageOp = (bool)array_filter( $items, static function ( $it ) {
		return br_is_page_op( $it['op'] ?? '' );
	} );
	if ( $hasPageOp && count( $items ) > 1 ) {
		foreach ( array_slice( $items, 1 ) as $it ) {
			$refused[(int)$it['n']] = [ 'status' => 'error', 'diff' => [],
				'msg' => 'a whole-page write cannot share an edit with another item' ];
		}
		$items = [ $items[0] ];
	}

	$pg = br_fetch_page( $title );
	if ( $pg === null ) {
		return br_group_fail( $items, $title, 'error', 'could not read ' . $title, $refused );
	}

	$isPageOp = br_is_page_op( $first['op'] ?? '' );
	// create-draft means "must not exist yet". write-page will happily create a
	// page that is missing, which is what the Categories index needs the first
	// time it is written.
	$createOnly = $isPageOp && ( ( $first['op'] === 'create-draft' ) || !empty( $first['create_only'] ) );
	if ( $createOnly && $pg['exists'] ) {
		return br_group_fail( $items, $title, 'already', 'the page already exists', $refused );
	}
	if ( !$isPageOp && !$pg['exists'] ) {
		return br_group_fail( $items, $title, 'missing', 'page does not exist', $refused );
	}
	$isCreate = !$pg['exists'];

	$text = (string)$pg['text'];
	$per  = $refused;
	$ready = [];
	$diff  = [];

	foreach ( $items as $it ) {
		$n  = (int)$it['n'];
		$op = (string)$it['op'];

		if ( $op === 'append-category' ) {
			$cat = (string)( $it['arg'] ?? '' );
			if ( $cat === '' ) {
				$per[$n] = [ 'status' => 'error', 'msg' => 'append-category with no category', 'diff' => [] ];
			} elseif ( br_has_category( $text, $cat ) ) {
				$per[$n] = [ 'status' => 'already', 'msg' => 'already in [[Category:' . $cat . ']]', 'diff' => [] ];
			} else {
				$text = br_append_category( $text, $cat );
				$d = [ '+ [[Category:' . str_replace( '_', ' ', $cat ) . ']]' ];
				$per[$n] = [ 'status' => 'ready', 'msg' => '', 'diff' => $d ];
				$diff = array_merge( $diff, $d );
				$ready[] = $it;
			}

		} elseif ( $op === 'replace-category' ) {
			$from = (string)( $it['from'] ?? '' );
			$to   = (string)( $it['to'] ?? '' );
			if ( $from === '' || $to === '' ) {
				$per[$n] = [ 'status' => 'error', 'msg' => 'replace-category needs from and to', 'diff' => [] ];
			} elseif ( !br_has_category( $text, $from ) ) {
				$per[$n] = [ 'status' => 'already', 'msg' => 'not in [[Category:' . $from . ']]', 'diff' => [] ];
			} else {
				$new = br_replace_category( $text, $from, $to );
				if ( $new === $text ) {
					$per[$n] = [ 'status' => 'already', 'msg' => 'nothing matched', 'diff' => [] ];
				} else {
					$text = $new;
					$d = [ '- [[Category:' . $from . ']]', '+ [[Category:' . $to . ']]' ];
					$per[$n] = [ 'status' => 'ready', 'msg' => '', 'diff' => $d ];
					$diff = array_merge( $diff, $d );
					$ready[] = $it;
				}
			}

		} elseif ( $op === 'link-mention' ) {
			// Deliberately untyped. A suggestion's whole claim to safety is that
			// approving one asserts nothing — it wraps a phrase the author
			// already wrote. A predicate is an assertion and needs a different,
			// higher standard of review, so it belongs on its own track with
			// its own approval, not smuggled into this one.
			if ( !empty( $it['predicate'] ) ) {
				$per[$n] = [ 'status' => 'error', 'diff' => [],
					'msg' => 'typed links are not applied by this tool: a predicate is an assertion and needs its own review' ];
			} else {
				$phrase = (string)( $it['arg'] ?? '' );
				[ $new, $ok, $why ] = br_link_first_mention( $text, $phrase );
				if ( !$ok ) {
					$per[$n] = [
						'status' => strpos( $why, 'already links' ) !== false ? 'already' : 'stale',
						'msg'    => $why,
						'diff'   => [],
					];
				} else {
					$d = br_line_diff( $text, $new );
					$text = $new;
					$per[$n] = [ 'status' => 'ready', 'msg' => '', 'diff' => $d ];
					$diff = array_merge( $diff, $d );
					$ready[] = $it;
				}
			}

		} elseif ( br_is_page_op( $op ) ) {
			$body = (string)( $it['text'] ?? '' );
			if ( $body === '' ) {
				$per[$n] = [ 'status' => 'error', 'msg' => 'proposal carries no page text', 'diff' => [] ];
			} elseif ( rtrim( $body ) === rtrim( $text ) ) {
				$per[$n] = [ 'status' => 'already', 'msg' => 'the page already has exactly this text', 'diff' => [] ];
			} else {
				$d = br_line_diff( $text, $body );
				$text = $body;
				$per[$n] = [ 'status' => 'ready', 'msg' => '', 'diff' => $d ];
				$diff = $d;
				$ready[] = $it;
			}

		} else {
			$per[$n] = [ 'status' => 'error', 'msg' => 'unknown operation: ' . $op, 'diff' => [] ];
		}
	}

	$status = $ready ? 'ready' : br_worst_status( $per );
	return [
		'kind'    => 'page',
		'title'   => $title,
		'status'  => $status,
		'before'  => (string)$pg['text'],
		'after'   => $text,
		'diff'    => $diff,
		'base'    => $pg['base'],
		'start'   => $pg['start'],
		'create'  => $isCreate,
		'items'   => $per,
		'ready'   => $ready,
		'summary' => $ready ? br_group_summary( $batch, $ready, $committer ) : '',
		'msg'     => $ready ? '' : ( $per ? reset( $per )['msg'] : '' ),
	];
}

function br_worst_status( array $per ) {
	foreach ( [ 'error', 'missing', 'stale', 'already' ] as $s ) {
		foreach ( $per as $p ) {
			if ( $p['status'] === $s ) { return $s; }
		}
	}
	return 'already';
}

function br_group_fail( array $items, $title, $status, $msg, array $per = [] ) {
	foreach ( $items as $it ) {
		$per[(int)$it['n']] = [ 'status' => $status, 'msg' => $msg, 'diff' => [] ];
	}
	return [
		'kind' => 'page', 'title' => $title, 'status' => $status, 'msg' => $msg,
		'diff' => [], 'items' => $per, 'ready' => [], 'summary' => '',
		'before' => '', 'after' => '', 'base' => null, 'start' => null, 'create' => false,
	];
}

/**
 * Apply one group: at most ONE wiki edit, however many items it holds.
 * In dry-run mode the planning is identical and the write is simply not sent.
 */
function br_apply_group( array $batch, array $items, $committer ) {
	$cfg = br_config();
	if ( ( $items[0]['op'] ?? '' ) === 'classify-bookmark' ) {
		return br_apply_bookmark_group( $batch, $items, $committer );
	}

	$plan = br_plan_group( $batch, $items, $committer );
	$now  = gmdate( 'c' );
	$out  = [];

	if ( $plan['status'] !== 'ready' ) {
		foreach ( $items as $it ) {
			$p = $plan['items'][(int)$it['n']] ?? [ 'status' => $plan['status'], 'msg' => $plan['msg'] ?? '' ];
			$out[(int)$it['n']] = [
				'status' => $p['status'] === 'error' ? 'error' : $p['status'],
				'msg'    => $p['msg'] ?: ( $plan['msg'] ?? '' ),
				'at'     => $now,
			];
		}
		return [ 'edited' => false, 'results' => $out, 'plan' => $plan ];
	}

	if ( !$cfg['live_writes'] ) {
		foreach ( $items as $it ) {
			$p = $plan['items'][(int)$it['n']];
			$out[(int)$it['n']] = [
				'status' => $p['status'] === 'ready' ? 'dry-run' : $p['status'],
				'msg'    => $p['status'] === 'ready'
					? ( 'would ' . br_item_phrase( $it ) . ' on ' . $plan['title'] )
					: $p['msg'],
				'diff'   => $p['diff'],
				'at'     => $now,
			];
		}
		return [ 'edited' => false, 'results' => $out, 'plan' => $plan ];
	}

	$res = br_edit_page(
		$plan['title'], $plan['after'], $plan['summary'],
		$plan['base'], $plan['start'], !empty( $plan['create'] )
	);
	foreach ( $items as $it ) {
		$n = (int)$it['n'];
		$p = $plan['items'][$n];
		if ( $p['status'] !== 'ready' ) {
			$out[$n] = [ 'status' => $p['status'], 'msg' => $p['msg'], 'at' => $now ];
			continue;
		}
		$out[$n] = [
			'status' => $res['status'],
			'msg'    => $res['msg'] ?? '',
			'revid'  => $res['revid'] ?? null,
			'diff'   => $p['diff'],
			'at'     => $now,
		];
	}
	return [ 'edited' => $res['status'] === 'ok', 'results' => $out, 'plan' => $plan ];
}

// --------------------------------------------------------------------------
// Diigo: the public / private decision
// --------------------------------------------------------------------------

/**
 * The release ledger. Append-only JSONL, one line per decision, outside the
 * docroot alongside the batches.
 *
 * This is the ONLY thing a bookmark decision writes. Nothing reaches the LLM
 * pipeline, the wiki or Diigo itself until someone runs the export
 * explicitly — and the export reads this file, never a batch file, so an
 * undecided bookmark cannot be swept along by an "approve all".
 */
function br_diigo_ledger_path() {
	$cfg = br_config();
	if ( !empty( $cfg['diigo_ledger'] ) ) {
		return $cfg['diigo_ledger'];
	}
	return br_mkdir( br_data_dir() . '/diigo' ) . '/decisions.jsonl';
}

/** Every decision recorded so far, latest wins. Returns [url => record]. */
function br_diigo_decisions() {
	static $d = null;
	if ( $d !== null ) {
		return $d;
	}
	$d = [];
	$f = br_diigo_ledger_path();
	if ( !is_file( $f ) ) {
		return $d;
	}
	foreach ( file( $f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: [] as $line ) {
		$r = json_decode( $line, true );
		if ( is_array( $r ) && !empty( $r['url'] ) ) {
			$d[$r['url']] = $r;
		}
	}
	return $d;
}

function br_diigo_record( array $rec ) {
	$f = br_diigo_ledger_path();
	@file_put_contents(
		$f,
		json_encode( $rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n",
		FILE_APPEND | LOCK_EX
	);
	@chmod( $f, 0660 );
}

/**
 * Applying a bookmark decision writes one ledger line. There is no wiki edit,
 * so 'live_writes' does not gate it — that switch is about the wiki. What it
 * does gate is nothing being released: the export step is separate and
 * deliberate.
 */
function br_apply_bookmark_group( array $batch, array $items, $committer ) {
	$now  = gmdate( 'c' );
	$out  = [];
	$seen = br_diigo_decisions();
	foreach ( $items as $it ) {
		$n   = (int)$it['n'];
		$url = (string)( $it['target'] ?? '' );
		if ( $url === '' ) {
			$out[$n] = [ 'status' => 'error', 'msg' => 'no url', 'at' => $now ];
			continue;
		}
		// An approved item releases; anything else keeps it private. This is
		// only ever called for approved items, but be explicit rather than
		// assume it.
		$vis = ( $it['decision'] ?? null ) === 'approve' ? 'public' : 'private';
		$prev = $seen[$url]['visibility'] ?? null;
		if ( $prev === $vis ) {
			$out[$n] = [ 'status' => 'already', 'msg' => 'already recorded as ' . $vis, 'at' => $now ];
			continue;
		}
		br_diigo_record( [
			'at'         => $now,
			'url'        => $url,
			'title'      => (string)( $it['title'] ?? $it['why'] ?? '' ),
			'visibility' => $vis,
			'by'         => (string)( $it['decided_by'] ?? $committer ),
			'batch'      => $batch['id'],
			'item'       => $n,
		] );
		$out[$n] = [
			'status' => 'ok',
			'msg'    => 'recorded as ' . $vis . ( $vis === 'public' ? ' — eligible for the wiki pipeline' : ' — stays out of the wiki' ),
			'at'     => $now,
		];
	}
	return [ 'edited' => false, 'results' => $out, 'plan' => [ 'kind' => 'bookmark', 'status' => 'ready' ] ];
}

// --------------------------------------------------------------------------
// small view helpers
// --------------------------------------------------------------------------

function br_h( $s ) { return htmlspecialchars( (string)$s, ENT_QUOTES, 'UTF-8' ); }

function br_wiki_link( $title ) {
	return br_config()['wiki_base'] . '/index.php?title=' . rawurlencode( str_replace( ' ', '_', $title ) );
}

/** A bookmark item's target is a URL, not a wiki page. */
function br_target_link( array $item ) {
	$t = (string)( $item['target'] ?? '' );
	return preg_match( '#^https?://#i', $t ) ? $t : br_wiki_link( $t );
}

function br_log_commit( array $rec ) {
	$f = br_log_dir() . '/' . gmdate( 'Ymd-His' ) . '-' . $rec['batch'] . '.json';
	file_put_contents( $f, json_encode( $rec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	@chmod( $f, 0660 );
}
