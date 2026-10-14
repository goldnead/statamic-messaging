# Changelog

## Unreleased

### Security

- A member who has left the recipient team can no longer read or write the team's conversations; before, a participant row from their time in the team kept the access. The initiator is unaffected.

### Added

- `ConversationService::MAX_BODY_LENGTH` (10000 characters) and `MessagingException::bodyTooLong()`.
- `Conversation::initiatorKey()` and an optional `$initiatorKey` argument on `Conversation::displayName()`.
- `LICENSE.md`, `.gitattributes`, Larastan (level 5), a CI matrix over PHP, Laravel and `--prefer-lowest`, and a MySQL run through `phpunit.mysql.xml`.

### Changed

- Erasing the recipient's account clears `conversations.recipient_user_id`.
- `ConversationService::thread()` names each sender once and looks the initiator up once, instead of querying per message.
- The first write of a team member without a participant row uses `firstOrCreate`, so two simultaneous first writes no longer collide on the unique key.
- `extra.statamic` carries `slug` and the developer link.
