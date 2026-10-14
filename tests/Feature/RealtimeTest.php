<?php

namespace Goldnead\Messaging\Tests\Feature;

use Goldnead\Messaging\Broadcasting\MessageBroadcast;
use Goldnead\Messaging\Events\MessageSent;
use Goldnead\Messaging\Listeners\BroadcastMessage;
use Goldnead\Messaging\Models\Conversation;
use Goldnead\Messaging\Models\Message;
use Goldnead\Messaging\Services\ConversationService;
use Goldnead\Messaging\Support\Realtime;
use Goldnead\Messaging\Tests\ProbeBroadcaster;
use Goldnead\Messaging\Tests\TestCase;
use Goldnead\Teams\TeamsManager;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\Antlers;

class RealtimeTest extends TestCase
{
    private const NAME = 'ZZNAME5e2b';

    private const BODY = 'ZZBODY1a77-geheim';

    private $singer;

    private $admin;

    private $member;

    private $stranger;

    private $team;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Switched on by hand with a probe driver: the tests need the channel
        // and auth routes, not a websocket server.
        $app['config']->set('messaging.realtime', true);
        $app['config']->set('broadcasting.default', 'probe');
        $app['config']->set('broadcasting.connections.probe', ['driver' => 'probe']);
        $app->make(BroadcastManager::class)->extend('probe', fn () => new ProbeBroadcaster);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->singer = $this->makeUser('zzname5e2b@example.test', self::NAME.' Sängerin');
        $this->admin = $this->makeUser('admin@example.test', 'Chor Admin');
        $this->member = $this->makeUser('member@example.test', 'Chor Mitglied');
        $this->stranger = $this->makeUser('stranger@example.test', 'Fremder Admin');
        $this->team = $this->makeTeam($this->admin, $this->member);
        $this->makeTeam($this->stranger);
    }

    private function service(): ConversationService
    {
        return app(ConversationService::class);
    }

    private function anonymous(): Conversation
    {
        return $this->service()->start($this->singer, $this->team, self::BODY, true);
    }

    /** @return list<string> */
    private function channels(MessageBroadcast $event): array
    {
        return array_map(fn ($c) => $c->name, $event->broadcastOn());
    }

    private function auth(string $channel)
    {
        return $this->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel]);
    }

    public function test_reply_broadcasts_on_the_conversation_and_user_channels(): void
    {
        $c = $this->anonymous();
        Event::fake([MessageBroadcast::class]);

        $message = $this->service()->reply($c, $this->admin, 'Willkommen.');

        Event::assertDispatched(MessageBroadcast::class, function (MessageBroadcast $e) use ($c, $message) {
            $channels = $this->channels($e);

            return $e->broadcastAs() === 'message.sent'
                && $e->broadcastWith() === ['conversation' => $c->id, 'message' => $message->id]
                && in_array('private-messaging.conversation.'.$c->id, $channels, true)
                && in_array('private-messaging.user.'.$this->singer->id(), $channels, true)
                && in_array('private-messaging.user.'.$this->member->id(), $channels, true);
        });
    }

    public function test_the_first_message_of_a_new_conversation_is_broadcast_too(): void
    {
        Event::fake([MessageBroadcast::class]);

        $c = $this->anonymous();

        Event::assertDispatched(MessageBroadcast::class, fn (MessageBroadcast $e) => $e->conversationId === $c->id);
    }

    public function test_channels_are_private(): void
    {
        $c = $this->anonymous();
        $event = new MessageBroadcast('sent', $c->id, 1, [(string) $this->singer->id()]);

        foreach ($event->broadcastOn() as $channel) {
            $this->assertInstanceOf(PrivateChannel::class, $channel);
        }
    }

    public function test_a_blocked_participant_gets_no_user_channel_event(): void
    {
        $c = $this->anonymous();
        $this->service()->block($c, $this->member);
        Event::fake([MessageBroadcast::class]);

        $this->service()->reply($c, $this->admin, 'Hallo.');

        Event::assertDispatched(MessageBroadcast::class, function (MessageBroadcast $e) {
            $channels = $this->channels($e);

            return ! in_array('private-messaging.user.'.$this->member->id(), $channels, true)
                && in_array('private-messaging.user.'.$this->singer->id(), $channels, true);
        });
    }

    public function test_payload_has_no_text_and_no_identity(): void
    {
        $c = $this->anonymous();
        Event::fake([MessageBroadcast::class]);
        $this->service()->reply($c, $this->singer, self::BODY.' noch einmal');

        Event::assertDispatched(MessageBroadcast::class, function (MessageBroadcast $e) {
            $json = json_encode([$e->broadcastWith(), $e->broadcastAs(), $this->channels($e)]);

            return ! str_contains($json, self::BODY)
                && ! str_contains($json, self::NAME)
                && ! str_contains($json, 'Anonym')
                && array_keys($e->broadcastWith()) === ['conversation', 'message'];
        });
    }

    public function test_a_hidden_message_is_never_announced_as_new(): void
    {
        $c = $this->anonymous();
        $message = $c->messages()->first();
        $message->update(['hidden' => true]);
        Event::fake([MessageBroadcast::class]);

        app(BroadcastMessage::class)->handle(new MessageSent($message));

        Event::assertNotDispatched(MessageBroadcast::class);
    }

    public function test_hiding_and_restoring_announce_the_change(): void
    {
        $c = $this->anonymous();
        $message = $c->messages()->first();
        $c->participants()->where('user_id', $this->admin->id())->update(['flagged' => true]);
        Event::fake([MessageBroadcast::class]);

        $this->service()->hideMessage($message, 'Spam');
        $this->service()->unhideMessage($message);

        Event::assertDispatched(MessageBroadcast::class, fn (MessageBroadcast $e) => $e->broadcastAs() === 'message.hidden' && $e->broadcastWith()['message'] === $message->id);
        Event::assertDispatched(MessageBroadcast::class, fn (MessageBroadcast $e) => $e->broadcastAs() === 'message.restored');
    }

    public function test_participants_may_listen_strangers_may_not(): void
    {
        $c = $this->anonymous();
        $channel = 'private-messaging.conversation.'.$c->id;

        $this->actingAs($this->singer)->auth($channel)->assertOk();
        $this->actingAs($this->admin)->auth($channel)->assertOk();
        $this->actingAs($this->member)->auth($channel)->assertOk();
        $this->actingAs($this->stranger)->auth($channel)->assertForbidden();
    }

    public function test_a_blocking_participant_is_shut_out(): void
    {
        $c = $this->anonymous();
        $this->service()->block($c, $this->member);

        $this->actingAs($this->member)->auth('private-messaging.conversation.'.$c->id)->assertForbidden();
        $this->actingAs($this->admin)->auth('private-messaging.conversation.'.$c->id)->assertOk();
    }

    public function test_a_member_who_left_the_team_is_shut_out(): void
    {
        $c = $this->anonymous();
        app(TeamsManager::class)->removeMember($this->team, $this->member);

        $this->actingAs($this->member)->auth('private-messaging.conversation.'.$c->id)->assertForbidden();
    }

    public function test_a_user_channel_belongs_to_its_user_only(): void
    {
        $this->actingAs($this->singer)->auth('private-messaging.user.'.$this->singer->id())->assertOk();
        $this->actingAs($this->singer)->auth('private-messaging.user.'.$this->admin->id())->assertForbidden();
    }

    public function test_guests_cannot_authorise(): void
    {
        $c = $this->anonymous();

        $this->auth('private-messaging.conversation.'.$c->id)->assertForbidden();
    }

    public function test_the_message_endpoint_masks_the_anonymous_sender_for_the_other_side(): void
    {
        $c = $this->anonymous();
        $message = $c->messages()->first();
        $url = '/messaging/realtime/'.$c->id.'/messages/'.$message->id;

        $team = $this->actingAs($this->admin)->getJson($url)->assertOk();
        $this->assertSame(self::BODY, $team->json('body'));
        $this->assertSame(Conversation::ANONYMOUS_LABEL, $team->json('sender'));
        $this->assertFalse($team->json('mine'));
        $this->assertStringNotContainsString(self::NAME, $team->getContent());

        $own = $this->actingAs($this->singer)->getJson($url)->assertOk();
        $this->assertTrue($own->json('mine'));
        $this->assertSame($message->id, $own->json('id'));
    }

    public function test_the_message_endpoint_refuses_strangers_hidden_and_foreign_messages(): void
    {
        $c = $this->anonymous();
        $other = $this->service()->start($this->singer, $this->makeTeam($this->makeUser('x@example.test')), 'Anderes', false);
        $message = $c->messages()->first();

        $this->actingAs($this->stranger)->getJson('/messaging/realtime/'.$c->id.'/messages/'.$message->id)->assertNotFound();
        $this->actingAs($this->admin)->getJson('/messaging/realtime/'.$other->id.'/messages/'.$message->id)->assertNotFound();

        $message->update(['hidden' => true]);
        $this->actingAs($this->admin)->getJson('/messaging/realtime/'.$c->id.'/messages/'.$message->id)->assertNotFound();
    }

    public function test_the_endpoint_needs_a_signed_in_user(): void
    {
        $c = $this->anonymous();

        $this->getJson('/messaging/realtime/'.$c->id.'/messages/'.$c->messages()->first()->id)->assertStatus(401);
    }

    public function test_the_script_is_served(): void
    {
        $response = $this->get('/messaging/realtime.js');

        $response->assertOk();
        $this->assertStringContainsString('javascript', (string) $response->headers->get('Content-Type'));
    }

    public function test_the_tag_renders_the_loader_for_a_participant(): void
    {
        $c = $this->anonymous();
        config(['broadcasting.connections.log' => ['driver' => 'log'], 'broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'pubkey']]);
        config(['broadcasting.default' => 'reverb']);
        $this->actingAs($this->admin);

        $html = (string) Antlers::parse('{{ messaging:realtime conversation="'.$c->id.'" }}', [], true);

        $this->assertStringContainsString('/messaging/realtime.js', $html);
        $this->assertStringContainsString('messaging.conversation.'.$c->id, $html);
        $this->assertStringContainsString('messaging.user.'.$this->admin->id(), $html);
        $this->assertStringContainsString('pubkey', $html);
        $this->assertStringNotContainsString('secret', strtolower($html));
    }

    public function test_the_tag_is_empty_for_a_stranger_and_for_a_guest(): void
    {
        $c = $this->anonymous();
        config(['broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'pubkey'], 'broadcasting.default' => 'reverb']);
        $tag = '{{ messaging:realtime conversation="'.$c->id.'" }}';

        $this->assertSame('', trim((string) Antlers::parse($tag, [], true)));

        $this->actingAs($this->stranger);
        $html = (string) Antlers::parse($tag, [], true);
        $this->assertStringContainsString('/messaging/realtime.js', $html);
        $this->assertStringNotContainsString('messaging.conversation.', $html);
    }

    public function test_the_tag_is_empty_without_a_driver(): void
    {
        $c = $this->anonymous();
        config(['messaging.realtime' => null, 'broadcasting.default' => 'null']);
        $this->actingAs($this->admin);

        $this->assertSame('', trim((string) Antlers::parse('{{ messaging:realtime conversation="'.$c->id.'" }}', [], true)));
    }

    public function test_enabled_follows_the_broadcast_driver(): void
    {
        config(['messaging.realtime' => null, 'broadcasting.connections.reverb' => ['driver' => 'reverb'], 'broadcasting.connections.pusher' => ['driver' => 'pusher']]);

        foreach (['null' => false, 'log' => false, 'reverb' => true, 'pusher' => true] as $driver => $expected) {
            config(['broadcasting.default' => $driver]);
            $this->assertSame($expected, Realtime::enabled(), $driver);
        }

        config(['messaging.realtime' => false, 'broadcasting.default' => 'reverb']);
        $this->assertFalse(Realtime::enabled());
    }

    public function test_a_broken_broadcast_server_never_breaks_sending(): void
    {
        $c = $this->anonymous();
        // `reverb` is configured but its library is not installed here: creating the driver throws.
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'k', 'secret' => 's', 'app_id' => '1', 'options' => []]]);

        $message = $this->service()->reply($c, $this->admin, 'Trotzdem gespeichert.');

        $this->assertNotNull(Message::find($message->id));
    }
}
