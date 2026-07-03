<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('public_email')->nullable()->unique()->after('email');
            $table->timestamp('public_email_synced_at')->nullable()->after('public_email');
        });

        $domain = strtolower((string) config('external_mail.public_domain', 'u-mail.local'));
        DB::table('users')->orderBy('id')->each(function ($user) use ($domain) {
            $base = Str::of(Str::ascii($user->name))
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/', '.')
                ->trim('.')
                ->value() ?: Str::before(strtolower($user->email), '@');
            $candidate = $base.'@'.$domain;
            $suffix = 2;
            while (DB::table('users')->where('public_email', $candidate)->exists()) {
                $candidate = $base.$suffix.'@'.$domain;
                $suffix++;
            }
            DB::table('users')->where('id', $user->id)->update(['public_email' => $candidate]);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('sender_id')->nullable()->change();
            $table->string('sender_email')->nullable()->after('sender_id');
            $table->string('sender_name')->nullable()->after('sender_email');
            $table->string('source')->default('internal')->index()->after('sender_name');
            $table->string('internet_message_id')->nullable()->unique()->after('source');
            $table->string('in_reply_to')->nullable()->index()->after('internet_message_id');
        });

        Schema::create('external_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('incoming_imports', function (Blueprint $table) {
            $table->id();
            $table->string('internet_message_id')->unique();
            $table->string('sender_email');
            $table->string('sender_name')->nullable();
            $table->json('recipient_addresses');
            $table->string('subject');
            $table->string('status')->index();
            $table->json('routed_user_ids')->nullable();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->string('raw_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_imports');
        Schema::dropIfExists('external_deliveries');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['internet_message_id']);
            $table->dropIndex(['source']);
            $table->dropIndex(['in_reply_to']);
            $table->dropColumn(['sender_email', 'sender_name', 'source', 'internet_message_id', 'in_reply_to']);
            $table->foreignId('sender_id')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['public_email']);
            $table->dropColumn(['public_email', 'public_email_synced_at']);
        });
    }
};
