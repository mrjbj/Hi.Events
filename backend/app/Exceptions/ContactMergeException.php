<?php

namespace HiEvents\Exceptions;

use Exception;

class ContactMergeException extends Exception
{
    public function __construct(
        string $message = 'Contacts cannot be merged',
        int $code = 422,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
