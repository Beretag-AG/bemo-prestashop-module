{*
 * Copyright (c) 2026 Beretag AG
 * Licensed under the Academic Free License version 3.0.
 *}
<div class="panel">
    <h3><i class="icon-link"></i> {$bemoTitle|escape:'html':'UTF-8'}</h3>
    {if $bemoIntro}<p>{$bemoIntro|escape:'html':'UTF-8'}</p>{/if}
    <table class="table">
        <tbody>
            {foreach from=$bemoRows item=bemoRow}
                <tr>
                    <th>{$bemoRow.label|escape:'html':'UTF-8'}</th>
                    <td>
                        {if $bemoRow.badge}
                            <span class="label {$bemoRow.badge|escape:'html':'UTF-8'}">{$bemoRow.value|escape:'html':'UTF-8'}</span>
                        {elseif $bemoRow.link}
                            <a href="{$bemoRow.value|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">{$bemoRow.value|escape:'html':'UTF-8'}</a>
                        {else}
                            {$bemoRow.value|escape:'html':'UTF-8'}
                        {/if}
                    </td>
                </tr>
            {/foreach}
        </tbody>
    </table>
    {if $bemoRestartLabel}
        <form method="post" action="{$bemoFormAction|escape:'html':'UTF-8'}">
            {foreach from=$bemoHiddenFields key=bemoFieldName item=bemoFieldValue}
                <input type="hidden" name="{$bemoFieldName|escape:'html':'UTF-8'}" value="{$bemoFieldValue|escape:'html':'UTF-8'}">
            {/foreach}
            <button type="submit" name="submitBemoActivateAccount" value="1" class="btn btn-primary">
                <i class="process-icon-refresh"></i> {$bemoRestartLabel|escape:'html':'UTF-8'}
            </button>
        </form>
    {/if}
</div>
