<?php
/**
 * Zinn Digital provisioning module for WHMCS.
 *
 * Sell Zinn hosting from a WHMCS installation: an order creates the client's account and
 * their site, WHMCS's own Suspend/Unsuspend/Terminate buttons drive the real thing, and the
 * client-area "Log in to your hosting" button opens their panel already signed in.
 *
 * ⛔⛔ THIS MODULE HAS NO PRIVILEGED PATH. Every call it makes is a documented endpoint any
 * reseller can call with their own API key — `POST /v1/orgs`, `POST /v1/sites`,
 * `GET|POST /v1/reseller/services/...`, `DELETE /v1/sites/{id}`. That is not a coincidence:
 * if this module needed something the public API could not do, the fix would be an endpoint
 * in the spec, not a back door here. See docs/74 §2c.
 *
 * Installation: copy this directory to `modules/servers/zinn/` in the WHMCS root.
 *
 * @package Zinn\WHMCS
 * @license GPL-2.0-or-later
 * @author  Neil Lock — CEO, Zinn Digital® Ltd
 * @link    https://zinndigital.com
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * The stacks the platform will actually provision, as a WHMCS dropdown option string.
 *
 * ⛔⛔ THE PLATFORM REQUIRES ONE. `POST /v1/sites` answers
 * `422 stack_type: "Required when blueprint_id is omitted."` without it, so a module that
 * omitted it could not provision a single account — which is exactly what this module did
 * until 2026-08-31 (D17360). It is a dropdown rather than free text because the set is
 * closed and a typo is an order that fails at the platform instead of at the form.
 *
 * ⛔ Kept in step with the engine's `hosting.enums.OFFERED_STACK_TYPES` by a test that reads
 * THIS FILE and compares — `engine/engine/hosting/tests/test_integration_stacks.py` — not by
 * anybody remembering. Three panel modules each carry their own copy on purpose (a module is
 * installed by copying one directory, so a shared file is a file the reseller does not copy);
 * the test is what stops the copies drifting.
 */
const ZINN_STACKS = 'headless_cms,nextcloud,node,one_click,owncloud,php,static,woocommerce,wordpress';

require_once __DIR__ . '/lib/Client.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Zinn\WHMCS\Client;

/**
 * Module metadata, shown in WHMCS's module list.
 *
 * @return array<string,mixed>
 */
function zinn_MetaData(): array
{
    return [
        // ⚖️ docs/203 §8: the ® goes on every occurrence of the mark in customer-facing copy,
        // and a reseller's WHMCS module list is customer-facing. ⛔ `/kb/whmcs-module`
        // quotes this exact string as the **Type** to pick, so the two must not drift —
        // a guide naming a value that is not in the dropdown is worse than no guide.
        'DisplayName' => "Zinn Digital\u{00AE}",
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        // WHMCS's own "suspend on overdue invoice" automation drives `zinn_SuspendAccount`,
        // which is exactly the lever this module exists to give a reseller.
        'DefaultNonSSLPort' => '80',
        'DefaultSSLPort' => '443',
        'ServiceSingleSignOnLabel' => 'Log in to hosting panel',
    ];
}

/**
 * The per-product configuration a reseller fills in on the product.
 *
 * ⛔ The API key lives on the SERVER entry (WHMCS's own encrypted `Password` field), never
 * here: a product option is plain text in the database and visible to every admin, and this
 * credential can create and destroy a client's hosting.
 *
 * @return array<string,array<string,mixed>>
 */
function zinn_ConfigOptions(): array
{
    return [
        'Product line' => [
            'Type' => 'text',
            'Size' => '32',
            'Default' => 'mainstream',
            'Description' => 'The Zinn product line to provision — mainstream, footprint_free, '
                . 'wordpress, linux, cloud, agency, app_hosting and the rest. '
                . 'GET /v1/reseller/lines lists the ones you sell; an unknown code is refused '
                . 'with the list, never silently substituted.',
        ],
        'Stack' => [
            'Type' => 'dropdown',
            'Options' => ZINN_STACKS,
            'Default' => 'wordpress',
            'Description' => 'What gets built on the account.',
        ],
        'Application' => [
            'Type' => 'text',
            'Size' => '32',
            'Description' => 'Only for the one_click and headless_cms stacks — the application '
                . 'key to install. Naming one on any other stack is refused.',
        ],
        'PHP version' => [
            'Type' => 'text',
            'Size' => '8',
            'Description' => 'Optional, e.g. 8.3. Leave empty for the product line default.',
        ],
        'Plan code' => [
            'Type' => 'text',
            'Size' => '32',
            'Description' => 'REQUIRED. The Zinn plan this product sells — GET /v1/reseller/prices '
                . 'lists yours. An order without it is refused rather than provisioned: a site '
                . 'created with no plan is one we never invoice you for, and neither end reports '
                . 'it.',
        ],
        'Billing interval' => [
            'Type' => 'dropdown',
            'Options' => 'monthly,annual',
            'Default' => 'monthly',
            'Description' => 'The interval your wholesale line for this client is raised on. '
                . 'This is between you and Zinn — what you charge your own client, and how '
                . 'often, is set on this product in WHMCS and is not affected.',
        ],
    ];
}

/**
 * The body `POST /v1/sites` is sent for one order.
 *
 * ⛔⛔ `stack_type` IS MANDATORY AND ITS ABSENCE IS NOT VISIBLE FROM THIS FILE. The platform
 * answers `422 stack_type: "Required when blueprint_id is omitted."`, so every order failed
 * before this function existed — while `Test Connection` reported a healthy `active`
 * programme, because that endpoint does not provision anything. **A green connection test is
 * not evidence that an order will succeed** (D17360, proven against the live API rather than
 * read back from the spec).
 *
 * ⛔ Optional fields are omitted rather than sent empty. `php_version: ""` is a `422` naming
 * the versions the line offers, and `application` on a stack that does not ask for one is a
 * `422` too — so an empty box on the product form would refuse the order rather than take
 * the line's default, which is the opposite of what an empty box means to the person filling
 * it in.
 *
 * ⭐ Extracted so the request body is TESTABLE. The old code built it inline inside a
 * `try`/`catch` that turned every failure into a returned string, so the one thing worth
 * asserting — what we actually ask the platform for — could not be reached by a test. That
 * is why a missing required field survived a green suite.
 *
 * @param string              $orgId  The client organisation id.
 * @param string              $domain The hostname being provisioned.
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return array<string,mixed>
 */
function zinn_sitePayload(
    string $orgId,
    string $domain,
    array $params,
    string $subscriptionId = ''
): array {
    $payload = [
        'org_id' => $orgId,
        'product_line' => trim((string) ($params['configoption1'] ?? '')) ?: 'mainstream',
        'primary_domain' => $domain,
        'name' => $domain,
        'stack_type' => trim((string) ($params['configoption2'] ?? '')) ?: 'wordpress',
    ];
    // ⛔⛔ THE FIELD WHOSE ABSENCE COST THE WHOLE INVOICE (D17700). Without it the platform
    // falls back to the client organisation's governing subscription — and a client this
    // module has just created has none, because `governing_subscription` resolves within one
    // org and does not walk up to the reseller. The site then lands with no plan at all: no
    // line on the reseller's wholesale statement, no entitlements for the client, and
    // nothing for `zinn_ChangePackage` to change. Nothing reports it; the site serves
    // perfectly. `zinn_clientPlan` is what makes this value exist.
    if ($subscriptionId !== '') {
        $payload['subscription_id'] = $subscriptionId;
    }
    $application = trim((string) ($params['configoption3'] ?? ''));
    if ($application !== '') {
        $payload['application'] = $application;
    }
    $phpVersion = trim((string) ($params['configoption4'] ?? ''));
    if ($phpVersion !== '') {
        $payload['php_version'] = $phpVersion;
    }
    return $payload;
}

/**
 * Provision hosting for a new order.
 *
 * ⛔⛔ IDEMPOTENT ON THE WHMCS SERVICE ID, and that is load-bearing rather than defensive.
 * WHMCS retries a failed provision from the admin area with the same service id, and a
 * client who clicks "Order" twice produces two attempts. Every call carries an
 * `Idempotency-Key` built from that id, so a retry returns the SAME account and the SAME
 * site instead of billing the reseller for a second one.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string 'success' or an error message.
 */
function zinn_CreateAccount(array $params): string
{
    try {
        $client = Client::fromParams($params);
        $serviceId = (string) $params['serviceid'];

        $existing = zinn_storedSiteId($params);
        if ($existing !== '') {
            return 'success'; // Already provisioned — never a second site.
        }

        $orgId = zinn_clientOrg($client, $params);
        $domain = strtolower(trim((string) $params['domain']));
        if ($domain === '') {
            return 'This service has no domain, so there is nothing to provision.';
        }

        // ⛔⛔ BEFORE the site, and a hard refusal when the product names no plan. Ordering
        // matters: `POST /v1/sites` records whatever subscription exists at the moment it
        // runs, so a plan granted afterwards does not attach retrospectively — it would need
        // a second call nobody makes. Refusing is deliberately louder than provisioning:
        // an unbilled site works perfectly and is reported by nothing at either end, so the
        // failure mode of guessing is silent and permanent (D17700).
        $subscriptionId = zinn_clientPlan($client, $orgId, $params);

        $site = $client->post(
            '/v1/sites',
            zinn_sitePayload($orgId, $domain, $params, $subscriptionId),
            'whmcs-site-' . $serviceId
        );

        if (empty($site['id'])) {
            return 'The hosting platform did not return a site.';
        }
        zinn_storeSiteId($params, (string) $site['id']);
        return 'success';
    } catch (Throwable $e) {
        // WHMCS renders the returned string to the admin verbatim, so the API's own
        // sentence is the most useful thing we can put there. `logModuleCall` keeps the
        // full exchange for the module log.
        return $e->getMessage();
    }
}

/**
 * Suspend the client's hosting — WHMCS's overdue-invoice automation calls this.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string
 */
function zinn_SuspendAccount(array $params): string
{
    try {
        $site = zinn_requireSiteId($params);
        Client::fromParams($params)->post(
            '/v1/reseller/services/' . rawurlencode($site) . '/suspend',
            ['reason' => 'WHMCS service #' . $params['serviceid']]
        );
        return 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

/**
 * Return the client's hosting to service.
 *
 * ⛔ A 409 here is CORRECT and must be shown, not swallowed: it means the site is suspended
 * by our abuse team rather than by this reseller, and no billing panel may release that.
 * The API's message says so in a sentence an admin can act on.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string
 */
function zinn_UnsuspendAccount(array $params): string
{
    try {
        $site = zinn_requireSiteId($params);
        Client::fromParams($params)->post(
            '/v1/reseller/services/' . rawurlencode($site) . '/unsuspend',
            []
        );
        return 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

/**
 * Terminate the client's hosting.
 *
 * ⛔⛔ `DELETE /v1/sites/{id}` REQUIRES A TYPED CONFIRMATION AND SILENTLY DID NOT GET ONE.
 * The endpoint answers `422 confirm_domain: "This field is required."` to a bare DELETE, so
 * every cancellation failed — and because WHMCS's own automation drives Terminate, the
 * service went on running and the reseller went on being billed by us for hosting their
 * customer had cancelled. A money defect with no red anywhere (D17363, proven against the
 * live API on 2026-08-31).
 *
 * ⛔ The confirmation is read back from the PLATFORM, never taken from `$params['domain']`.
 * It is compared against `SiteDeletion.primary_domain` and never against an alias, so a
 * WHMCS service whose domain field was later edited — or which was ordered on an alias —
 * would fail a check it should pass. One extra GET on a rare path buys a confirmation that
 * cannot disagree with what it is confirming.
 *
 * ⛔ A `404` on that read is treated as ALREADY GONE and reported as success. WHMCS retries
 * a failed termination, and a second attempt that reported failure because the site is no
 * longer there would leave a cancelled service stuck in the admin queue for ever.
 *
 * ⚠️ The platform SCHEDULES a deletion rather than destroying data immediately, which is a
 * deliberate customer protection and not a failure of this call. The scheduled date comes
 * back on the service row; `zinn_ClientArea` shows it, so nobody believes data is already
 * gone when it is not.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string
 */
function zinn_TerminateAccount(array $params): string
{
    try {
        $site = zinn_requireSiteId($params);
        $client = Client::fromParams($params);
        try {
            $row = $client->get('/v1/reseller/services/' . rawurlencode($site), []);
        } catch (Throwable $e) {
            // Gone already, or never ours. Either way there is nothing left to terminate,
            // and reporting failure would jam WHMCS's cancellation queue.
            return 'success';
        }
        $domain = trim((string) ($row['primary_domain'] ?? ''));
        if ($domain === '') {
            return 'The hosting platform did not report this service\'s domain, so the '
                . 'deletion could not be confirmed. Nothing has been changed.';
        }
        $client->delete('/v1/sites/' . rawurlencode($site), ['confirm_domain' => $domain]);
        return 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

/**
 * Refresh disk and bandwidth usage for every service this module owns.
 *
 * ⛔⛔ ONE list call, then ONE detail call per service the reseller actually owns — never a
 * loop that asks the platform about every site in the world. The list endpoint deliberately
 * carries no usage, because reading it is a round trip to the hosting box; the arithmetic
 * that matters is that this cron does `1 + N` requests where N is the reseller's OWN client
 * count, and it runs once a day.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return void
 */
function zinn_UsageUpdate(array $params): void
{
    try {
        $client = Client::fromParams($params);
        $cursor = '';
        do {
            $query = $cursor === '' ? [] : ['cursor' => $cursor];
            $page = $client->get('/v1/reseller/services', $query);
            foreach (($page['data'] ?? []) as $row) {
                zinn_recordUsage($client, $row);
            }
            $cursor = (string) ($page['page']['next_cursor'] ?? '');
        } while ($cursor !== '');
    } catch (Throwable $e) {
        logActivity('Zinn usage update failed: ' . $e->getMessage());
    }
}

/**
 * Write one service's usage back into WHMCS.
 *
 * @param Client              $client The API client.
 * @param array<string,mixed> $row    One `ResellerService` row.
 * @return void
 */
function zinn_recordUsage(Client $client, array $row): void
{
    $siteId = (string) ($row['site_id'] ?? '');
    if ($siteId === '') {
        return;
    }
    $detail = $client->get('/v1/reseller/services/' . rawurlencode($siteId), []);
    $disk = $detail['disk_used_bytes'] ?? null;
    $bandwidth = $detail['bandwidth_used_bytes'] ?? null;
    // ⛔ A null is skipped, never written as 0. "We could not measure this" and "it used
    // nothing" are different facts, and WHMCS would render the second one as a green bar.
    if ($disk === null && $bandwidth === null) {
        return;
    }
    // ⛔⛔ MATCHED ON `username` ALONE, and the `orWhere('domain', …)` that used to sit here
    // was a real defect rather than a helpful fallback. `tblhosting.username` is the field
    // THIS module writes with the Zinn site id, so it is the only reliable handle. Matching
    // on the domain as well reaches across the whole WHMCS install: a customer who buys
    // hosting AND a domain registration for `acme.com` has two rows carrying that domain, and
    // the disjunction would write disk and bandwidth onto whichever the planner returned
    // first — a usage bar on a product that has no usage, and none on the one that does.
    // ⭐ Found by this lane's own adversarial pass. Silent, plausible, and impossible to spot
    // from the reseller's side, because both rows are theirs and both look fine.
    $service = Capsule::table('tblhosting')->where('username', $siteId)->first();
    if ($service === null) {
        return;
    }
    $update = ['lastupdate' => date('Y-m-d H:i:s')];
    if ($disk !== null) {
        $update['diskused'] = (int) round(((int) $disk) / 1024);
    }
    if ($bandwidth !== null) {
        $update['bwusage'] = (int) round(((int) $bandwidth) / 1024);
    }
    Capsule::table('tblhosting')->where('id', $service->id)->update($update);
}

/**
 * The client-area panel: what they bought, and the button that opens it.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return array<string,mixed>
 */
function zinn_ClientArea(array $params): array
{
    $site = zinn_storedSiteId($params);
    if ($site === '') {
        return ['templatefile' => 'clientarea', 'vars' => ['status' => 'not provisioned']];
    }
    try {
        $row = Client::fromParams($params)->get('/v1/reseller/services/' . rawurlencode($site), []);
    } catch (Throwable $e) {
        return ['templatefile' => 'clientarea', 'vars' => ['error' => $e->getMessage()]];
    }
    return [
        'templatefile' => 'clientarea',
        'vars' => [
            'domain' => (string) ($row['primary_domain'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'plan' => (string) ($row['plan_name'] ?? ''),
            'held' => !empty($row['held_by_reseller']),
            'pendingDeletionAt' => $row['pending_deletion_at'] ?? null,
        ],
    ];
}

/**
 * Single sign-on: hand the client a one-time link into their panel.
 *
 * ⛔ Minted HERE, when the button is clicked, and never rendered into the page. The link is
 * single-use and short-lived, so putting it in the client-area HTML would burn it on a page
 * view and leave it in every cache and log between us and the browser.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return array<string,mixed>
 */
function zinn_ServiceSingleSignOn(array $params): array
{
    try {
        $site = zinn_requireSiteId($params);
        $response = Client::fromParams($params)->post(
            '/v1/reseller/services/' . rawurlencode($site) . '/sso',
            []
        );
        if (empty($response['url'])) {
            return ['success' => false, 'errorMsg' => 'No sign-in link was returned.'];
        }
        return ['success' => true, 'redirectTo' => (string) $response['url']];
    } catch (Throwable $e) {
        return ['success' => false, 'errorMsg' => $e->getMessage()];
    }
}

/**
 * "Test connection" on the server entry — a real call, not a field check.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return array<string,mixed>
 */
function zinn_TestConnection(array $params): array
{
    try {
        $program = Client::fromParams($params)->get('/v1/reseller/program', []);
        $status = (string) ($program['status'] ?? '');
        if ($status !== 'active') {
            return [
                'success' => false,
                'error' => 'Connected, but your reseller programme is "' . $status . '" rather than active.',
            ];
        }
        return ['success' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Resolve — or create — the Zinn client organisation for this WHMCS client.
 *
 * ⛔ Keyed on the WHMCS **client** id, not the service id: a client's second order must land
 * in the account they already have. Keying on the service would give one customer three
 * unrelated accounts and three separate panels.
 *
 * @param Client              $client The API client.
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string
 */
function zinn_clientOrg(Client $client, array $params): string
{
    $clientId = (int) ($params['clientsdetails']['userid'] ?? 0);
    $existing = zinn_orgIdFor($clientId);
    if ($existing !== '') {
        return $existing;
    }

    $name = trim((string) ($params['clientsdetails']['companyname'] ?? ''));
    if ($name === '') {
        $name = trim(
            ($params['clientsdetails']['firstname'] ?? '') . ' ' . ($params['clientsdetails']['lastname'] ?? '')
        );
    }
    if ($name === '') {
        $name = (string) ($params['clientsdetails']['email'] ?? 'Client');
    }

    $org = $client->post('/v1/orgs', [
        'type' => 'customer',
        'name' => $name,
    ], 'whmcs-org-' . $clientId);

    if (empty($org['id'])) {
        throw new RuntimeException('The hosting platform did not return a client account.');
    }
    zinn_storeOrgId($clientId, (string) $org['id']);
    return (string) $org['id'];
}

/**
 * The Zinn site id stored against this WHMCS service.
 *
 * ⛔ Stored in `tblhosting.username`, which is WHMCS's own per-service field for exactly
 * this — a module's handle on the remote account. It is the field WHMCS itself passes back
 * as `$params['username']` on every subsequent call, so a service provisioned by this
 * module is self-describing rather than depending on a table that could be lost in a
 * restore.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string
 */
function zinn_storedSiteId(array $params): string
{
    return trim((string) ($params['username'] ?? ''));
}

/**
 * The site id, or an exception naming what to do about it.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string
 */
function zinn_requireSiteId(array $params): string
{
    $site = zinn_storedSiteId($params);
    if ($site === '') {
        throw new RuntimeException(
            'This service has no Zinn site recorded against it. Create the account first.'
        );
    }
    return $site;
}

/**
 * Record the Zinn site id against the WHMCS service.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @param string              $siteId Zinn site id.
 * @return void
 */
function zinn_storeSiteId(array $params, string $siteId): void
{
    Capsule::table('tblhosting')
        ->where('id', (int) $params['serviceid'])
        ->update(['username' => $siteId]);
}

/**
 * The Zinn organisation id for a WHMCS client, or an empty string.
 *
 * @param int $clientId WHMCS client id.
 * @return string
 */
function zinn_orgIdFor(int $clientId): string
{
    zinn_ensureMappingTable();
    $row = Capsule::table('mod_zinn_clients')->where('client_id', $clientId)->first();
    return $row === null ? '' : (string) $row->org_id;
}

/**
 * Record the Zinn organisation id for a WHMCS client.
 *
 * @param int    $clientId WHMCS client id.
 * @param string $orgId    Zinn organisation id.
 * @return void
 */
function zinn_storeOrgId(int $clientId, string $orgId): void
{
    zinn_ensureMappingTable();
    Capsule::table('mod_zinn_clients')->updateOrInsert(
        ['client_id' => $clientId],
        ['org_id' => $orgId]
    );
}

/**
 * Create the client-mapping table on first use.
 *
 * @return void
 */
function zinn_ensureMappingTable(): void
{
    if (Capsule::schema()->hasTable('mod_zinn_clients')) {
        return;
    }
    Capsule::schema()->create('mod_zinn_clients', function ($table) {
        $table->integer('client_id')->primary();
        $table->string('org_id', 64);
    });
}

/**
 * Put the client organisation on the plan this product sells, and return its subscription.
 *
 * ⛔⛔ THE STEP WHOSE ABSENCE MEANT ZINN INVOICED THE RESELLER NOTHING (D17700, measured on
 * production 2026-09-01 with a control). A client organisation this module has just created
 * holds no subscription, and `POST /v1/sites` has no plan field — so every service ordered
 * through this module landed with no plan: no wholesale line, no entitlements, no quota on
 * the box, and nothing for an upgrade to move. Every part of that is silent.
 *
 * ⚖️ Owner ruling 2026-09-01 on a client's SECOND service, and the engine enforces it: the
 * same plan is a no-op (the plan's own site allowance covers the extra site), a different
 * plan is refused rather than superseding — because superseding would bill for one plan
 * while the client ran two services.
 *
 * ⛔ A missing plan code is refused here rather than defaulted. There is no safe default:
 * any guess provisions hosting on a plan the reseller did not choose and may not have
 * priced, and the wrong guess is invisible until a statement arrives.
 *
 * @param Client              $client The API client.
 * @param string              $orgId  The client organisation id.
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string The subscription id to record the site against.
 */
function zinn_clientPlan(Client $client, string $orgId, array $params): string
{
    $planCode = trim((string) ($params['configoption5'] ?? ''));
    if ($planCode === '') {
        throw new RuntimeException(
            'This product has no Zinn plan code, so the order was not provisioned. Set '
            . '"Plan code" on the product\'s Module Settings tab to one of the plans from '
            . 'GET /v1/reseller/prices. Provisioning without it would create hosting that '
            . 'carries no plan — you would not be invoiced for it and the client would get '
            . 'no allowances.'
        );
    }
    $interval = trim((string) ($params['configoption6'] ?? '')) ?: 'monthly';
    $plan = $client->post(
        '/v1/reseller/clients/' . rawurlencode($orgId) . '/plan',
        ['plan_code' => $planCode, 'interval' => $interval],
        'whmcs-plan-' . $orgId . '-' . $planCode
    );
    $subscriptionId = trim((string) ($plan['subscription_id'] ?? ''));
    if ($subscriptionId === '') {
        throw new RuntimeException(
            'The hosting platform did not return a subscription for plan "' . $planCode
            . '", so nothing was provisioned.'
        );
    }
    return $subscriptionId;
}

/**
 * Upgrade or downgrade — WHMCS's own "Change Package" on an existing service.
 *
 * ⛔⛔ WHMCS HANDS US THE **NEW** PRODUCT'S CONFIGURATION AND NOTHING ABOUT THE OLD ONE, so
 * "what changed" cannot be computed from `$params`. Every field is therefore compared
 * against the PLATFORM's current state, read back first. That is one extra GET on a path a
 * reseller uses rarely, and it buys the only comparison that cannot be wrong — a diff
 * against remembered state would silently re-apply a value a support agent had changed by
 * hand in between.
 *
 * ⛔ The customer is NOT charged by us. WHMCS has already taken the reseller's own money
 * from their client and computed its own proration; the platform moves the entitlements and
 * puts the new plan on the reseller's next wholesale statement. A second charge here would
 * bill one upgrade twice, to two different people.
 *
 * ⛔ The package is moved BEFORE the PHP version. A package change can fail on a downgrade
 * guard, and a PHP switch applied first would leave the service on a runtime the plan it is
 * still on may not offer — a partial change that reads as success.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string 'success' or an error message.
 */
function zinn_ChangePackage(array $params): string
{
    try {
        $site = zinn_requireSiteId($params);
        $client = Client::fromParams($params);
        $path = '/v1/reseller/services/' . rawurlencode($site);

        $changed = [];
        $planCode = trim((string) ($params['configoption5'] ?? ''));
        if ($planCode !== '') {
            $current = $client->get($path . '/package', []);
            if (trim((string) ($current['current_plan_code'] ?? '')) !== $planCode) {
                $client->post($path . '/package', ['plan_code' => $planCode]);
                $changed[] = 'package';
            }
        }

        $phpVersion = trim((string) ($params['configoption4'] ?? ''));
        if ($phpVersion !== '') {
            $live = $client->get('/v1/sites/' . rawurlencode($site) . '/php-version', []);
            if (trim((string) ($live['php_version'] ?? '')) !== $phpVersion) {
                $client->put(
                    '/v1/sites/' . rawurlencode($site) . '/php-version',
                    ['php_version' => $phpVersion]
                );
                $changed[] = 'PHP version';
            }
        }

        if ($changed === []) {
            // ⛔ Success, not an error. WHMCS calls this on every product change including
            // ones that touch nothing this module owns (a price edit, a billing-cycle
            // change). Reporting failure would leave those stuck in the admin queue for ever.
            return 'success';
        }
        return 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

/**
 * Set the WordPress administrator's password — WHMCS's "Change Password" box.
 *
 * ⛔⛔ THIS DELIBERATELY DOES NOT EXIST FOR NON-WORDPRESS STACKS, and the refusal names why.
 * Zinn hosting has no single "service password": the client reaches their panel by
 * single sign-on, and a `php` or `static` site has no application account to change. WHMCS
 * hides the box entirely when a module has no `_ChangePassword`, so the alternative to this
 * function is not "a worse experience" — it is no box at all, which is right for a stack
 * with no password and wrong for WordPress, where it is the commonest support request a
 * reseller gets.
 *
 * ⛔ The **administrator** is resolved from the platform, never assumed to be user 1. A
 * WordPress install migrated in from elsewhere routinely has a different id, and setting the
 * password of whichever account happens to be first would hand the client someone else's
 * credentials.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string 'success' or an error message.
 */
function zinn_ChangePassword(array $params): string
{
    try {
        $site = zinn_requireSiteId($params);
        $password = (string) ($params['password'] ?? '');
        if ($password === '') {
            return 'No new password was supplied, so nothing was changed.';
        }
        $client = Client::fromParams($params);
        $base = '/v1/sites/' . rawurlencode($site);

        $users = $client->get($base . '/wordpress/users', []);
        $administrator = null;
        foreach (($users['data'] ?? []) as $user) {
            $roles = $user['roles'] ?? [];
            if (is_array($roles) && in_array('administrator', $roles, true)) {
                $administrator = $user;
                break;
            }
        }
        if ($administrator === null) {
            return 'This service has no WordPress administrator account, so there is no '
                . 'password to change. Zinn hosting is signed into with the "Log in to '
                . 'hosting panel" button rather than a service password.';
        }
        $client->put(
            $base . '/wordpress/users/' . rawurlencode((string) $administrator['id']) . '/password',
            ['password' => $password]
        );
        return 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

/**
 * Renewal — WHMCS calls this each time a renewal invoice is paid.
 *
 * ⛔⛔ A DELIBERATE NO-OP, AND IT MUST EXIST RATHER THAN BE ABSENT. Zinn hosting is
 * continuous: nothing expires, so there is no remote term to extend. But WHMCS treats a
 * missing `_Renew` and a failing one differently from a succeeding one, and a reseller
 * watching their service list needs renewals to settle rather than sit. Returning success
 * from a function that does nothing is the honest answer here; doing nothing *silently*,
 * with no function at all, leaves the reseller unable to tell the two apart.
 *
 * ⭐ What the renewal actually pays for reaches us through the reseller's wholesale
 * statement, which is built from the client's live subscription — not from this call.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string
 */
function zinn_Renew(array $params): string
{
    try {
        // Read the service back so a renewal on a terminated or unknown service is reported
        // rather than silently accepted — a renewal that "succeeds" against nothing is how a
        // reseller keeps billing a client for hosting that no longer exists.
        $site = zinn_requireSiteId($params);
        Client::fromParams($params)->get('/v1/reseller/services/' . rawurlencode($site), []);
        return 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

/**
 * The buttons a reseller's ADMIN sees on the service.
 *
 * @return array<string,string>
 */
function zinn_AdminCustomButtonArray(): array
{
    return [
        'Sync Usage Now' => 'adminSyncUsage',
        'Purge Cache' => 'adminPurgeCache',
        'Take Backup' => 'adminBackup',
    ];
}

/**
 * The buttons the reseller's CLIENT sees in their client area.
 *
 * ⛔ A strict subset of the admin set, and the omission is the point: taking a backup and
 * purging a cache are safe for a client to do to their own site, and nothing here can change
 * what they are entitled to or what they are billed. Anything that moves money or
 * entitlements belongs on the admin list only.
 *
 * @return array<string,string>
 */
function zinn_ClientAreaCustomButtonArray(): array
{
    return [
        'Purge Cache' => 'clientPurgeCache',
        'Take Backup' => 'clientBackup',
    ];
}

/**
 * Functions a client may invoke that are NOT rendered as buttons.
 *
 * ⛔ WHMCS authorises client-area module calls against this list, so a function absent from
 * it is refused however it is reached. It therefore has to name the button functions too —
 * `_ClientAreaCustomButtonArray` decides what is *drawn*, this decides what may *run*, and
 * a function in the first but not the second is a button that always fails.
 *
 * @return array<string,string>
 */
function zinn_ClientAreaAllowedFunctions(): array
{
    return zinn_ClientAreaCustomButtonArray();
}

/**
 * Refresh one service's usage on demand, from the admin area.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string
 */
function zinn_adminSyncUsage(array $params): string
{
    try {
        $client = Client::fromParams($params);
        $site = zinn_requireSiteId($params);
        $row = $client->get('/v1/reseller/services/' . rawurlencode($site), []);
        zinn_recordUsage($client, ['site_id' => $site] + $row);
        return 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

/**
 * Clear the server-side cache in front of the client's site.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string
 */
function zinn_adminPurgeCache(array $params, ?Client $client = null): string
{
    return zinn_purgeCache($params, $client);
}

/**
 * Clear the server-side cache — the client-area button.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string
 */
function zinn_clientPurgeCache(array $params, ?Client $client = null): string
{
    return zinn_purgeCache($params, $client);
}

/**
 * The one implementation both cache buttons call.
 *
 * ⛔ One function rather than two identical bodies: two copies is two places for the path to
 * be wrong and only one of them would ever be fixed — the same argument `Client` records for
 * not letting each module function roll its own cURL.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string
 */
function zinn_purgeCache(array $params, ?Client $client = null): string
{
    try {
        $site = zinn_requireSiteId($params);
        $client = $client ?: Client::fromParams($params);
        $client->post('/v1/sites/' . rawurlencode($site) . '/cache/purge', []);
        return 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

/**
 * Take an on-demand backup — the admin button.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string
 */
function zinn_adminBackup(array $params, ?Client $client = null): string
{
    return zinn_takeBackup($params, $client);
}

/**
 * Take an on-demand backup — the client-area button.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string
 */
function zinn_clientBackup(array $params, ?Client $client = null): string
{
    return zinn_takeBackup($params, $client);
}

/**
 * The one implementation both backup buttons call.
 *
 * ⛔⛔ THE `?Client $client` ARGUMENT IS A TEST SEAM AND IS INERT IN PRODUCTION. WHMCS calls
 * every module function with exactly one argument, so this is always null in a real install
 * and the function builds its own client exactly as before. It exists because every entry
 * point otherwise begins `Client::fromParams($params)`, which reaches the network — and a
 * module whose entry points cannot be executed without a network is a module whose entry
 * points do not get executed at all. That is most of why three of these shipped unable to
 * provision anything while their suite was green (D17360, D17363).
 *
 * ⭐ An argument rather than a static override on purpose: static mutable state leaks
 * between tests, so one test's double silently answers another test's call and the second
 * test passes for the wrong reason.
 *
 * ⛔ A `409` means a backup is ALREADY RUNNING, and it is reported as success. Telling a
 * client their backup failed when one is in progress makes them press the button again,
 * which is the one thing that cannot help.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string
 */
function zinn_takeBackup(array $params, ?Client $client = null): string
{
    try {
        $site = zinn_requireSiteId($params);
        $client = $client ?: Client::fromParams($params);
        $client->post('/v1/sites/' . rawurlencode($site) . '/backups', []);
        return 'success';
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'already') !== false) {
            return 'success';
        }
        return $e->getMessage();
    }
}

/**
 * The link an ADMIN clicks to open the client's panel from the service page.
 *
 * ⛔⛔ A FORM THAT POSTS BACK TO WHMCS, NEVER A MINTED LINK. A sign-in ticket is single-use
 * and short-lived: rendering one into this page would burn it the moment the admin loaded
 * the service — before they clicked anything — and would leave a live credential in the page
 * source, the browser cache and every proxy in between. `_AdminSingleSignOn` mints it on the
 * click instead, which is exactly what that hook is for.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string HTML.
 */
function zinn_LoginLink(array $params): string
{
    if (zinn_storedSiteId($params) === '') {
        return '<span class="label label-default">Not provisioned yet</span>';
    }
    return '<a href="clientsservices.php?userid=' . (int) ($params['userid'] ?? 0)
        . '&id=' . (int) ($params['serviceid'] ?? 0)
        . '&modop=sso" class="btn btn-default btn-sm" target="_blank" rel="noopener">'
        . 'Open hosting panel</a>';
}

/**
 * Sign an ADMIN into the client's panel, minted at the moment of the click.
 *
 * ⛔ The same one-time grant the client's own button uses, and it is audit-logged against
 * the reseller rather than against the client — staff opening a customer's account is an
 * event somebody may need to account for months later.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return array<string,mixed>
 */
function zinn_AdminSingleSignOn(array $params): array
{
    return zinn_ServiceSingleSignOn($params);
}

/**
 * What the reseller sees on the SERVER entry — a live answer, not a static label.
 *
 * ⛔ Deliberately does not print the API key, its length, or a masked form of it. A masked
 * credential on a page is still an oracle: it says the key is present and how long it is,
 * which is exactly what an attacker with admin read access wants to know first.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return string HTML.
 */
function zinn_AdminLink(array $params): string
{
    $host = trim((string) ($params['serverhostname'] ?? '')) ?: 'api.zinndigital.com';
    return '<p>Zinn Digital&reg; hosting API at <strong>' . htmlspecialchars($host, ENT_QUOTES)
        . '</strong>. Put your reseller API key in the <em>Password</em> field above — it is '
        . 'stored encrypted by WHMCS. Use <em>Test Connection</em> to check it reaches your '
        . 'reseller programme.</p>';
}

/**
 * The extra rows on the admin's Service tab.
 *
 * ⛔⛔ A FAILED READ IS PRINTED, NOT SWALLOWED. This block is where an admin looks when a
 * client complains, so an empty panel on an API error is the single least useful thing it
 * could do — it reads as "nothing wrong here" at the exact moment something is.
 *
 * ⛔ Usage is rendered as "not measured" when null rather than as 0 B. A zero is a fact
 * about the site; a null is a fact about our reading of it, and an admin deciding whether a
 * client is over quota must not be shown the first when we mean the second.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return array<string,string>
 */
function zinn_AdminServicesTabFields(array $params): array
{
    $site = zinn_storedSiteId($params);
    if ($site === '') {
        return ['Zinn service' => 'Not provisioned yet.'];
    }
    try {
        $row = Client::fromParams($params)->get(
            '/v1/reseller/services/' . rawurlencode($site),
            []
        );
    } catch (Throwable $e) {
        return ['Zinn service' => htmlspecialchars($e->getMessage(), ENT_QUOTES)];
    }
    $fields = [
        'Zinn site id' => htmlspecialchars($site, ENT_QUOTES),
        'Status' => htmlspecialchars((string) ($row['status'] ?? 'unknown'), ENT_QUOTES),
        'Plan' => htmlspecialchars((string) ($row['plan_name'] ?? 'none'), ENT_QUOTES),
        'Disk used' => zinn_humanBytes($row['disk_used_bytes'] ?? null),
        'Bandwidth used' => zinn_humanBytes($row['bandwidth_used_bytes'] ?? null),
    ];
    if (!empty($row['held_by_reseller'])) {
        $fields['Hold'] = 'Suspended by you (releasable from the Unsuspend button).';
    } elseif (($row['status'] ?? '') === 'suspended') {
        // ⛔ The distinction matters and the admin cannot see it anywhere else: a suspension
        // our abuse desk placed is NOT releasable by a billing panel, and an admin pressing
        // Unsuspend on one gets a refusal they cannot act on unless they are told this.
        $fields['Hold'] = 'Suspended by Zinn (not releasable from WHMCS — contact support).';
    }
    if (!empty($row['pending_deletion_at'])) {
        $fields['Scheduled deletion'] = htmlspecialchars(
            (string) $row['pending_deletion_at'],
            ENT_QUOTES
        );
    }
    return $fields;
}

/**
 * Metrics WHMCS graphs and can bill overage on.
 *
 * ⛔ A null reading is OMITTED rather than sent as 0. WHMCS would draw the zero as a real
 * datapoint, so a day the platform could not be reached would appear on the reseller's graph
 * as a day the client used nothing — and on an overage-billed product, as a day they owed
 * nothing.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return array<string,array<string,mixed>>
 */
function zinn_MetricProvider(array $params, ?Client $client = null): array
{
    try {
        $site = zinn_requireSiteId($params);
        $client = $client ?: Client::fromParams($params);
        $row = $client->get(
            '/v1/reseller/services/' . rawurlencode($site),
            []
        );
    } catch (Throwable $e) {
        logActivity('Zinn metric provider failed: ' . $e->getMessage());
        return [];
    }
    $metrics = [];
    if (($row['disk_used_bytes'] ?? null) !== null) {
        $metrics['diskusage'] = [
            'value' => (int) $row['disk_used_bytes'],
            'unit' => 'B',
        ];
    }
    if (($row['bandwidth_used_bytes'] ?? null) !== null) {
        $metrics['bandwidth'] = [
            'value' => (int) $row['bandwidth_used_bytes'],
            'unit' => 'B',
        ];
    }
    return $metrics;
}

/**
 * Every service this reseller has, so WHMCS can import services it does not know about.
 *
 * ⛔⛔ PAGINATED TO EXHAUSTION. The list endpoint is cursored, and reading only the first
 * page would present a reseller with a partial import that looks complete — they would tick
 * it, and every service past the first page would be left unmanaged with nothing reporting
 * why (§2.44: the truncated answer and the true answer are the same shape).
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 * @return array<string,mixed>
 */
function zinn_ListAccounts(array $params, ?Client $client = null): array
{
    try {
        $client = $client ?: Client::fromParams($params);
        $accounts = [];
        $cursor = '';
        do {
            $query = $cursor === '' ? [] : ['cursor' => $cursor];
            $page = $client->get('/v1/reseller/services', $query);
            foreach (($page['data'] ?? []) as $row) {
                $accounts[] = [
                    'username' => (string) ($row['site_id'] ?? ''),
                    'domain' => (string) ($row['primary_domain'] ?? ''),
                    'product' => (string) ($row['plan_name'] ?? ''),
                    // ⛔ WHMCS's own vocabulary, not ours. It matches an imported account to
                    // a service by this word, and an unrecognised one is silently skipped.
                    'status' => ($row['status'] ?? '') === 'suspended' ? 'Suspended' : 'Active',
                ];
            }
            $cursor = (string) ($page['page']['next_cursor'] ?? '');
        } while ($cursor !== '');
        return ['success' => true, 'accounts' => $accounts];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Bytes as something an admin can read, or an honest "not measured".
 *
 * ⛔ `null` is NOT zero. See `zinn_recordUsage` — "we could not measure this" and "it used
 * nothing" are different facts and only one of them is a reason to act.
 *
 * @param mixed $bytes The reading, or null.
 * @return string
 */
function zinn_humanBytes($bytes): string
{
    if ($bytes === null) {
        return 'not measured';
    }
    $value = (float) $bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    return round($value, 1) . ' ' . $units[$index];
}
