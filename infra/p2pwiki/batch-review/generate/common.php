<?php
/**
 * Shared helpers for the proposal generators.
 *
 * Generators are CLI-only and read-only against the wiki: they work out what
 * ought to change and write a batch file. Nothing here can edit the wiki —
 * that only ever happens when a human presses Commit in the web UI.
 */

define( 'BR_ENTRY', 1 );
require_once dirname( __DIR__ ) . '/lib.php';

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( "Generators are command-line only.\n" );
}

function g_say( $s ) { fwrite( STDERR, $s . "\n" ); }

function g_die( $s ) { fwrite( STDERR, "error: " . $s . "\n" ); exit( 1 ); }

/** Parse `--key value` and `--key=value` into an array. */
function g_args( array $argv ) {
	$out = [];
	for ( $i = 1; $i < count( $argv ); $i++ ) {
		$a = $argv[$i];
		if ( substr( $a, 0, 2 ) !== '--' ) { continue; }
		$a = substr( $a, 2 );
		if ( strpos( $a, '=' ) !== false ) {
			[ $k, $v ] = explode( '=', $a, 2 );
			$out[$k] = $v;
		} else {
			$next = $argv[$i + 1] ?? null;
			if ( $next !== null && substr( $next, 0, 2 ) !== '--' ) { $out[$a] = $next; $i++; }
			else { $out[$a] = true; }
		}
	}
	return $out;
}

/** Every page in a category, main namespace, non-redirect. Returns [title => true]. */
function g_category_members( $cat, $ns = 0 ) {
	$out  = [];
	$cont = [];
	do {
		$p = array_merge( [
			'action'  => 'query',
			'list'    => 'categorymembers',
			'cmtitle' => 'Category:' . str_replace( '_', ' ', $cat ),
			'cmlimit' => 500,
			'cmnamespace' => $ns,
			'cmprop'  => 'title',
		], $cont );
		$r = br_api( 'GET', $p );
		if ( !$r ) { g_die( 'API call failed for Category:' . $cat ); }
		foreach ( $r['query']['categorymembers'] ?? [] as $m ) {
			$out[$m['title']] = true;
		}
		$cont = $r['continue'] ?? [];
	} while ( $cont );
	return $out;
}

/** Categories a page currently declares, as a lookup [Name => true] without the prefix. */
function g_page_categories( $title ) {
	$out  = [];
	$cont = [];
	do {
		$p = array_merge( [
			'action'  => 'query',
			'prop'    => 'categories',
			'titles'  => $title,
			'cllimit' => 'max',
		], $cont );
		$r = br_api( 'GET', $p );
		if ( !$r ) { g_die( 'API call failed for ' . $title ); }
		foreach ( $r['query']['pages'][0]['categories'] ?? [] as $c ) {
			$out[substr( $c['title'], strlen( 'Category:' ) )] = true;
		}
		$cont = $r['continue'] ?? [];
	} while ( $cont );
	return $out;
}

/** How many main-namespace pages a category holds, plus whether its page exists. */
function g_category_info( $cat ) {
	$r = br_api( 'GET', [
		'action' => 'query',
		'prop'   => 'categoryinfo',
		'titles' => 'Category:' . str_replace( '_', ' ', $cat ),
	] );
	$pg = $r['query']['pages'][0] ?? [];
	return [
		'exists' => empty( $pg['missing'] ),
		'pages'  => (int)( $pg['categoryinfo']['pages'] ?? 0 ),
		'subcats'=> (int)( $pg['categoryinfo']['subcats'] ?? 0 ),
	];
}

/** Write a batch, refusing to clobber one that has already been committed. */
function g_write_batch( $id, $title, $kind, $rationale, array $items ) {
	if ( !br_valid_batch_id( $id ) ) { g_die( 'bad batch id: ' . $id ); }
	$existing = br_load_batch( $id );
	if ( $existing && ( $existing['status'] ?? 'open' ) !== 'open' ) {
		g_die( "batch '$id' has already been committed; choose a new id rather than overwriting the record." );
	}
	$n = 0;
	foreach ( $items as $i => $it ) {
		$items[$i]['n']          = ++$n;
		$items[$i]['decision']   = $it['decision']   ?? null;
		$items[$i]['decided_by'] = $it['decided_by'] ?? null;
		$items[$i]['decided_at'] = $it['decided_at'] ?? null;
		$items[$i]['result']     = $it['result']     ?? null;
	}
	$b = [
		'id'        => $id,
		'title'     => $title,
		'kind'      => $kind,
		'rationale' => $rationale,
		'created'   => gmdate( 'c' ),
		'created_by'=> 'generator:' . basename( $_SERVER['SCRIPT_NAME'] ?? 'unknown' ),
		'status'    => 'open',
		'items'     => array_values( $items ),
	];
	br_save_batch( $b );
	g_say( sprintf( "wrote batch '%s' — %d items -> %s", $id, count( $items ), br_batch_path( $id ) ) );
	return $b;
}
