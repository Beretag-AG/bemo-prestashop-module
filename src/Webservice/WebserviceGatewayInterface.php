<?php

namespace Bemo\LiveShopping\Webservice;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface WebserviceGatewayInterface
{
    public function enableWebservice();

    public function createReadOnlyAccount($shopId, $key, array $permissions);

    public function isAccountValid($accountId, $shopId, $key, array $permissions);

    public function updatePermissions($accountId, array $permissions);

    public function deleteAccount($accountId);
}
