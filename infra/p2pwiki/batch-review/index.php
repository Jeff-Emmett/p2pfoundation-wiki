<?php
/** Batch list. */
define( 'BR_ENTRY', 1 );
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/_chrome.php';

$user = br_require_reviewer();
$batches = br_list_batches();

br_head( 'Queue', $user );
?>
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
      <?php if ( $c['error'] ): ?><span class="pill p-error"><?= $c['error'] ?> failed</span><?php endif; ?></td>
  <td class="ev"><?= br_h( substr( (string)( $b['created'] ?? '' ), 0, 16 ) ) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<h2>What the three proposal kinds mean</h2>
<div class="scroll"><table>
<thead><tr><th>Kind</th><th>What an item does</th><th>Where it comes from</th></tr></thead>
<tbody>
<tr><td class="mono">category-parent</td>
    <td>Adds one <code>[[Category:Parent]]</code> line to a <em>category page</em>, so a
        sub-topic finally appears under its parent.</td>
    <td>The 82 missing parent edges found in the taxonomy audit.</td></tr>
<tr><td class="mono">article-category</td>
    <td>Adds one <code>[[Category:X]]</code> line to an <em>article</em> that belongs in a
        primary category but is not tagged with it.</td>
    <td>Roll-up gaps, format-only articles, and uncategorised articles.</td></tr>
<tr><td class="mono">category-merge</td>
    <td>Rewrites <code>[[Category:Typo]]</code> to <code>[[Category:Correct]]</code> on each
        member, so a duplicate category can be retired.</td>
    <td>The spelling-variant table in the audit.</td></tr>
<tr><td class="mono">synthesis</td>
    <td>Creates a page in <code>Draft:</code> from a set of existing articles, with a
        provenance box listing every source. Never touches the main namespace.</td>
    <td><code>generate/gen_synthesis.php</code>, run by hand with an explicit source list.</td></tr>
</tbody></table></div>
<?php br_foot();
