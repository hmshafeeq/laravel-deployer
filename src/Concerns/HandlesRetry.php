<?php

namespace Shaf\LaravelDeployer\Concerns;

/**
 * Provides retry functionality with configurable attempts and delays.
 *
 * Requires the using class to have:
 * - $this->cmd (CommandService) for output logging
 */
trait HandlesRetry
{
    /**
     * Retry an operation with configurable attempts and delay.
     *
     * @param  callable  $operation  The operation to retry (should return result or throw exception)
     * @param  callable  $isSuccess  Callback to determine if result is successful
     * @param  int  $maxAttempts  Maximum number of attempts
     * @param  int  $delaySeconds  Delay between attempts in seconds
     * @param  string  $operationName  Name of operation for logging
     * @return mixed The successful result
     *
     * @throws \RuntimeException If all attempts fail
     */
    protected function retry(
        callable $operation,
        callable $isSuccess,
        int $maxAttempts = 3,
        int $delaySeconds = 5,
        string $operationName = 'Operation'
    ): mixed {
        $lastException = null;
        $lastResult = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->cmd->info("  → Attempt {$attempt}/{$maxAttempts}: {$operationName}");

                $startTime = microtime(true);
                $result = $operation();
                $duration = (microtime(true) - $startTime) * 1000;

                if ($isSuccess($result)) {
                    $this->cmd->success(sprintf(
                        '%s passed (%dms)',
                        $operationName,
                        (int) $duration
                    ));

                    return $result;
                }

                $lastResult = $result;
                $this->cmd->warning("  ✗ {$operationName} returned unexpected result");

            } catch (\Exception $e) {
                $lastException = $e;
                $this->cmd->warning("  ✗ {$operationName} failed: {$e->getMessage()}");
            }

            if ($attempt < $maxAttempts) {
                $this->cmd->info("  ⏳ Waiting {$delaySeconds}s before retry...");
                sleep($delaySeconds);
            }
        }

        if ($lastException) {
            throw new \RuntimeException(
                "{$operationName} failed after {$maxAttempts} attempts: {$lastException->getMessage()}",
                0,
                $lastException
            );
        }

        throw new \RuntimeException("{$operationName} failed after {$maxAttempts} attempts");
    }
}
