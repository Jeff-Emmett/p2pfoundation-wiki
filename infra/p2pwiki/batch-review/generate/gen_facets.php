<?php
/**
 * Batch generator: attach the facet roots.
 *
 * Tier 1 of the taxonomy audit, and the cheapest edit on the wiki.
 * Category:P2P Entity Type declares no parent at all, and that one missing
 * line detaches a whole facet: Movements (3,745 articles — the second-largest
 * category on the wiki) and Conferences (453) are unreachable from the root
 * because of it. Adding the line lifts root reachability from 85.3% to 87.2%.
 *
 * Reads data/facets.php, checks every root against the live wiki, and proposes
 * only the edges genuinely missing. Touches category pages only, never
 * articles. Run it when everything is wired and it produces nothing.
 *
 *   php generate/gen_facets.php --id facets-2026-09-04
 */

require_once __DIR__ . '/common.php';

$args   = g_args( $argv );
$id     = (string)( $args['id'] ?? 'facets-' . gmdate( 'Ymd' ) );
$facets = require dirname( __DIR__ ) . '/data/facets.php';

$items = [];
foreach ( $facets['roots'] as $facet => $r ) {
	$child  = $r['category'];
	$parent = $r['parent'];

	$cInfo = g_category_info( $child );
	if ( !$cInfo['exists'] ) {
		g_say( "skip: Category:$child does not exist" );
		continue;
	}
	$pInfo = g_category_info( $parent );
	if ( !$pInfo['exists'] ) {
		g_say( "skip: parent Category:$parent does not exist" );
		continue;
	}
	$cats = g_page_categories( 'Category:' . $child );
	if ( isset( $cats[$parent] ) ) {
		g_say( sprintf( '  ok: %-28s already under %s', $child, $parent ) );
		continue;
	}
	$items[] = [
		'target' => 'Category:' . $child,
		'op'     => 'append-category',
		'arg'    => $parent,
		'why'    => sprintf(
			'the %s facet root hangs off nothing; %d subcategories and %d directly tagged articles '
			. 'sit under it and are unreachable from Category:Top Category',
			$facet, $cInfo['subcats'], $cInfo['pages']
		),
		'evidence' => [
			'facet'           => $facet,
			'subcategories'   => $cInfo['subcats'],
			'direct_articles' => $cInfo['pages'],
			'current_parents' => $cats ? implode( ', ', array_keys( $cats ) ) : 'none',
			'note'            => $r['note'] ?? '',
		],
	];
	g_say( sprintf( '  + Category:%-28s -> %s', $child, $parent ) );
}

if ( !$items ) {
	g_say( 'nothing to do: every facet root already declares its parent.' );
	exit( 0 );
}

g_write_batch(
	$id,
	'Reattach ' . count( $items ) . ' facet root' . ( count( $items ) === 1 ? '' : 's' ),
	'category-facet',
	'The wiki already has a faceted category scheme — subject, format, entity, place and language. '
	. 'Someone drew it around 2013, wired up about half of it, and stopped. Each item here adds a '
	. 'single [[Category:Parent]] line to one facet root, so the facet rejoins the tree. No article '
	. 'is touched, and nothing is renamed or deleted. Checked against the live wiki at '
	. gmdate( 'Y-m-d H:i' ) . ' UTC.',
	$items
);
