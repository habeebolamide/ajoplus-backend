<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_groups', function (Blueprint $table): void {
            $table->boolean('requires_approval')->default(true);
        });

        Schema::create('group_join_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('savings_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->timestamps();
            $table->unique(['group_id', 'user_id']);
            $table->index(['group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_join_requests');
        Schema::table('savings_groups', function (Blueprint $table): void {
            $table->dropColumn('requires_approval');
        });
    }
};
