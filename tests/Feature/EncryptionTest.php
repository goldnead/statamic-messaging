<?php

namespace Goldnead\Messaging\Tests\Feature;

use Goldnead\Messaging\Integrations\Accounts\MessagingContributor;
use Goldnead\Messaging\Models\Conversation;
use Goldnead\Messaging\Models\Message;
use Goldnead\Messaging\Services\ConversationService;
use Goldnead\Messaging\Tests\TestCase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EncryptionTest extends TestCase
{
    private const SECRET = 'ZZKLARTEXT-3c9e-nur-fuer-die-beteiligten';

    private function migration(): object
    {
        return require dirname(__DIR__, 2).'/database/migrations/2026_10_15_000005_encrypt_message_bodies.php';
    }

    /** A conversation row plus one message row written behind the model's back, as an old install would hold it. */
    private function plainRow(string $body, ?int $conversationId = null): int
    {
        $conversationId ??= DB::table('conversations')->insertGetId(['created_at' => now(), 'updated_at' => now()]);

        return DB::table('messages')->insertGetId([
            'conversation_id' => $conversationId, 'sender_user_id' => 'u1', 'body' => $body, 'hidden' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_stored_column_is_not_the_plain_text_but_the_model_returns_it(): void
    {
        $singer = $this->makeUser('singer@example.test');
        $admin = $this->makeUser('admin@example.test');
        $team = $this->makeTeam($admin, $this->makeUser('m@example.test'));

        $c = app(ConversationService::class)->start($singer, $team, self::SECRET);
        app(ConversationService::class)->reply($c, $admin, self::SECRET.' Antwort');

        $raw = DB::table('messages')->where('conversation_id', $c->id)->orderBy('id')->pluck('body');
        $this->assertCount(2, $raw);
        foreach ($raw as $stored) {
            $this->assertStringNotContainsString('ZZKLARTEXT', $stored);
            $this->assertStringStartsWith('eyJ', $stored); // base64 of the JSON payload Crypt produces
        }

        $this->assertSame(self::SECRET, Message::where('conversation_id', $c->id)->orderBy('id')->first()->body);
        $this->assertSame(self::SECRET.' Antwort', app(ConversationService::class)->thread($c, $admin)->last()['body']);
    }

    public function test_the_longest_allowed_text_of_four_byte_characters_fits_the_column(): void
    {
        $singer = $this->makeUser('singer@example.test');
        $team = $this->makeTeam($this->makeUser('admin@example.test'));
        $text = str_repeat('😀', ConversationService::MAX_BODY_LENGTH);

        $c = app(ConversationService::class)->start($singer, $team, $text);

        $this->assertSame($text, $c->messages()->first()->body);
    }

    public function test_the_migration_encrypts_existing_plain_rows_and_skips_encrypted_ones(): void
    {
        $plain = $this->plainRow('Alt und offen');
        $empty = $this->plainRow('');
        $already = $this->plainRow(Crypt::encryptString('Schon verschluesselt'));
        $before = DB::table('messages')->where('id', $already)->value('body');

        $this->migration()->up();

        $this->assertNotSame('Alt und offen', DB::table('messages')->where('id', $plain)->value('body'));
        $this->assertSame('Alt und offen', Message::find($plain)->body);
        $this->assertSame('', Message::find($empty)->body);
        $this->assertSame($before, DB::table('messages')->where('id', $already)->value('body'), 'an encrypted row is left alone');
        $this->assertSame('Schon verschluesselt', Message::find($already)->body);

        // Idempotent: a second run changes nothing.
        $snapshot = DB::table('messages')->orderBy('id')->pluck('body', 'id')->all();
        $this->migration()->up();
        $this->assertSame($snapshot, DB::table('messages')->orderBy('id')->pluck('body', 'id')->all());
    }

    public function test_the_migration_works_through_more_than_one_chunk(): void
    {
        $conversation = DB::table('conversations')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
        foreach (range(1, 530) as $i) {
            $this->plainRow("Nachricht $i", $conversation);
        }

        $this->migration()->up();

        $this->assertSame(0, DB::table('messages')->where('body', 'like', 'Nachricht %')->count());
        $this->assertSame('Nachricht 530', Message::where('conversation_id', $conversation)->orderByDesc('id')->first()->body);
    }

    public function test_the_migration_down_decrypts_again(): void
    {
        $id = $this->plainRow('Rueckweg');
        $this->migration()->up();
        $this->assertNotSame('Rueckweg', DB::table('messages')->where('id', $id)->value('body'));

        $this->migration()->down();

        $this->assertSame('Rueckweg', DB::table('messages')->where('id', $id)->value('body'));
        $this->migration()->down(); // plain rows are skipped, not broken
        $this->assertSame('Rueckweg', DB::table('messages')->where('id', $id)->value('body'));
    }

    public function test_erasing_an_account_leaves_a_readable_empty_body(): void
    {
        $singer = $this->makeUser('singer@example.test');
        $admin = $this->makeUser('admin@example.test');
        $team = $this->makeTeam($admin);
        $c = app(ConversationService::class)->start($singer, $team, 'x');
        app(ConversationService::class)->reply($c, $admin, 'Antwort');

        app(MessagingContributor::class)->erase($admin);

        $last = Message::where('conversation_id', $c->id)->orderByDesc('id')->first();
        $this->assertSame('', $last->body);
        $this->assertStringNotContainsString('Antwort', (string) DB::table('messages')->where('id', $last->id)->value('body'));
        $this->assertInstanceOf(Conversation::class, $c->fresh());
    }
}
