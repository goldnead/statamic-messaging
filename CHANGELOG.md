# Changelog

## 0.1.1 — 2026-10-15

### Fixed

- `composer.json` required `goldnead/statamic-teams` as a dev branch and set `minimum-stability` to `dev`, so 0.1.0 could not be installed from Packagist. It now requires `^0.4` and `stable`.

## 0.1.0 — 2026-10-15

### Security

- A member who has left the recipient team can no longer read or write the team's conversations; before, a participant row from their time in the team kept the access. The initiator is unaffected.

### Changed (breaking for direct database access)

- Message texts are stored encrypted (`encrypted` cast on `Message::$body`). The encryption depends on `APP_KEY`; a key change is only safe with `APP_PREVIOUS_KEYS`, otherwise the messages are lost. Two new migrations widen `messages.body` to `longText` and encrypt the existing plain rows (idempotent, in chunks, reversible). Raw `DB::table('messages')` reads now return ciphertext. Erasing an account clears the body through the model so the empty text is stored encrypted too.

### Added

- Realtime messaging over websockets (Laravel Reverb, or Pusher protocol), on as soon as `broadcasting.default` is a `reverb` or `pusher` connection and silently off otherwise. `MessageBroadcast` on private channels `messaging.conversation.{id}` and `messaging.user.{key}` for sent, hidden and restored messages, carrying two ids and no text or name; the browser fetches the message from `GET /messaging/realtime/{conversation}/messages/{message}`. Channel authorisation is registered by the addon (participants who may still read and have not blocked). New Antlers tag `{{ messaging:realtime }}`, prebuilt script served at `/messaging/realtime.js` (`resources/dist/realtime`), `ConversationService::present()`, `config/messaging.php`. See "Realtime in 3 steps" in the README.
- Control Panel: **Messages** listing and detail (metadata only, never texts or names of anonymous initiators), moderation (hide and unhide a message of a reported conversation with a reason, unblock a participant), a dashboard widget, and the permissions `view messaging` and `manage messaging`. New migration for `messages.hidden_reason` and `hidden_at`; `ConversationService::hideMessage()`, `unhideMessage()`, `unblock()`.

- `ConversationService::MAX_BODY_LENGTH` (10000 characters) and `MessagingException::bodyTooLong()`.
- `Conversation::initiatorKey()` and an optional `$initiatorKey` argument on `Conversation::displayName()`.
- `LICENSE.md`, `.gitattributes`, Larastan (level 5), a CI matrix over PHP, Laravel and `--prefer-lowest`, and a MySQL run through `phpunit.mysql.xml`.

### Changed

- Erasing the recipient's account clears `conversations.recipient_user_id`.
- `ConversationService::thread()` names each sender once and looks the initiator up once, instead of querying per message.
- The first write of a team member without a participant row uses `firstOrCreate`, so two simultaneous first writes no longer collide on the unique key.
- `extra.statamic` carries `slug` and the developer link.
