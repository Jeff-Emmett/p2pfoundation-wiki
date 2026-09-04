<?php
/**
 * Batch generator: draft a synthesis article from existing wiki material.
 *
 * The model only ever sees text that is already on this wiki, and the draft it
 * returns is stored in the batch for a human to read. Approving one creates a
 * page in Draft: — never the main namespace — carrying a provenance box that
 * names every source article. Moving a draft into the encyclopedia stays a
 * deliberate human act with a normal page history.
 *
 *   php generate/gen_synthesis.php \
 *       --title "Cosmo-Local Production" \
 *       --sources "Cosmo-Localism|Design Global, Manufacture Local|Fab City"
 *
 *   php generate/gen_synthesis.php \
 *       --title "Credit Commons" --from-category "Credit Commons" --max-sources 12
 *
 * Needs an OpenAI-compatible endpoint:
 *   BR_LLM_BASE   e.g. http://litellm:4000/v1     (reachable from this container)
 *   BR_LLM_KEY    virtual key for that endpoint
 *   BR_LLM_MODEL  e.g. gx10-heavy
 *
 * --timeout <seconds> caps the model call; default 900.
 *
 * Pass --dry to assemble the prompt and print what would be sent without
 * calling the model at all.
 */

require_once __DIR__ . '/common.php';

$args    = g_args( $argv );
$title   = $args['title'] ?? null;
if ( !$title ) { g_die( 'need --title "Article Title"' ); }

$maxSrc  = (int)( $args['max-sources'] ?? 14 );
$perSrc  = (int)( $args['chars-per-source'] ?? 2500 );
$id      = (string)( $args['id'] ?? 'synth-' . strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $title ) ) . '-' . gmdate( 'Ymd' ) );
$dry     = !empty( $args['dry'] );

// ---- pick the sources ----------------------------------------------------
$sources = [];
if ( !empty( $args['sources'] ) ) {
	foreach ( explode( '|', (string)$args['sources'] ) as $s ) {
		$s = trim( $s );
		if ( $s !== '' ) { $sources[] = $s; }
	}
} elseif ( !empty( $args['from-category'] ) ) {
	$m = g_category_members( (string)$args['from-category'] );
	$sources = array_slice( array_keys( $m ), 0, $maxSrc );
} else {
	g_die( 'need --sources "A|B|C" or --from-category "Some Category"' );
}
$sources = array_slice( $sources, 0, $maxSrc );
if ( count( $sources ) < 2 ) { g_die( 'a synthesis needs at least two sources' ); }

$draftTitle = br_config()['draft_prefix'] . $title;
$exists = br_fetch_page( $draftTitle );
if ( $exists && $exists['exists'] ) {
	g_die( "$draftTitle already exists; choose another title or delete the old draft first." );
}

// ---- gather the material -------------------------------------------------
g_say( 'reading ' . count( $sources ) . ' source articles ...' );
$corpus = [];
foreach ( $sources as $s ) {
	$pg = br_fetch_page( $s );
	if ( !$pg || !$pg['exists'] ) {
		g_say( "  skip (missing): $s" );
		continue;
	}
	$txt = $pg['text'];
	// Strip the noisiest markup so the budget goes on prose.
	$txt = preg_replace( '/\[\[\s*[Cc]ategory\s*:[^\]]*\]\]/u', '', $txt );
	$txt = preg_replace( '/\[\[\s*[Ff]ile\s*:[^\]]*\]\]/u', '', $txt );
	$txt = preg_replace( '/<ref[^>]*>.*?<\/ref>/su', '', $txt );
	$txt = trim( preg_replace( '/\n{3,}/', "\n\n", $txt ) );
	if ( $txt === '' ) { continue; }
	$corpus[$s] = mb_substr( $txt, 0, $perSrc );
	g_say( sprintf( '  %-52s %6d chars', mb_substr( $s, 0, 52 ), mb_strlen( $corpus[$s] ) ) );
}
if ( count( $corpus ) < 2 ) { g_die( 'fewer than two usable sources' ); }

$prompt = "You are drafting an article for the P2P Foundation wiki, an encyclopedia of "
	. "peer-to-peer, commons and post-capitalist practice.\n\n"
	. "Write a synthesis titled \"$title\" using ONLY the source articles below, all of which "
	. "are already on this wiki. Rules:\n"
	. "- Every claim must be supported by the sources. Do not add outside knowledge, and do not "
	. "invent names, dates, figures or citations.\n"
	. "- Where the sources disagree, say so rather than picking one.\n"
	. "- MediaWiki markup. Start with a one-paragraph definition, then == sections ==.\n"
	. "- Link to the source articles inline with [[double brackets]] where they are named.\n"
	. "- 600-1100 words. No category tags; those are added separately.\n"
	. "- If the sources do not support a real article on this topic, reply with exactly: "
	. "INSUFFICIENT SOURCES\n\n";
foreach ( $corpus as $s => $t ) {
	$prompt .= "=== SOURCE: $s ===\n$t\n\n";
}

if ( $dry ) {
	g_say( sprintf( 'dry: prompt is %d chars over %d sources; not calling the model.', strlen( $prompt ), count( $corpus ) ) );
	echo $prompt;
	exit( 0 );
}

// ---- call the model ------------------------------------------------------
$base  = rtrim( (string)getenv( 'BR_LLM_BASE' ), '/' );
$key   = (string)getenv( 'BR_LLM_KEY' );
$model = (string)getenv( 'BR_LLM_MODEL' );
if ( $base === '' || $model === '' ) {
	g_die( 'set BR_LLM_BASE and BR_LLM_MODEL (and BR_LLM_KEY if the endpoint needs one)' );
}

g_say( "asking $model ..." );
$payload = json_encode( [
	'model'       => $model,
	'temperature' => 0.2,
	'messages'    => [ [ 'role' => 'user', 'content' => $prompt ] ],
] );
$headers = [ 'Content-Type: application/json' ];
if ( $key !== '' ) { $headers[] = 'Authorization: Bearer ' . $key; }

// A local model writing a thousand words runs for minutes, and this is a CLI
// script with no HTTP client waiting on it, so give it real room.
$timeout = (int)( $args['timeout'] ?? 900 );
$t0  = microtime( true );
$raw = br_http( 'POST', $base . '/chat/completions', $headers, $payload, $timeout );
g_say( sprintf( '  ... %.1fs', microtime( true ) - $t0 ) );
if ( $raw === null ) { g_die( 'no response from ' . $base . ' within ' . $timeout . 's' ); }
$j = json_decode( $raw, true );
$text = $j['choices'][0]['message']['content'] ?? null;
if ( !$text ) {
	g_die( 'model returned no text: ' . mb_substr( $raw, 0, 400 ) );
}
$text = trim( $text );
if ( stripos( $text, 'INSUFFICIENT SOURCES' ) === 0 ) {
	g_die( 'the model judged the sources insufficient for this topic. Pick different sources.' );
}

// ---- assemble the page with its provenance -------------------------------
// The banner is written inline rather than as {{Synthesised draft}} on purpose:
// a template call for a page that does not exist renders as a red link, which
// would turn the one visible safety warning into a broken link. Inline wikitext
// always renders, on any wiki, with nothing to install first.
$prov  = "<div style=\"border:1px solid #c8a34a;background:#fdf6e3;padding:0.7em 1em;margin:0 0 1em\">\n"
	. "'''Machine-assembled draft — not reviewed.''' This page was written on "
	. gmdate( 'j F Y' ) . " from " . count( $corpus ) . " articles that are already on this wiki, "
	. "using the model " . $model . ", and from no other material. Nothing in it has been "
	. "checked for accuracy. Every source is listed at the foot of the page. Do not move it "
	. "into the main namespace until a human has verified it.\n"
	. "</div>\n"
	. "<!-- Generated " . gmdate( 'Y-m-d' ) . " by batch-review from " . count( $corpus )
	. " existing wiki articles, using model " . $model . ". Not yet reviewed for accuracy. -->\n\n";

$foot = "\n\n== Source articles ==\n"
	. "''This draft was synthesised from the following pages on this wiki, and from nothing else.''\n";
foreach ( array_keys( $corpus ) as $s ) {
	$foot .= '* [[' . $s . "]]\n";
}

$page = $prov . $text . $foot;

$items = [ [
	'target'  => $draftTitle,
	'op'      => 'create-draft',
	'text'    => $page,
	'sources' => array_keys( $corpus ),
	'why'     => 'synthesis of ' . count( $corpus ) . ' existing articles',
	'evidence' => [
		'model'   => $model,
		'sources' => count( $corpus ),
		'bytes'   => strlen( $page ),
	],
] ];

g_write_batch(
	$id,
	'Synthesised draft: ' . $title,
	'synthesis',
	'One proposed page in the Draft namespace, written from ' . count( $corpus ) . ' articles that '
	. 'are already on this wiki and from no other material. Read it before approving — the model '
	. 'was told not to add outside knowledge, but that is an instruction, not a guarantee. '
	. 'Approving creates ' . $draftTitle . ' only; nothing in the main namespace changes, and '
	. 'moving it into the encyclopedia afterwards is a separate human decision.',
	$items
);

g_say( '' );
g_say( 'Read the draft before approving:' );
g_say( '  ' . br_batch_path( $id ) );
