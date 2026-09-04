<?php
/**
 * Batch generator: the Diigo public / private decision.
 *
 * Michel's Diigo archive is 118,728 bookmarks, of which a few thousand are
 * marked private. The public ones already feed the wiki work; the private ones
 * must not, and until somebody says so one at a time, none of them can.
 *
 * The old shape of this was a CSV: 2,629 rows emailed out, ticked in a
 * spreadsheet, re-imported. That round trip is why the integration has been on
 * hold since 27 May 2026 — one file in one inbox, no audit trail, and no way to
 * do half of it. This puts the same decision in the review queue instead:
 *
 *   approve = public   the URL becomes eligible for the link and article
 *                      generators that write into the wiki, and for the
 *                      re-import that flips `shared` on Diigo itself
 *   reject  = private  it stays out of the wiki, out of the LLM pipeline and
 *                      out of every export, permanently
 *   blank   = neither  it stays private. Doing nothing is always safe.
 *
 * The CSV's own posture — default TRUE, uncheck the exceptions — is preserved
 * as a *suggestion* per row, not as a decision. "Adopt all suggestions" in the
 * UI is one click, so nothing is slower; the difference is that a release is
 * always something a person chose, and the batch records who and when.
 *
 * Deciding writes one line to a ledger and nothing else. No bookmark reaches
 * any pipeline until somebody runs export_diigo_release.php, and that reads the
 * ledger rather than the batch, so an undecided row cannot be swept along.
 *
 *   php generate/gen_diigo_visibility.php --from /tmp/private_review.csv --limit 500
 *   php generate/gen_diigo_visibility.php --from /tmp/private_review.csv --offset 500
 *
 * The CSV lives in the p2pfoundation-wiki repo (`diigo/private_review.csv`,
 * gitignored) and is not in the container, so stage it first:
 *   docker cp diigo/private_review.csv p2pwiki:/tmp/private_review.csv
 */

require_once __DIR__ . '/common.php';

$args   = g_args( $argv );
$from   = $args['from'] ?? null;
$limit  = (int)( $args['limit'] ?? 500 );
$offset = (int)( $args['offset'] ?? 0 );
$redo   = !empty( $args['redo'] );
$id     = (string)( $args['id'] ?? 'diigo-visibility-' . gmdate( 'Ymd' )
	. ( $offset ? '-' . $offset : '' ) );
if ( !$from ) {
	g_die( 'need --from <private_review.csv>' );
}
if ( !is_readable( $from ) ) {
	g_die( "cannot read $from" );
}

$fh  = fopen( $from, 'r' );
$hdr = fgetcsv( $fh );
if ( !$hdr ) { g_die( 'empty csv' ); }
// The file carries a UTF-8 BOM; strip it or the first column name never matches.
$hdr = array_map( static function ( $h ) {
	return strtolower( trim( $h, " \t\n\r\0\x0B\xEF\xBB\xBF\"" ) );
}, $hdr );
foreach ( [ 'url' ] as $need ) {
	if ( !in_array( $need, $hdr, true ) ) {
		g_die( "csv has no '$need' column; found: " . implode( ', ', $hdr ) );
	}
}

$decided = br_diigo_decisions();
$items = [];
$row   = 0;
$skip  = [ 'decided' => 0, 'nourl' => 0 ];

while ( ( $r = fgetcsv( $fh ) ) !== false ) {
	$rec = array_combine( $hdr, array_pad( array_slice( $r, 0, count( $hdr ) ), count( $hdr ), '' ) );
	$url = trim( (string)( $rec['url'] ?? '' ) );
	if ( $url === '' || !preg_match( '#^https?://#i', $url ) ) { $skip['nourl']++; continue; }
	if ( !$redo && isset( $decided[$url] ) ) { $skip['decided']++; continue; }

	$row++;
	if ( $row <= $offset ) { continue; }
	if ( count( $items ) >= $limit ) { break; }

	$mk  = strtolower( trim( (string)( $rec['make_public'] ?? '' ) ) );
	$pub = in_array( $mk, [ 'true', 'yes', '1', 'x', 'public' ], true );

	$host = parse_url( $url, PHP_URL_HOST ) ?: '';
	// The description is Michel's own highlighted quote. A reviewer needs to see
	// enough of it to judge, and no more — it stays inside the container, behind
	// the reviewer gate, and never leaves in an export unless the bookmark is
	// released.
	$desc = trim( preg_replace( '/\s+/u', ' ', (string)( $rec['desc'] ?? '' ) ) );
	if ( mb_strlen( $desc ) > 240 ) { $desc = mb_substr( $desc, 0, 237 ) . '...'; }

	$items[] = [
		'target'  => $url,
		'op'      => 'classify-bookmark',
		'arg'     => $pub ? 'public' : 'private',
		'title'   => (string)( $rec['title'] ?? $url ),
		'suggest' => $pub ? 'approve' : 'reject',
		'why'     => $pub
			? 'nothing about this looks personal; the classifier suggests releasing it'
			: 'held back by the classifier: ' . ( $rec['reason'] ?: 'flagged for review' ),
		'evidence' => array_filter( [
			'domain'  => $host,
			'tags'    => (string)( $rec['tags'] ?? '' ),
			'saved'   => substr( (string)( $rec['created_at'] ?? '' ), 0, 10 ),
			'reason'  => (string)( $rec['reason'] ?? '' ),
			'quote'   => $desc,
		] ),
	];
}
fclose( $fh );

g_say( sprintf( 'proposing %d bookmarks (offset %d) · skipped %d already decided, %d without a url',
	count( $items ), $offset, $skip['decided'], $skip['nourl'] ) );
if ( !$items ) {
	g_say( 'nothing to propose.' );
	exit( 0 );
}
$pub = count( array_filter( $items, static function ( $i ) { return $i['suggest'] === 'approve'; } ) );

g_write_batch(
	$id,
	sprintf( 'Diigo: release or keep private, %d bookmarks', count( $items ) ),
	'bookmark-visibility',
	sprintf(
		'These are private bookmarks from the Diigo archive. Approving one makes it public: its URL '
		. 'becomes eligible for the link and article generators that write into the wiki, and for '
		. 'the re-import that flips `shared` on Diigo. Rejecting keeps it private and it is never '
		. 'sent anywhere. A row left blank stays private — doing nothing is always safe. Nothing '
		. 'here edits the wiki; deciding writes one line to a ledger, and a separate, deliberate '
		. 'export is what any pipeline reads. The classifier suggests releasing %d of these %d; '
		. '"Adopt all suggestions" applies its view in one click, and every row still records who '
		. 'chose it. Read from %s at %s UTC.',
		$pub, count( $items ), basename( $from ), gmdate( 'Y-m-d H:i' )
	),
	$items
);
