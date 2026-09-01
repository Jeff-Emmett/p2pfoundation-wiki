<?php
/**
 * Batch generator: wire sub-topic categories to their parent.
 *
 * Reads data/families.php, checks each declared child against the live wiki,
 * and proposes a [[Category:Parent]] line only where one is genuinely absent.
 * Re-running it after a commit produces a smaller batch, or none at all.
 *
 *   php generate/gen_category_parents.php --id cat-parents-01
 *   php generate/gen_category_parents.php --id commons-only --family Commons
 */

require_once __DIR__ . '/common.php';

$args   = g_args( $argv );
$id     = (string)( $args['id'] ?? 'cat-parents-' . gmdate( 'Ymd' ) );
$only   = $args['family'] ?? null;
$fams   = require dirname( __DIR__ ) . '/data/families.php';

$items = [];
$seen  = 0;

foreach ( $fams as $parent => $children ) {
	if ( $only !== null && $only !== $parent ) {
		continue;
	}
	$pInfo = g_category_info( $parent );
	if ( !$pInfo['exists'] ) {
		g_say( "skip: Category:$parent does not exist" );
		continue;
	}
	foreach ( $children as $child ) {
		$seen++;
		$cInfo = g_category_info( $child );
		if ( !$cInfo['exists'] ) {
			g_say( "skip: Category:$child does not exist" );
			continue;
		}
		$cats = g_page_categories( 'Category:' . $child );
		if ( isset( $cats[$parent] ) ) {
			continue; // already wired
		}
		$current = array_keys( $cats );
		$items[] = [
			'target' => 'Category:' . $child,
			'op'     => 'append-category',
			'arg'    => $parent,
			'why'    => sprintf(
				'%s holds %d articles and is not declared under %s',
				$child, $cInfo['pages'], $parent
			),
			'evidence' => [
				'child_pages'  => $cInfo['pages'],
				'parent_pages' => $pInfo['pages'],
				'current_parents' => $current ? implode( ', ', $current ) : 'none',
			],
		];
		g_say( sprintf( '  + Category:%-34s -> %s (%d articles)', $child, $parent, $cInfo['pages'] ) );
	}
}

if ( !$items ) {
	g_say( "nothing to do: all $seen declared edges are already present." );
	exit( 0 );
}

usort( $items, static function ( $a, $b ) {
	return $b['evidence']['child_pages'] <=> $a['evidence']['child_pages'];
} );

g_write_batch(
	$id,
	'Wire ' . count( $items ) . ' sub-topic categories to their parent'
		. ( $only ? ' (' . $only . ')' : '' ),
	'category-parent',
	'Each item adds a single [[Category:Parent]] line to a category page, so a sub-topic that '
	. 'currently hangs off nothing appears as a subcategory of the primary it belongs to. No '
	. 'article is touched. Checked against the live wiki at ' . gmdate( 'Y-m-d H:i' ) . ' UTC; '
	. 'edges that already existed were dropped rather than proposed.',
	$items
);
