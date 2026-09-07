<div class="panel">
  <h3>{$bemoUpdateTitle|escape:'html':'UTF-8'}</h3>
  <p>{$bemoUpdateExplanation|escape:'html':'UTF-8'}</p>
  <p>{$bemoUpdatePreservation|escape:'html':'UTF-8'}</p>
  <form method="post" action="{$bemoUpdateAction|escape:'html':'UTF-8'}">
    <input type="hidden" name="bemo_upgrade_token" value="{$bemoUpdateToken|escape:'html':'UTF-8'}">
    <button type="submit" class="btn btn-primary" name="submitBemoFinishUpdate" value="1">{$bemoUpdateButton|escape:'html':'UTF-8'}</button>
  </form>
</div>
