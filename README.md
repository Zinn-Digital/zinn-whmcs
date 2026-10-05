# WHMCS module — Zinn Digital® hosting

A provisioning module so a WHMCS shop can sell Zinn® hosting without leaving WHMCS.

## What it does

| WHMCS action                | What happens                                                                                        |
| --------------------------- | --------------------------------------------------------------------------------------------------- |
| **Create**                  | Creates the client's Zinn® account (or reuses the one they already have) and provisions their site. |
| **Suspend**                 | Puts the site on a non-payment hold. WHMCS's own overdue-invoice automation drives this.            |
| **Unsuspend**               | Releases _your_ hold. A site our abuse team suspended is refused, with a sentence saying so.        |
| **Terminate**               | Schedules the site's deletion. The scheduled date is shown in the client area.                      |
| **Usage update**            | Refreshes disk and bandwidth on every service, once a day.                                          |
| **Log in to hosting panel** | Opens a one-time, single-use sign-in link straight into the client's panel.                         |
| **Test connection**         | Makes a real call and reports what the platform said — not a "the field is filled in" check.        |

## Install

1. Copy `zinn/` into your WHMCS `modules/servers/` directory, so the module file lands at
   `modules/servers/zinn/zinn.php`.
2. In WHMCS, go to **Configuration → System Settings → Servers** and add a server:
   - **Type**: `Zinn Digital`
   - **Hostname**: `api.zinndigital.com`
   - **Password**: your Zinn® API key (see below)
3. Create a product and set its **Module Settings** to that server. The fields are:

   | Field                | Required | What it is                                                                                                                                                                                                                                                                                                               |
   | -------------------- | -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
   | **Product line**     | yes      | The Zinn® line you are selling — `mainstream`, `footprint_free`, `wordpress`, `linux`, `cloud`, `agency`, `app_hosting`, `ai_hosting`, `lms_hosting`, `managed_database`, `vector_hosting`, `zinn_compute`, `fleet_linux`, `fleet_enterprise`, `mail`, `reseller`. `GET /v1/reseller/lines` lists the ones **you** sell. |
   | **Stack**            | yes      | What gets built: `wordpress` (the default), `woocommerce`, `php`, `static`, `node`, `one_click`, `headless_cms`, `nextcloud`, `owncloud`.                                                                                                                                                                                |
   | **Application**      | no       | Only for `one_click` and `headless_cms` — the application key to install. Naming one on any other stack is refused, because that stack already decides what gets installed.                                                                                                                                              |
   | **PHP version**      | no       | e.g. `8.3`. Leave empty for the line's default. A version the line does not offer is refused with the list of the ones it does.                                                                                                                                                                                          |
   | **Plan code**        | **yes**  | The Zinn® plan this product sells. `GET /v1/reseller/prices` lists yours. **An order without it is refused rather than provisioned** — see the warning below.                                                                                                                                                            |
   | **Billing interval** | no       | `monthly` (default) or `annual` — the interval _your_ wholesale line for this client is raised on. What you charge your own client, and how often, is set on the WHMCS product and is unaffected.                                                                                                                        |

   ⛔ **Stack is required by the platform, and leaving it unset is what "wordpress" is for.**
   `POST /v1/sites` refuses an order that names no stack — before 2026-08-31 this module sent
   none at all, so every order failed (D17360).

   ⛔⛔ **PLAN CODE IS REQUIRED, AND AN ORDER WITHOUT ONE IS REFUSED ON PURPOSE (D17700).**
   Until 2026-09-01 this field was decorative and nothing read it, so the module ordered
   hosting that carried **no plan at all** — and every consequence of that was silent:

   - your **wholesale statement is built from your clients' live subscriptions**, so there
     was no line for the service and Zinn® invoiced you **nothing** for as long as it ran;
   - the client inherited no entitlements, so no disk or file quota was ever applied to
     their site;
   - **Change Package had nothing to change**, so an upgrade you sold could not be performed.

   The site provisioned and served perfectly throughout, and nothing at either end reported
   any of it. The module now refuses the order instead, because there is no safe plan to
   guess: a guess provisions hosting on a plan you did not choose and may not have priced,
   and — unlike a refusal, which you read immediately — it surfaces only when a statement
   arrives, if at all.

4. Press **Test Connection** on the server. It calls the API and reports the answer.

### Before your first order: you need a plan with site allowance

⛔ **The module cannot conjure hosting out of nothing.** Sites your clients order are counted
against **your** reseller plan, so an order placed before you hold one is refused with:

> This organization has no hosting plan, so it cannot host a site yet. Choose a plan to
> continue. (max_sites: 0; sites_in_use: 0)

That is not a module fault and re-installing will not change it — buy or extend your reseller
plan in the Zinn® dashboard and the same order succeeds. ⭐ **Test Connection cannot tell you
this**, because it reads your programme rather than trying to provision anything: a green
connection test is not evidence that an order will succeed.

## The API key

Create it in your Zinn® dashboard under **API keys**, and give it only what this module
needs:

| Permission              | Why                                                                              |
| ----------------------- | -------------------------------------------------------------------------------- |
| `org.create`            | **Create a client account on the first order.**                                  |
| `sites.create`          | Provision a site.                                                                |
| `sites.view`            | Read a service, its PHP version and its backups.                                 |
| `sites.delete`          | Terminate.                                                                       |
| `reseller.view`         | List your services, read usage, read a package before changing it.               |
| `reseller.provision`    | Suspend, release, sign a client in, **set their plan and change their package**. |
| `sites.cache.purge`     | The Purge Cache buttons.                                                         |
| `hosting.backup.manage` | The Take Backup buttons.                                                         |
| `sites.panel_access`    | Read the WordPress users and set the administrator's password.                   |
| `hosting.php.manage`    | Switch PHP version on an upgrade or downgrade.                                   |

> ⛔⛔ **This table is a claim about the key, it has been wrong three times, and it is now the
> only one nobody has to maintain by hand.** It omitted `org.create` until 2026-08-31
> (`403` on the first order), four more rows until 2026-09-01, and until 2026-09-02 it named
> **`sites.manage`, which is not a permission this platform has** — so a key minted from the
> table as written was refused outright, and a reseller who worked around that by dropping
> the row silently lost cache purging and backups (D18634).
>
> ⭐ **It is now derived, not written.** `engine/api/tests/test_integration_module_permissions.py`
> reads every `(method, path)` this module calls out of `zinn.php`, resolves each against the
> live URL map, asks the real view which permissions it enforces, and fails if this table is
> not exactly that set — in **both** directions, so an over-granted row is caught as well as a
> missing one. It also counts the call sites in the file, so an extractor that goes blind fails
> loudly instead of quietly agreeing.
>
> ⛔ A key missing any of these still passes **Test Connection**, because that call reads your
> programme and provisions nothing. What you get instead is a `403` on the one operation the
> missing permission covers, months later, from a customer. **Grant the whole table.**
>
> ⚠️ `org.read` and `billing.view` were listed here until 2026-09-02 and no call in this module
> needs either. They are removed rather than kept "just in case": this key can create and
> destroy a client's hosting, and a row nothing uses is reach nobody audits.

> ⛔ **`org.create` is not optional and this table omitted it until 2026-08-31.** A key
> granted exactly the six permissions listed before passes **Test Connection** — that call
> reads your programme and provisions nothing — and then answers `403` on the first order,
> because the module has to create the client's Zinn® account before it can create their
> site. Measured against the live API, not inferred (D17361).

⛔ The key goes in the server's **Password** field, which WHMCS stores encrypted. Do **not**
put it in a product configuration option: those are plain text in the database and visible to
every admin, and this credential can create and destroy a client's hosting.

## What your resellers get, button by button

Everything below is driven by WHMCS's own controls — there are no core edits, no hooks into
WHMCS internals, and nothing to reapply after a WHMCS upgrade.

| WHMCS control                                     | What it does here                                                                                                                       |
| ------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| **Create**                                        | Puts the client on the product's plan, then provisions their site on it.                                                                |
| **Suspend / Unsuspend**                           | Driven by WHMCS's own overdue-invoice automation.                                                                                       |
| **Terminate**                                     | Schedules deletion with a grace period, and shows the client the date.                                                                  |
| **Change Package**                                | Upgrade or downgrade. Moves the package and, if the new product names one, the PHP version.                                             |
| **Change Password**                               | Sets the **WordPress administrator's** password. Hidden on stacks that have no such account.                                            |
| **Renew**                                         | Confirms the service still exists. Zinn® hosting is continuous, so there is no remote term to extend.                                   |
| **Log in to hosting panel**                       | A one-time, single-use link, minted when it is clicked and never rendered into a page.                                                  |
| **Admin: Sync Usage / Purge Cache / Take Backup** | On the service's admin page.                                                                                                            |
| **Client area: Purge Cache / Take Backup**        | Safe for a client to do to their own site; neither can change what they are entitled to or billed.                                      |
| **Service tab**                                   | Status, plan, disk, bandwidth, and **who** placed a suspension — a hold Zinn® placed is not releasable from WHMCS, and the tab says so. |
| **Usage sync**                                    | A daily job writes disk and bandwidth back into WHMCS so overage billing works.                                                         |
| **Import**                                        | `ListAccounts` pages to exhaustion, so an import cannot silently stop at the first page.                                                |

⛔ **Change Password exists only for WordPress, and that is deliberate.** Zinn® hosting has no
single "service password" — a client reaches their panel by single sign-on, and a `php` or
`static` site has no application account to change. WHMCS hides the box entirely when a
module implements no `_ChangePassword`, so the alternative here is not a worse experience but
no box at all: right for a stack with no password, wrong for WordPress, where resetting it is
the commonest request a reseller gets.

⛔ **Your client is never invoiced by Zinn®, on an order or an upgrade.** You bill them
through WHMCS; we bill you, on your wholesale statement. An upgrade performed here therefore
moves entitlements and the wholesale line, and touches no card — WHMCS has already taken your
client's money and computed its own proration.

## What it does not do

This module has no privileged path into our platform. Every call it makes is a documented
endpoint any reseller can call with their own key —
[the API reference](https://zinndigital.com/developers/api) lists all of them. If it ever
needs something the public API cannot do, that is an API gap for us to close in the spec, not
a workaround to add here.

## Licence

GPL-2.0-or-later. Author: Neil Lock — CEO, Zinn Digital® Ltd — <https://zinndigital.com>
