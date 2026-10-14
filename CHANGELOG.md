# Changelog

## Unreleased

### Security

- A member who has left the recipient team can no longer read or write the team's conversations; before, a participant row from their time in the team kept the access. The initiator is unaffected.

### Changed (breaking for direct database access)

- Message texts are stored encrypted (`encrypted` cast on `Message::$body`). The encryption depends on `APP_KEY`; a key change is only safe with `APP_PREVIOUS_KEYS`, otherwise the messages are lost. Two new migrations widen `messages.body` to `longText` and encrypt the existing plain rows (idempotent, in chunks, reversible). Raw `DB::table('messages')` reads now return ciphertext. Erasing an account clears the body through the model so the empty text is stored encrypted too.

### Added

- Control Panel: **Messages** listing and detail (metadata only, never texts or names of anonymous initiators), moderation (hide and unhide a message of a reported conversation with a reason, unblock a participant), a dashboard widget, and the permissions `view messaging` and `manage messaging`. New migration for `messages.hidden_reason` and `hidden_at`; `ConversationService::hideMessage()`, `unhideMessage()`, `unblock()`.

- `ConversationService::MAX_BODY_LENGTH` (10000 characters) and `MessagingException::bodyTooLong()`.
- `Conversation::initiatorKey()` and an optional `$initiatorKey` argument on `Conversation::displayName()`.
- `LICENSE.md`, `.gitattributes`, Larastan (level 5), a CI matrix over PHP, Laravel and `--prefer-lowest`, and a MySQL run through `phpunit.mysql.xml`.

### Changed

- Erasing the recipient's account clears `conversations.recipient_user_id`.
- `ConversationService::thread()` names each sender once and looks the initiator up once, instead of querying per message.
- The first write of a team member without a participant row uses `firstOrCreate`, so two simultaneous first writes no longer collide on the unique key.
- `extra.statamic` carries `slug` and the developer link.
