<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_recipients', function (Blueprint $table) {
            $table->string('email')->nullable()->after('user_id');
            $table->string('name')->nullable()->after('email');
        });

        DB::table('message_recipients')->orderBy('id')->each(function ($recipient) {
            $user = DB::table('users')->where('id', $recipient->user_id)->first();
            if ($user) {
                DB::table('message_recipients')->where('id', $recipient->id)->update([
                    'email' => strtolower($user->email),
                    'name' => $user->name,
                ]);
            }
        });

        Schema::table('message_recipients', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->unique(['message_id', 'email', 'type'], 'message_recipient_email_type_unique');
            $table->index(['email', 'type']);
        });
    }

    public function down(): void
    {
        DB::table('message_recipients')->whereNull('user_id')->delete();

        Schema::table('message_recipients', function (Blueprint $table) {
            $table->dropUnique('message_recipient_email_type_unique');
            $table->dropIndex(['email', 'type']);
            $table->foreignId('user_id')->nullable(false)->change();
            $table->dropColumn(['email', 'name']);
        });
    }
};
