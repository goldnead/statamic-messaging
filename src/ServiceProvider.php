<?php

namespace Goldnead\Messaging;

use Goldnead\Messaging\Integrations\Accounts\MessagingContributor;
use Goldnead\Messaging\Models\Conversation;
use Goldnead\Messaging\Policies\ConversationPolicy;
use Goldnead\Messaging\Services\ConversationService;
use Illuminate\Support\Facades\Gate;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public function register(): void
    {
        parent::register();

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
    }
}
