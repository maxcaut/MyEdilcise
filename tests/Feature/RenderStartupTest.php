<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RenderStartupTest extends TestCase
{
    /** @param array<string, string> $environment
     * @param  callable(Process, string): void  $assertions
     */
    private function withContainerStartup(array $environment, callable $assertions): void
    {
        $directory = sys_get_temp_dir().'/edilcise-startup-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory.'/bin', 0755, true);
        $dockerfile = File::get(base_path('Dockerfile'));
        preg_match("/<<'ENTRYPOINT'\n(.*?)\nENTRYPOINT/s", $dockerfile, $matches);
        File::put($directory.'/start-app', $matches[1]);
        $php = <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$STARTUP_EVENTS"
case "$2" in
    telegram:setup) exit "${SETUP_EXIT_CODE:-0}" ;;
    queue:work)
        if [ "${WORKER_EXIT:-0}" = 1 ]; then exit 1; fi
        echo 'worker-ready'
        exec /bin/sleep 60 ;;
    schedule:work)
        echo 'scheduler-ready'
        exec /bin/sleep 60 ;;
esac
SH;
        File::put($directory.'/bin/php', $php);
        File::put($directory.'/bin/chown', "#!/bin/sh\nexit 0\n");
        File::put($directory.'/bin/apache2-foreground', "#!/bin/sh\necho web-ready\nexec /bin/sleep 60\n");
        foreach (['php', 'chown', 'apache2-foreground'] as $command) {
            chmod($directory.'/bin/'.$command, 0755);
        }
        $process = new Process(['sh', $directory.'/start-app'], $directory, $environment + [
            'PATH' => $directory.'/bin:'.getenv('PATH'),
            'STARTUP_EVENTS' => $directory.'/events', 'APP_KEY' => 'test-key',
            'TELEGRAM_BOT_TOKEN' => '', 'TELEGRAM_BOT_USERNAME' => '', 'TELEGRAM_WEBHOOK_SECRET' => '',
        ], timeout: 10);
        try {
            $process->start();
            $assertions($process, $directory.'/events');
        } finally {
            if ($process->isRunning()) {
                $process->signal(15);
                $process->wait();
            }
            File::deleteDirectory($directory);
        }
    }

    /** @return array<string, string> */
    private function telegramEnvironment(): array
    {
        return ['TELEGRAM_BOT_TOKEN' => 'test-token', 'TELEGRAM_BOT_USERNAME' => 'test_bot', 'TELEGRAM_WEBHOOK_SECRET' => 'test-secret'];
    }

    public function test_web_service_starts_without_telegram_credentials(): void
    {
        $this->withContainerStartup([], function (Process $process, string $events): void {
            $this->assertTrue($process->waitUntil(fn (): bool => str_contains($process->getOutput(), 'web-ready')));
            $this->assertStringNotContainsString('telegram:setup', File::get($events));
            $this->assertStringNotContainsString('queue:work', File::get($events));
            $this->assertTrue($process->isRunning());
        });
    }

    #[TestWith(['0'])]
    #[TestWith(['1'])]
    public function test_configured_bot_starts_worker_and_scheduler_and_setup_failure_keeps_web_service_available(string $setupExitCode): void
    {
        $this->withContainerStartup($this->telegramEnvironment() + ['SETUP_EXIT_CODE' => $setupExitCode], function (Process $process, string $events): void {
            $this->assertTrue($process->waitUntil(fn (): bool => str_contains($process->getOutput(), 'web-ready')
                && str_contains($process->getOutput(), 'worker-ready') && str_contains($process->getOutput(), 'scheduler-ready')));
            $commands = File::get($events);
            $this->assertStringContainsString('artisan migrate --force --no-interaction', $commands);
            $this->assertStringContainsString('artisan telegram:setup --no-interaction', $commands);
            $this->assertStringContainsString('artisan queue:work database --queue=telegram --timeout=80 --sleep=1 --no-interaction', $commands);
            $this->assertStringContainsString('artisan schedule:work --no-interaction', $commands);
            $process->signal(15);
            $this->assertSame(0, $process->wait());
        });
    }

    public function test_container_stops_with_failure_if_worker_exits(): void
    {
        $this->withContainerStartup($this->telegramEnvironment() + ['WORKER_EXIT' => '1'], function (Process $process): void {
            $this->assertSame(1, $process->wait());
            $this->assertStringContainsString('worker terminato', $process->getErrorOutput());
        });
    }
}
