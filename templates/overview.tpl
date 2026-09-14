{* Uptimeify client area overview (spec §9.3). Read only. Smarty 3/4 compatible: no PHP tags, no modifier except escape, every printed value escaped. *}
<div class="uptimeify-client">
  <h2>{$L.heading|escape}</h2>
  {if $state == 'not_logged_in'}
    <div class="alert alert-info">{$L.login_required|escape}</div>
  {elseif $state == 'not_set_up'}
    <div class="alert alert-info">{$L.not_set_up|escape}</div>
  {elseif $state == 'unavailable'}
    <div class="alert alert-warning">{$L.unavailable|escape}</div>
  {elseif $state == 'no_permission'}
    <div class="alert alert-info">{$L.no_permission|escape}</div>
  {else}
    {if $stale}
      <div class="alert alert-warning">{$L.stale|escape}</div>
    {/if}
    <div class="row">
      <div class="col-6 col-md-3 mb-3"><div class="card text-center"><div class="card-body"><h3 class="mb-0">{$totals.total|escape}</h3><small class="text-muted">{$L.total|escape}</small></div></div></div>
      <div class="col-6 col-md-3 mb-3"><div class="card text-center"><div class="card-body"><h3 class="mb-0 text-success">{$totals.up|escape}</h3><small class="text-muted">{$L.up|escape}</small></div></div></div>
      <div class="col-6 col-md-3 mb-3"><div class="card text-center"><div class="card-body"><h3 class="mb-0{if $totals.down > 0} text-danger{/if}">{$totals.down|escape}</h3><small class="text-muted">{$L.down|escape}</small></div></div></div>
      <div class="col-6 col-md-3 mb-3"><div class="card text-center"><div class="card-body"><h3 class="mb-0">{$totals.paused|escape}</h3><small class="text-muted">{$L.paused|escape}</small></div></div></div>
    </div>

    {if $monitors}
      <div class="table-responsive">
        <table class="table table-striped">
          <thead>
            <tr>
              <th>{$L.col_monitor|escape}</th>
              <th>{$L.col_type|escape}</th>
              <th>{$L.col_status|escape}</th>
              <th>{$L.uptime_day|escape}</th>
              <th>{$L.uptime_month|escape}</th>
              <th>{$L.uptime_year|escape}</th>
              <th>{$L.col_response|escape}</th>
              <th>{$L.col_incidents|escape}</th>
              <th>{$L.col_expires|escape}</th>
            </tr>
          </thead>
          <tbody>
            {foreach $monitors as $m}
              <tr>
                <td>{$m.name|escape}<br><small class="text-muted">{$m.target|escape}</small></td>
                <td>{$m.typeLabel|escape}</td>
                <td><span class="badge badge-{$m.statusClass|escape}">{$m.statusLabel|escape}</span></td>
                <td>{$m.uptimeDay|escape}</td>
                <td>{$m.uptimeMonth|escape}</td>
                <td>{$m.uptimeYear|escape}</td>
                <td>{$m.avgResponse|escape}</td>
                <td>{$m.openIncidents|escape}</td>
                <td>{if $m.isDomain}{$m.expiresAt|escape}<br><small class="text-muted">{$m.registrar|escape}</small>{else}&mdash;{/if}</td>
              </tr>
            {/foreach}
          </tbody>
        </table>
      </div>
    {else}
      <p class="text-muted">{$L.no_monitors|escape}</p>
    {/if}

    <h3>{$L.open_incidents|escape}</h3>
    {if !$incidentsAvailable}
      <p class="text-muted">{$L.incidents_unavailable|escape}</p>
    {elseif !$incidentsComplete}
      <p class="text-muted">{$L.incidents_incomplete|escape}</p>
    {/if}
    {if $incidents}
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>{$L.col_incident|escape}</th>
              <th>{$L.col_monitor|escape}</th>
              <th>{$L.col_started|escape}</th>
            </tr>
          </thead>
          <tbody>
            {foreach $incidents as $i}
              <tr>
                <td>{$i.title|escape}</td>
                <td>{$i.monitorName|escape}</td>
                <td>{$i.startedAt|escape}</td>
              </tr>
            {/foreach}
          </tbody>
        </table>
      </div>
    {else}
      <p class="text-muted">{$L.no_incidents|escape}</p>
    {/if}

    <p class="text-muted small">
      {$L.refreshed_at|escape}: {$refreshedAt|escape}
      {if $portalUrl} &middot; <a href="{$portalUrl|escape}" target="_blank" rel="noopener noreferrer">{$L.status_portal|escape}</a>{/if}
    </p>
  {/if}
</div>
