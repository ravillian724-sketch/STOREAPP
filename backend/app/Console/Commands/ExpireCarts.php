<?php

namespace App\Console\Commands;

use App\Services\Cart\CartExpirationSweepService;
use Illuminate\Console\Command;

final class ExpireCarts extends Command
{
    protected $signature =
        'carts:expire
        {--batch=100 : Maximum due carts discovered per tenant batch}';

    protected $description =
        'Expire due carts and release their inventory reservations';

    public function handle(
        CartExpirationSweepService $sweep,
    ): int {
        $rawBatch =
            $this->option('batch');

        $batchSize =
            filter_var(
                $rawBatch,
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 1,
                        'max_range' => CartExpirationSweepService::MAX_BATCH_SIZE,
                    ],
                ],
            );

        if ($batchSize === false) {
            $this->error(
                'The --batch option must be an integer between 1 and '.
                CartExpirationSweepService::MAX_BATCH_SIZE.'.'
            );

            return self::FAILURE;
        }

        $expired =
            $sweep->sweep(
                $batchSize
            );

        $this->info(
            sprintf(
                'Expired %d cart(s).',
                $expired,
            )
        );

        return self::SUCCESS;
    }
}
