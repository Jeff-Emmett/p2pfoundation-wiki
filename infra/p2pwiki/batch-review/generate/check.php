<?php
/**
 * Audit the data files against the wiki they describe. Proposes nothing and
 * writes nothing; it prints a table and exits.
 *
 *   php generate/check.php --what data     # do the categories we name exist?
 *   php generate/check.php --what vocab    # do our keywords discriminate?
 *   php generate/check.php --what all
 *
 * WHY VOCAB IS AUDITED AT ALL
 *
 * The temptation with a keyword vocabulary is to choose it carefully in advance
 * — a small controlled set, picked from an ontology — and assume control is the
 * property that makes it work. This wiki is the counter-example. Michel's nine
 * reading-room lenses are exactly such a set, deliberately designed, and when
 * they were measured against the corpus, 41 of their 91 keywords matched no
 * category at all. "The Commons-Based Society" reaches zero pages: every one of
 * its keywords is dead, including `intellectual property`, which this wiki
 * files under `IP` (1,059 pages). Another lens reaches 20,430 because its terms
 * are as broad as `economics`. Three fail on a space alone — `peer production`
 * never matches the category `Peerproduction`, and with `Peergovernance` and
 * `Peerproperty` that is 2,549 pages the opening lens exists to hold and cannot
 * see.
 *
 * Every one of those failures is silent: unfiled is a legal state, an empty
 * lens throws no error, and an over-broad lens returns plenty of results.
 * Control was not the missing ingredient. Discrimination is — whether a term
 * separates the corpus — and it can only be measured against the corpus you
 * actually have. This is that measurement, run against our own vocabulary
 * rather than someone else's.
 */

require_once __DIR__ . '/common.php';

$args = g_args( $argv );
$what = (string)( $args['what'] ?? 'all' );

function chk_all_categories() {
	static $c = null;
	if ( $c !== null ) { return $c; }
	$c = [];
	$cont = [];
	do {
		$r = br_api( 'GET', array_merge( [
			'action'  => 'query',
			'list'    => 'allcategories',
			'aclimit' => 'max',
			'acprop'  => 'size',
		], $cont ) );
		if ( !$r ) { g_die( 'API call failed listing categories' ); }
		foreach ( $r['query']['allcategories'] ?? [] as $row ) {
			$c[$row['category']] = (int)( $row['pages'] ?? $row['size'] ?? 0 );
		}
		$cont = $r['continue'] ?? [];
	} while ( $cont );
	return $c;
}

function chk_data() {
	$cats = chk_all_categories();
	g_say( sprintf( "the wiki has %d categories in use\n", count( $cats ) ) );

	$named = [];   // category => [ where it is named ]
	$fams = require dirname( __DIR__ ) . '/data/families.php';
	foreach ( $fams as $parent => $children ) {
		$named[$parent][] = 'families:parent';
		foreach ( $children as $c ) { $named[$c][] = 'families:' . $parent; }
	}
	$facets = require dirname( __DIR__ ) . '/data/facets.php';
	foreach ( $facets['roots'] as $f => $r ) {
		$named[$r['category']][] = 'facets:root';
		$named[$r['parent']][]   = 'facets:root-parent';
	}
	foreach ( [ 'primaries', 'proposed_primaries' ] as $k ) {
		foreach ( $facets[$k] as $p => $list ) {
			foreach ( $list as $c ) { $named[$c][] = 'facets:' . $p; }
		}
	}
	foreach ( array_keys( $facets['needs_judgement'] ) as $c ) { $named[$c][] = 'facets:judgement'; }
	$merges = require dirname( __DIR__ ) . '/data/merges.php';
	foreach ( $merges as $g => $rows ) {
		foreach ( $rows as $r ) {
			$named[$r['from']][] = 'merges:' . $g . ':from';
			$named[$r['to']][]   = 'merges:' . $g . ':to';
			if ( !empty( $r['also'] ) ) { $named[$r['also']][] = 'merges:' . $g . ':also'; }
		}
	}

	$missing = $empty = 0;
	ksort( $named );
	foreach ( $named as $cat => $where ) {
		$n = $cats[$cat] ?? null;
		if ( $n === null ) {
			// A missing category is not fatal — the generators skip it — but it
			// is always either a typo or a decision nobody made, and both are
			// invisible without this line.
			g_say( sprintf( '  MISSING  %-46s named by %s', $cat, implode( ', ', array_unique( $where ) ) ) );
			$missing++;
		} elseif ( $n === 0 ) {
			g_say( sprintf( '  empty    %-46s named by %s', $cat, implode( ', ', array_unique( $where ) ) ) );
			$empty++;
		}
	}
	g_say( sprintf( "\n%d categories named across the data files · %d do not exist · %d exist but hold nothing",
		count( $named ), $missing, $empty ) );
	if ( $missing ) {
		g_say( 'A "from" that does not exist is harmless. A "to" or a parent that does not exist is '
			. 'a row that will never fire — fix the spelling or create the category.' );
	}
}

function chk_vocab() {
	$vocab = require dirname( __DIR__ ) . '/data/vocab.php';
	$cats  = chk_all_categories();
	$catsFlat = [];
	foreach ( $cats as $c => $n ) { $catsFlat[strtolower( str_replace( [ '_', ' ' ], '', $c ) )] = $c; }

	// One search per term for a corpus-wide count. srlimit=0 asks for the count
	// and no results, so this is cheap even for a couple of hundred terms.
	$total = null;
	$r = br_api( 'GET', [ 'action' => 'query', 'meta' => 'siteinfo', 'siprop' => 'statistics' ] );
	$total = (int)( $r['query']['statistics']['articles'] ?? 0 );
	g_say( sprintf( "corpus: %s articles\n", number_format( $total ) ) );

	$dead = $broad = $blind = 0;
	foreach ( $vocab as $subject => $tiers ) {
		g_say( $subject );
		foreach ( $tiers as $weight => $terms ) {
			foreach ( $terms as $t ) {
				$s = br_api( 'GET', [
					'action'      => 'query',
					'list'        => 'search',
					'srsearch'    => '"' . $t . '"',
					'srlimit'     => 0,
					'srinfo'      => 'totalhits',
					'srnamespace' => 0,
				] );
				$hits = (int)( $s['query']['searchinfo']['totalhits'] ?? 0 );
				$pct  = $total ? ( 100.0 * $hits / $total ) : 0;

				// Does a category exist whose name IS this term, ignoring spaces?
				// That is the `peer production` / `Peerproduction` failure, and it
				// is invisible any other way.
				$key = strtolower( str_replace( [ '_', ' ' ], '', $t ) );
				$cat = $catsFlat[$key] ?? null;
				$flag = '';
				if ( $hits === 0 )      { $flag = 'DEAD  — matches nothing in the corpus'; $dead++; }
				elseif ( $pct > 12 )    { $flag = 'BROAD — ' . round( $pct, 1 ) . '% of all articles; it does not separate anything'; $broad++; }
				if ( $cat !== null && $cat !== $t ) {
					$flag .= ( $flag ? ' · ' : '' ) . 'category exists as "' . $cat . '" — the spacing differs, so nothing matches it';
					$blind++;
				}
				g_say( sprintf( '  w%d %-34s %7s hits %5.1f%%  %s',
					$weight, $t, number_format( $hits ), $pct, $flag ) );
			}
		}
	}
	g_say( sprintf( "\n%d dead terms · %d over-broad terms · %d that miss an existing category on spacing alone",
		$dead, $broad, $blind ) );
	g_say( 'Dead and over-broad terms are the two ways a vocabulary fails silently. Neither shows up '
		. 'as an error anywhere else: unfiled is a legal state, an empty lens throws nothing, and an '
		. 'over-broad one returns plenty of results.' );
}

if ( $what === 'data' || $what === 'all' ) {
	g_say( "=== data files against the live wiki ===" );
	chk_data();
}
if ( $what === 'vocab' || $what === 'all' ) {
	g_say( "\n=== vocabulary discrimination ===" );
	chk_vocab();
}
