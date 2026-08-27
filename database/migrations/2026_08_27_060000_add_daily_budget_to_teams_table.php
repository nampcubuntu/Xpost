<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ceiling on what one team can spend at X in a day.
 *
 * Posting is pay-per-use, and nothing stopped a team before the money ran out:
 * a recurring schedule pointed at ten thousand accounts spends whatever it is
 * given, overnight, and the first anyone knows is the invoice. The out-of-credit
 * halt catches it a step too late -- by then it has already been spent, and it
 * stops every team rather than the one that overspent.
 *
 * Null means no ceiling, which is the right default: a limit nobody chose,
 * silently stopping a campaign, would be worse than none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->decimal('daily_budget_usd', 10, 2)->nullable()->after('account_quota');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn('daily_budget_usd');
        });
    }
};
