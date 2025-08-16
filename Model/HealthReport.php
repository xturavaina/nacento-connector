<?php
declare(strict_types=1);

namespace Nacento\Connector\Model;

final class HealthReport
{
    /** @var array<int,array{name:string,status:string,duration_ms:float,details:array,error:?string}> */
    private array $checks = [];

    /** @var array<string,mixed> */
    private array $env = [];

    public function addEnv(string $key, mixed $value): void
    {
        $this->env[$key] = $value;
    }

    /** @param array<string,mixed> $details */
    public function addCheck(string $name, string $status, float $durationMs, array $details = [], ?string $error = null): void
    {
        $this->checks[] = [
            'name' => $name,
            'status' => $status, // ok|fail|skipped
            'duration_ms' => $durationMs,
            'details' => $details,
            'error' => $error,
        ];
    }

    /** @return array{name:string,status:string,duration_ms:float,details:array,error:?string}[] */
    public function getChecks(): array { return $this->checks; }

    /** @return array<string,mixed> */
    public function getEnv(): array { return $this->env; }

    public function hasFailures(): bool
    {
        foreach ($this->checks as $c) if ($c['status'] === 'fail') return true;
        return false;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'env' => $this->env,
            'checks' => $this->checks,
            'summary' => [
                'total' => count($this->checks),
                'ok' => count(array_filter($this->checks, fn($c) => $c['status'] === 'ok')),
                'fail' => count(array_filter($this->checks, fn($c) => $c['status'] === 'fail')),
                'skipped' => count(array_filter($this->checks, fn($c) => $c['status'] === 'skipped')),
            ],
        ];
    }
}
