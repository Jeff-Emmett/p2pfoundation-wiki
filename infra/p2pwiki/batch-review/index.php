<?php
/** Batch list. */
define( 'BR_ENTRY', 1 );
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/_chrome.php';

$user    = br_require_reviewer();
$batches = br_list_batches();
$stop    = br_stop_state();
br_audit( 'open.queue', [ 'user' => $user['name'], 'id' => $user['id'] ] );

br_head( 'Queue', $user );
?>
<?php if ( $stop['halted'] ): ?>
<div class="banner live"><b>Everything is stopped.</b>
<a href="<?= br_h( br_wiki_link( $stop['page'] ) ) ?>"><code><?= br_h( $stop['page'] ) ?></code></a>
is not blank, so no batch can be committed by anyone until it is. Blank that page to resume.</div>
<?php endif; ?>

<h2>Queued batches</h2>
<p class="sub">Each batch is one kind of change with one rationale. Open a batch to decide item
by item, then commit. Nothing in a batch is applied until you press Commit, and only the items
you marked <em>approve</em> are touched.</p>

<?php if ( !$batches ): ?>
<p class="sub">Nothing queued. Generate a batch with one of the scripts in
<code>generate/</code> — for example
<code>php generate/gen_category_parents.php</code>.</p>
<?php else: ?>
<div class="scroll"><table>
<thead><tr>
  <th>Batch</th><th>Kind</th><th class="num">Items</th>
  <th class="num">Approved</th><th class="num">Rejected</th><th class="num">Left</th>
  <th>Status</th><th>Created</th>
</tr></thead>
<tbody>
<?php foreach ( $batches as $b ):
	$c = br_batch_counts( $b );
	$st = $b['status'] ?? 'open'; ?>
<tr>
  <td><a href="batch.php?id=<?= br_h( rawurlencode( $b['id'] ) ) ?>"><?= br_h( $b['title'] ?? $b['id'] ) ?></a>
      <div class="ev"><?= br_h( $b['id'] ) ?></div></td>
  <td class="mono"><?= br_h( $b['kind'] ?? '—' ) ?></td>
  <td class="num"><?= $c['total'] ?></td>
  <td class="num"><?= $c['approve'] ?></td>
  <td class="num"><?= $c['reject'] ?></td>
  <td class="num"><?= $c['undecided'] ?></td>
  <td><span class="pill p-<?= br_h( $st ) ?>"><?= br_h( $st ) ?></span>
      <?php if ( $c['applied'] ): ?><span class="ev"><?= $c['applied'] ?> applied</span><?php endif; ?>
      <?php if ( $c['error'] ): ?><span class="pill p-error"><?= $c['error'] ?> failed</span><?php endif; ?>
      <?php if ( !empty( $b['commit'] ) && empty( $b['commit']['done'] ) ): ?><span class="pill p-dry">part-way</span><?php endif; ?></td>
  <td class="ev"><?= br_h( substr( (string)( $b['created'] ?? '' ), 0, 16 ) ) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<h2>What the proposal kinds mean</h2>
<div class="scroll"><table>
<thead><tr><th>Kind</th><th>What an item does</th><th>Where it comes from</th></tr></thead>
<tbody>
<tr><td class="mono">category-parent</td>
    <td>Adds one <code>[[Category:Parent]]</code> line to a <em>category page</em>, so a
        sub-topic finally appears under its parent.</td>
    <td>The 82 missing parent edges found in the taxonomy audit — <code>gen_category_parents.php</code>.</td></tr>
<tr><td class="mono">category-facet</td>
    <td>The same edit, but on the five facet roots and the sixteen subject primaries: the half-built
        2013 scheme the audit found, finished rather than replaced. Includes the single cheapest edit
        on the wiki — giving <code>Category:P2P Entity Type</code> a parent, which reattaches
        Movements (3,745) and Conferences (453).</td>
    <td><code>gen_facets.php</code> over <code>data/facets.php</code>.</td></tr>
<tr><td class="mono">article-category</td>
    <td>Adds one <code>[[Category:X]]</code> line to an <em>article</em> that belongs in a
        primary category but is not tagged with it.</td>
    <td>Roll-up gaps, format-only articles, and uncategorised articles.</td></tr>
<tr><td class="mono">category-merge</td>
    <td>Rewrites <code>[[Category:Typo]]</code> to <code>[[Category:Correct]]</code> on each
        member, so a duplicate category can be retired.</td>
    <td>The spelling-variant and French-fork tables in the audit — <code>gen_category_merge.php</code>.</td></tr>
<tr><td class="mono">suggested-link</td>
    <td>Puts <code>[[brackets]]</code> around a phrase <em>the author already wrote</em>, where a page
        of that exact title exists and the article does not link to it. Adds no claim, no sentence,
        no assertion — four characters. First occurrence only, never if the article already links there.</td>
    <td><code>gen_links.php</code> over the candidate file from the graph pipeline.</td></tr>
<tr><td class="mono">page-write</td>
    <td>Writes one whole page — the curated Categories index, for instance — shown as a line diff
        against whatever is there now.</td>
    <td><code>gen_index_page.php</code>.</td></tr>
<tr><td class="mono">synthesis</td>
    <td>Creates a page in <code>Draft:</code> from a set of existing articles, with a
        provenance box listing every source. Never touches the main namespace.</td>
    <td><code>gen_synthesis.php</code>, run by hand with an explicit source list.</td></tr>
<tr><td class="mono">bookmark-visibility</td>
    <td><strong>Not a wiki edit.</strong> Decides whether one private Diigo bookmark may be released:
        <em>public</em> makes its URL eligible for the link and article generators, <em>private</em>
        keeps it out of the wiki and out of the LLM pipeline for good. Deciding writes one line to a
        ledger and nothing else.</td>
    <td><code>gen_diigo_visibility.php</code> over <code>diigo/private_review.csv</code>.</td></tr>
</tbody></table></div>
<?php br_foot();
