<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->unsignedBigInteger('recipient_team_id')->nullable()->index();
            $table->boolean('anonymous')->default(false);
            $table->boolean('profile_shared')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('user_id')->index();
            $table->string('role')->default('member');
            $table->boolean('archived')->default(false);
            $table->boolean('blocked')->default(false);
            $table->boolean('flagged')->default(false);
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('sender_user_id')->nullable()->index();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->boolean('hidden')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
