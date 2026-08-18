{*
 * Copyright (c) 2026 Beretag AG
 * Licensed under the Academic Free License version 3.0.
 *}
<div class="panel">
    <h3><i class="icon-refresh"></i> {$bemoTitle|escape:'html':'UTF-8'}</h3>
    {if $bemoWarning}
        <div class="alert alert-warning">{$bemoWarning|escape:'html':'UTF-8'}</div>
    {/if}
    <table class="table">
        <tbody>
            {foreach from=$bemoRows item=bemoRow}
                <tr>
                    <th>{$bemoRow.label|escape:'html':'UTF-8'}</th>
                    <td>{$bemoRow.value|escape:'html':'UTF-8'}</td>
                </tr>
            {/foreach}
        </tbody>
    </table>
    {if $bemoSyncUrl}
        <div class="form-group">
            <label class="control-label">{$bemoSyncUrlLabel|escape:'html':'UTF-8'}</label>
            <input type="text" class="form-control" readonly="readonly" onclick="this.select();" value="{$bemoSyncUrl|escape:'html':'UTF-8'}">
            <p class="help-block">{$bemoSyncUrlHelp|escape:'html':'UTF-8'}</p>
        </div>
    {else}
        <p class="text-muted">{$bemoSyncUrlUnavailable|escape:'html':'UTF-8'}</p>
    {/if}
</div>
