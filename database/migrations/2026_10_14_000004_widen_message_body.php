<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // The body is stored encrypted. The ciphertext is about twice as long as the text, and a
            // MySQL `text` stops at 65,535 bytes, which 10,000 four-byte characters would exceed.
            $table->longText('body')->change();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->text('body')->change();
        });
    }
};
