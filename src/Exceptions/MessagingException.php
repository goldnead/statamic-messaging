<?php

namespace Goldnead\Messaging\Exceptions;

use RuntimeException;

class MessagingException extends RuntimeException
{
    public static function emptyBody(): self
    {
        return new self('A message needs a text.');
    }

    public static function bodyTooLong(int $max): self
    {
        return new self("A message is limited to {$max} characters.");
    }

    public static function ambiguousRecipient(): self
    {
        return new self('A conversation needs exactly one recipient: a team or a person.');
    }

    public static function notReported(): self
    {
        return new self('Only a message in a reported conversation can be hidden.');
    }

    public static function invalidReason(int $max): self
    {
        return new self("A reason is required and limited to {$max} characters.");
    }

    public static function noRecipient(): self
    {
        return new self('A conversation needs at least two parties; the team has nobody else to receive it.');
    }
}
