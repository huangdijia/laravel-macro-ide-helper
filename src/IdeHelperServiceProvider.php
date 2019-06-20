<?php

namespace Huangdijia\IdeHelper;

use Huangdijia\IdeHelper\Console\MacroCommand;
use Illuminate\Support\ServiceProvider;

class IdeHelperServiceProvider extends ServiceProvider
{
    // protected $defer = true;

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/macro-ide-helper.php' => app()->basePath('config/macro-ide-helper.php')], 'config');
        }
    }

    public function register()
    {
        $this->commands([
            MacroCommand::class,
        ]);
    }
}
