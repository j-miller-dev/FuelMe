<?php

use App\Domain\Foods\AfcdImporter;
use App\Domain\Foods\OffImporter;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/** Writes a tiny AFCD-shaped workbook set (same sheet names and header row as the real downloads). */
function fakeAfcdDir(): string
{
    $dir = sys_get_temp_dir() . '/afcd_' . bin2hex(random_bytes(4));
    mkdir($dir);

    $book = function (string $file, array $sheets) use ($dir) {
        $w = new Writer();
        $w->openToFile("$dir/$file");
        $first = true;
        foreach ($sheets as $name => $rows) {
            $first ? $w->getCurrentSheet()->setName($name) : $w->addNewSheetAndMakeItCurrent()->setName($name);
            $first = false;
            foreach ($rows as $r) {
                $w->addRow(Row::fromValues($r));
            }
        }
        $w->close();
    };

    $book('Food-Details.xlsx', ['Food details' => [
        ['Release 3 - Food details'], [''],
        ['Public Food Key', 'Classification', 'Derivation', 'Food Name', 'Food Description', 'Sampling Details', 'Nitrogen Factor', 'Fat Factor', 'Specific Gravity'],
        ['F1', '18301', 'Analysed', 'Chicken, breast, lean flesh, raw', '', '', '', '', 0],
        ['F2', '19104', 'Borrowed', 'Milk, cow, fluid, regular fat (~3.5%)', '', '', '', '', 1.03],
        ['F3', '24705', 'Imputed', 'Capsicum, red, fresh, raw', '', '', '', '', 0],
    ]]);

    $hdr = ['Public Food Key', 'Classification', 'Derivation', 'Food Name', "Energy with dietary fibre, equated \n(kJ)", 'Protein  (g)', 'Fat, total (g)',
        'Total dietary fibre (g)', 'Total sugars (g)', 'Available carbohydrate, with sugar alcohols (g)', 'Sodium (Na) (mg)', 'Iron (Fe) (mg)'];
    $book('Nutrient-profiles.xlsx', [
        'Contents' => [['Content summary']],
        'All solids & liquids per 100 g' => [['Release 3'], [''], $hdr,
            ['F1', '18301', 'Analysed', 'Chicken, breast, lean flesh, raw', 412, 22.5, 0.8, 0, 0, 0, 45, 0.4],
            ['F2', '19104', 'Borrowed', 'Milk, cow, fluid, regular fat (~3.5%)', 278, 3.3, 3.4, 0, 5.4, 5.4, 40, ''],   // liquid: must come from the mL sheet only
            ['F3', '24705', 'Imputed', 'Capsicum, red, fresh, raw', 115, 1, 0.3, 2, 4, 4.5, 3, 0.4],
            ['F999', '', '', 'Not in food details', 1, 1, 1, 1, 1, 1, 1, 1]],
        'Liquids only per 100 mL' => [['Release 3'], [''], $hdr,
            ['F2', '19104', 'Borrowed', 'Milk, cow, fluid, regular fat (~3.5%)', 278, 3.4, 3.5, 0, 5.5, 5.5, 41, '']],
    ]);

    $book('Food-group-information.xlsx', ['Food group information' => [
        ['Release 3 - Food group information'], [''],
        ['Food group ID', 'Food group name', 'Inclusions'],
        [18, 'Meat, poultry and game products and dishes', ''], [19, 'Milk products and dishes', ''], [24, 'Vegetable products and dishes', ''],
    ]]);

    return $dir;
}

it('imports AFCD: kcal from kJ, liquids per 100 mL, categories, long-tail nutrients, idempotent', function () {
    $dir = fakeAfcdDir();
    $r = (new AfcdImporter())->import($dir);

    expect($r['foods'])->toBe(3)->and($r['liquids'])->toBe(1);
    expect(DB::table('foods')->count())->toBe(3);

    $chicken = DB::table('foods')->where('source_id', 'F1')->first();
    expect((float) $chicken->per100_kcal)->toBe(98.47)                  // 412 kJ / 4.184
        ->and((float) $chicken->per100_protein)->toBe(22.5)
        ->and($chicken->category)->toBe('Meat, poultry and game products and dishes')
        ->and($chicken->state)->toBe('raw')->and((bool) $chicken->is_liquid)->toBeFalse()
        ->and((int) $chicken->quality)->toBe(90);

    $milk = DB::table('foods')->where('source_id', 'F2')->first();       // per-100 mL values, density from specific gravity
    expect((bool) $milk->is_liquid)->toBeTrue()->and((float) $milk->per100_protein)->toBe(3.4)->and((float) $milk->density_g_ml)->toBe(1.03);

    // Long tail: iron is stored, blank cells are not, the energy column is not duplicated.
    expect((float) DB::table('food_nutrients')->where('food_id', $chicken->id)->where('nutrient', 'iron_fe_mg')->value('per100'))->toBe(0.4)
        ->and(DB::table('food_nutrients')->where('food_id', $milk->id)->where('nutrient', 'iron_fe_mg')->exists())->toBeFalse()
        ->and(DB::table('food_nutrients')->where('nutrient', 'like', 'energy%')->exists())->toBeFalse();

    $nutrientsBefore = DB::table('food_nutrients')->count();
    (new AfcdImporter())->import($dir);   // re-run: no duplicates
    expect(DB::table('foods')->count())->toBe(3)->and(DB::table('food_nutrients')->count())->toBe($nutrientsBefore);
});

it('explains a missing AFCD file', function () {
    expect(fn () => (new AfcdImporter())->import(sys_get_temp_dir() . '/nope_' . bin2hex(random_bytes(3))))->toThrow(RuntimeException::class, 'Missing');
});

// ---- Open Food Facts ----------------------------------------------------------------------------------------------

function offCsv(array $rows): string
{
    $cols = ['code', 'product_name', 'brands', 'stores', 'categories_en', 'countries_tags', 'quantity', 'serving_size', 'serving_quantity',
        'energy-kcal_100g', 'proteins_100g', 'carbohydrates_100g', 'fat_100g', 'fiber_100g', 'sugars_100g', 'saturated-fat_100g', 'sodium_100g'];
    $lines = [implode("\t", $cols)];
    foreach ($rows as $r) {
        $lines[] = implode("\t", array_map(fn ($c) => $r[$c] ?? '', $cols));
    }
    $path = sys_get_temp_dir() . '/off_' . bin2hex(random_bytes(4)) . '.csv.gz';
    file_put_contents($path, gzencode(implode("\n", $lines) . "\n"));

    return $path;
}

it('imports only valid Australian OFF rows', function () {
    $good = ['code' => '9300633500100', 'product_name' => 'Weet-Bix', 'brands' => 'Sanitarium', 'countries_tags' => 'en:australia,en:new-zealand', 'serving_size' => '2 biscuits (30 g)',
        'serving_quantity' => '30', 'energy-kcal_100g' => '350', 'proteins_100g' => '11.5', 'carbohydrates_100g' => '67', 'fat_100g' => '1.4'];
    $path = offCsv([
        $good,
        ['countries_tags' => 'en:france'] + $good,                                               // wrong country: skipped
        ['code' => '9300000000011', 'energy-kcal_100g' => '900'] + $good,                       // kcal disagrees with macros: rejected
        ['code' => '9300000000028', 'proteins_100g' => ''] + $good,                             // missing macros: rejected
        ['code' => '9300000000035', 'product_name' => 'Store brand oats', 'stores' => 'Aldi'] + $good, // second good row
    ]);

    $r = (new OffImporter())->import($path);

    expect($r['seen'])->toBe(5)->and($r['au'])->toBe(4)->and($r['imported'])->toBe(2)
        ->and($r['rejected'])->toBe(['kcal disagrees with macros' => 1, 'missing macros' => 1]);

    $wb = DB::table('foods')->where('barcode', '9300633500100')->first();
    expect($wb->name)->toBe('Weet-Bix')->and($wb->brand)->toBe('Sanitarium')->and($wb->source)->toBe('off');
    expect(DB::table('food_servings')->where('food_id', $wb->id)->value('label'))->toBe('2 biscuits (30 g)');

    (new OffImporter())->import($path);   // idempotent
    expect(DB::table('foods')->where('source', 'off')->count())->toBe(2)->and(DB::table('food_servings')->count())->toBe(2);
});

it('does not overwrite a food the user already owns with the same barcode', function () {
    DB::table('foods')->insert(['source' => 'user', 'source_id' => null, 'barcode' => '9300633500100', 'name' => 'My Weet-Bix', 'quality' => 50,
        'created_at' => now(), 'updated_at' => now()]);
    $path = offCsv([['code' => '9300633500100', 'product_name' => 'Weet-Bix', 'countries_tags' => 'en:australia', 'energy-kcal_100g' => '350',
        'proteins_100g' => '11.5', 'carbohydrates_100g' => '67', 'fat_100g' => '1.4']]);

    expect((new OffImporter())->import($path)['imported'])->toBe(0);
    expect(DB::table('foods')->where('barcode', '9300633500100')->value('name'))->toBe('My Weet-Bix');
});

it('refuses a file that is not an OFF export', function () {
    $p = sys_get_temp_dir() . '/bad_' . bin2hex(random_bytes(3)) . '.csv';
    file_put_contents($p, "a\tb\n1\t2\n");
    expect(fn () => (new OffImporter())->import($p))->toThrow(RuntimeException::class, 'Not an Open Food Facts');
});
