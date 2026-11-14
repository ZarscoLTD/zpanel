<?php

declare(strict_types=1);

namespace ZarsPanel;

/** An error with a message that is safe to show the user and the HTTP status to answer with. */
final class PanelError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message, $status);
    }
}
