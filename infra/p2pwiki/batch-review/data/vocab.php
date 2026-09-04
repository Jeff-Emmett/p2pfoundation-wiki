<?php
/**
 * Vocabulary per primary subject, used to suggest a category for articles that
 * have none.
 *
 * Deliberately keyword-based rather than a model: a reviewer can see exactly
 * which words triggered a suggestion and overrule it in one glance. The
 * generator reports the matched terms as evidence on every item.
 *
 * Terms are matched case-insensitively on word boundaries. Weight 2 terms are
 * ones that on this wiki almost always mean the topic; weight 1 terms are
 * suggestive but appear across several subjects.
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
		2 => [ 'commons', 'commoning', 'commoner', 'commoners', 'common pool', 'commons-based', 'Ostrom' ],
		1 => [ 'stewardship', 'enclosure', 'shared resource', 'collective ownership', 'trust' ],
	],
	'Economics' => [
		2 => [ 'economy', 'economics', 'economic', 'market', 'capitalism', 'value chain', 'GDP' ],
		1 => [ 'exchange', 'production', 'consumption', 'firm', 'wage', 'profit', 'growth' ],
	],
	'Money' => [
		2 => [ 'currency', 'currencies', 'monetary', 'credit', 'banking', 'cryptocurrency', 'token economics', 'basic income' ],
		1 => [ 'money', 'finance', 'funding', 'investment', 'debt', 'accounting', 'payment' ],
	],
	'Governance' => [
		2 => [ 'governance', 'decision-making', 'sociocracy', 'holacracy', 'consensus', 'subsidiarity', 'polycentric' ],
		1 => [ 'coordination', 'stewards', 'assembly', 'council', 'bylaws', 'protocol' ],
	],
	'Politics' => [
		2 => [ 'politics', 'political', 'state', 'democracy', 'party', 'election', 'municipal', 'sovereignty' ],
		1 => [ 'citizen', 'public', 'policy', 'movement', 'activism', 'rights' ],
	],
	'Technology' => [
		2 => [ 'protocol', 'software', 'network', 'internet', 'peer-to-peer', 'distributed', 'blockchain', 'algorithm', 'platform' ],
		1 => [ 'technology', 'digital', 'infrastructure', 'server', 'data', 'open source' ],
	],
	'Open' => [
		2 => [ 'open source', 'free software', 'open access', 'open data', 'open licence', 'open license', 'copyleft', 'creative commons' ],
		1 => [ 'open', 'sharing', 'transparency', 'public domain' ],
	],
	'Ecology' => [
		2 => [ 'ecology', 'ecological', 'biodiversity', 'climate', 'regenerative', 'permaculture', 'watershed', 'carbon' ],
		1 => [ 'environment', 'sustainability', 'resource', 'energy', 'soil', 'water' ],
	],
	'Manufacturing' => [
		2 => [ 'manufacturing', 'fabrication', 'fab lab', '3d printing', 'open hardware', 'makerspace', 'CNC', 'distributed manufacturing' ],
		1 => [ 'production', 'design', 'machine', 'tooling', 'supply chain', 'prototype' ],
	],
	'Urbanism' => [
		2 => [ 'urban', 'city', 'cities', 'neighbourhood', 'neighborhood', 'housing', 'municipal', 'placemaking' ],
		1 => [ 'planning', 'community', 'land', 'district', 'transport' ],
	],
	'Education' => [
		2 => [ 'education', 'learning', 'pedagogy', 'curriculum', 'school', 'university', 'MOOC', 'teaching' ],
		1 => [ 'course', 'student', 'training', 'knowledge' ],
	],
	'Labor' => [
		2 => [ 'labour', 'labor', 'worker', 'workers', 'union', 'employment', 'precarity', 'gig economy' ],
		1 => [ 'work', 'job', 'wage', 'cooperative', 'guild' ],
	],
	'Cooperatives' => [
		2 => [ 'cooperative', 'co-operative', 'co-op', 'mutual', 'platform cooperative', 'worker-owned', 'member-owned' ],
		1 => [ 'membership', 'solidarity', 'association' ],
	],
	'P2P Theory' => [
		2 => [ 'peer production', 'peer-to-peer', 'P2P theory', 'commons-based peer production', 'network theory', 'complexity theory' ],
		1 => [ 'theory', 'framework', 'paradigm', 'model', 'dynamics', 'emergence' ],
	],
	'Culture' => [
		2 => [ 'culture', 'cultural', 'art', 'artist', 'music', 'literature', 'ritual', 'aesthetic' ],
		1 => [ 'practice', 'tradition', 'story', 'narrative' ],
	],
	'Spirituality' => [
		2 => [ 'spiritual', 'spirituality', 'contemplative', 'buddhist', 'sacred', 'mysticism', 'integral theory' ],
		1 => [ 'consciousness', 'meaning', 'wisdom', 'practice' ],
	],
	'Health' => [
		2 => [ 'health', 'healthcare', 'medicine', 'medical', 'patient', 'epidemic', 'pandemic', 'wellbeing' ],
		1 => [ 'care', 'clinic', 'disease', 'treatment' ],
	],
	'Agrifood' => [
		2 => [ 'agriculture', 'farming', 'farmer', 'food', 'agroecology', 'seed', 'harvest', 'CSA' ],
		1 => [ 'land', 'rural', 'crop', 'soil', 'garden' ],
	],
];
