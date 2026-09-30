<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('contribution_amount_kobo');
            $table->string('frequency', 16);
            $table->unsignedSmallInteger('max_members');
            $table->date('start_date');
            $table->string('invite_code', 16)->unique();
            $table->unsignedSmallInteger('current_cycle')->default(1);
            $table->string('status', 16)->default('forming');
            $table->timestamps();
        });

        Schema::create('group_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('savings_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('payout_position');
            $table->timestamp('joined_at');
            $table->timestamps();
            $table->unique(['group_id', 'user_id']);
            $table->unique(['group_id', 'payout_position']);
        });

        Schema::create('contributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('savings_groups')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('group_members')->cascadeOnDelete();
            $table->unsignedSmallInteger('cycle');
            $table->unsignedBigInteger('amount_kobo');
            $table->string('status', 16)->default('pending');
            $table->string('payment_reference')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['group_id', 'member_id', 'cycle']);
        });

        Schema::create('payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('savings_groups')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('group_members')->restrictOnDelete();
            $table->unsignedSmallInteger('cycle');
            $table->unsignedBigInteger('amount_kobo');
            $table->date('scheduled_for');
            $table->string('status', 16)->default('completed');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['group_id', 'cycle']);
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('savings_groups')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('group_members')->restrictOnDelete();
            $table->string('type', 16);
            $table->unsignedBigInteger('amount_kobo');
            $table->string('status', 16);
            $table->string('reference')->unique();
            $table->timestamps();
            $table->index(['group_id', 'created_at']);
        });

        Schema::create('app_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('message');
            $table->string('type', 32);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('contributions');
        Schema::dropIfExists('group_members');
        Schema::dropIfExists('savings_groups');
    }
};
