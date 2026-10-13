<?php

namespace Goldnead\Messaging\Tests\Feature;

use Goldnead\Accounts\PersonalData\ErasureRegistry;
use Goldnead\Accounts\Services\PersonalDataErasure;
use Goldnead\Accounts\Services\PersonalDataExport;
use Goldnead\Messaging\Events\MessageSent;
use Goldnead\Messaging\Exceptions\MessagingException;
use Goldnead\Messaging\Integrations\Accounts\MessagingContributor;
use Goldnead\Messaging\Models\Conversation;
use Goldnead\Messaging\Models\Message;
use Goldnead\Messaging\Services\ConversationService;
use Goldnead\Messaging\Tests\TestCase;
use Goldnead\Teams\TeamsManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;

class MessagingTest extends TestCase
{
    private $singer;

    private $admin;

    private $member;

    private $stranger;

    private $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->singer = $this->makeUser('singer@example.test', 'Sina Sängerin');
        $this->admin = $this->makeUser('admin@example.test', 'Chor Admin');
        $this->member = $this->makeUser('member@example.test', 'Chor Mitglied');
        $this->stranger = $this->makeUser('stranger@example.test', 'Fremder Admin');
        $this->team = $this->makeTeam($this->admin, $this->member);
        $this->makeTeam($this->stranger); // the stranger administers a team of their own
    }

    private function service(): ConversationService
    {
        return app(ConversationService::class);
    }

    private function start(bool $anonymous = false): Conversation
    {
        return $this->service()->start($this->singer, $this->team, 'Hallo, ich suche einen Chor.', $anonymous, ['choir', 7]);
    }

    public function test_start_creates_roles_and_first_message(): void
    {
        $c = $this->start();

        $this->assertSame('initiator', $c->participantOf($this->singer)->role);
        $this->assertSame('member', $c->participantOf($this->admin)->role);
        $this->assertSame('member', $c->participantOf($this->member)->role);
        $this->assertSame(1, $c->messages()->count());
        $this->assertSame('choir', $c->subject_type);
        $this->assertNotNull($c->last_message_at);
    }

    public function test_less_than_two_parties_is_no_conversation(): void
    {
        $solo = $this->makeTeam($this->singer); // the only member is the sender

        $this->expectException(MessagingException::class);
        try {
            $this->service()->start($this->singer, $solo, 'Hi');
        } finally {
            $this->assertSame(0, Conversation::count());
        }
    }

    public function test_empty_body_is_refused(): void
    {
        $this->expectException(MessagingException::class);
        $this->service()->start($this->singer, $this->team, '  <b></b> ');
    }

    public function test_foreign_team_admin_reads_and_changes_nothing(): void
    {
        $c = $this->start();

        foreach (['view', 'reply', 'shareProfile', 'block', 'archive'] as $ability) {
            $this->assertFalse($this->stranger->can($ability, $c) ?? false, $ability);
        }

        $calls = [
            fn () => $this->service()->thread($c, $this->stranger),
            fn () => $this->service()->reply($c, $this->stranger, 'Mitlesen'),
            fn () => $this->service()->block($c, $this->stranger),
            fn () => $this->service()->archive($c, $this->stranger),
            fn () => $this->service()->shareProfile($c, $this->stranger),
            fn () => $this->service()->markRead($c, $this->stranger),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Expected AuthorizationException');
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame(1, $c->messages()->count());
        $this->assertNull($c->participantOf($this->stranger));
        $this->assertFalse($c->fresh()->profile_shared);
    }

    public function test_team_member_without_row_can_read_and_reply(): void
    {
        $c = $this->start();
        $late = $this->makeUser('late@example.test');
        app(TeamsManager::class)->addMember($this->team, $late);

        $this->assertCount(1, $this->service()->thread($c, $late));
        $this->service()->reply($c, $late, 'Willkommen');

        $this->assertSame('member', $c->participantOf($late)->role);
        $this->assertSame(2, $c->messages()->count());
    }

    public function test_anonymous_identity_stays_hidden(): void
    {
        $c = $this->start(anonymous: true);

        $thread = $this->service()->thread($c, $this->admin);
        $this->assertSame('Anonyme:r Interessent:in', $thread[0]['sender']);
        $this->assertSame('Anonyme:r Interessent:in', $c->displayName($this->singer, $this->admin));
        $this->assertStringNotContainsString('Sina', json_encode($thread));
        $this->assertStringNotContainsString('singer@example.test', json_encode($thread));

        // The anonymous side sees their own name, and the choir's side stays named.
        $this->assertSame('Sina Sängerin', $c->displayName($this->singer, $this->singer));
        $this->assertSame('Chor Admin', $c->displayName($this->admin, $this->singer));
    }

    public function test_only_the_anonymous_side_can_reveal_itself(): void
    {
        $c = $this->start(anonymous: true);

        foreach ([$this->admin, $this->member, $this->stranger] as $other) {
            try {
                $this->service()->shareProfile($c, $other);
                $this->fail('Expected AuthorizationException');
            } catch (AuthorizationException) {
            }
        }
        $this->assertTrue($c->fresh()->anonymous);

        $this->service()->shareProfile($c, $this->singer);

        $c = $c->fresh();
        $this->assertTrue($c->profile_shared);
        $this->assertFalse($c->anonymous);
        $this->assertSame('Sina Sängerin', $c->displayName($this->singer, $this->admin));
    }

    public function test_share_profile_on_open_conversation_is_refused(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service()->shareProfile($this->start(), $this->singer);
    }

    public function test_blocked_cannot_send_but_the_other_side_can(): void
    {
        $c = $this->start();
        $this->service()->block($c, $this->admin);

        try {
            $this->service()->reply($c, $this->admin, 'Noch eine');
            $this->fail('Expected AuthorizationException');
        } catch (AuthorizationException) {
        }
        $this->assertSame(1, $c->messages()->count());

        // The block is the blocker's own: the singer still writes, the colleague too.
        $this->service()->reply($c, $this->singer, 'Antwort');
        $this->service()->reply($c, $this->member, 'Hallo');
        $this->assertSame(3, $c->messages()->count());
    }

    public function test_archive_is_per_person(): void
    {
        $c = $this->start();
        $this->service()->archive($c, $this->singer);

        $this->assertTrue($c->participantOf($this->singer)->archived);
        $this->assertFalse($c->participantOf($this->admin)->archived);
    }

    public function test_hidden_messages_drop_out_of_the_thread_and_read_marks_only_others(): void
    {
        $c = $this->start();
        $reply = $this->service()->reply($c, $this->admin, 'Wir melden uns');
        $reply->update(['hidden' => true]);

        $this->assertCount(1, $this->service()->thread($c, $this->singer));

        $this->service()->markRead($c, $this->admin);
        $this->assertNotNull(Message::first()->read_at);
        $this->assertNull(Message::find($reply->id)->read_at);
    }

    public function test_message_sent_fires_per_message(): void
    {
        Event::fake([MessageSent::class]);
        $c = $this->start();
        $this->service()->reply($c, $this->admin, 'Ja');

        Event::assertDispatchedTimes(MessageSent::class, 2);
    }

    public function test_account_erasure_removes_or_anonymises_messages(): void
    {
        // The real accounts services, as the account deletion calls them: via the tagged eraser registry.
        $this->assertInstanceOf(MessagingContributor::class, app(ErasureRegistry::class)->available()['messaging']);
        $erasure = app(PersonalDataErasure::class);

        $own = $this->start();
        $other = $this->service()->start($this->member, $this->makeTeam($this->makeUser('x@example.test')), 'Frage an ein anderes Team');
        $this->service()->reply($own, $this->admin, 'Antwort vom Admin');

        // The admin's account goes: their reply stays unattributed, the singer's conversation survives.
        $result = $erasure->erase($this->admin)['messaging'];
        $this->assertSame(1, $result->anonymized['messages']);
        $this->assertSame(0, Message::where('sender_user_id', $this->admin->id())->count());
        $this->assertSame('', Message::where('conversation_id', $own->id)->orderByDesc('id')->first()->body);
        $this->assertNull($own->participantOf($this->admin));

        // The singer's account goes: the conversation they started is deleted with its messages.
        $erasure->erase($this->singer);
        $this->assertNull(Conversation::find($own->id));
        $this->assertSame(0, Message::where('conversation_id', $own->id)->count());
        $this->assertNotNull(Conversation::find($other->id));
    }

    public function test_export_lists_own_messages(): void
    {
        $this->start();

        // The real export collects through the tagged contributor registry.
        $export = app(PersonalDataExport::class);
        $mine = $export->collect($this->singer);
        $this->assertSame([], $mine['manifest']['failed']);
        $this->assertContains('messaging', $mine['manifest']['sections']);
        $this->assertCount(1, $mine['sections']['messaging']['messages']);
        $this->assertCount(0, $export->collect($this->stranger)['sections']['messaging']['messages']);
    }
}
