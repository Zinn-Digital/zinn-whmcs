{* Zinn Digital — the client-area panel for a hosting service.

   Kept deliberately small: it renders inside the reseller's OWN WHMCS template, so it
   sets no colours, no widths and no fonts. Everything it shows comes from the service
   row the module fetched; nothing is inferred here.
*}
<div class="zinn-service">
	{if $error}
		<div class="alert alert-danger">{$error|escape}</div>
	{else}
		<table class="table">
			<tbody>
				<tr>
					<th>Domain</th>
					<td>{$domain|escape}</td>
				</tr>
				<tr>
					<th>Plan</th>
					<td>{if $plan}{$plan|escape}{else}&mdash;{/if}</td>
				</tr>
				<tr>
					<th>Status</th>
					<td>{$status|escape}</td>
				</tr>
				{if $pendingDeletionAt}
					{* The platform SCHEDULES a deletion rather than destroying data at once.
					   Showing the date is what stops a client believing their files are
					   already gone — and what tells them how long they have to change
					   their mind. *}
					<tr>
						<th>Scheduled for deletion</th>
						<td>{$pendingDeletionAt|escape}</td>
					</tr>
				{/if}
			</tbody>
		</table>
	{/if}
</div>
