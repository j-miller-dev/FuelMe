<?php
declare(strict_types=1);

namespace App\Domain\Foods;

use Illuminate\Support\Facades\DB;

/** The empty-search lists (BLUEPRINT 5.3): Frequent now (time-of-day aware), Recents and Favourites, all from YOUR logs. */
final class QuickFoods
{
    public function __construct(private FoodSearch $search) {}

    /** @return array{frequent:list<array>, recents:list<array>, favourites:list<array>} */
    public function for(int $userId, \DateTimeInterface $now, string $tz): array
    {
        $local = \DateTimeImmutable::createFromInterface($now)->setTimezone(new \DateTimeZone($tz));

        // Last 90 days of food-based logs are plenty to learn habits and keep this one small query.
        $logs = DB::table('food_logs')->where('user_id', $userId)->whereNotNull('food_id')
            ->where('logged_at', '>=', $local->modify('-90 days')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'))
            ->get(['food_id', 'logged_at'])
            ->map(function ($l) use ($tz, $local) {
                $t = (new \DateTimeImmutable($l->logged_at, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($tz));

                return ['food_id' => (int) $l->food_id, 'hour' => (int) $t->format('G'), 'days_ago' => ($local->getTimestamp() - $t->getTimestamp()) / 86400];
            })->all();

        $frequent = array_column(Frequent::rank($logs, (int) $local->format('G'), 8), 'food_id');

        $recent = DB::table('food_logs')->where('user_id', $userId)->whereNotNull('food_id')
            ->selectRaw('food_id, max(logged_at) as last_at')->groupBy('food_id')->orderByDesc('last_at')->limit(12)->pluck('food_id')
            ->map(fn ($i) => (int) $i)->all();

        $favs = DB::table('food_favourites')->where('user_id', $userId)->orderByDesc('created_at')->limit(30)->pluck('food_id')->map(fn ($i) => (int) $i)->all();

        return [
            'frequent' => $this->search->byIds($frequent, $userId),
            'recents' => $this->search->byIds($recent, $userId),
            'favourites' => $this->search->byIds($favs, $userId),
        ];
    }
}
