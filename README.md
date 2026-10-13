# Messaging for Statamic

Conversations between a person and a team (`goldnead/statamic-teams`), with anonymous requests, profile release and blocking. No routes and no UI: a site builds those on `ConversationService`.

## Model

- `Conversation`: optional subject (`subject_type`, `subject_id`), `recipient_team_id`, `anonymous`, `profile_shared`.
- `ConversationParticipant`: role `initiator` or `member`, and `archived`, `blocked`, `flagged` per person.
- `Message`: sender, body, `read_at`, `hidden`.

## Rules

- `start()` adds the initiator and every other team member. A team with nobody else cannot receive a conversation.
- Read and write: participants and members of the recipient team only. Admins of other teams get nothing (`ConversationPolicy`).
- An anonymous side shows as `Anonyme:r Interessent:in` until it calls `shareProfile()`. Only the initiator can.
- `block()` stops the blocking person from sending. It changes nothing for the others.
- Render with `ConversationService::thread()` and `Conversation::displayName()`, never from the raw rows.
- `MessageSent` fires once per message, after commit. A listener for anonymous conversations must not put the sender's name or the text in a mail.

## Personal data

With `goldnead/statamic-accounts` installed, `MessagingContributor` is tagged for export and erasure. Erasing an account deletes the conversations it started. Messages it wrote elsewhere stay, without sender and text.

## Tests

```bash
composer install
vendor/bin/phpunit
vendor/bin/pint --test
```
