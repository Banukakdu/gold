<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_main')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['branch_id', 'user_id']);
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name');
            $table->string('type', 20)->default('cash'); // cash | bank | wallet
            $table->string('bank_name')->nullable();
            $table->string('account_no', 50)->nullable();
            $table->string('currency', 3)->default('LKR');
            $table->boolean('is_main_account')->default(false);
            $table->decimal('current_balance', 15, 2)->default(0); // cache of ledger_entries
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories');
            $table->foreignId('branch_id')->nullable()->constrained(); // null = global
            $table->string('type', 10); // income | expense
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['parent_id', 'branch_id', 'name']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('ref_no', 40)->nullable()->unique();
            $table->foreignId('branch_id')->constrained();
            $table->string('type', 30); // capital|opening_allocation|fund_allocation|fund_return|branch_transfer|internal_transfer|income|expense|reversal
            $table->date('txn_date');
            $table->decimal('amount', 15, 2);
            $table->foreignId('account_id')->nullable()->constrained('accounts');      // income / expense / capital
            $table->foreignId('from_account_id')->nullable()->constrained('accounts'); // transfers
            $table->foreignId('to_account_id')->nullable()->constrained('accounts');   // transfers
            $table->foreignId('related_branch_id')->nullable()->constrained('branches');
            $table->foreignId('category_id')->nullable()->constrained();
            $table->string('payee_payer')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('posted'); // pending|posted|rejected|void|reversed
            $table->foreignId('reversal_of_id')->nullable()->constrained('transactions');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'txn_date']);
            $table->index(['type', 'status']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();
            $table->foreignId('branch_id')->constrained();
            $table->date('entry_date');
            $table->decimal('debit', 15, 2)->default(0);  // money in
            $table->decimal('credit', 15, 2)->default(0); // money out
            $table->timestamps();
            $table->index(['account_id', 'entry_date']);
        });

        Schema::create('period_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained(); // null = all branches
            $table->string('period', 7); // YYYY-MM
            $table->foreignId('locked_by')->constrained('users');
            $table->timestamps();
            $table->unique(['branch_id', 'period']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->foreignId('branch_id')->nullable()->constrained();
            $table->string('event', 50);
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['auditable_type', 'auditable_id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'audit_logs', 'period_locks', 'ledger_entries', 'transactions', 'categories', 'accounts', 'branch_user', 'branches'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
