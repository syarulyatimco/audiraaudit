<?php

namespace App\Ai\Gateway;

/** Transient provider failure: network, timeout, rate limit, 5xx. Safe to retry. */
class LlmTransportException extends \RuntimeException {}
