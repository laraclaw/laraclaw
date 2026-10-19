<?php

namespace Laraclaw\Exceptions;

use Exception;

/**
 * Thrown when an outbound request an agent tool asked for is refused.
 *
 * The message is handed straight back to the agent, so it stays short and never
 * repeats the address a hostname resolved to.
 */
class OutboundRequestBlocked extends Exception {}
