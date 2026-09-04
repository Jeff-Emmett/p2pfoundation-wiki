<?php
putenv('BR_DATA_DIR=' . sys_get_temp_dir() . '/br-test-data');
if ( PHP_SAPI !== 'cli' ) { http_response_code(403); exit; }
require_once dirname( __DIR__ ) . '/lib.php';

$fail = 0;
function ok($cond, $what) { global $fail; if ($cond) { echo "  ok   $what\n"; } else { echo "  FAIL $what\n"; $fail++; } }

echo "-- reviewer gate --\n";
ok( br_is_reviewer(['name'=>'JeffEmmett','id'=>2943]) === true,  'JeffEmmett/2943 in' );
ok( br_is_reviewer(['name'=>'jeffEmmett','id'=>2943]) === true,  'first char case-insensitive, as MediaWiki is' );
ok( br_is_reviewer(['name'=>'Mbauwens','id'=>9])      === true,  'Mbauwens/9 in' );
ok( br_is_reviewer(['name'=>'Mbauwens','id'=>2946])   === false, 'right name, wrong account id -> out' );
ok( br_is_reviewer(['name'=>'MBauwens bot','id'=>2946])===false, 'the bot account is not a reviewer' );
ok( br_is_reviewer(['name'=>'Jeffemmett','id'=>2943]) === false, 'case-sensitive after the first char' );
ok( br_is_reviewer(['name'=>'BryanX','id'=>3001])     === false, 'anyone else is out' );
ok( br_is_reviewer(['name'=>'JeffEmmett','id'=>0])    === false, 'no id at all -> out' );
ok( br_is_reviewer(['name'=>'Mbauwens_','id'=>9])     === true,  'trailing underscore normalises away, as MediaWiki does' );
ok( br_is_reviewer(['name'=>'Mbauwens x','id'=>9])   === false, 'a different name with the right id is still out' );
ok( br_is_reviewer(['name'=>'','id'=>9])              === false, 'empty name -> out' );

echo "-- categories --\n";
ok( br_has_category("x\n[[Category:Urban Commons]]", 'Urban Commons'), 'plain tag found' );
ok( br_has_category("[[Category:Urban_Commons|Foo]]", 'Urban Commons'), 'underscore + sortkey found' );
ok( br_has_category("[[Category:urban Commons]]", 'Urban Commons'), 'first char case folded' );
ok( !br_has_category("[[Category:Urban commons]]", 'Urban Commons'), 'later characters are NOT case folded' );
ok( !br_has_category("[[Category:Urban Commonsx]]", 'Urban Commons'), 'no partial match' );
ok( trim(br_append_category("body\n[[Category:A]]", 'B')) === "body\n[[Category:A]]\n[[Category:B]]", 'appends into an existing tag block' );
ok( br_replace_category("[[Category:Bioregion|z]]", 'Bioregion', 'Bioregional') === "[[Category:Bioregional|z]]", 'replace keeps the sortkey' );

echo "-- suggested links --\n";
$t = "The work of Kanishka Jayasuriya matters. Later, Kanishka Jayasuriya again.";
[$a,$okk,$why] = br_link_first_mention($t, 'Kanishka Jayasuriya');
ok( $okk && substr_count($a,'[[Kanishka Jayasuriya]]') === 1, 'links the FIRST occurrence only' );
ok( strpos($a, 'Later, Kanishka Jayasuriya again') !== false, 'second occurrence untouched' );
[$b2,$ok2,$why2] = br_link_first_mention($a, 'Kanishka Jayasuriya');
ok( !$ok2 && $b2 === $a, 'idempotent: a second pass is a no-op' );
ok( strpos($why2,'already links') !== false, 'and says why' );
[, $ok3,] = br_link_first_mention("== Fab City ==\nsome text", 'Fab City');
ok( !$ok3, 'never inside a heading' );
[, $ok4,] = br_link_first_mention("see [[Fab City]] there", 'Fab City');
ok( !$ok4, 'never when already linked' );
[, $ok5,] = br_link_first_mention("{{cite|Fab City}}", 'Fab City');
ok( !$ok5, 'never inside a template' );
[, $ok6,] = br_link_first_mention("[http://x.com/Fab City]", 'Fab City');
ok( !$ok6, 'never inside an external link' );
[, $ok7,] = br_link_first_mention("Fab Cityscape", 'Fab City');
ok( !$ok7, 'word-bounded: no match inside a longer word' );
[$c8,$ok8,] = br_link_first_mention("about Fab City here", 'fab city');
ok( !$ok8, 'exact case: lower-case prose is not a name' );

echo "-- grouping (guarantee 04) --\n";
$items = [
  ['n'=>1,'target'=>'Fab City','op'=>'append-category','arg'=>'Commons'],
  ['n'=>2,'target'=>'Other','op'=>'append-category','arg'=>'Commons'],
  ['n'=>3,'target'=>'Fab_City','op'=>'append-category','arg'=>'Money'],
  ['n'=>5,'target'=>'fab City','op'=>'append-category','arg'=>'Open'],
  ['n'=>4,'target'=>'https://x.com/a','op'=>'classify-bookmark'],
];
$g = br_group_items($items);
ok( count($g) === 3, 'three groups from five items' );
ok( count($g['page:Fab City']) === 3, 'underscore and first-char variants land in one edit' );
ok( !isset($g['page:Fab city']), 'but a genuinely different title stays its own page' );
ok( isset($g['bookmark:https://x.com/a']), 'a bookmark is never merged with an edit' );

echo "-- summaries (guarantee 05) --\n";
$s = br_group_summary(['id'=>'cat-parents-2026-09-01'], [
  ['n'=>3,'op'=>'append-category','arg'=>'Commons','decided_by'=>'Mbauwens'],
  ['n'=>7,'op'=>'append-category','arg'=>'Money','decided_by'=>'Mbauwens'],
], 'JeffEmmett');
ok( strpos($s,'#3,#7') !== false, 'summary carries the row ids' );
ok( strpos($s,'approved by Mbauwens') !== false, 'summary names the approver, not the committer' );
ok( strpos($s,'[batch-review cat-parents-2026-09-01') === 0, 'summary is greppable by batch' );
ok( mb_strlen($s) <= 480, 'summary fits inside MediaWiki\'s limit' );

echo "-- diff --\n";
$d = br_line_diff("a\nb\nc", "a\nB\nc");
ok( in_array('- b',$d,true) && in_array('+ B',$d,true), 'line diff marks the changed line' );
ok( br_line_diff("same","same") === [], 'no diff when nothing changed' );

echo "-- titles --\n";
ok( br_norm_title('category:urban commons') === 'category:Urban commons', 'namespaced title folds after the colon' );
ok( br_norm_title('fab_city') === 'Fab city', 'underscores are spaces' );

echo ($fail ? "\n$fail FAILURES\n" : "\nall passed\n");
exit($fail ? 1 : 0);
