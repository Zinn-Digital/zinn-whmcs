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
    private const TIMEOUT = 20;

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
            $message = 'The hosting platform answered with status ' . $status . '.';
        }
        // ⛔⛔ THE `details` ARRAY IS THE HALF THAT SAYS WHAT TO DO, AND IT USED TO BE
        // DISCARDED. A validation failure's top-level message is deliberately generic —
        // "The request was well-formed but failed validation." — because the platform puts
        // the actionable half in `details`: which field, and why. Dropping it left a
        // reseller's admin staring at a sentence that names nothing, on the one screen
        // where they are trying to work out what to change (D17362).
        $message .= self::detailSuffix($decoded);
        throw new RuntimeException($message);
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
        $headers = $this->headers($body !== null, $idempotencyKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
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
            throw new RuntimeException('Could not reach the hosting platform: ' . $transportError);
        }

        return $this->interpret($status, (string) $raw);
    }
}
