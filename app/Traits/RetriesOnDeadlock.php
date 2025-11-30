<?php

namespace App\Traits;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

trait RetriesOnDeadlock
{
    /**
     * Execute a callback with deadlock retry logic.
     *
     * @param callable $callback
     * @param int $maxRetries
     * @return mixed
     * @throws \Exception
     */
    protected function retryOnDeadlock(callable $callback, int $maxRetries = 3)
    {
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                return $callback();
            } catch (QueryException $e) {
                // Check if it's a deadlock error (MySQL error code 1213)
                if ($e->getCode() == '40001' || str_contains($e->getMessage(), 'Deadlock')) {
                    $attempt++;

                    if ($attempt >= $maxRetries) {
                        Log::warning('Deadlock retry limit exceeded', [
                            'attempts' => $attempt,
                            'error' => $e->getMessage(),
                        ]);
                        throw $e;
                    }

                    // Exponential backoff: 50ms, 100ms, 200ms
                    $delay = 50 * pow(2, $attempt - 1);
                    usleep($delay * 1000);

                    Log::info('Retrying after deadlock', [
                        'attempt' => $attempt,
                        'delay_ms' => $delay,
                    ]);

                    continue;
                }

                throw $e;
            }
        }

        throw new \Exception('Max retries exceeded');
    }
}
