<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Why a moderator hid the message. Written by the Control Panel,
            // never by a participant, so it is not personal text.
            $table->string('hidden_reason')->nullable();
            $table->timestamp('hidden_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['hidden_reason', 'hidden_at']);
        });
    }
};
