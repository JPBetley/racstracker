<?php

namespace App\LastWar\Game\Exceptions;

use RuntimeException;

/**
 * Base failure for the native game client: transport, protocol, or a server rejection.
 */
class GameClientException extends RuntimeException {}
