<?php
/**
 * The HTTP client every Zinn WHMCS call goes through.
 *
 * ⛔ ONE class. Six module functions each rolling their own cURL is six places the API key
 * can reach a log, six timeout policies and six ideas of what an error looks like — and
 * only one of them would ever be fixed.
 *
 * @package Zinn\WHMCS
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Zinn\WHMCS;

use RuntimeException;

/**
 * A thin JSON client for the Zinn public API.
 *
 * ⛔⛔ NOT `final`, AND THAT IS A TEST SEAM RATHER THAN AN OVERSIGHT. It was final until
 * 2026-09-01, and the consequence was that no module entry point could be exercised without
 * a network: every function begins `Client::fromParams($params)`, so a test could reach the
 * request body only for the handful of helpers that take a client as an argument. That is
 * most of why three modules shipped unable to provision anything (D17360, D17363) while
 * their suite was green — the suite could only ever reach `interpret()` and the header list.
 *
 * The double a test uses extends this and overrides `get`/`post`/`put`/`delete`, recording
 * what was asked for. ⛔ It must NOT override `interpret()` or `headers()`: those two are
 * the things worth asserting, and a double that reimplements them is a test agreeing with
 * its own author rather than with the platform.
 */
class Client
{
    /**
     * Seconds to wait. WHMCS runs provisioning inside a request, and an admin staring at a
     * spinner for a minute will click again — which is precisely the double-provision the
     * idempotency keys exist to survive. Short, and honest about it.
     */
    public const TIMEOUT = 20;

    /**
     * Seconds to wait on a call that carries an `Idempotency-Key`.
     *
     * ⛔⛔ IT MUST EXCEED THE PLATFORM'S IN-FLIGHT LOCK, AND 20 DID NOT (D18631, measured in
     * WHMCS 9.0.7 against production on 2026-09-02). `IdempotentCreateMixin.replay` reserves
     * the key for **30 seconds** and answers a repeat inside that window with
     * `409 A request with this Idempotency-Key is already in progress.` So a client that
     * gives up at 20s is in the one state with no way out: the site really is being created,
     * the answer carrying its id is discarded, and the retry that would recover it is
     * refused for another ten seconds.
     *
     * ⭐ Measured: `POST /v1/sites` timed out at exactly 20.0s, the site existed on the
     * platform, WHMCS recorded nothing, and the service stayed Pending while the reseller's
     * wholesale line ran. A create that succeeds and reports failure is worse than one that
     * fails, because the reseller refunds a customer who has hosting.
     *
     * ⛔ Only keyed calls get the longer wait. A read that hangs should fail fast; a keyed
     * write is safe to wait on precisely because a duplicate cannot be created.
     */
    private const PROVISION_TIMEOUT = 60;

    private string $base;
    private string $key;

    /**
     * @param string $base API base URL.
     * @param string $key  API key.
     */
    public function __construct(string $base, string $key)
    {
        $this->base = rtrim($base, '/');
        $this->key = $key;
    }

    /**
     * Build a client from WHMCS's module parameters.
     *
     * ⛔ The key comes from the SERVER entry's password field, which WHMCS stores encrypted
     * and decrypts into `serverpassword`. It is deliberately not a product config option:
     * those are plain text in the database and visible to every admin, and this credential
     * can create and destroy a client's hosting.
     *
     * @param array<string,mixed> $params WHMCS module parameters.
     * @return self
     */
    public static function fromParams(array $params): self
    {
        $host = trim((string) ($params['serverhostname'] ?? ''));
        $base = $host === '' ? 'https://api.zinndigital.com' : $host;
        if (!preg_match('#^https?://#i', $base)) {
            // A WHMCS server entry usually holds a bare hostname. TLS is not negotiable for
            // a credential that can destroy hosting, so the scheme is forced rather than
            // guessed from a checkbox somebody might have left off.
            $base = 'https://' . $base;
        }
        $key = trim((string) ($params['serverpassword'] ?? ''));
        if ($key === '') {
            throw new RuntimeException(
                'No Zinn API key on this server entry. Put the key in the server Password field.'
            );
        }
        return new self($base, $key);
    }

    /**
     * GET a path.
     *
     * @param string               $path  API path beginning with `/v1/`.
     * @param array<string,scalar> $query Query parameters.
     * @return array<string,mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $url = $this->base . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        return $this->send('GET', $url, null, '');
    }

    /**
     * POST a JSON body.
     *
     * @param string              $path           API path beginning with `/v1/`.
     * @param array<string,mixed> $body           JSON body.
     * @param string              $idempotencyKey Stable key for this logical operation.
     * @return array<string,mixed>
     */
    public function post(string $path, array $body, string $idempotencyKey = ''): array
    {
        return $this->send('POST', $this->base . $path, $body, $idempotencyKey);
    }

    /**
     * PUT a JSON body.
     *
     * ⛔ Distinct from :meth:`post` rather than a flag on it, because the platform
     * distinguishes them: ``PUT /v1/sites/{id}/php-version`` is a replace and answers 202,
     * while the POST paths create. A client that sent POST everywhere would get 405s on
     * exactly the half of the module that changes an existing service.
     *
     * @param string              $path           API path beginning with `/v1/`.
     * @param array<string,mixed> $body           JSON body.
     * @param string              $idempotencyKey Stable key for this logical operation.
     * @return array<string,mixed>
     */
    public function put(string $path, array $body, string $idempotencyKey = ''): array
    {
        return $this->send('PUT', $this->base . $path, $body, $idempotencyKey);
    }

    /**
     * DELETE a path.
     *
     * @param string $path API path beginning with `/v1/`.
     * @return array<string,mixed>
     */
    public function delete(string $path, ?array $body = null): array
    {
        return $this->send('DELETE', $this->base . $path, $body, '');
    }

    /**
     * The headers one request carries.
     *
     * ⭐ Public and pure so a test can assert the `Idempotency-Key` is actually sent. A
     * retried provision that silently omitted it would bill the reseller for a second site,
     * and that is not visible in a diff — only in a header list.
     *
     * @param bool   $hasBody        Whether a JSON body is being sent.
     * @param string $idempotencyKey Idempotency key, or an empty string.
     * @return array<int,string>
     */

    /**
     * The `Idempotency-Key` a request actually carries.
     *
     * ⛔⛔ THE CALLER'S KEY NAMES THE OPERATION; THE BODY HASH SAYS WHICH ONE (D18635).
     * The module's keys are stable per service (`whmcs-site-42`), per client (`whmcs-org-7`)
     * and per plan — which is right for a retry and wrong for everything else, because the
     * platform fingerprints the BODY and answers a key reused with a different one:
     *
     *     {"result":"error","message":"Idempotency-Key reused with a different request body."}
     *
     * Measured inside WHMCS 9.0.7 on 2026-09-02: terminate a service, re-order it on a new
     * domain, and the create is refused — for the full 24-hour replay window, on the one
     * button that sells anything. The same trap fires when a client's company name is edited
     * (the org body changes) or a product's billing interval is switched.
     *
     * ⭐ Hashing the body REMOVES the class rather than patching three call sites, and it
     * keeps the property the keys exist for: a genuine retry sends the same bytes, so it
     * still replays; a different request is a different operation and is allowed to proceed.
     * Fixing the three call sites individually would have been an enumeration of the cases
     * somebody thought of (§2.24).
     *
     * @param string                   $logicalKey The caller's key, or an empty string.
     * @param array<string,mixed>|null $body       The JSON body, or null.
     * @return string
     */

    /**
     * The bytes a JSON body is sent as.
     *
     * ⛔⛔ AN EMPTY PHP ARRAY IS A JSON **LIST**, AND THE PLATFORM REFUSES ONE (D18636).
     * `json_encode([])` is `[]`, not `{}`, so every call this module makes with no fields —
     * unsuspend, single sign-on, take backup, purge cache — sent a list where an object was
     * required and came back:
     *
     *     422 non_field_errors: Invalid data. Expected a dictionary, but got list.
     *
     * Measured through a real WHMCS on 2026-09-02: **Purge Cache had never worked**, from the
     * admin page or the client area, and neither had the other three. It is D17360's shape
     * exactly — a body the platform will not accept — and it survived because the suite
     * asserts the body as a PHP array, where `[]` and "an empty object" are the same value.
     * Only the wire tells them apart.
     *
     * ⭐ Fixed here rather than at the four call sites, and the difference matters: passing
     * `new stdClass()` at each one is an enumeration of the calls somebody remembered, and the
     * fifth would be wrong again (§2.24).
     *
     * @param array<string,mixed>|null $body The JSON body, or null for none.
     * @return string|null
     */
    public static function encodeBody(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }
        // An empty array is the only ambiguous case: PHP cannot tell "no fields" from
        // "an empty list", and JSON must.
        return $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR);
    }

    public static function idempotencyKeyFor(string $logicalKey, ?array $body): string
    {
        if ($logicalKey === '') {
            return '';
        }
        $encoded = $body === null ? '' : (string) json_encode($body);
        return $logicalKey . '-' . substr(sha1($encoded), 0, 10);
    }

    public function headers(bool $hasBody, string $idempotencyKey): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->key,
            'Accept: application/json',
            'User-Agent: ZinnWHMCS/1.0',
        ];
        if ($hasBody) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        return $headers;
    }

    /**
     * Turn a status + raw body into decoded data, or throw with the API's own message.
     *
     * ⛔⛔ Public and pure for the same reason `headers()` is: this decides what a reseller's
     * admin reads when something goes wrong, and it is the one thing in this file worth a
     * test. `integrations/tests/ClientBehaviourTest.php` runs the same cases against this
     * and against the HostBill copy, so a fix applied to one and not the other goes red.
     *
     * @param int    $status HTTP status.
     * @param string $raw    Raw response body.
     * @return array<string,mixed>
     */
    public function interpret(int $status, string $raw): array
    {
        $decoded = json_decode($raw, true);
        if ($status >= 200 && $status < 300) {
            return is_array($decoded) ? $decoded : [];
        }
        $message = '';
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            $message = (string) $decoded['error']['message'];
        }
        if ($message === '') {
            // ⛔⛔ A 5xx IS OUR FAULT AND THE OLD SENTENCE READ LIKE THE RESELLER'S (D18637).
            // "The hosting platform answered with status 500." names no cause and no next
            // step, so a reseller re-checks their key, their product and their plan — none of
            // which is wrong. Measured on 2026-09-02: three buttons answered 500 on a site
            // whose vendor account was missing, and the platform's own log carried a perfect
            // sentence saying exactly that, which the response body did not.
            $message = $status >= 500
                ? 'The hosting platform had a fault of its own (HTTP ' . $status . '), so '
                    . 'nothing here is wrong with your settings. It is safe to try again in a '
                    . 'few minutes; if it keeps happening, send Zinn support this service and '
                    . 'the time you pressed it.'
                : 'The hosting platform answered with status ' . $status . '.';
        }
        // ⛔⛔ THE `details` ARRAY IS THE HALF THAT SAYS WHAT TO DO, AND IT USED TO BE
        // DISCARDED. A validation failure's top-level message is deliberately generic —
        // "The request was well-formed but failed validation." — because the platform puts
        // the actionable half in `details`: which field, and why. Dropping it left a
        // reseller's admin staring at a sentence that names nothing, on the one screen
        // where they are trying to work out what to change (D17362).
        $message .= self::detailSuffix($decoded);
        // ⛔⛔ THE STATUS TRAVELS WITH THE MESSAGE, AND WITHOUT IT A TERMINATION DELETED
        // NOTHING (D18633). A caller that has to decide *"is this gone, or did I fail to
        // ask?"* cannot read a sentence — and reading every failure as "gone" is the
        // reassuring direction (§2.44). Measured live: a transient `502` on the pre-delete
        // lookup made WHMCS mark a service Terminated while the site kept running and the
        // reseller kept being invoiced for it.
        //
        // ⭐ Carried as the exception CODE rather than a new exception class: every existing
        // `catch (RuntimeException)` and every message assertion is unchanged, and a caller
        // that does not care never sees it. A transport failure keeps code 0 — deliberately
        // NOT a status, because "we never got an answer" is a different fact from any status.
        throw new RuntimeException($message, $status);
    }

    /**
     * The ` (field: reason)` tail appended to a refusal, or an empty string.
     *
     * ⛔ Deduplicated and capped. A bulk refusal can carry a `details` entry per row, and a
     * WHMCS module's returned string is rendered into an admin page — an unbounded paste of
     * fifty identical reasons is a message nobody reads, which is the same outcome as
     * having no message at all.
     *
     * ⭐ `code: "none"` entries carry a bare VALUE rather than a sentence — the platform
     * uses them to attach numbers to an error (`max_sites: 0`, `sites_in_use: 0` on
     * `PLAN_REQUIRED`) — so they are rendered as `field: value`, which is what they mean.
     *
     * @param mixed $decoded The decoded response body.
     * @return string
     */
    private static function detailSuffix($decoded): string
    {
        if (!is_array($decoded) || !isset($decoded['error']['details'])) {
            return '';
        }
        $details = $decoded['error']['details'];
        if (!is_array($details)) {
            return '';
        }
        $parts = [];
        foreach ($details as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $field = trim((string) ($entry['field'] ?? ''));
            $reason = trim((string) ($entry['message'] ?? ''));
            if ($field === '' && $reason === '') {
                continue;
            }
            $part = $field === '' ? $reason : $field . ': ' . $reason;
            if (!in_array($part, $parts, true)) {
                $parts[] = $part;
            }
            if (count($parts) === 5) {
                break;
            }
        }
        return $parts === [] ? '' : ' (' . implode('; ', $parts) . ')';
    }

    /**
     * Perform the call and decode the answer.
     *
     * ⛔⛔ A non-2xx throws with the API's OWN message. The platform's errors are written to
     * be read by a person — "This service cannot be suspended from its current state. It may
     * already be suspended, still being set up, or held by our abuse team." — and WHMCS
     * shows a module's returned string to the admin verbatim. Replacing that with "Request
     * failed" would throw away the only useful half of the response, on the one screen where
     * somebody is trying to work out what to do.
     *
     * @param string                   $method         HTTP method.
     * @param string                   $url            Absolute URL.
     * @param array<string,mixed>|null $body           JSON body, or null.
     * @param string                   $idempotencyKey Idempotency key, or an empty string.
     * @return array<string,mixed>
     */
    private function send(string $method, string $url, ?array $body, string $idempotencyKey): array
    {
        $idempotencyKey = self::idempotencyKeyFor($idempotencyKey, $body);
        $headers = $this->headers($body !== null, $idempotencyKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $idempotencyKey === '' ? self::TIMEOUT : self::PROVISION_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, self::encodeBody($body));
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $transportError = curl_error($ch);
        curl_close($ch);

        // ⛔ The module log records the REQUEST with the credential replaced. WHMCS shows
        // this log to admins and keeps it for weeks; a bearer token in it is a credential
        // published to everyone with admin access and to every backup of the database.
        if (function_exists('logModuleCall')) {
            logModuleCall(
                'zinn',
                $method . ' ' . $url,
                $body === null ? '' : $body,
                (string) $raw,
                '',
                [$this->key, 'Bearer ' . $this->key]
            );
        }

        if ($raw === false) {
            // ⛔⛔ A KEYED WRITE THAT DID NOT ANSWER IS NOT A WRITE THAT DID NOT HAPPEN, AND
            // SAYING SO IS THE DIFFERENCE BETWEEN A RETRY AND A REFUND (D18631). The old
            // sentence — "Could not reach the hosting platform: Operation timed out" — reads
            // as *nothing happened*, which is the reassuring direction and the wrong one: the
            // site was created, the reseller's wholesale line was running, and the admin's
            // natural response to "could not reach" is to cancel the order.
            if ($idempotencyKey !== '') {
                throw new RuntimeException(
                    'The hosting platform did not answer in time (' . $transportError . '). '
                    . 'The operation may still have completed — wait a minute and press the '
                    . 'same button again. It carries an idempotency key, so a repeat returns '
                    . 'the same account rather than creating a second one.'
                );
            }
            throw new RuntimeException('Could not reach the hosting platform: ' . $transportError);
        }

        return $this->interpret($status, (string) $raw);
    }
}
