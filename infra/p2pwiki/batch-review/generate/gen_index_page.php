<?php
/**
 * Batch generator: the curated Categories page.
 *
 * Tier 5 of the taxonomy audit. There is currently no index worth landing on —
 * Category:Categories, Category:Contents and Category:Browse all return
 * nothing, so a reader who wants to browse by topic gets Special:Categories:
 * 499 names in alphabetical order, "15M Movements" first, with no indication
 * that 38 of them are empty and 127 hold two articles or fewer.
 *
 * This builds one page listing the five facets and the sixteen subject
 * primaries with live counts, and proposes it as a single write-page item so a
 * human sees the whole thing as a diff before it exists.
 *
 *   php generate/gen_index_page.php --id categories-index
 *   php generate/gen_index_page.php --page "P2P Foundation:Categories" --exact
 *
 * --exact computes each primary's true article count by unioning its
 * categories' membership. It is correct and slow (a few hundred API calls);
 * without it the page shows per-category counts only, which are exact per line
 * and never summed, because summing them would double-count every article that
 * sits in two of a primary's categories.
 */

require_once __DIR__ . '/common.php';

$args   = g_args( $argv );
$id     = (string)( $args['id'] ?? 'categories-index-' . gmdate( 'Ymd' ) );
$page   = (string)( $args['page'] ?? 'P2P Foundation:Categories' );
$exact  = !empty( $args['exact'] );
$facets = require dirname( __DIR__ ) . '/data/facets.php';

$w  = "''A map of what this wiki files things under. Maintained by the batch-review tool from "
    . "[[Special:Categories]]; the counts are articles directly tagged with each category, read "
    . "from the wiki on " . gmdate( 'j F Y' ) . ".''\n\n";
$w .= "The wiki classifies along five independent facets. An article normally carries several "
    . "tags — one or more subjects, plus what kind of thing it is, plus where it is about. "
    . "They are not exclusive bins.\n\n";

// ---- the five facets ------------------------------------------------------
$w .= "== The five facets ==\n";
$w .= "{| class=\"wikitable\"\n! facet !! what it answers !! root category !! subcategories !! articles\n";
foreach ( $facets['roots'] as $facet => $r ) {
	$info = g_category_info( $r['category'] );
	g_say( sprintf( '  facet %-9s %-28s %5d subcats  %6d direct', $facet, $r['category'], $info['subcats'], $info['pages'] ) );
	$asks = [
		'Subject'  => 'what it is about',
		'Format'   => 'what kind of thing it is',
		'Entity'   => 'who or what it describes',
		'Place'    => 'where',
		'Language' => 'what it is written in',
	];
	$w .= "|-\n| '''" . $facet . "''' || " . ( $asks[$facet] ?? '' ) . " || [[:Category:"
		. $r['category'] . "]] || " . $info['subcats'] . " || " . $info['pages'] . "\n";
}
$w .= "|}\n\n";

// ---- the sixteen primaries ------------------------------------------------
$w .= "== Subjects ==\n";
$w .= "Sixteen groupings, each a union of categories that already exist. Nothing here is a new "
    . "category: they are a way of reading the subject facet, not a replacement for it.\n\n";

foreach ( $facets['primaries'] as $primary => $cats ) {
	$rows  = [];
	$union = [];
	foreach ( $cats as $cat ) {
		$info = g_category_info( $cat );
		if ( !$info['exists'] && $info['pages'] === 0 ) {
			g_say( "  skip (missing): Category:$cat" );
			continue;
		}
		$rows[$cat] = $info['pages'];
		if ( $exact ) {
			foreach ( g_category_members( $cat ) as $t => $_ ) { $union[$t] = true; }
		}
	}
	arsort( $rows );
	g_say( sprintf( '%-28s %2d categories%s', $primary, count( $rows ),
		$exact ? sprintf( ', %6d distinct articles', count( $union ) ) : '' ) );

	$w .= "=== " . $primary . " ===\n";
	$w .= "''" . count( $rows ) . " categories"
		. ( $exact ? ', ' . number_format( count( $union ) ) . ' distinct articles' : '' ) . "''\n\n";
	$bits = [];
	foreach ( $rows as $cat => $n ) {
		$bits[] = '[[:Category:' . $cat . '|' . $cat . ']] <small>(' . number_format( $n ) . ')</small>';
	}
	$w .= implode( ' · ', $bits ) . "\n\n";
}

// ---- the open questions ---------------------------------------------------
$w .= "== Not yet placed ==\n";
$w .= "These are open questions rather than omissions, and they are recorded here so they stay "
    . "visible.\n\n";
foreach ( $facets['proposed_primaries'] as $name => $cats ) {
	$n = 0;
	foreach ( $cats as $cat ) { $n += g_category_info( $cat )['pages']; }
	$w .= "* '''" . $name . "''' — proposed, not created. " . count( $cats ) . " categories, none of "
		. "which declares a parent. The material is substantial and the main page now leads with "
		. "the crypto and blockchain transformations, which is the argument for giving it a home of "
		. "its own rather than scattering it across Economy, Governance and Technology.\n";
}
foreach ( $facets['needs_judgement'] as $cat => $why ) {
	$info = g_category_info( $cat );
	$w .= "* [[:Category:" . $cat . "]] <small>(" . number_format( $info['pages'] ) . ")</small> — "
		. $why . ".\n";
}
$w .= "\n[[Category:P2P Foundation Wiki]]\n";

$items = [ [
	'target' => $page,
	'op'     => 'write-page',
	'what'   => 'the curated Categories index',
	'text'   => $w,
	'why'    => 'there is no curated index: Category:Categories, Category:Contents and '
		. 'Category:Browse all return nothing, so browsing by topic means reading 499 names in '
		. 'alphabetical order with no sign that 38 are empty and 127 hold two articles or fewer',
	'evidence' => [
		'facets'    => count( $facets['roots'] ),
		'primaries' => count( $facets['primaries'] ),
		'bytes'     => strlen( $w ),
		'counts'    => $exact ? 'exact unions' : 'per-category, never summed',
	],
] ];

g_write_batch(
	$id,
	'The curated Categories page',
	'page-write',
	'One page: the five facets and the sixteen subject primaries, with live counts, to replace '
	. 'Special:Categories as the place a reader lands. It is generated, so it can be regenerated '
	. 'when the counts move; the commit screen shows it as a line diff against whatever is on that '
	. 'title now. Built against the live wiki at ' . gmdate( 'Y-m-d H:i' ) . ' UTC.',
	$items
);
