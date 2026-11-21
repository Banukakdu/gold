<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AG-094 in-app notifications (docs/specs/notifications.md section 5 "Storage"). Laravel's database notifications
 * table (as `php artisan make:notifications-table` makes it: uuid id, type, notifiable morph, data text, read_at,
 * timestamps; no extra index, R9) and users.approval_request_email (default on, Q3). ADD only, resumable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasColumn('users', 'approval_request_email')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('approval_request_email')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'approval_request_email')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('approval_request_email'));
        }
        Schema::dropIfExists('notifications');
    }
};
