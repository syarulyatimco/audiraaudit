<?php

namespace App\Ai\Gateway;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

trait HttpErrors
{
    private function send(PendingRequest $http, string $url, array $body): array
    {
        try {
            $response = $http->post($url, $body);
        } catch (ConnectionException $e) {
            throw new LlmTransportException('Provider unreachable: '.$e->getMessage(), 0, $e);
        }

        return $this->check($response);
    }

    private function check(Response $response): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }
        $msg = $this->provider().' HTTP '.$response->status().': '.mb_substr((string) ($response->json('error.message') ?? $response->body()), 0, 300);
        if ($response->status() === 429 || $response->status() === 529 || $response->serverError()) {
            throw new LlmTransportException($msg);
        }
        throw new LlmRequestException($msg);
    }
}
