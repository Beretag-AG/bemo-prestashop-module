{*
 * Copyright (c) 2026 Beretag AG
 * Licensed under the Academic Free License version 3.0.
 *}
<div class="panel">
    <h3><i class="icon-refresh"></i> {$bemoTitle|escape:'html':'UTF-8'}</h3>
    <p>
        <strong>{$bemoStatusTitle|escape:'html':'UTF-8'}</strong><br>
        {$bemoStatusText|escape:'html':'UTF-8'}
    </p>
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
    <p class="help-block">{$bemoQueueHelp|escape:'html':'UTF-8'}</p>

    <details{if !$bemoScheduled} open{/if}>
        <summary><strong>{$bemoManualTitle|escape:'html':'UTF-8'}</strong></summary>
        <div style="padding-top: 12px;">
            <p>{$bemoManualIntro|escape:'html':'UTF-8'}</p>
            <ol>
                <li>{$bemoManualStepOne|escape:'html':'UTF-8'}</li>
                <li>{$bemoManualStepTwo|escape:'html':'UTF-8'}</li>
                <li>{$bemoManualStepThree|escape:'html':'UTF-8'}</li>
            </ol>
            {if $bemoSyncUrl}
                <div class="form-group">
                    <label class="control-label">{$bemoSyncUrlLabel|escape:'html':'UTF-8'}</label>
                    <input type="text" class="form-control" readonly="readonly" onclick="this.select();" value="{$bemoSyncUrl|escape:'html':'UTF-8'}">
                </div>
            {else}
                <p class="text-muted">{$bemoSyncUrlUnavailable|escape:'html':'UTF-8'}</p>
            {/if}
        </div>
    </details>
</div>
