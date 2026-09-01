<?php
/**
 * Batch generator: suggest a subject category for articles that have none.
 *
 * Two populations, both from the taxonomy audit:
 *   --mode none    articles carrying no category at all (1,276 at audit time)
 *   --mode format  articles carrying only format/entity tags — Books, Webcasts,
 *                  Bios and the like — so they are invisible to any subject
 *                  browse (1,665 at audit time)
 *
 * Scoring is keyword-based on purpose: the item shows which words triggered the
 * suggestion, so a reviewer can dismiss a bad call in a glance instead of
 * taking a model's word for it. Only articles with a clear winner are proposed.
 *
 *   php generate/gen_uncategorised.php --mode none --limit 120
 *   php generate/gen_uncategorised.php --mode format --limit 200 --min-score 8
 */

require_once __DIR__ . '/common.php';

$args   = g_args( $argv );
$mode   = (string)( $args['mode'] ?? 'none' );
$limit  = (int)( $args['limit'] ?? 150 );
$minSc  = (int)( $args['min-score'] ?? 6 );
$margin = (float)( $args['margin'] ?? 1.5 );   // winner must beat runner-up by this factor
$id     = (string)( $args['id'] ?? 'uncat-' . $mode . '-' . gmdate( 'Ymd' ) );

$vocab = require dirname( __DIR__ ) . '/data/vocab.php';

// Categories that say what a page *is*, not what it is *about*.
$FORMAT = [
	'Articles', 'Books', 'Webcasts', 'Podcasts', 'Media', 'Interviews', 'Music', 'Graphics',
	'Maps', 'Fiction', 'Blog', 'Wiki', 'Publications', 'Films', 'Audiovisual',
	'Documentary films', 'Encyclopedia', 'Bios', 'Quotes', 'Courses', 'Conferences', 'Cases',
	'Companies', 'Organizations', 'Projects', 'Movements', 'Reference', 'Statistics',
	'Definitions', 'Publishers', 'Media Outlet', 'Intro', 'In a Nutshell', 'Introduction',
	'Bibliographie', 'Bibliography', 'Resources', 'Places', 'Country',
];
$FORMAT = array_flip( $FORMAT );

// ---- gather candidate titles --------------------------------------------
$candidates = [];
if ( $mode === 'none' ) {
	g_say( 'listing uncategorised pages ...' );
	$cont = [];
	do {
		$r = br_api( 'GET', array_merge( [
			'action'    => 'query',
			'list'      => 'querypage',
			'qppage'    => 'Uncategorizedpages',
			'qplimit'   => 500,
		], $cont ) );
		if ( !$r ) { g_die( 'API call failed' ); }
		foreach ( $r['query']['querypage']['results'] ?? [] as $row ) {
			$candidates[] = $row['title'];
		}
		$cont = $r['continue'] ?? [];
	} while ( $cont && count( $candidates ) < $limit * 6 );

} elseif ( $mode === 'format' ) {
	g_say( 'scanning format categories for articles with no subject ...' );
	$seen = [];
	foreach ( array_keys( $FORMAT ) as $fc ) {
		foreach ( array_keys( g_category_members( $fc ) ) as $t ) {
			$seen[$t] = true;
			if ( count( $seen ) >= $limit * 8 ) { break 2; }
		}
	}
	$candidates = array_keys( $seen );
} else {
	g_die( "--mode must be 'none' or 'format'" );
}

g_say( sprintf( '%d candidate articles; scoring ...', count( $candidates ) ) );

// ---- score ---------------------------------------------------------------
$items = [];
$looked = 0;
foreach ( $candidates as $title ) {
	if ( count( $items ) >= $limit ) { break; }
	$looked++;

	if ( $mode === 'format' ) {
		$cats = g_page_categories( $title );
		if ( !$cats ) { continue; }
		$subject = array_diff( array_keys( $cats ), array_keys( $FORMAT ) );
		if ( $subject ) { continue; }   // it already has a subject; not our problem
	}

	$pg = br_fetch_page( $title );
	if ( !$pg || !$pg['exists'] ) { continue; }
	$hay = ' ' . strtolower( strip_tags( $pg['text'] ) ) . ' ';
	$hayTitle = ' ' . strtolower( $title ) . ' ';

	$scores = [];
	$hits   = [];
	foreach ( $vocab as $cat => $tiers ) {
		$s = 0;
		$h = [];
		foreach ( $tiers as $weight => $terms ) {
			foreach ( $terms as $t ) {
				$q = preg_quote( strtolower( $t ), '/' );
				$n = preg_match_all( '/\b' . $q . '\b/u', $hay );
				if ( $n > 0 ) {
					// Diminishing returns: a word repeated 40 times is not 40x the signal.
					$s += (int)$weight * (int)min( 6, 1 + (int)floor( log( $n, 2 ) ) );
					$h[] = $t . '×' . $n;
				}
				// A term in the title is worth a lot.
				if ( preg_match( '/\b' . $q . '\b/u', $hayTitle ) ) {
					$s += (int)$weight * 4;
				}
			}
		}
		if ( $s > 0 ) { $scores[$cat] = $s; $hits[$cat] = $h; }
	}
	if ( !$scores ) { continue; }
	arsort( $scores );
	$best   = array_key_first( $scores );
	$bestSc = $scores[$best];
	$second = 0;
	$i = 0;
	foreach ( $scores as $k => $v ) { if ( $i++ === 1 ) { $second = $v; break; } }

	if ( $bestSc < $minSc ) { continue; }
	if ( $second > 0 && $bestSc < $second * $margin ) { continue; }  // too close to call

	$topHits = array_slice( $hits[$best], 0, 6 );
	$items[] = [
		'target' => $title,
		'op'     => 'append-category',
		'arg'    => $best,
		'why'    => ( $mode === 'none' ? 'has no category at all' : 'has only format tags' )
			. '; strongest subject match is ' . $best,
		'evidence' => [
			'score'     => $bestSc,
			'runner_up' => $second ? ( array_keys( $scores )[1] . ' (' . $second . ')' ) : 'none',
			'matched'   => implode( ', ', $topHits ),
		],
	];
	g_say( sprintf( '  %-56s -> %-14s %3d', mb_substr( $title, 0, 56 ), $best, $bestSc ) );
}

g_say( sprintf( 'looked at %d, proposing %d', $looked, count( $items ) ) );
if ( !$items ) { g_say( 'nothing confident enough to propose.' ); exit( 0 ); }

usort( $items, static function ( $a, $b ) {
	return $b['evidence']['score'] <=> $a['evidence']['score'];
} );

g_write_batch(
	$id,
	sprintf( 'Suggest a subject for %d %s articles', count( $items ),
		$mode === 'none' ? 'uncategorised' : 'format-only' ),
	'article-category',
	'These articles cannot be found by anyone browsing a topic: '
	. ( $mode === 'none'
		? 'they carry no category at all.'
		: 'they carry only format tags such as Books or Webcasts, which say what a page is rather than what it is about.' )
	. ' The suggested category is the best keyword match, and every item lists the words that '
	. 'triggered it and the runner-up, so a wrong call is obvious without opening the article. '
	. 'Only articles where one subject clearly won are proposed; ambiguous ones were dropped. '
	. 'Read against the live wiki at ' . gmdate( 'Y-m-d H:i' ) . ' UTC.',
	$items
);
