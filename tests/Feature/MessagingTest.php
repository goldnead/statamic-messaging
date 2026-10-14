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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

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

    public function test_person_recipient_reads_and_replies_third_party_does_not(): void
    {
        $c = $this->service()->start($this->singer, null, 'Hallo Chorleitung', false, ['choir_leader', 3], $this->member);

        $this->assertNull($c->recipient_team_id);
        $this->assertSame($this->member->id(), $c->recipient_user_id);
        $this->assertCount(1, $this->service()->thread($c, $this->member));
        $this->service()->reply($c, $this->member, 'Gern!');
        $this->assertCount(2, $this->service()->thread($c, $this->singer));

        // Neither a team admin nor a stranger gets in.
        foreach ([$this->admin, $this->stranger] as $third) {
            try {
                $this->service()->thread($c, $third);
                $this->fail('third party read the conversation');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_person_recipient_keeps_the_anonymous_side_hidden(): void
    {
        $c = $this->service()->start($this->singer, null, 'Anonym gefragt', true, null, $this->member);

        $this->assertSame(Conversation::ANONYMOUS_LABEL, $this->service()->thread($c, $this->member)[0]['sender']);
        $this->assertNotSame(Conversation::ANONYMOUS_LABEL, $this->service()->thread($c, $this->singer)[0]['sender']);
    }

    public function test_exactly_one_recipient_is_required(): void
    {
        foreach ([[$this->team, $this->member], [null, null]] as [$team, $person]) {
            try {
                $this->service()->start($this->singer, $team, 'Hallo', false, null, $person);
                $this->fail('no exception');
            } catch (MessagingException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, Conversation::count());
    }

    public function test_recipient_account_erasure_keeps_conversation_for_the_other_side(): void
    {
        $c = $this->service()->start($this->singer, null, 'Hallo', false, null, $this->member);
        $this->service()->reply($c, $this->member, 'Antwort');

        app(PersonalDataErasure::class)->erase($this->member);

        $thread = $this->service()->thread($c, $this->singer);
        $this->assertCount(2, $thread);
        $this->assertSame(Conversation::DELETED_LABEL, $thread[1]['sender']);
        $this->assertSame('', $thread[1]['body']);
        $this->assertNotNull(Conversation::find($c->id));
    }

    public function test_a_member_who_left_the_team_loses_the_conversation(): void
    {
        $c = $this->start();
        $this->service()->reply($c, $this->member, 'Ich schaue mal');
        app(TeamsManager::class)->removeMember($this->team, $this->member);

        foreach (['view', 'reply', 'block', 'archive'] as $ability) {
            $this->assertFalse($this->member->can($ability, $c) ?? false, $ability);
        }
        $this->expectException(AuthorizationException::class);
        $this->service()->thread($c, $this->member);
    }

    public function test_the_initiator_keeps_the_conversation_when_the_team_is_gone(): void
    {
        $c = $this->start();
        app(TeamsManager::class)->delete($this->team);

        $this->assertCount(1, $this->service()->thread($c->fresh(), $this->singer));
    }

    public function test_a_guest_gets_nothing(): void
    {
        $c = $this->start();

        foreach (['view', 'reply', 'shareProfile', 'block', 'archive'] as $ability) {
            $this->assertFalse(Gate::forUser(null)->allows($ability, $c), $ability);
        }
    }

    public function test_an_overlong_message_is_refused(): void
    {
        $this->expectException(MessagingException::class);
        $this->service()->start($this->singer, $this->team, str_repeat('a', ConversationService::MAX_BODY_LENGTH + 1));
    }

    public function test_a_message_at_the_limit_is_stored(): void
    {
        $c = $this->service()->start($this->singer, $this->team, str_repeat('ä', ConversationService::MAX_BODY_LENGTH));

        $this->assertSame(ConversationService::MAX_BODY_LENGTH, mb_strlen($c->messages()->first()->body));
    }

    public function test_thread_does_not_query_per_message(): void
    {
        $c = $this->start(anonymous: true);
        $this->service()->thread($c, $this->admin); // loads the lazy relations once
        DB::enableQueryLog();
        $this->service()->thread($c, $this->admin);
        $few = count(DB::getQueryLog());

        foreach (range(1, 8) as $i) {
            $this->service()->reply($c, $i % 2 ? $this->admin : $this->singer, "Nachricht $i");
        }
        DB::flushQueryLog();
        $this->service()->thread($c, $this->admin);
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($few, $many, 'the number of queries must not grow with the number of messages');
    }

    public function test_recipient_account_erasure_drops_the_recipient_key_from_the_conversation(): void
    {
        $c = $this->service()->start($this->singer, null, 'Hallo', false, null, $this->member);

        app(PersonalDataErasure::class)->erase($this->member);

        $this->assertNull($c->fresh()->recipient_user_id);
    }

    public function test_message_sent_waits_for_the_commit(): void
    {
        $heard = [];
        Event::listen(MessageSent::class, function (MessageSent $e) use (&$heard) {
            $heard[] = $e->message->id;
        });

        DB::beginTransaction();
        $c = $this->start();
        $this->assertSame([], $heard, 'a listener must not run before the surrounding transaction commits');
        DB::commit();

        $this->assertCount(1, $heard);
        $this->assertSame($c->messages()->first()->id, $heard[0]);
    }

    public function test_the_first_write_of_a_member_without_a_row_creates_exactly_one_row(): void
    {
        $c = $this->start();
        $late = $this->makeUser('late@example.test');
        app(TeamsManager::class)->addMember($this->team, $late);

        $this->service()->block($c, $late);
        $this->service()->archive($c, $late);

        $this->assertSame(1, $c->participants()->where('user_id', $late->id())->count());
    }
}
