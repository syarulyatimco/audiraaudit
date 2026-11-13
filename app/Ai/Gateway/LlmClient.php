<?php

namespace App\Ai\Gateway;

interface LlmClient
{
    /**
     * @throws LlmTransportException retryable (network, 429, 5xx)
     * @throws LlmRequestException   not retryable (bad request, auth)
     */
    public function complete(LlmRequest $request): LlmResponse;

    public function provider(): string;
}
