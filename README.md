# Messaging for Statamic

Conversations between a person and a team or another person (`goldnead/statamic-teams`), with anonymous requests, profile release and blocking. No routes and no UI: a site builds those on `ConversationService`.

## Model

- `Conversation`: optional subject (`subject_type`, `subject_id`), `recipient_team_id` or `recipient_user_id` (exactly one), `anonymous`, `profile_shared`.
- `ConversationParticipant`: role `initiator` or `member`, and `archived`, `blocked`, `flagged` per person.
- `Message`: sender, body, `read_at`, `hidden`.

## Rules

- `start()` adds the initiator and every other team member. A team with nobody else cannot receive a conversation.
- Read and write: participants and members of the recipient team only. Admins of other teams get nothing (`ConversationPolicy`).
- An anonymous side shows as `Anonyme:r Interessent:in` until it calls `shareProfile()`. Only the initiator can.
- `block()` stops the blocking person from sending. It changes nothing for the others.
- Render with `ConversationService::thread()` and `Conversation::displayName()`, never from the raw rows.
- `MessageSent` fires once per message, after commit. A listener for anonymous conversations must not put the sender's name or the text in a mail.

## Person as recipient

`start($initiator, null, $body, $anonymous, $subject, recipientUser: $person)` opens a conversation with one person instead of a team. Exactly one of team and person must be given, otherwise `MessagingException`. The same rules apply: only the two participants read and reply, nobody else, and an anonymous initiator stays masked. If the recipient's account is erased, their messages lose sender and text, and the other side still reads the thread with the sender shown as `Konto gelöscht`. Existing installs run the new migration to get `recipient_user_id`.

## Personal data

With `goldnead/statamic-accounts` installed, `MessagingContributor` is tagged for export and erasure. Erasing an account deletes the conversations it started. Messages it wrote elsewhere stay, without sender and text.

## Tests

```bash
composer install
vendor/bin/phpunit
vendor/bin/pint --test
```
