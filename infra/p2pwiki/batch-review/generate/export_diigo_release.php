<?php
/**
 * Export the bookmarks that were actually released.
 *
 * This is the ONLY door between a Diigo bookmark and anything downstream — the
 * wiki link and article generators, the Portico RAG collection, the re-import
 * that flips `shared` on Diigo. It reads the decision ledger, never a batch
 * file, so a bookmark that was merely proposed, or approved-but-not-committed,
 * or left blank, cannot get out.
 *
 * A URL is exported if, and only if, the latest ledger line for it says
 * `public`. Absence is not consent.
 *
 *   php generate/export_diigo_release.php --out /tmp/released.jsonl
 *   php generate/export_diigo_release.php --out /tmp/released.jsonl \
 *        --source /tmp/mbauwens.jsonl        # emit the full bookmark records
 *   php generate/export_diigo_release.php --format txt --out /tmp/released.txt
 *   php generate/export_diigo_release.php --stats
 */

require_once __DIR__ . '/common.php';

$args   = g_args( $argv );
$out    = $args['out'] ?? null;
$format = (string)( $args['format'] ?? 'jsonl' );
$source = $args['source'] ?? null;

$all = br_diigo_decisions();
$public = $private = [];
foreach ( $all as $url => $rec ) {
	if ( ( $rec['visibility'] ?? '' ) === 'public' ) { $public[$url] = $rec; }
	else { $private[$url] = $rec; }
}

g_say( sprintf( 'ledger: %s', br_diigo_ledger_path() ) );
g_say( sprintf( '  %d decided · %d released (public) · %d held (private)',
	count( $all ), count( $public ), count( $private ) ) );

if ( !empty( $args['stats'] ) ) {
	$by = [];
	foreach ( $all as $r ) { $by[( $r['by'] ?? '?' )][$r['visibility'] ?? '?'] = ( $by[( $r['by'] ?? '?' )][$r['visibility'] ?? '?'] ?? 0 ) + 1; }
	foreach ( $by as $who => $counts ) {
		g_say( sprintf( '  %-16s %s', $who, json_encode( $counts ) ) );
	}
	exit( 0 );
}
if ( !$out ) {
	g_die( 'need --out <path> (or --stats)' );
}
if ( !$public ) {
	g_die( 'nothing has been released yet; refusing to write an empty export over anything' );
}

// With --source, emit the full bookmark record for each released URL, filtered
// from the archive. Without it, emit the ledger record, which is enough for a
// pipeline that only needs to know which URLs it may touch.
$rows = [];
if ( $source ) {
	if ( !is_readable( $source ) ) { g_die( "cannot read $source" ); }
	$seen = 0;
	$fh = fopen( $source, 'r' );
	while ( ( $line = fgets( $fh ) ) !== false ) {
		$j = json_decode( $line, true );
		if ( !is_array( $j ) || empty( $j['url'] ) ) { continue; }
		$seen++;
		if ( !isset( $public[$j['url']] ) ) { continue; }
		$j['released_by'] = $public[$j['url']]['by'] ?? '';
		$j['released_at'] = $public[$j['url']]['at'] ?? '';
		$rows[] = $j;
	}
	fclose( $fh );
	g_say( sprintf( '  matched %d of %d archive records against the released set', count( $rows ), $seen ) );
} else {
	$rows = array_values( $public );
}

$fh = fopen( $out, 'w' );
foreach ( $rows as $r ) {
	fwrite( $fh, $format === 'txt'
		? ( $r['url'] . "\n" )
		: ( json_encode( $r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" ) );
}
fclose( $fh );
@chmod( $out, 0640 );
g_say( sprintf( 'wrote %d released bookmarks -> %s', count( $rows ), $out ) );
g_say( 'held back: ' . count( $private ) . '. Nothing outside this file may be sent to a model, '
	. 'indexed, or published.' );
