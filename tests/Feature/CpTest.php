<?php

namespace Goldnead\Messaging\Tests\Feature;

use Goldnead\Messaging\Exceptions\MessagingException;
use Goldnead\Messaging\Models\Conversation;
use Goldnead\Messaging\Models\ConversationParticipant;
use Goldnead\Messaging\Models\Message;
use Goldnead\Messaging\Scopes\Filters\Anonymous;
use Goldnead\Messaging\Scopes\Filters\Blocked;
use Goldnead\Messaging\Scopes\Filters\CreatedAt;
use Goldnead\Messaging\Scopes\Filters\Flagged;
use Goldnead\Messaging\Scopes\Filters\HiddenMessages;
use Goldnead\Messaging\Services\ConversationService;
use Goldnead\Messaging\Support\MessagingStats;
use Goldnead\Messaging\Tests\TestCase;
use Goldnead\Messaging\Widgets\MessagingWidget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Widgets\VueComponent;
use Statamic\Widgets\Widget;

class CpTest extends TestCase
{
    private const BODY = 'ZZBODY7f3a-geheimer-text';

    private const NAME = 'ZZNAME9c1d';

    private $singer;

    private $admin;

    private $team;

    private Conversation $anonymous;

    private Conversation $open;

    protected function setUp(): void
    {
        parent::setUp();

        $this->singer = $this->makeUser('zzname9c1d@example.test', self::NAME.' Sängerin');
        $this->admin = $this->makeUser('admin@example.test', 'Chor Admin');
        $this->team = $this->makeTeam($this->admin);

        $service = app(ConversationService::class);

        $this->anonymous = $service->start($this->singer, $this->team, self::BODY, true, ['choir', 'ZZSUBJECT-free-text']);
        $service->reply($this->anonymous, $this->admin, self::BODY.' Antwort');

        $this->open = $service->start($this->singer, $this->team, 'Sichtbar und unauffällig.');
    }

    protected function tearDown(): void
    {
        foreach (glob(__DIR__.'/../__fixtures__/users/*.yaml') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function operator(array $permissions): UserContract
    {
        $handle = 'role'.md5(implode(',', $permissions).microtime());
        Role::make($handle)->addPermission(['access cp', ...$permissions])->save();

        $user = $this->makeUser($handle.'@example.test', 'Operator');
        $user->assignRole($handle)->save();

        return $user;
    }

    private function viewer(): UserContract
    {
        return $this->operator(['view messaging']);
    }

    private function manager(): UserContract
    {
        return $this->operator(['view messaging', 'manage messaging']);
    }

    private function widget(): ?VueComponent
    {
        $widget = app(MessagingWidget::class);
        $widget->setConfig([]);

        return $widget->component();
    }

    private function flagReport(): ConversationParticipant
    {
        $participant = $this->anonymous->participantOf($this->admin);
        $participant->update(['flagged' => true]);

        return $participant;
    }

    /** @return array<int, TestResponse> */
    private function allScreens(): array
    {
        return [
            $this->getJson('/cp/messaging'),
            $this->get('/cp/messaging'),
            $this->get('/cp/messaging/'.$this->anonymous->id),
            $this->get('/cp/messaging/'.$this->open->id),
        ];
    }

    // --- access ---------------------------------------------------------

    public function test_every_screen_is_forbidden_without_the_permission(): void
    {
        $this->actingAs($this->operator([]));

        $this->getJson('/cp/messaging')->assertForbidden();
        $this->getJson('/cp/messaging/'.$this->anonymous->id)->assertForbidden();
        $this->postJson('/cp/messaging/messages/1/hide', ['reason' => 'x'])->assertForbidden();
        $this->postJson('/cp/messaging/messages/1/unhide')->assertForbidden();
        $this->postJson('/cp/messaging/participants/1/unblock')->assertForbidden();

        // A browser is sent away with a toast instead of seeing a 403 page,
        // which is core's own behaviour for the Control Panel.
        $this->get('/cp/messaging')->assertRedirect();
        $this->get('/cp/messaging/'.$this->anonymous->id)->assertRedirect();
    }

    public function test_viewing_does_not_allow_moderating(): void
    {
        $this->flagReport();
        $this->actingAs($this->viewer());

        $this->get('/cp/messaging')->assertOk();

        $message = $this->anonymous->messages()->first();
        $this->postJson('/cp/messaging/messages/'.$message->id.'/hide', ['reason' => 'Beleidigung'])->assertForbidden();
        $this->postJson('/cp/messaging/messages/'.$message->id.'/unhide')->assertForbidden();
        $this->postJson('/cp/messaging/participants/'.$this->anonymous->participantOf($this->admin)->id.'/unblock')->assertForbidden();

        $this->assertFalse($message->fresh()->hidden);
    }

    public function test_the_nav_item_and_permissions_are_registered(): void
    {
        $permissions = Permission::all()->map->value()->all();

        $this->assertContains('view messaging', $permissions);
        $this->assertContains('manage messaging', $permissions);
    }

    // --- privacy --------------------------------------------------------

    public function test_no_screen_ever_contains_a_body_or_the_identity_of_the_anonymous_side(): void
    {
        $this->flagReport();
        $message = $this->anonymous->messages()->first();
        app(ConversationService::class)->hideMessage($message, 'Beleidigung');

        $this->actingAs($this->manager());

        $secrets = [
            self::BODY,
            self::NAME,
            strtolower(self::NAME),
            'Sängerin',
            'ZZSUBJECT-free-text',
            (string) $this->singer->id(),
            'Chor Admin',
            'admin@example.test',
        ];

        $responses = [
            ...$this->allScreens(),
            $this->getJson('/cp/messaging?sort=messages_count&order=asc'),
            $this->getJson('/cp/messaging?filters='.base64_encode(json_encode(['messaging_anonymous' => ['anonymous' => 'yes']]))),
        ];

        foreach ($responses as $response) {
            $response->assertOk();
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent(), "leaked '{$secret}'");
            }
        }

        // The widget payload too.
        $widget = json_encode($this->widget()->toArray());
        $this->assertStringContainsString('messaging-widget', $widget);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, (string) $widget);
        }
    }

    public function test_the_detail_page_names_sides_never_people(): void
    {
        $this->flagReport();
        $this->actingAs($this->viewer());

        $this->get('/cp/messaging/'.$this->anonymous->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('messaging::Show')
                ->where('conversation.recipient', 'Chor')
                ->where('conversation.anonymous', true)
                ->where('conversation.subject_type', 'choir')
                ->where('timeline.0.side', 'initiator')
                ->where('timeline.1.side', 'recipient')
                ->where('timeline.0.hidden', false)
                ->has('participants', 2)
                ->where('participants.0.side', 'initiator')
                ->where('canManage', false)
                ->missing('timeline.0.body')
                ->missing('timeline.0.sender')
                ->missing('participants.0.user_id'));
    }

    public function test_a_person_recipient_shows_as_person(): void
    {
        $this->actingAs($this->viewer());

        $conversation = app(ConversationService::class)->start($this->singer, null, 'Hallo', false, null, $this->admin);

        $this->getJson('/cp/messaging?sort=id&order=desc')
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonPath('data.0.recipient', 'Person');
    }

    public function test_missing_tables_give_an_empty_state_instead_of_a_500(): void
    {
        $this->actingAs($this->viewer());
        Schema::drop('messages');

        $this->get('/cp/messaging')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('setupRequired', true)->where('hasAny', false));
        $this->getJson('/cp/messaging')->assertOk()->assertJsonPath('meta.total', 0);
    }

    // --- listing --------------------------------------------------------

    public function test_the_listing_answers_the_listing_contract(): void
    {
        $this->flagReport();
        $this->anonymous->participantOf($this->admin)->update(['blocked' => true]);
        $this->anonymous->messages()->first()->update(['hidden' => true]);
        $this->actingAs($this->viewer());

        $response = $this->getJson('/cp/messaging?sort=id&order=asc')->assertOk();

        $response->assertJsonStructure([
            'data' => [['id', 'created_at', 'last_message_at', 'recipient', 'anonymous', 'messages_count', 'is_flagged', 'is_blocked', 'has_hidden', 'show_url']],
            'meta' => ['columns', 'activeFilterBadges', 'current_page', 'last_page', 'per_page', 'from', 'to', 'total'],
        ]);

        $first = $response->json('data.0');
        $this->assertSame($this->anonymous->id, $first['id']);
        $this->assertSame(2, $first['messages_count']);
        $this->assertTrue($first['anonymous']);
        $this->assertTrue($first['is_flagged']);
        $this->assertTrue($first['is_blocked']);
        $this->assertTrue($first['has_hidden']);
        $this->assertFalse($response->json('data.1.is_flagged'));
        $this->assertNotEmpty($response->json('meta.columns'));
    }

    public function test_the_listing_filters(): void
    {
        $this->flagReport();
        $this->anonymous->participantOf($this->admin)->update(['blocked' => true]);
        $this->anonymous->messages()->first()->update(['hidden' => true]);
        $this->actingAs($this->viewer());

        $ids = fn (string $handle, array $values) => collect(
            $this->getJson('/cp/messaging?filters='.base64_encode(json_encode([$handle => $values])))->assertOk()->json('data')
        )->pluck('id')->all();

        $this->assertSame([$this->anonymous->id], $ids('messaging_anonymous', ['anonymous' => 'yes']));
        $this->assertSame([$this->open->id], $ids('messaging_anonymous', ['anonymous' => 'no']));
        $this->assertSame([$this->anonymous->id], $ids('messaging_flagged', ['flagged' => 'yes']));
        $this->assertSame([$this->open->id], $ids('messaging_flagged', ['flagged' => 'no']));
        $this->assertSame([$this->anonymous->id], $ids('messaging_blocked', ['blocked' => 'yes']));
        $this->assertSame([$this->anonymous->id], $ids('messaging_hidden', ['hidden' => 'yes']));
        $this->assertSame([$this->open->id], $ids('messaging_hidden', ['hidden' => 'no']));

        Conversation::query()->whereKey($this->anonymous->id)->update(['created_at' => now()->subDays(40)]);

        $this->assertSame([$this->open->id], $ids('messaging_created_at', ['range' => ['start' => now()->subDays(5)->toDateString(), 'end' => now()->toDateString()]]));
        $this->assertSame([$this->anonymous->id], $ids('messaging_created_at', ['range' => ['start' => now()->subDays(60)->toDateString(), 'end' => now()->subDays(20)->toDateString()]]));
    }

    public function test_an_unknown_sort_column_is_ignored(): void
    {
        $this->actingAs($this->viewer());

        $this->getJson('/cp/messaging?sort=body&order=asc')->assertOk();
        $this->getJson('/cp/messaging?sort=id) --&order=asc')->assertOk();
    }

    public function test_the_page_boots_the_native_listing(): void
    {
        $this->actingAs($this->viewer());

        $this->get('/cp/messaging')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('messaging::Index')
            ->where('hasAny', true)
            ->has('columns')
            ->has('filters')
            ->where('listingUrl', cp_route('messaging.index')));
    }

    public function test_the_filters_stay_off_other_listings(): void
    {
        foreach ([new Anonymous, new Flagged, new Blocked, new HiddenMessages, new CreatedAt] as $filter) {
            $this->assertTrue($filter->visibleTo('messaging'));
            $this->assertFalse($filter->visibleTo('entries'));
        }
    }

    // --- moderation -----------------------------------------------------

    public function test_a_message_of_a_reported_conversation_can_be_hidden_and_shown_again(): void
    {
        $this->flagReport();
        $message = $this->anonymous->messages()->first();
        $this->actingAs($this->manager());

        $this->post('/cp/messaging/messages/'.$message->id.'/hide', ['reason' => 'Beleidigung'])
            ->assertRedirect(cp_route('messaging.show', ['conversation' => $this->anonymous->id]));

        $message->refresh();
        $this->assertTrue($message->hidden);
        $this->assertSame('Beleidigung', $message->hidden_reason);
        $this->assertNotNull($message->hidden_at);

        // The participants no longer see it.
        $thread = app(ConversationService::class)->thread($this->anonymous, $this->admin);
        $this->assertCount(1, $thread);

        $this->get('/cp/messaging/'.$this->anonymous->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canManage', true)
                ->where('timeline.0.hidden', true)
                ->where('timeline.0.hidden_reason', 'Beleidigung'));

        $this->post('/cp/messaging/messages/'.$message->id.'/unhide')->assertRedirect();

        $message->refresh();
        $this->assertFalse($message->hidden);
        $this->assertNull($message->hidden_reason);
        $this->assertNull($message->hidden_at);
    }

    public function test_a_message_of_an_unreported_conversation_cannot_be_hidden(): void
    {
        $message = $this->anonymous->messages()->first();
        $this->actingAs($this->manager());

        $this->post('/cp/messaging/messages/'.$message->id.'/hide', ['reason' => 'Willkür'])->assertRedirect();

        $this->assertFalse($message->fresh()->hidden);
    }

    public function test_hiding_needs_a_reason(): void
    {
        $this->flagReport();
        $message = $this->anonymous->messages()->first();
        $this->actingAs($this->manager());

        $this->post('/cp/messaging/messages/'.$message->id.'/hide', ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post('/cp/messaging/messages/'.$message->id.'/hide', ['reason' => str_repeat('x', 256)])->assertSessionHasErrors('reason');

        $this->assertFalse($message->fresh()->hidden);
    }

    public function test_the_service_refuses_what_the_screen_refuses(): void
    {
        $service = app(ConversationService::class);
        $message = $this->anonymous->messages()->first();

        try {
            $service->hideMessage($message, 'Grund');
            $this->fail('An unreported message was hidden.');
        } catch (MessagingException) {
            $this->assertFalse($message->fresh()->hidden);
        }

        $this->flagReport();

        $this->expectException(MessagingException::class);
        $service->hideMessage($message, '   ');
    }

    public function test_a_participant_can_be_unblocked(): void
    {
        $participant = $this->anonymous->participantOf($this->admin);
        $participant->update(['blocked' => true]);
        try {
            app(ConversationService::class)->reply($this->anonymous, $this->admin, 'Gesperrt.');
            $this->fail('A blocked participant could write.');
        } catch (AuthorizationException) {
        }
        $this->actingAs($this->manager());

        $this->post('/cp/messaging/participants/'.$participant->id.'/unblock')
            ->assertRedirect(cp_route('messaging.show', ['conversation' => $this->anonymous->id]));

        $this->assertFalse($participant->fresh()->blocked);
        $this->assertNotNull(app(ConversationService::class)->reply($this->anonymous->fresh(), $this->admin, 'Wieder da.'));
    }

    // --- widget ---------------------------------------------------------

    public function test_the_widget_counts_conversations_messages_and_flags(): void
    {
        $this->flagReport();

        Conversation::query()->whereKey($this->anonymous->id)->update(['created_at' => now()->subDays(20)]);
        Message::query()->where('conversation_id', $this->anonymous->id)->update(['created_at' => now()->subDays(20)]);

        $stats = MessagingStats::summary();

        $this->assertSame(['days' => 7, 'conversations' => 1, 'messages' => 1], $stats['windows'][0]);
        $this->assertSame(['days' => 30, 'conversations' => 2, 'messages' => 3], $stats['windows'][1]);
        $this->assertSame(1, $stats['flagged']);
    }

    public function test_the_widget_renders_only_for_those_who_may_view(): void
    {
        $this->actingAs($this->operator([]));
        $this->assertNull($this->widget());

        $this->actingAs($this->viewer());
        $component = $this->widget()->toArray();

        $this->assertSame('messaging-widget', $component['name']);
        $this->assertSame(cp_route('messaging.index'), $component['props']['url']);
        $this->assertSame(0, $component['props']['stats']['flagged']);
        $this->assertSame(2, $component['props']['stats']['windows'][0]['conversations']);
    }

    public function test_the_dashboard_offers_the_widget(): void
    {
        $this->assertSame(MessagingWidget::class, app('statamic.extensions')[Widget::class]['messaging'] ?? null);
    }
}
