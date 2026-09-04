<?php
/**
 * The faceted scheme the wiki already has.
 *
 * The taxonomy audit of 1 September 2026 found that browsing Special:Categories
 * gives the impression of 499 flat labels, but the parent links tell a different
 * story: a deliberate multi-facet scheme sits underneath, with named roots for
 * subject, format, entity, place and language. Someone drew it around 2013,
 * wired up about half of it, and stopped.
 *
 * So this file is not a new taxonomy. It is the old one, written down, so the
 * generators can finish it. Everything here is checked against the live wiki
 * before anything is proposed.
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
	// The five facet roots, and the parent each one should declare.
	//
	// Four of the five are already attached. The fifth is the single cheapest
	// edit on the wiki: Category:P2P Entity Type declares no parent at all,
	// and that one missing line detaches a whole facet — Movements (3,745
	// articles, the second-largest category on the wiki) and Conferences (453)
	// are unreachable from the root because of it. Adding the line lifts root
	// reachability from 85.3% to 87.2% of all categorised articles.
	//
	// The generator checks each of these against the live wiki and proposes
	// only the ones genuinely missing, so running it when everything is
	// already wired produces an empty batch rather than five no-ops.
	// ---------------------------------------------------------------------
	'roots' => [
		'Subject' => [
			'category' => 'P2P Domains',
			'parent'   => 'P2P Foundation Knowledge Commons',
			'note'     => '20 subject categories, 24,459 articles',
		],
		'Format' => [
			'category' => 'P2P Multimedia Directory',
			'parent'   => 'P2P Foundation Knowledge Commons',
			'note'     => '15 format categories, 13,748 articles. The audit suggests renaming this '
			            . 'to Formats; a rename is a Tier 3 decision, not a parent link, so it is '
			            . 'not proposed here.',
		],
		'Entity' => [
			'category' => 'P2P Entity Type',
			'parent'   => 'P2P Foundation Knowledge Commons',
			'note'     => 'TIER 1. Parentless. Detaches Movements (3,745) and Conferences (453).',
		],
		'Place' => [
			'category' => 'World',
			'parent'   => 'Top Category',
			'note'     => '9 continents, 107 countries, 4,341 articles. Already well built.',
		],
		'Language' => [
			'category' => 'Languages',
			'parent'   => 'Top Category',
			'note'     => '17 language categories, 866 articles. Should absorb the French fork.',
		],
	],

	// ---------------------------------------------------------------------
	// The sixteen subject primaries.
	//
	// Read this carefully before writing a generator against it: these are NOT
	// categories to create. Every one is a union of categories that already
	// exist, and the audit's tiers deliberately stop short of minting sixteen
	// new category pages. They exist here for ONE purpose — the curated
	// Categories index page (Tier 5), which lists the five facets and the
	// sixteen primaries with their counts, replacing the 499-name alphabetical
	// list a reader gets from Special:Categories today.
	//
	// Together they cover 35,998 of 37,839 categorised articles — 95.1%.
	// A category appearing under two primaries is deliberate: MediaWiki allows
	// multiple parents, and forcing a single one is what produced the orphans.
	// ---------------------------------------------------------------------
	'primaries' => [
		'Governance & Politics' => [
			'Politics', 'Policy', 'Governance', 'Peergovernance', 'P2P State Approaches',
			'Global Governance', 'Democracy', 'Commons Policy', 'Identity Politics',
			'Mutual Coordination', 'Crypto Governance', 'Civil Society', 'Rights',
			'Public Services', 'Social Charters', 'Geopolitics', 'Network Nations',
			'Participation', 'Panarchy', 'Crypto Politics', 'Open Governance',
		],
		'Economy & Money' => [
			'Business', 'Money', 'Economics', 'Commons Economics', 'Collaborative Economy',
			'P2P Accounting', 'Cooperatives', 'Peerfunding', 'Sharing', 'Crypto Economy',
			'Business Models', 'P2P Market Approaches', 'Companies', 'Platform Cooperatives',
			'Post-Corporate', 'Post-Growth', 'Taxation', 'Circular Economy', 'Solidarity Economy',
			'Cooperative Commonwealth', 'Degrowth', 'Community Economics', 'Demonetization',
			'OpenCapital', 'Care Economy', 'Contributive Economy', 'Netarchical Capitalism',
			'Peereconomy', 'EthicalEconomy',
		],
		'P2P Theory' => [
			'Relational', 'P2P Theory', 'Peergovernance', 'P2P Hierarchy Theory', 'Facilitation',
			'P2P Class Theory', 'Integral Theory', 'Mutual Coordination', 'P2P Technology Theory',
			'Cooperation', 'P2P Cycles', 'Complexity', 'Network Theory', 'Cybernetics',
			'Patterns', 'Change Theory', 'P2P Ideologies', 'Synergy', 'Pattern Language',
		],
		'Knowledge & Research' => [
			'Research', 'Media', 'Education', 'Science', 'Intelligence', 'Courses',
			'Open Science', 'Statistics', 'Reference', 'P2P Education',
		],
		'Futures & Transition' => [
			'Movements', 'Civilizational Analysis', 'P2P History', '15M Movements', 'P2P Futures',
			'Post-Capitalism', 'Corona Solidarity Initiatives', 'OccupyWallStreet',
			'Commons Transition', 'Utopia', 'Transitions', 'RBE', 'P2P Transition',
		],
		'Technology & Infrastructure' => [
			'Technology', 'P2P Infrastructure', 'Standards', 'Cryptoledger Applications',
			'Security', 'Protocols and Algorithms', 'Free Software', 'Big Data',
			'Crypto Technology', 'AI', 'P2P Hardware', 'Software', 'Collaboration Software',
			'3D Printing', 'Autonomous Internet', 'NextNet', 'Application Layer',
		],
		'Commons' => [
			'Commons', 'Commons Economics', 'Peerproperty', 'Commons Policy', 'Urban Commons',
			'Commons Infrastructure', 'Data Commons', 'Global Commons', 'Commons Transition',
			'Catholic Commons', 'Credit Commons', 'Law and the Commons Project', 'User Owned',
			'Land Commons', 'Worker Owned', 'Community Owned', 'Knowledge Commons',
		],
		'Ecology & Energy' => [
			'Ecology', 'Agrifood', 'Energy', 'Thermodynamic Efficiencies', 'Regenerative Approaches',
			'Bioregional', 'Collapse', 'Circular Economy', 'Existential Risk', 'Crypto Ecology',
			'Degrowth', 'Resilience', 'Biofuel', 'Sustainability', 'Land Commons', 'Rural',
		],
		'Open' => [
			'Open', 'Sharing', 'Collaborative Economy', 'Peerproduction', 'Open Company Formats',
			'Free Software', 'Open Data', 'Open Science', 'Open Governance', 'Open Intelligence',
			'Open Technology Transfer', 'Open Cooperativism',
		],
		'Making & Design' => [
			'Manufacturing', 'Design', 'Sustainable Manufacturing', 'Transportation', 'Housing',
			'Cosmo-Local Production', '3D Printing',
			'Repositories and Collaboratories for Open Design',
		],
		'Law & IP' => [ 'Standards', 'IP', 'P2P Law', 'Licensing', 'Rights', 'Digital Justice' ],
		'Culture & Belief' => [
			'Spirituality', 'Culture', 'Art', 'Music', 'Gaming', 'Neotraditional', 'Evolution',
			'Cosmobiological', 'Fiction', 'Noosphere', 'Psychology', 'Crypto Culture',
		],
		'Place & Habitat' => [
			'Urbanism', 'Geography', 'Urban Commons', 'Places', 'Villages', 'Localization',
			'Travel', 'Third Places',
		],
		'Work & Labour' => [ 'Labor', 'P2P Solidarity', 'Guilds', 'Coworking', 'Third Places', 'Tiers-Lieux' ],
		'Society & Identity' => [
			'Health', 'Community', 'Gender', 'Race', 'P2P Women', 'Care Economy', 'Housing',
			'Diversity and Inclusion',
		],
		'P2P Foundation' => [
			'Michel Bauwens', 'Bauwens Reading Notes Project', 'WikiSprints outputs',
			'P2P Foundation', 'P2P WikiSprint', 'About the P2P Foundation', 'Publications',
			'P2P Lab', 'P2P Foundation Network', 'P2P Foundation Publishing', 'P2P Foundation Wiki',
		],
	],

	// A seventeenth, argued for in the audit and not yet decided. There is no
	// home for the crypto material and it is substantial — 1,593 distinct
	// articles, all parentless — and the main page now leads with "Monitoring
	// the Crypto and Blockchain-based transformations in our world". Listed
	// separately because minting a primary is an editorial decision, not a
	// mechanical one: the index generator shows it, no generator creates it.
	'proposed_primaries' => [
		'Crypto & Blockchain' => [
			'Cryptoledger Applications', 'Crypto Economy', 'Crypto Governance',
			'Crypto Technology', 'Crypto Politics', 'Crypto Ecology', 'Crypto Culture',
		],
	],

	// Large orphans the audit explicitly refused to place, because the right
	// parent is a judgement rather than an obvious fact. Named here so they
	// stay visible instead of quietly falling out of every generator's scope.
	'needs_judgement' => [
		'Encyclopedia'  => 'format/provenance, not subject — the endnotes of a 2005 essay',
		'Resources'     => 'format/provenance, not subject',
		'Relational'    => 'a subject with no natural parent above it; probably a primary in its own right',
		'Spirituality'  => 'a subject with no natural parent above it; probably a primary in its own right',
	],
];
