<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\PaymentReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendPaymentReminders extends Command
{
    protected $signature = 'billing:reminders';

    protected $description = 'Queue tiffin payment reminders that are due in each merchant time zone';

    public function handle(PaymentReminderService $reminders): int
    {
        $count = $reminders->dispatchDue(CarbonImmutable::now('UTC'));
        $this->info("Queued {$count} reminder(s).");

        return self::SUCCESS;
    }
}
