<?php

namespace App\Ai\Gateway;

/** Permanent failure: invalid request, authentication, unknown model. Do not retry. */
class LlmRequestException extends \RuntimeException {}
