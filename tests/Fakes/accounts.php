<?php

// Stand-ins for goldnead/statamic-accounts (a suggest, not a dependency).

namespace Goldnead\Accounts\Contracts {
    use Goldnead\Accounts\PersonalData\ErasureResult;
    use Statamic\Auth\User;

    interface ContributesPersonalData
    {
        public function key(): string;

        public function label(): string;

        public function available(): bool;

        public function collect(User $user): array;
    }

    interface ErasesPersonalData
    {
        public function key(): string;

        public function label(): string;

        public function available(): bool;

        public function blockers(User $user, string $audience = 'customer'): array;

        public function erase(User $user): ErasureResult;
    }
}

namespace Goldnead\Accounts\PersonalData {
    final class ErasureResult
    {
        public function __construct(
            public readonly string $key,
            public readonly array $deleted = [],
            public readonly array $anonymized = [],
            public readonly array $retained = [],
            public readonly ?string $note = null,
        ) {}
    }
}
