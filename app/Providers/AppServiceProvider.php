<?php

namespace App\Providers;

use App\Domain\Search\Synonyms;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Domain\Capture\RuleParser::class, fn () => \App\Domain\Capture\RuleParser::fromFile(base_path('data/capture_vocab.json')));
        // AU synonyms live in data/synonyms.json so they can be extended without code changes.
        $this->app->singleton(Synonyms::class, fn () => Synonyms::fromFile(base_path('data/synonyms.json')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
