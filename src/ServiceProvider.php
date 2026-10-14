<?php

namespace Goldnead\Messaging;

use Goldnead\Messaging\Integrations\Accounts\MessagingContributor;
use Goldnead\Messaging\Models\Conversation;
use Goldnead\Messaging\Policies\ConversationPolicy;
use Goldnead\Messaging\Scopes\Filters;
use Goldnead\Messaging\Services\ConversationService;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    /**
     * Statamic 6 reads an addon's Vite config from this property and nowhere
     * else. The values have to byte-match `laravel()` in vite.config.js.
     *
     * The parent annotates this `list<string>`, which is not what registerVite()
     * reads; the one baseline entry covers that upstream annotation.
     */
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    public function register(): void
    {
        parent::register();

        // Registered against the resolving translator: the nav and permission
        // labels are built before bootAddon() runs.
        $this->app->resolving('translator', function ($translator): void {
            $translator->addNamespace('messaging', __DIR__.'/../resources/lang');
        });

        $this->app->singleton(ConversationService::class);
    }

    public function bootAddon(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Gate::policy(Conversation::class, ConversationPolicy::class);

        // goldnead/statamic-accounts picks these up by tag; `key()` is the
        // same, so one class serves the export and the erasure.
        if (interface_exists('Goldnead\Accounts\Contracts\ErasesPersonalData')) {
            $this->app->tag([MessagingContributor::class], 'accounts.personal-data');
            $this->app->tag([MessagingContributor::class], 'accounts.personal-data-erasers');
        }

        $this->registerNavigation()->registerPermissions()->bootFilters();
    }

    /**
     * The parent's `$scopes` path only runs once Statamic's own boot sequence
     * has fired, and only scans one folder level. Registration is idempotent.
     */
    protected function bootFilters(): self
    {
        foreach ([
            Filters\Anonymous::class,
            Filters\Flagged::class,
            Filters\Blocked::class,
            Filters\HiddenMessages::class,
            Filters\CreatedAt::class,
        ] as $filter) {
            $filter::register();
        }

        return $this;
    }

    protected function registerNavigation(): self
    {
        Nav::extend(function ($nav): void {
            $nav->create(__('messaging::cp.nav'))
                ->section('Tools')
                ->icon('mail')
                ->route('messaging.index')
                ->can('view messaging');
        });

        return $this;
    }

    protected function registerPermissions(): self
    {
        Permission::extend(function (): void {
            Permission::group('messaging', __('messaging::cp.nav'), function (): void {
                Permission::register('view messaging')
                    ->label(__('messaging::cp.permission_view'))
                    ->children([
                        Permission::make('manage messaging')->label(__('messaging::cp.permission_manage')),
                    ]);
            });
        });

        return $this;
    }
}
