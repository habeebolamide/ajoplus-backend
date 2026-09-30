<?php

namespace App\Support;

use App\Models\SavingsGroup;
use Carbon\CarbonImmutable;

class GroupSchedule
{
    public static function dateForCycle(SavingsGroup $group, int $cycle): string
    {
        $start = CarbonImmutable::parse($group->start_date);

        return match ($group->frequency) {
            'daily' => $start->addDays($cycle - 1)->toDateString(),
            'weekly' => $start->addWeeks($cycle - 1)->toDateString(),
            'biweekly' => $start->addWeeks(2 * ($cycle - 1))->toDateString(),
            'monthly' => $start->addMonthsNoOverflow($cycle - 1)->toDateString(),
        };
    }
}
