# Messaging for Statamic

Conversations between a person and a team or another person (`goldnead/statamic-teams`), with anonymous requests, profile release and blocking. No routes and no UI: a site builds those on `ConversationService`.

## Requirements

- PHP 8.2 or newer (Laravel 13 needs 8.3)
- Statamic 6, Laravel 12.40 or 13
- `goldnead/statamic-teams`, which supplies teams and their members

## Installation

```bash
composer require goldnead/statamic-messaging
php artisan migrate
```

The service provider is discovered automatically; the migrations load from the package, there is nothing to publish. The package is not on Packagist yet, and its `goldnead/statamic-teams` constraint points at a branch, so a site has to declare the `repositories` entries for both until they are released.

## Usage

Everything goes through `ConversationService`, resolved from the container. Every call is authorised by `ConversationPolicy`; an unauthorised call throws `Illuminate\Auth\Access\AuthorizationException`.

```php
use Goldnead\Messaging\Services\ConversationService;

$conversations = app(ConversationService::class);

// A person writes to a team, optionally anonymous, optionally about something (type, id).
$conversation = $conversations->start($singer, $team, 'Hallo, ich suche einen Chor.', anonymous: true, subject: ['choir', 7]);

$conversations->reply($conversation, $teamMember, 'Komm gern zur Probe.');
$conversations->shareProfile($conversation, $singer); // the anonymous side reveals itself
$conversations->block($conversation, $teamMember);    // only for the blocking person
$conversations->archive($conversation, $singer);      // per person

$thread = $conversations->thread($conversation, $viewer); // what a page or a mail renders
$conversations->markRead($conversation, $viewer);
```

Listen to `Goldnead\Messaging\Events\MessageSent` to notify the other side.

## Model

- `Conversation`: optional subject (`subject_type`, `subject_id`), `recipient_team_id` or `recipient_user_id` (exactly one), `anonymous`, `profile_shared`.
- `ConversationParticipant`: role `initiator` or `member`, and `archived`, `blocked`, `flagged` per person.
- `Message`: sender, body, `read_at`, `hidden`.

## Rules

- `start()` adds the initiator and every other team member. A team with nobody else cannot receive a conversation.
- Read and write: the initiator and the recipient (the person, or the members of the recipient team as they are now). A member who has left the team loses the conversation. Admins of other teams get nothing (`ConversationPolicy`).
- A message is plain text, tags are stripped, and it holds at most `ConversationService::MAX_BODY_LENGTH` (10000) characters. Empty or longer text throws `MessagingException`.
- An anonymous side shows as `Anonyme:r Interessent:in` until it calls `shareProfile()`. Only the initiator can.
- `block()` stops the blocking person from sending. It changes nothing for the others.
- Render with `ConversationService::thread()` and `Conversation::displayName()`, never from the raw rows.
- `MessageSent` fires once per message, after commit. A listener for anonymous conversations must not put the sender's name or the text in a mail.

## Person as recipient

`start($initiator, null, $body, $anonymous, $subject, recipientUser: $person)` opens a conversation with one person instead of a team. Exactly one of team and person must be given, otherwise `MessagingException`. The same rules apply: only the two participants read and reply, nobody else, and an anonymous initiator stays masked. If the recipient's account is erased, their messages lose sender and text, the conversation loses the recipient's key, and the other side still reads the thread with the sender shown as `Konto gelöscht`. Existing installs run the new migration to get `recipient_user_id`.

## Personal data

With `goldnead/statamic-accounts` installed, `MessagingContributor` is tagged for export and erasure. Erasing an account deletes the conversations it started. Messages it wrote elsewhere stay, without sender and text.

## Encrypted storage

Message texts are stored encrypted (`messages.body`, Laravel's `encrypted` cast), so they are unreadable for anyone with database access and in backups. The encryption depends on `APP_KEY`. A key change is only safe with `APP_PREVIOUS_KEYS`; otherwise the stored messages are lost. Because the database cannot read the text, never filter, search or sort on `body` in SQL: load the model and work on the decrypted attribute. When you read or copy rows with `DB::table('messages')`, you get ciphertext; use the `Message` model. A bulk `Message::query()->update(['body' => ...])` does not apply the cast either, update through the model. Two migrations upgrade an existing install: one widens the column to `longText`, one encrypts the plain rows that are still there (safe to run twice, `migrate:rollback` decrypts again).

## Control Panel

A moderator's view under **Tools > Messages** (`Nachrichten` in German). It shows the shape of the conversations and never their content: **no message text, no participant name or key, and no free-text subject id** appear on any screen, in any response or in the widget. The initiator of an anonymous conversation is only ever "Initiator".

- **Listing**: id, created, last activity, recipient (the team's name, or "Person"), anonymous, message count and the flags flagged, blocked and has hidden messages. Filters: anonymous, flagged, blocked, hidden messages, created (date range). There is no search, because everything a search could match is a text or a name.
- **Detail**: the metadata, a timeline (message sent, which side, hidden or not, with the moderator's reason) and the participants by side with their flags.
- **Moderation** (needs `manage messaging`): hide a message with a reason, show it again, unblock a participant. A message can only be hidden once a participant has flagged the conversation (`ConversationParticipant::$flagged`); a hidden message drops out of `ConversationService::thread()`. The same rules are on `ConversationService::hideMessage()`, `unhideMessage()` and `unblock()`, which skip the policy because the moderator is not a party to the conversation. Setting `flagged` is up to your site.
- **Dashboard widget** `messaging`: conversations and messages of the last 7 and 30 days, and the number of flagged conversations.

Permissions: `view messaging` for everything above, `manage messaging` (a child of it) for the actions. Run `php artisan migrate` once: moderation adds `hidden_reason` and `hidden_at` to `messages`.

The compiled assets are committed (`resources/dist/build`); after changing `resources/js` or `resources/css`, run `composer install && npm ci && npm run build`. CI fails on a stale build.

## Tests

```bash
composer install
vendor/bin/phpunit                       # SQLite, in memory
vendor/bin/phpunit -c phpunit.mysql.xml  # the same suite on MySQL (DB_* variables)
composer lint                            # Pint
composer analyse                         # Larastan, level 5
```

## License

Proprietary, see `LICENSE.md`.
