<?php

namespace Goldnead\Messaging\Tests;

use Goldnead\Messaging\ServiceProvider;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\TeamsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    use RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getPackageProviders($app): array
    {
        return [
            \Goldnead\Teams\ServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    /** Statamic boots only the addon under test, so the dependency's tables come in by hand. */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname((string) (new \ReflectionClass(\Goldnead\Teams\ServiceProvider::class))->getFileName(), 2).'/database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        $app['config']->set('statamic.users.repository', 'file');
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('mail.default', 'array');
    }

    protected function tearDown(): void
    {
        foreach (glob(__DIR__.'/__fixtures__/users/*.yaml') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    protected function makeUser(string $email, ?string $name = null): UserContract
    {
        $user = User::make()->email($email)->data(['name' => $name ?? ucfirst(strstr($email, '@', true) ?: $email)]);
        $user->save();

        return $user;
    }

    /** A team owned by `$owner`, with `$members` added on top. */
    protected function makeTeam(UserContract $owner, UserContract ...$members): Team
    {
        $teams = app(TeamsManager::class);
        $team = $teams->create('Chor', $owner);
        foreach ($members as $member) {
            $teams->addMember($team, $member);
        }

        return $team;
    }
}
