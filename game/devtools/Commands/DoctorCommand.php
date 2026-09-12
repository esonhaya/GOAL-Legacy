<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Devtools\Diagnostics\CoreFoundationCheck;
use Throwable;
use Tools\Doctor\Checks\PhpRuntimeCheck;
use Tools\Doctor\Engine\CheckRunner;
use Tools\Doctor\Engine\DoctorExitCode;
use Tools\Doctor\DTO\DoctorResult;
use Tools\Doctor\Output\ConsoleRenderer;
use Tools\Doctor\Registry\CheckRegistry;

final class DoctorCommand implements CommandInterface
{
    /**
     * @param array<int, \Tools\Doctor\Contracts\CheckInterface>|null $checks
     */
    public function __construct(
        private readonly CoreServices $services,
        private readonly string $projectRoot,
        private readonly ?array $checks = null,
    ) {
    }

    public function name(): string { return 'doctor'; }

    public function description(): string { return 'Check the Core runtime environment.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        try {
            $registry = new CheckRegistry();
            $registry->fromChecks($this->checks ?? [
                new PhpRuntimeCheck(),
                new CoreFoundationCheck($this->services, $this->projectRoot),
            ]);
            $result = (new CheckRunner())->run($registry->all(), 'DOCTOR');

            ob_start();
            try {
                (new ConsoleRenderer())->render($result);
                $rendered = ob_get_clean();
            } catch (Throwable $exception) {
                ob_end_clean();
                throw $exception;
            }
            $output->write((string) $rendered);

            return $this->exitCode($result);
        } catch (Throwable $exception) {
            $output->error(sprintf('Doctor failed: %s', $exception->getMessage()));

            return DoctorExitCode::forExecutionFailure($exception);
        }
    }

    private function exitCode(DoctorResult $result): int
    {
        if ($result->failCount('PROJECT') > 0 || $result->failCount('DOCTOR') > 0) {
            return 1;
        }

        return DoctorExitCode::forResult($result);
    }
}
