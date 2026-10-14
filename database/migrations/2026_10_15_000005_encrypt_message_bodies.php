<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Encrypts every body that is still plain text. A row that already decrypts is skipped, so the
     * migration can run twice. A plain text that happens to be a valid payload of the current key
     * cannot occur in practice.
     */
    public function up(): void
    {
        $this->each(function (object $row): ?string {
            return $this->decrypts((string) $row->body) ? null : Crypt::encryptString((string) $row->body);
        });
    }

    /** Writes the plain text back. Rows that do not decrypt are left as they are. */
    public function down(): void
    {
        $this->each(function (object $row): ?string {
            try {
                return Crypt::decryptString((string) $row->body);
            } catch (DecryptException) {
                return null;
            }
        });
    }

    /** @param  callable(object): ?string  $convert  the new body, or null to leave the row */
    private function each(callable $convert): void
    {
        DB::table('messages')->select(['id', 'body'])->chunkById(200, function ($rows) use ($convert) {
            foreach ($rows as $row) {
                $new = $convert($row);

                if ($new !== null) {
                    DB::table('messages')->where('id', $row->id)->update(['body' => $new]);
                }
            }
        });
    }

    private function decrypts(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
