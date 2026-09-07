{*
 * Copyright (c) 2026 Beretag AG
 * Licensed under the Academic Free License version 3.0.
 *}
<div class="panel">
    <h3><i class="icon-building"></i> {$bemoTitle|escape:'html':'UTF-8'}</h3>
    <p class="lead">{$bemoIntro|escape:'html':'UTF-8'}</p>
    <p class="text-muted">{$bemoHelp|escape:'html':'UTF-8'}</p>

    <table class="table">
        <thead>
            <tr>
                <th>{l s='Shop' mod='bemoliveshopping'}</th>
                <th>{l s='Shop ID' mod='bemoliveshopping'}</th>
                <th>{l s='Storefront' mod='bemoliveshopping'}</th>
                <th>{l s='Status' mod='bemoliveshopping'}</th>
                <th class="text-right">{l s='Action' mod='bemoliveshopping'}</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$bemoShops item=bemoShop}
                <tr>
                    <td><strong>{$bemoShop.name|escape:'html':'UTF-8'}</strong></td>
                    <td>{$bemoShop.shopId|escape:'html':'UTF-8'}</td>
                    <td>
                        {if $bemoShop.storefrontUrl}
                            <a href="{$bemoShop.storefrontUrl|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">
                                {$bemoShop.storefrontUrl|escape:'html':'UTF-8'}
                            </a>
                        {else}
                            <span class="text-muted">{l s='Unavailable' mod='bemoliveshopping'}</span>
                        {/if}
                    </td>
                    <td>
                        <span class="label {$bemoShop.badge|escape:'html':'UTF-8'}">
                            {$bemoShop.status|escape:'html':'UTF-8'}
                        </span>
                    </td>
                    <td class="text-right">
                        <a class="btn btn-default" href="{$bemoShop.configurationUrl|escape:'html':'UTF-8'}">
                            {$bemoShop.action|escape:'html':'UTF-8'}
                        </a>
                    </td>
                </tr>
            {/foreach}
        </tbody>
    </table>
</div>
