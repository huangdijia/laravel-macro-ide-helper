<?php

namespace Huangdijia\IdeHelper;

use Huangdijia\IdeHelper\Console\MacroCommand;
use Illuminate\Support\ServiceProvider;

class IdeHelperServiceProvider extends ServiceProvider
{
    // protected $defer = true;

    public function boot()
    {
        $configPath = __DIR__ . '/../config/macro-ide-helper.php';

        if ($this->app->runningInConsole()) {
            $this->publishes([$configPath => app()->basePath('config/macro-ide-helper.php')], 'config');
        }

        $this->mergeConfigFrom($configPath, 'mitake');
    }

    public function register()
    {
        $this->commands([
            MacroCommand::class,
        ]);
    }
}
