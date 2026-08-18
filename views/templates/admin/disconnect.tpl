{*
 * Copyright (c) 2026 Beretag AG
 * Licensed under the Academic Free License version 3.0.
 *}
<div class="panel">
    <h3><i class="icon-unlink"></i> {$bemoTitle|escape:'html':'UTF-8'}</h3>
    <p>{$bemoText|escape:'html':'UTF-8'}</p>
    <form method="post" action="{$bemoFormAction|escape:'html':'UTF-8'}">
        <button type="submit" name="submitBemoDisconnect" value="1" class="btn btn-default" onclick="return confirm('{$bemoConfirm|escape:'javascript'}');">
            <i class="process-icon-delete"></i> {$bemoButtonLabel|escape:'html':'UTF-8'}
        </button>
    </form>
</div>
