<?php

namespace hexa_package_wptoolkit\Support;

use Symfony\Component\Process\Process;

class LocalShellConnection
{
    protected int $timeout = 30;

    public function __construct(
        protected ?string $workingDirectory = null
    ) {}

    public function exec(string $command): string
    {
        $process = Process::fromShellCommandline(
            $command,
            $this->workingDirectory ?: base_path()
        );
        $process->setTimeout($this->timeout);
        $process->run();

        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();

        if ($errorOutput !== '') {
            $output .= ($output !== '' && !str_ends_with($output, "\n") ? "\n" : '') . $errorOutput;
        }

        return $output;
    }

    public function setTimeout(int $seconds): void
    {
        $this->timeout = max(1, $seconds);
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function disconnect(): void
    {
        // Local shell execution is stateless.
    }
}
