<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_groups', function (Blueprint $table): void {
            $table->string('ajo_type', 16)->default('rotating');
            $table->unsignedSmallInteger('savings_cycles')->nullable();
        });
        Schema::table('payouts', function (Blueprint $table): void {
            $table->unique(['group_id', 'cycle', 'member_id']);
        });
        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropUnique(['group_id', 'cycle']);
        });
    }

    public function down(): void
    {
        // Multiple Savings Ajo repayments cannot fit the old unique index.
        if (DB::table('payouts')->select('group_id', 'cycle')->groupBy('group_id', 'cycle')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot roll back while Savings Ajo repayments exist.');
        }
        Schema::table('payouts', function (Blueprint $table): void {
            $table->unique(['group_id', 'cycle']);
        });
        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropUnique(['group_id', 'cycle', 'member_id']);
        });
        Schema::table('savings_groups', function (Blueprint $table): void {
            $table->dropColumn(['ajo_type', 'savings_cycles']);
        });
    }
};
