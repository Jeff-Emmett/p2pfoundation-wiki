<?php
/**
 * Batch generator: put articles into the primary category their own sub-topic
 * tags already imply.
 *
 * An article tagged [[Category:Urban Commons]] and [[Category:Credit Commons]]
 * but not [[Category:Commons]] is invisible to anyone browsing the commons.
 * This proposes the missing membership, and shows which sub-topics vouched for
 * it so a reviewer can judge the item without opening the article.
 *
 *   php generate/gen_rollup.php --family Commons --id rollup-commons --limit 200
 *   php generate/gen_rollup.php --family Commons --min-evidence 2
 *
 * --min-evidence raises the bar: 2 means only propose articles carried by at
 * least two different sub-topics of that primary. Start there for a first pass.
 */

require_once __DIR__ . '/common.php';

$args   = g_args( $argv );
$family = $args['family'] ?? null;
if ( !$family ) {
	g_die( "need --family <primary category>; see data/families.php for the list" );
}
$id     = (string)( $args['id'] ?? 'rollup-' . strtolower( str_replace( ' ', '-', $family ) ) . '-' . gmdate( 'Ymd' ) );
$limit  = (int)( $args['limit'] ?? 200 );
$minEv  = max( 1, (int)( $args['min-evidence'] ?? 1 ) );

$fams = require dirname( __DIR__ ) . '/data/families.php';
if ( !isset( $fams[$family] ) ) {
	g_die( "unknown family '$family'. Known: " . implode( ', ', array_keys( $fams ) ) );
}

g_say( "reading Category:$family ..." );
$have = g_category_members( $family );
g_say( sprintf( '  %s currently holds %d articles', $family, count( $have ) ) );

// Collect every article carried by a sub-topic, remembering which ones vouched.
$vouched = [];
foreach ( $fams[$family] as $child ) {
	$m = g_category_members( $child );
	g_say( sprintf( '  %-38s %5d articles', $child, count( $m ) ) );
	foreach ( $m as $t => $_ ) {
		$vouched[$t][] = $child;
	}
}

$missing = [];
foreach ( $vouched as $title => $by ) {
	if ( isset( $have[$title] ) ) { continue; }
	if ( count( $by ) < $minEv )  { continue; }
	$missing[$title] = $by;
}

// Strongest evidence first: an article carried by four sub-topics is a safer
// call than one carried by a single small category.
uasort( $missing, static function ( $a, $b ) { return count( $b ) <=> count( $a ); } );

g_say( sprintf(
	"%d articles sit in a %s sub-topic but not in %s (min-evidence %d); proposing the first %d",
	count( $missing ), $family, $family, $minEv, min( $limit, count( $missing ) )
) );

$items = [];
foreach ( array_slice( $missing, 0, $limit, true ) as $title => $by ) {
	$items[] = [
		'target' => $title,
		'op'     => 'append-category',
		'arg'    => $family,
		'why'    => 'already in ' . count( $by ) . ' ' . $family . ' sub-topic'
			. ( count( $by ) === 1 ? '' : 's' ) . ': ' . implode( ', ', $by ),
		'evidence' => [
			'vouched_by' => count( $by ),
			'via'        => implode( ' · ', $by ),
		],
	];
}

if ( !$items ) {
	g_say( 'nothing to do.' );
	exit( 0 );
}

g_write_batch(
	$id,
	sprintf( 'Add %d articles to Category:%s', count( $items ), $family ),
	'article-category',
	sprintf(
		'Every article here is already tagged with at least %d sub-topic of %s but is not in %s '
		. 'itself, so it does not appear when a reader browses that category. Each item adds one '
		. '[[Category:%s]] line and changes nothing else. Ordered by how many sub-topics vouch '
		. 'for it, strongest first. Read against the live wiki at %s UTC.',
		$minEv, $family, $family, $family, gmdate( 'Y-m-d H:i' )
	),
	$items
);
