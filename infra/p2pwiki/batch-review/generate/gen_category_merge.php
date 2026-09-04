<?php
/**
 * Batch generator: merge, rename and retire the tail.
 *
 * Tier 3 of the taxonomy audit. Each proposed item rewrites [[Category:from]]
 * to [[Category:to]] on one member page, so a duplicate or misnamed category
 * can be retired without any article losing its classification. Where a row
 * carries `also`, a second tag goes on in the same edit — that is how the
 * French fork folds into the Language facet instead of simply disappearing.
 *
 *   php generate/gen_category_merge.php --group spelling --id merge-typos
 *   php generate/gen_category_merge.php --group french
 *   php generate/gen_category_merge.php --group renames --max-members 100
 *
 * Groups: spelling (17 rows), french (9), renames (4), countries (17), all.
 *
 * THE SIZE GUARD. The audit's clearest negative finding is "don't merge the big
 * categories": across every pair holding 250 articles or more the highest
 * Jaccard similarity is 28.9%, so the forty categories that carry the wiki are
 * genuinely about different things and merging any pair would destroy a real
 * distinction. This script refuses any source category above --max-members
 * (default 250) unless --force is passed, so that finding is enforced rather
 * than remembered.
 */

require_once __DIR__ . '/common.php';

$args   = g_args( $argv );
$group  = (string)( $args['group'] ?? 'spelling' );
$id     = (string)( $args['id'] ?? 'merge-' . $group . '-' . gmdate( 'Ymd' ) );
$limit  = (int)( $args['limit'] ?? 400 );
$maxMem = (int)( $args['max-members'] ?? 250 );
$force  = !empty( $args['force'] );

$all = require dirname( __DIR__ ) . '/data/merges.php';
if ( $group === 'all' ) {
	$rows = [];
	foreach ( $all as $g => $rs ) {
		foreach ( $rs as $r ) { $r['group'] = $g; $rows[] = $r; }
	}
} elseif ( isset( $all[$group] ) ) {
	$rows = array_map( static function ( $r ) use ( $group ) { $r['group'] = $group; return $r; }, $all[$group] );
} else {
	g_die( "unknown group '$group'. Known: " . implode( ', ', array_keys( $all ) ) . ', all' );
}

$items = [];
foreach ( $rows as $r ) {
	$from = $r['from'];
	$to   = $r['to'];

	$fInfo = g_category_info( $from );
	if ( !$fInfo['exists'] && $fInfo['pages'] === 0 ) {
		g_say( sprintf( 'skip: Category:%s does not exist and holds nothing', $from ) );
		continue;
	}
	$tInfo = g_category_info( $to );
	if ( !$tInfo['exists'] ) {
		// Retiring a category into one that does not exist would strand its
		// members. Refuse rather than create the target silently.
		g_say( sprintf( 'skip: target Category:%s does not exist — create it first', $to ) );
		continue;
	}
	if ( $fInfo['pages'] > $maxMem && !$force ) {
		g_say( sprintf(
			'REFUSED: Category:%s holds %d articles, above the %d-article guard. The audit found no '
			. 'pair of large categories similar enough to merge; pass --force only if you have a '
			. 'reason this one is different.',
			$from, $fInfo['pages'], $maxMem
		) );
		continue;
	}

	$members = g_category_members( $from );
	if ( !$members ) {
		g_say( sprintf( 'note: Category:%s has no main-namespace members; the page itself still '
			. 'needs deleting or redirecting by hand', $from ) );
		continue;
	}
	g_say( sprintf( '  %-30s -> %-28s %4d members', $from, $to, count( $members ) ) );

	foreach ( array_keys( $members ) as $title ) {
		if ( count( $items ) >= $limit ) { break 2; }
		$items[] = [
			'target' => $title,
			'op'     => 'replace-category',
			'from'   => $from,
			'to'     => $to,
			'why'    => sprintf( '%s is a duplicate of %s (%d vs %d articles)%s',
				$from, $to, $fInfo['pages'], $tInfo['pages'],
				!empty( $r['why'] ) ? ' — ' . $r['why'] : '' ),
			'evidence' => [
				'group'        => $r['group'],
				'from_members' => $fInfo['pages'],
				'to_members'   => $tInfo['pages'],
			],
		];
		if ( !empty( $r['also'] ) ) {
			// Same page, so guarantee 04 folds this into the same single edit:
			// the member moves into the ordinary category AND gains the language
			// tag in one revision.
			$items[] = [
				'target' => $title,
				'op'     => 'append-category',
				'arg'    => $r['also'],
				'why'    => sprintf( 'language is a facet: tag it %s alongside %s rather than '
					. 'keeping a parallel French category tree', $r['also'], $to ),
				'evidence' => [ 'group' => $r['group'], 'paired_with' => $from . ' -> ' . $to ],
			];
		}
	}
}

if ( !$items ) {
	g_say( 'nothing to do.' );
	exit( 0 );
}

$titles = [];
foreach ( $items as $it ) { $titles[$it['target']] = true; }

g_write_batch(
	$id,
	sprintf( 'Retire %s: %d edits across %d pages', $group, count( $items ), count( $titles ) ),
	'category-merge',
	'Each item rewrites one category tag on one page so a duplicate or misnamed category can be '
	. 'retired. No page loses a classification: every member keeps a tag, on the surviving category. '
	. 'Two items on the same page are applied as a single edit. Large categories are refused by a '
	. 'size guard, because the audit found no pair of big categories similar enough to merge — the '
	. "wiki's problem is that its categories are not connected to each other, not that there are too "
	. 'many. Read against the live wiki at ' . gmdate( 'Y-m-d H:i' ) . ' UTC.',
	$items
);
