<?php

namespace Bemo\LiveShopping\Lock;

if (!defined('_PS_VERSION_')) {
    exit;
}

use RuntimeException;

class LockUnavailableException extends RuntimeException
{
}
