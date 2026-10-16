<?php

namespace App\Services\Telegram;

use RuntimeException;

class BotApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $errorCode = 0)
    {
        parent::__construct($message, $errorCode);
    }

    /** The message id does not exist (never posted, or deleted). */
    public function isMissingMessage(): bool
    {
        return $this->errorCode === 400 && preg_match('/message (to forward|to copy)? ?not found|MESSAGE_ID_INVALID|message_id_invalid/i', $this->getMessage()) === 1;
    }
}
