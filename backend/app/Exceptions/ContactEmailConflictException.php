<?php

declare(strict_types=1);

namespace HiEvents\Exceptions;

use Exception;

class ContactEmailConflictException extends Exception
{
    public function __construct(
        string $message = 'Another contact in this account already uses this email address',
        int $code = 409,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
