<?php
/** Typed delivery failure. GPL-3.0-or-later. */
namespace ParcelBackups;

final class TelegramException extends \RuntimeException
{
    public bool $retryable;
    public int $retry_after;

    public function __construct(string $message, bool $retryable = false, int $retry_after = 0, int $code = 0)
    {
        parent::__construct($message, $code);
        $this->retryable = $retryable;
        $this->retry_after = max(0, $retry_after);
    }
}
