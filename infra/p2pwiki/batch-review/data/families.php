<?php
/**
 * Which categories are sub-topics of which.
 *
 * This is the one editorial judgement in the whole system, so it lives in a
 * single reviewable file rather than being inferred. Everything downstream —
 * the missing parent edges, the roll-up gaps — is derived from it mechanically
 * and re-checked against the live wiki at generation time.
 *
 * Source: the taxonomy audit of 1 September 2026. Counts in the comments are
 * main-namespace, non-redirect articles at that date.
 *
 * A category may legitimately appear under two parents. MediaWiki allows it,
 * and forcing a single parent is what produced the orphans in the first place.
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

	'Commons' => [
		'Commons Economics',           // 937
		'Commons Policy',              // 646
		'Urban Commons',               // 439
		'Commons Infrastructure',      // 154
		'Data Commons',                // 108  — currently parentless
		'Global Commons',              // 106
		'Commons Transition',          // 92
		'Catholic Commons',            // 85   — currently parentless
		'Credit Commons',              // 53
		'Law and the Commons Project', // 46
		'Commons Abundance Network',   // 26
		'Land Commons',                // 17
		'Knowledge Commons',           // 8
	],

	'Governance' => [
		'Peergovernance',              // 1043
		'Global Governance',           // 667  — parentless
		'Democracy',                   // 603  — parentless
		'Mutual Coordination',         // 424  — parentless
		'Crypto Governance',           // 414  — parentless
		'Open Governance',             // 24   — parentless
		'Panarchy',                    // 7
	],

	'Economics' => [
		'Commons Economics',           // 937
		'Collaborative Economy',       // 919
		'Crypto Economy',              // 556  — parentless
		'P2P Market Approaches',       // 351  — parentless
		'Post-Growth',                 // 113  — parentless
		'Circular Economy',            // 76
		'Solidarity Economy',          // 71
		'Degrowth',                    // 54   — parentless
		'Peereconomy',                 // 45
		'Community Economics',         // 39
		'Demonetization',              // 29   — parentless
		'Care Economy',                // 27   — parentless
		'Contributive Economy',        // 26   — parentless
		'EthicalEconomy',              // 88
	],

	'P2P Theory' => [
		'P2P Hierarchy Theory',        // 724  — parentless
		'P2P Class Theory',            // 557  — parentless
		'Integral Theory',             // 518  — parentless
		'P2P Technology Theory',       // 368  — parentless
		'P2P Cycles',                  // 285  — parentless
		'Network Theory',              // 251  — parentless
		'Change Theory',               // 77   — parentless
		'P2P Ideologies',              // 30   — parentless
	],

	'Technology' => [
		'P2P Infrastructure',          // 1468
		'Cryptoledger Applications',   // 629  — parentless; the largest of the 1,593
		                               //        crypto articles the audit found homeless
		'Protocols and Algorithms',    // 423  — parentless
		'Free Software',               // 274  — parentless
		'Big Data',                    // 126  — parentless
		'Crypto Technology',           // 106  — parentless
		'AI',                          // 94   — parentless
		'P2P Hardware',                // 87   — parentless
		'Software',                    // 85
		'3D Printing',                 // 20   — parentless
		'Autonomous Internet',         // 15   — parentless
		'Social Technology',           // 6    — parentless
		'Mobile',                      // 6    — parentless
		'Collaboration Software',      // 12
	],

	'Open' => [
		'Open Company Formats',        // 340  — parentless
		'Free Software',               // 274  — parentless
		'Open Data',                   // 256  — parentless
		'Open Science',                // 107  — parentless
		'Open Governance',             // 24   — parentless
		'Open Intelligence',           // 24   — parentless
		'Open Technology Transfer',    // 17   — parentless
		'Open Cooperativism',          // 8    — parentless
	],

	'Manufacturing' => [
		'Design',                      // 1017
		'Sustainable Manufacturing',   // 389  — parentless
		'Cosmo-Local Production',      // 137  — parentless
		'P2P Hardware',                // 87   — parentless
		'3D Printing',                 // 20   — parentless
		'Open Hardware',               // 1    — parentless
		'Repositories and Collaboratories for Open Design', // 5
	],

	'Money' => [
		'P2P Accounting',              // 893  — parentless
		'Peerfunding',                 // 739  — parentless
		'Crypto Economy',              // 556  — parentless
		'Taxation',                    // 99
		'Credit Commons',              // 53
		'OpenCapital',                 // 29   — parentless
		'Demonetization',              // 29   — parentless
	],

	'Politics' => [
		'P2P State Approaches',        // 720  — parentless
		'Democracy',                   // 603  — parentless
		'Identity Politics',           // 579  — parentless
		'Civil Society',               // 205  — parentless
		'Rights',                      // 182  — parentless
		'Crypto Politics',             // 106  — parentless
		'Geopolitics',                 // 73   — parentless
	],

	'Cooperatives' => [
		'Platform Cooperatives',       // 180
		'Cooperative Commonwealth',    // 62   — parentless
		'User Owned',                  // 30
		'Worker Owned',                // 11   — parentless
		'Community Owned',             // 9    — parentless
		'Open Cooperativism',          // 8    — parentless
	],

	'Urbanism' => [
		'Urban Commons',               // 439
		'Housing',                     // 247  — parentless
		'Villages',                    // 130
		'Localization',                // 97   — parentless
		'Bioregional',                 // 90   — parentless
		'Third Places',                // 1    — parentless
	],

	'Ecology' => [
		'Thermodynamic Efficiencies',  // 698  — parentless
		'Regenerative Approaches',     // 148  — parentless
		'Collapse',                    // 81   — parentless
		'Circular Economy',            // 76
		'Existential Risk',            // 74   — parentless
		'Crypto Ecology',              // 59   — parentless
		'Degrowth',                    // 54   — parentless
		'Resilience',                  // 21   — parentless
		'Biofuel',                     // 17
	],
];
