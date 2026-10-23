<?php

namespace App\Exceptions;

use Exception;

class DuplicateRequestException extends Exception
{
    public function __construct(
        public readonly string $agentId,
        public readonly string $clientRequestId,
        string $message = "",
        int $code = 409
    ) {
        $msg = $message ?: "A request with agent_id '{$agentId}' and client_request_id '{$clientRequestId}' already exists.";
        parent::__construct($msg, $code);
    }
}
