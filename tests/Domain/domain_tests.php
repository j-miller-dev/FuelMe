<?php
declare(strict_types=1);
require __DIR__ . '/../Engine/bootstrap.php';
foreach (['Search\\QueryParser', 'Search\\Synonyms', 'Shopping\\ShoppingListBuilder'] as $c) {
    [$ns, $n] = explode('\\', $c);
    require_once __DIR__ . "/../../app/Domain/$ns/$n.php";
}
use App\Domain\Search\{QueryParser, Synonyms};
use App\Domain\Shopping\ShoppingListBuilder as B;

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? "ok   " : "FAIL ") . $name . "\n"; if (!$ok) $fail++; }

check('150g chicken breast', QueryParser::parse('150g chicken breast') == ['term' => 'chicken breast', 'qty' => 150.0, 'unit' => 'g']);
check('2 eggs', QueryParser::parse('2 eggs') == ['term' => 'eggs', 'qty' => 2.0, 'unit' => null]);
check('1 cup rice', QueryParser::parse('1 cup rice') == ['term' => 'rice', 'qty' => 1.0, 'unit' => 'cup']);
check('300 ml milk', QueryParser::parse('300 ml milk') == ['term' => 'milk', 'qty' => 300.0, 'unit' => 'ml']);
check('weetbix 2', QueryParser::parse('weetbix 2') == ['term' => 'weetbix', 'qty' => 2.0, 'unit' => null]);
check('plain', QueryParser::parse('jalna greek yoghurt')['qty'] === null);

$syn = Synonyms::fromFile(__DIR__ . '/../../data/synonyms.json');
check('capsicum->bell pepper', in_array('bell pepper', $syn->expand('capsicum'), true));
check('mince->ground beef', in_array('ground beef', $syn->expand('beef mince'), true) || in_array('beef ground beef', $syn->expand('beef mince'), true));
check('weetbix variants', in_array('weet-bix', $syn->expand('weetbix'), true));

$aisles = json_decode(file_get_contents(__DIR__ . '/../../data/aisles.json'), true);
$b = new B($aisles['categories'], $aisles['order']);
$chicken = ['id' => 'r1', 'name' => 'Chicken & rice', 'yield' => 4, 'ingredients' => [
    ['name' => 'Chicken breast', 'qty' => 800, 'unit' => 'g', 'category' => 'poultry'],
    ['name' => 'Onion', 'qty' => 1, 'unit' => 'count', 'category' => 'vegetable', 'unit_g' => 150],
    ['name' => 'Salt', 'qty' => null, 'category' => 'spice'],
]];
$stir = ['id' => 'r2', 'name' => 'Beef stir-fry', 'yield' => 2, 'ingredients' => [
    ['name' => 'Onions', 'qty' => 100, 'unit' => 'g', 'category' => 'vegetable'],
    ['name' => 'Salt', 'qty' => null, 'category' => 'spice'],
]];
$out = $b->build([['recipe' => $chicken, 'servings' => 6], ['recipe' => $stir, 'servings' => 2]], ['chicken breast' => 'bought'], ['salt']);
$by = array_column($out['items'], null, 'key');
check('scale 6/4: chicken 1200 g', $by['chicken breast']['qty'] == 1200.0);
check('onion count+g merged: 1.5*150+100=325 g', $by['onion']['qty'] == 325.0 && $by['onion']['unit'] === 'g');
check('staple listed once, not summed', $by['salt']['qty'] === null && $by['salt']['staple']);
check('state preserved on regeneration', $by['chicken breast']['status'] === 'bought');
check('always-have -> have', $by['salt']['status'] === 'have');
check('aisle grouping order', array_key_first($out['byAisle']) === 'Produce');
check('onion shows both recipes', count($by['onion']['recipes']) === 2);
check('pack rounding 640/500', B::roundToPacks(640, 500) === [2, 1000.0]);
echo $fail ? "FAILED $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
