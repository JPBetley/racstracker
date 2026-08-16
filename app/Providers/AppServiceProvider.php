<?php

namespace App\Providers;

use App\Imports\Ocr\AiVisionRosterScreenshotReader;
use App\Imports\Ocr\AiVisionVsScoreScreenshotReader;
use App\Imports\Ocr\Contracts\RosterScreenshotReader;
use App\Imports\Ocr\Contracts\VsScoreScreenshotReader;
use App\LastWar\Contracts\LastWarApi;
use App\LastWar\HttpLastWarApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(RosterScreenshotReader::class, AiVisionRosterScreenshotReader::class);
        $this->app->bind(VsScoreScreenshotReader::class, AiVisionVsScoreScreenshotReader::class);
        $this->app->bind(LastWarApi::class, HttpLastWarApi::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
