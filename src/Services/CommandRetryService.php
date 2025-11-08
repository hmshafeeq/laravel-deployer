<?php

namespace Shaf\LaravelDeployer\Services;

class CommandRetryService
{
    /**
     * Retry a callback with exponential backoff
     *
     * @param callable $callback The callback to execute
     * @param int $maxRetries Maximum number of retry attempts
     * @param int $delaySeconds Initial delay between retries in seconds
     * @param callable|null $onRetry Callback to execute before each retry
     * @return mixed
     * @throws \Exception
     */
    public function retry(
        callable $callback,
        int $maxRetries = 3,
        int $delaySeconds = 5,
        ?callable $onRetry = null
    ): mixed {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $callback($attempt);
            } catch (\Exception $e) {
                $lastException = $e;

                if ($attempt < $maxRetries) {
                    if ($onRetry) {
                        $onRetry($attempt, $e);
                    }
                    sleep($delaySeconds);
                }
            }
        }

        throw $lastException;
    }

    /**
     * Retry with exponential backoff
     *
     * @param callable $callback The callback to execute
     * @param int $maxRetries Maximum number of retry attempts
     * @param int $initialDelay Initial delay in seconds
     * @param callable|null $onRetry Callback to execute before each retry
     * @return mixed
     */
    public function retryWithBackoff(
        callable $callback,
        int $maxRetries = 3,
        int $initialDelay = 2,
        ?callable $onRetry = null
    ): mixed {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $callback($attempt);
            } catch (\Exception $e) {
                $lastException = $e;

                if ($attempt < $maxRetries) {
                    $delay = $initialDelay * (2 ** ($attempt - 1)); // Exponential backoff: 2, 4, 8, 16...

                    if ($onRetry) {
                        $onRetry($attempt, $delay, $e);
                    }

                    sleep($delay);
                }
            }
        }

        throw $lastException;
    }
}
