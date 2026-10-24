<?php

namespace Webpatser\ResonateUserCap\Tests\Support;

use Webpatser\Resonate\Contracts\Logger;

/**
 * Records what the plugin logs, so a test can assert how often it logged.
 */
class FakeLogger implements Logger
{
    /**
     * Every error message logged.
     *
     * @var list<string>
     */
    public array $errors = [];

    /**
     * Every info entry logged, as "title: message".
     *
     * @var list<string>
     */
    public array $infos = [];

    /**
     * Log an informational message.
     */
    public function info(string $title, ?string $message = null): void
    {
        $this->infos[] = $title.': '.$message;
    }

    /**
     * Log an error message.
     */
    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    /**
     * Log a message sent to the server.
     */
    public function message(string $message): void
    {
        //
    }

    /**
     * Append a new line to the log.
     */
    public function line(int $lines = 1): void
    {
        //
    }
}
