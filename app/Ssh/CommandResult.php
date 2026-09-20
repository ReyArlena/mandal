<?php

namespace App\Ssh;

final readonly class CommandResult
{
    public function __construct(
        public string $command,
        public int $exitCode,
        public string $output,
        public string $errorOutput = '',
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SshException when the output is not a JSON object or array
     */
    public function json(): array
    {
        $decoded = json_decode($this->output, associative: true);

        if (! is_array($decoded)) {
            throw new SshException("Expected JSON from [{$this->command}] but got: ".mb_substr(trim($this->output), 0, 200));
        }

        return $decoded;
    }
}
