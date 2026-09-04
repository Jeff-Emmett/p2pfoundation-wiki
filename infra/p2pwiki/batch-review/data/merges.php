<?php
/**
 * Tier 3 of the taxonomy audit: categories to merge, rename or retire.
 *
 * Each row rewrites [[Category:from]] to [[Category:to]] on every member, so a
 * duplicate or misnamed category can be retired without an article losing its
 * classification. `also` adds a second tag in the same edit — that is how the
 * French fork is folded into the Language facet rather than simply deleted.
 *
 * WHAT IS DELIBERATELY NOT HERE
 *
 * The big categories. The obvious instinct with 499 categories is to
 * consolidate the large ones, and the overlap data says don't: across every
 * pair holding 250 articles or more, the highest Jaccard similarity is 28.9%
 * (Crypto Economy against Cryptoledger Applications) and the highest one-way
 * containment is 49.9%. The forty categories that carry the wiki are genuinely
 * about different things. The generator enforces this with a size guard, not
 * just this comment.
 *
 * Counts are from 1 September 2026 and are re-checked live before anything is
 * proposed.
 */

// Not a web entry point. `.htaccess` cannot enforce this on wiki.p2pfoundation.net —
// block-bots.conf carries a site-wide `<Location "/"> Require all granted`, and a
// <Location> is merged after every <Directory> and .htaccess, so it overrides them.
// Guard in PHP instead, which no server config can undo.
if ( !defined( 'BR_ENTRY' ) && PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit;
}
return [

	// ---------------------------------------------------------------------
	// Same thing, spelled more than one way. Each of these affects 0-2
	// articles, so it is pure cleanup — the value is in Special:Categories
	// no longer showing a typo next to its correct spelling.
	// ---------------------------------------------------------------------
	'spelling' => [
		[ 'from' => 'Civilizatonal Analysis', 'to' => 'Civilizational Analysis' ],  // 1  -> 1,246
		[ 'from' => 'Standard',               'to' => 'Standards' ],                // 0  -> 1,264
		[ 'from' => 'Inteliigence',           'to' => 'Intelligence' ],             // 1  -> 1,192
		[ 'from' => 'C Cooperatives',         'to' => 'Cooperatives' ],             // 1  -> 850
		[ 'from' => 'Laws',                   'to' => 'P2P Law' ],                  // 1  -> 659
		[ 'from' => 'History',                'to' => 'P2P History' ],              // 1  -> 647
		[ 'from' => 'United Kingdom',         'to' => 'UK' ],                       // 0  -> 521
		[ 'from' => 'Integral',               'to' => 'Integral Theory' ],          // 1  -> 518
		[ 'from' => 'Cooperative Platforms',  'to' => 'Platform Cooperatives' ],    // 1  -> 180
		[ 'from' => 'Cooperative platforms',  'to' => 'Platform Cooperatives' ],    // 2  -> 180
		[ 'from' => 'Villagess',              'to' => 'Villages' ],                 // 1  -> 130
		[ 'from' => 'Bioregion',              'to' => 'Bioregional' ],              // 1  -> 90
		[ 'from' => 'Ethical Economy',        'to' => 'EthicalEconomy' ],           // 0  -> 88
		[ 'from' => 'Korea',                  'to' => 'South Korea' ],              // 0  -> 42
		[ 'from' => 'Transition',             'to' => 'Transitions' ],              // 1  -> 30
		[ 'from' => 'Ethics',                 'to' => 'P2P Ethics' ],               // 1  -> 18
		[ 'from' => 'Burma',                  'to' => 'Myanmar' ],                  // 1  -> 2
	],

	// ---------------------------------------------------------------------
	// The French fork. Nineteen categories duplicate the main scheme in French
	// rather than tagging alongside it: not one of the 29 pages in "Articles en
	// français" is in "Articles", not one of the 22 in "French-Books" is in
	// "Books". Language is a facet — it should be a tag added to the normal
	// categories, not a fork of them, which is what the other 16 languages
	// already do.
	//
	// So each row moves the page into the ordinary category AND adds the
	// language tag, in one edit.
	// ---------------------------------------------------------------------
	'french' => [
		[ 'from' => 'Articles en français',  'to' => 'Articles',   'also' => 'French' ],  // 29
		[ 'from' => 'Articles in French',    'to' => 'Articles',   'also' => 'French' ],  // 3
		[ 'from' => 'French-Books',          'to' => 'Books',      'also' => 'French' ],  // 22
		[ 'from' => 'French-Webcasts',       'to' => 'Webcasts',   'also' => 'French' ],  // 2
		[ 'from' => 'Mouvements français',   'to' => 'Movements',  'also' => 'French' ],  // 10
		[ 'from' => 'Français movements',    'to' => 'Movements',  'also' => 'French' ],  // 1
		[ 'from' => 'Individus français',    'to' => 'Bios',       'also' => 'French' ],  // 4
		[ 'from' => 'Bibliographie',         'to' => 'Bibliography', 'also' => 'French' ], // 58
		[ 'from' => 'Définitions',           'to' => 'Definitions', 'also' => 'French' ], // 39
	],

	// ---------------------------------------------------------------------
	// Names that claim to be general and are not.
	//
	// The P2P WikiSprint of 20 April 2013 left a cluster of categories with
	// identical or nested membership. Three of them carry general-purpose
	// names, so editors have been tagging into them ever since as if they meant
	// something. Renaming is the fix: the membership is real, the name is the
	// lie. A genuine Category:Organizations can be created afterwards, if one
	// is wanted.
	//
	// Run this group only after a reviewer has agreed the new names — a rename
	// is more visible to editors than a typo merge.
	//
	// NOTE, confirmed against the live wiki on 4 September 2026 by
	// `check.php --what data`: none of the three target categories exists yet,
	// so this group currently proposes nothing. The generator refuses to move
	// members into a category that is not there, because retiring a category
	// into a void would strand every page in it. Create the three category
	// pages first, then run this.
	// ---------------------------------------------------------------------
	'renames' => [
		[ 'from' => 'X',              'to' => 'Spanish',
		  'why'  => 'its category page reads, in full: "Spanish-language initiatives"' ],       // 52
		[ 'from' => 'Organizations',  'to' => 'WikiSprint 2013 organizations',
		  'why'  => '100% inside the April 2013 sprint; not a general directory' ],             // 63
		[ 'from' => 'Blog',           'to' => 'WikiSprint 2013 blogs',
		  'why'  => '100% inside the April 2013 sprint' ],                                       // 33
		[ 'from' => 'P2P Technology', 'to' => 'WikiSprint 2013 technology',
		  'why'  => 'declared a subcategory of Technology; is actually sprint residue' ],        // 8
	],

	// ---------------------------------------------------------------------
	// Single-article country categories, folded upward into their continent
	// until they earn their own page. Every one of these holds one article.
	//
	// The continent names are the ones the Place facet already uses; the
	// generator checks each target exists and skips the row if it does not,
	// so a wrong guess here costs nothing but a line of stderr.
	// ---------------------------------------------------------------------
	'countries' => [
		[ 'from' => 'Afghanistan',   'to' => 'Asia' ],
		[ 'from' => 'Bahrain',       'to' => 'Asia' ],
		[ 'from' => 'Cambodia',      'to' => 'Asia' ],
		[ 'from' => 'Laos',          'to' => 'Asia' ],
		[ 'from' => 'Qatar',         'to' => 'Asia' ],
		[ 'from' => 'Saudi Arabia',  'to' => 'Asia' ],
		[ 'from' => 'Tibet',         'to' => 'Asia' ],
		[ 'from' => 'Ladakh',        'to' => 'Asia' ],
		[ 'from' => 'Albania',       'to' => 'Europe' ],
		[ 'from' => 'Belarus',       'to' => 'Europe' ],
		[ 'from' => 'Moldova',       'to' => 'Europe' ],
		[ 'from' => 'Sierra Leone',  'to' => 'Africa' ],
		[ 'from' => 'Sudan',         'to' => 'Africa' ],
		[ 'from' => 'Swaziland',     'to' => 'Africa' ],
		[ 'from' => 'Togo',          'to' => 'Africa' ],
		[ 'from' => 'Belize',        'to' => 'Central America' ],
		[ 'from' => 'Suriname',      'to' => 'South America' ],
	],
];
