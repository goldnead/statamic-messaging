<?php

namespace Goldnead\Messaging\Exceptions;

use RuntimeException;

class MessagingException extends RuntimeException
{
    public static function emptyBody(): self
    {
        return new self('A message needs a text.');
    }

    public static function noRecipient(): self
    {
        return new self('A conversation needs at least two parties; the team has nobody else to receive it.');
    }
}
