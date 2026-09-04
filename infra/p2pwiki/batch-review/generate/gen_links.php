<?php
/**
 * Batch generator: put brackets around words the authors already wrote.
 *
 * The wiki holds 39,915 articles and only ~40,655 links between them, barely
 * one apiece, and 64% of articles link nowhere at all. Comparing articles by
 * what they are about surfaces 59,267 unlinked pairs that look related. That
 * is not a review queue, it is a denial of service on an editor's attention,
 * so nearly all the work is subtraction — and the rule that does most of it is
 * not about similarity at all:
 *
 *   A suggestion only counts if the target article's exact title already
 *   appears in the source article's prose, unlinked.
 *
 * The words are the author's. Approving one adds no claim, no sentence and no
 * assertion — it wraps text that is already on the page. Four characters.
 *
 *   php generate/gen_links.php --from /tmp/link-candidates.jsonl --limit 60
 *
 * Input: JSONL (one object per line) or CSV with a header row. Fields:
 *   source   the article to edit
 *   target   the title to link to
 *   sentence optional — the sentence it appears in, shown to the reviewer
 *   score    optional — carried through as evidence
 * Produced by demo/aiwiki/graph/scripts/ in the p2pfoundation-wiki repo.
 *
 * FILTERS, in the order they cost least to apply:
 *   --min-words 2   the target must be more than one word. Single common nouns
 *                   are what overlinking is made of: "…integrate the
 *                   information" is not a reference to [[Information]], and
 *                   "…personal development" is not [[Development]].
 *   exact case      the author must have written the phrase as a name.
 *   --max-per-page 3  no article is rewritten by one run.
 *   live check      the source is fetched and the mention re-located now.
 *                   Offsets recorded when candidates were generated are never
 *                   trusted.
 *
 * TYPED LINKS ARE DELIBERATELY NOT SUPPORTED. A predicate is an assertion, and
 * an assertion needs a different and higher standard of review than "this
 * phrase is already on the page". A candidate carrying a `predicate` field is
 * refused rather than quietly stripped. Typed links are the right second step,
 * on their own track, with their own approval.
 */

require_once __DIR__ . '/common.php';

$args    = g_args( $argv );
$from    = $args['from'] ?? null;
$id      = (string)( $args['id'] ?? 'links-' . gmdate( 'Ymd' ) );
$limit   = (int)( $args['limit'] ?? 60 );
$minWord = (int)( $args['min-words'] ?? 2 );
$perPage = (int)( $args['max-per-page'] ?? 3 );
if ( !$from ) {
	g_die( 'need --from <candidates.jsonl|.csv>' );
}
if ( !is_readable( $from ) ) {
	g_die( "cannot read $from" );
}

// ---- read candidates ------------------------------------------------------
$rows = [];
if ( preg_match( '/\.csv$/i', $from ) ) {
	$fh = fopen( $from, 'r' );
	$hdr = fgetcsv( $fh );
	if ( !$hdr ) { g_die( 'empty csv' ); }
	$hdr = array_map( static function ( $h ) { return strtolower( trim( $h, " \t\n\r\0\x0B\xEF\xBB\xBF\"" ) ); }, $hdr );
	while ( ( $r = fgetcsv( $fh ) ) !== false ) {
		$rows[] = array_combine( $hdr, array_pad( array_slice( $r, 0, count( $hdr ) ), count( $hdr ), '' ) );
	}
	fclose( $fh );
} else {
	foreach ( file( $from, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$j = json_decode( $line, true );
		if ( is_array( $j ) ) { $rows[] = $j; }
	}
}
g_say( sprintf( 'read %d candidates from %s', count( $rows ), $from ) );

// ---- cheap filters, before any API call -----------------------------------
$kept = [];
$drop = [ 'predicate' => 0, 'one_word' => 0, 'incomplete' => 0, 'self' => 0 ];
foreach ( $rows as $r ) {
	$src = trim( (string)( $r['source'] ?? $r['article'] ?? '' ) );
	$tgt = trim( (string)( $r['target'] ?? $r['link'] ?? '' ) );
	if ( $src === '' || $tgt === '' ) { $drop['incomplete']++; continue; }
	if ( !empty( $r['predicate'] ) ) { $drop['predicate']++; continue; }
	if ( br_norm_title( $src ) === br_norm_title( $tgt ) ) { $drop['self']++; continue; }
	if ( count( preg_split( '/\s+/u', $tgt ) ) < $minWord ) { $drop['one_word']++; continue; }
	$kept[] = [ 'source' => $src, 'target' => $tgt,
		'sentence' => (string)( $r['sentence'] ?? '' ), 'score' => $r['score'] ?? null ];
}
g_say( sprintf( 'after cheap filters: %d (dropped %d one-word, %d typed, %d self, %d incomplete)',
	count( $kept ), $drop['one_word'], $drop['predicate'], $drop['self'], $drop['incomplete'] ) );

// ---- live check, most expensive last --------------------------------------
$items  = [];
$byPage = [];
$pages  = [];        // cached wikitext, so several candidates on one article cost one fetch
$stats  = [ 'missing' => 0, 'linked' => 0, 'absent' => 0, 'capped' => 0, 'ok' => 0 ];

foreach ( $kept as $k ) {
	if ( count( $items ) >= $limit ) { break; }
	$src = $k['source'];
	if ( ( $byPage[$src] ?? 0 ) >= $perPage ) { $stats['capped']++; continue; }

	if ( !array_key_exists( $src, $pages ) ) {
		$pg = br_fetch_page( $src );
		$pages[$src] = ( $pg && $pg['exists'] ) ? $pg['text'] : null;
	}
	$text = $pages[$src];
	if ( $text === null ) { $stats['missing']++; continue; }

	if ( br_has_link( $text, $k['target'] ) ) { $stats['linked']++; continue; }

	// Does the target page exist? A suggestion that would create a red link is
	// a different proposal from one that connects two existing articles.
	$tp = br_fetch_page( $k['target'] );
	if ( !$tp || !$tp['exists'] ) { $stats['missing']++; continue; }

	[ $after, $ok, $why ] = br_link_first_mention( $text, $k['target'] );
	if ( !$ok ) { $stats['absent']++; continue; }

	// Apply it to the cached copy so a second candidate on the same article is
	// judged against the text as it will be, not as it was.
	$pages[$src] = $after;
	$byPage[$src] = ( $byPage[$src] ?? 0 ) + 1;
	$stats['ok']++;

	$sentence = $k['sentence'];
	if ( $sentence === '' ) {
		// Pull the sentence out of the article so the reviewer never has to open it.
		$pos = mb_strpos( $text, $k['target'] );
		if ( $pos !== false ) {
			$sentence = trim( preg_replace( '/\s+/u', ' ',
				mb_substr( $text, max( 0, $pos - 90 ), mb_strlen( $k['target'] ) + 180 ) ) );
		}
	}
	$items[] = [
		'target'   => $src,
		'op'       => 'link-mention',
		'arg'      => $k['target'],
		'sentence' => $sentence,
		'why'      => sprintf( '"%s" is already written in this article, unlinked, and a page of '
			. 'exactly that title exists', $k['target'] ),
		'evidence' => array_filter( [
			'words' => count( preg_split( '/\s+/u', $k['target'] ) ),
			'score' => $k['score'],
			'nth_on_page' => $byPage[$src],
		], static function ( $v ) { return $v !== null; } ),
	];
	g_say( sprintf( '  %-52s -> [[%s]]', mb_substr( $src, 0, 52 ), $k['target'] ) );
}

g_say( sprintf( 'proposing %d · skipped: %d already linked, %d phrase not found unlinked, '
	. '%d page missing, %d over the %d-per-article cap',
	count( $items ), $stats['linked'], $stats['absent'], $stats['missing'], $stats['capped'], $perPage ) );

if ( !$items ) {
	g_say( 'nothing to propose.' );
	exit( 0 );
}

g_write_batch(
	$id,
	sprintf( 'Link %d phrases the authors already wrote', count( $items ) ),
	'suggested-link',
	'Each item puts [[brackets]] around a phrase that is already in the article, where a page of '
	. 'exactly that title exists and the article does not link to it. Nothing is added, reordered, '
	. 'reworded or removed — four characters. The first occurrence only, and never if the article '
	. 'already links there, because linking every occurrence of a phrase is the overlinking this '
	. 'design exists to avoid. No article appears more than ' . $perPage . ' times. Links are '
	. 'untyped on purpose: a predicate is an assertion and needs its own, higher, standard of '
	. 'review. Re-checked against the live wiki at ' . gmdate( 'Y-m-d H:i' ) . ' UTC.',
	$items
);
