<?php

namespace Bemo\LiveShopping\Webservice;

if (!defined('_PS_VERSION_')) {
    exit;
}

use RuntimeException;

class PrestaShopWebserviceGateway implements WebserviceGatewayInterface
{
    public function enableWebservice()
    {
        return (bool) \Configuration::updateValue('PS_WEBSERVICE', 1);
    }

    public function createReadOnlyAccount($shopId, $key, array $permissions)
    {
        $this->assertResourcesExist(array_keys($permissions));

        $account = new \WebserviceKey();
        $account->key = $key;
        $account->description = 'BEMO Live Shopping read-only integration';
        $account->active = true;
        $account->id_shop_list = array((int) $shopId);

        if (!$account->add()) {
            throw new RuntimeException('Unable to create the BEMO Webservice account.');
        }

        if (!\WebserviceKey::setPermissionForAccount((int) $account->id, $permissions)) {
            $account->delete();
            throw new RuntimeException('Unable to assign BEMO Webservice permissions.');
        }

        return (int) $account->id;
    }

    public function isAccountValid($accountId, $shopId, $key, array $permissions)
    {
        $account = new \WebserviceKey((int) $accountId);
        if (!\Validate::isLoadedObject($account)
            || !hash_equals((string) $account->key, (string) $key)
            || !\WebserviceKey::isKeyActive((string) $key)) {
            return false;
        }

        $shops = array_values(array_map('intval', $account->getAssociatedShops()));
        sort($shops);
        if ($shops !== array((int) $shopId)) {
            return false;
        }

        $actual = \WebserviceKey::getPermissionForAccount((string) $key);
        if (!is_array($actual) || count($actual) !== count($permissions)) {
            return false;
        }
        foreach ($permissions as $resource => $methods) {
            if (!isset($actual[$resource]) || !$this->sameEnabledMethods($actual[$resource], $methods)) {
                return false;
            }
        }

        return true;
    }

    public function updatePermissions($accountId, array $permissions)
    {
        $this->assertResourcesExist(array_keys($permissions));

        $account = new \WebserviceKey((int) $accountId);
        if (!\Validate::isLoadedObject($account)) {
            return false;
        }

        return (bool) \WebserviceKey::setPermissionForAccount((int) $account->id, $permissions);
    }

    public function deleteAccount($accountId)
    {
        $account = new \WebserviceKey((int) $accountId);

        if (!\Validate::isLoadedObject($account)) {
            return true;
        }

        $account->id_shop_list = $account->getAssociatedShops();

        return (bool) $account->delete();
    }

    private function assertResourcesExist(array $requiredResources)
    {
        $availableResources = array_keys(\WebserviceRequest::getResources());
        $missingResources = array_diff($requiredResources, $availableResources);

        if ($missingResources !== array()) {
            throw new RuntimeException(
                'Required Webservice resources are unavailable: ' . implode(', ', $missingResources)
            );
        }
    }

    private function sameEnabledMethods(array $actual, array $required)
    {
        $actualEnabled = array();
        foreach ($actual as $method => $enabled) {
            if (is_int($method) && is_string($enabled)) {
                $actualEnabled[] = strtoupper($enabled);
            } elseif ($enabled) {
                $actualEnabled[] = strtoupper($method);
            }
        }
        $requiredEnabled = array();
        foreach ($required as $method => $enabled) {
            if ($enabled) {
                $requiredEnabled[] = strtoupper($method);
            }
        }
        sort($actualEnabled);
        sort($requiredEnabled);

        return $actualEnabled === $requiredEnabled;
    }
}
