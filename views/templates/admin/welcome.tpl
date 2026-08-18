{*
 * Copyright (c) 2026 Beretag AG
 * Licensed under the Academic Free License version 3.0.
 *}
<div class="panel">
    <h3><i class="icon-bolt"></i> {$bemoTitle|escape:'html':'UTF-8'}</h3>
    <p class="lead">{$bemoLead|escape:'html':'UTF-8'}</p>
    <h4>{$bemoStepsTitle|escape:'html':'UTF-8'}</h4>
    <ul class="list-unstyled">
        {foreach from=$bemoSteps item=bemoStep}
            <li>
                <p>
                    <strong>{$bemoStep.title|escape:'html':'UTF-8'}</strong><br>
                    <span class="text-muted">{$bemoStep.text|escape:'html':'UTF-8'}</span>
                </p>
            </li>
        {/foreach}
    </ul>
    <p>
        <a href="{$bemoSetupAnchor|escape:'html':'UTF-8'}" class="btn btn-primary">
            <i class="icon-arrow-down"></i> {$bemoCta|escape:'html':'UTF-8'}
        </a>
    </p>
    <p class="text-muted">
        <a href="{$bemoDocsUrl|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">
            {$bemoDocsLabel|escape:'html':'UTF-8'}
        </a>
    </p>
</div>
