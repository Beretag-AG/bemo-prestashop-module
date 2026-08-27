<?php

namespace Bemo\LiveShopping\Installation;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface ModuleVersionRepositoryInterface
{
    public function current($moduleName);

    public function record($moduleName, $version);
}
