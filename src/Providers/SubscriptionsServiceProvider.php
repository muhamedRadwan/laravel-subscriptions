<?php

declare(strict_types=1);

namespace Rinvex\Subscriptions\Providers;

use Rinvex\Subscriptions\Models\Plan;
use Illuminate\Support\ServiceProvider;
use Rinvex\Support\Traits\ConsoleTools;
use Rinvex\Subscriptions\Models\PlanFeature;
use Rinvex\Subscriptions\Models\PlanSubscription;
use Rinvex\Subscriptions\Models\PlanSubscriptionUsage;
use Rinvex\Subscriptions\Console\Commands\MigrateCommand;
use Rinvex\Subscriptions\Console\Commands\PublishCommand;
use Rinvex\Subscriptions\Console\Commands\RollbackCommand;

class SubscriptionsServiceProvider extends ServiceProvider
{
    use ConsoleTools;

    /**
     * The commands to be registered.
     *
     * @var array
     */
    protected $commands = [
        MigrateCommand::class => 'command.rinvex.subscriptions.migrate',
        PublishCommand::class => 'command.rinvex.subscriptions.publish',
        RollbackCommand::class => 'command.rinvex.subscriptions.rollback',
    ];

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(realpath(__DIR__.'/../../config/config.php'), 'rinvex.subscriptions');

        // Bind eloquent models to IoC container
        $this->registerModels([
            'rinvex.subscriptions.plan' => Plan::class,
            'rinvex.subscriptions.plan_feature' => PlanFeature::class,
            'rinvex.subscriptions.plan_subscription' => PlanSubscription::class,
            'rinvex.subscriptions.plan_subscription_usage' => PlanSubscriptionUsage::class,
        ]);

        // Register console commands
        $this->registerCommands($this->commands);
    }

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Publish Resources
        $this->publishesConfig('rinvex/laravel-subscriptions');
        $this->publishesMigrations('rinvex/laravel-subscriptions');
        ! $this->autoloadMigrations('rinvex/laravel-subscriptions') || $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    /**
     * Determine if the application is running in the console.
     *
     * @TODO: Implement this method to detect if we're in active dev zone or not!
     *        Ex: running inside cortex/console action
     *
     * @return bool
     */
    public function runningInDevzone()
    {
        return true;
    }

    /**
     * Register console commands.
     *
     * @param array $commands
     *
     * @return void
     */
    protected function registerCommands(array $commands): void
    {
        if (!$this->app->runningInConsole() && !$this->runningInDevzone()) {
            return;
        }

        foreach ($commands as $key => $value) {
            $this->app->singleton($value, $key);
        }

        $this->commands(array_values($commands));
    }

    /**
     * Publish package migrations.
     *
     * @return void
     */
    protected function publishesMigrations(string $package, bool $isModule = false): void
    {
        if (!$this->publishesResources()) {
            return;
        }

        $namespace = str_replace('laravel-', '', $package);
        $basePath = $isModule ? $this->app->path($package)
            : $this->app->basePath('vendor/' . $package);

        if (file_exists($path = $basePath . '/database/migrations')) {
            $stubs = $this->app['files']->glob($path . '/*.php');
            $existing = $this->app['files']->glob($this->app->databasePath('migrations/' . $package . '/*.php'));

            $migrations = collect($stubs)->flatMap(function ($migration) use ($existing, $package) {
                $sequence = mb_substr(basename($migration), 0, 17);
                $match = collect($existing)->first(function ($item, $key) use ($migration, $sequence) {
                    return mb_strpos($item, str_replace($sequence, '', basename($migration))) !== false;
                });

                return [$migration => $this->app->databasePath('migrations/' . $package . '/' . ($match ? basename($match) : date('Y_m_d_His', time() + mb_substr($sequence, -6)) . str_replace($sequence, '', basename($migration))))];
            })->toArray();

            $this->publishes($migrations, $namespace . '::migrations');
        }
    }

    /**
     * Publish package config.
     *
     * @return void
     */
    protected function publishesConfig(string $package, bool $isModule = false): void
    {
        if (!$this->publishesResources()) {
            return;
        }

        $namespace = str_replace('laravel-', '', $package);
        $basePath = $isModule ? $this->app->path($package)
            : $this->app->basePath('vendor/' . $package);

        if (file_exists($path = $basePath . '/config/config.php')) {
            $this->publishes([$path => $this->app->configPath(str_replace('/', '.', $namespace) . '.php')], $namespace . '::config');
        }
    }

    /**
     * Can publish resources.
     *
     * @return bool
     */
    protected function publishesResources(): bool
    {
        return !$this->app->environment('production') || $this->app->runningInConsole() || $this->runningInDevzone();
    }

    /**
     * Can autoload migrations.
     *
     * @param string $module
     *
     * @return bool
     */
    protected function autoloadMigrations(string $module): bool
    {
        return $this->publishesResources() && $this->app['config'][str_replace(['laravel-', '/'], ['', '.'], $module) . '.autoload_migrations'];
    }
}
