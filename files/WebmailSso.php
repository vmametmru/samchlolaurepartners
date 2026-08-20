<?php

declare(strict_types=1);

namespace App;

/**
 * Single sign-on into the hosting provider's cPanel Webmail (Roundcube)
 * portal, using cPanel's official UAPI
 * `Session::create_webmail_session_for_mail_user` call.
 *
 * This lets an already-authenticated admin/partner open their inbox
 * without retyping their mailbox password, using a privileged cPanel API
 * token (account-level credential, configured once by the hosting owner)
 * rather than the mailbox's own IMAP password — the cPanel account that
 * owns the token already has authority over every mailbox under its
 * domain(s), so no mailbox password is required for this call.
 *
 * Unlike a plain redirect URL, cPanel's webmail session requires the
 * browser to POST the returned `session` value to the login endpoint (so
 * the resulting session cookie is set for the *user's own browser*, not
 * our server) — see EmailController::openWebmail(), which renders a small
 * auto-submitting HTML form for this.
 *
 * Requires three settings configured on the admin "Configuration du
 * serveur de messagerie" page: CPANEL_HOST, CPANEL_USERNAME,
 * CPANEL_API_TOKEN. If any is missing, or the API call fails for any
 * reason, callers should fall back to linking directly to the plain
 * webmail login page (manual login).
 */
final class WebmailSso
{
    /**
     * Human-readable reason the last createSession() call failed/returned
     * null, e.g. "CPANEL_HOST/CPANEL_USERNAME/CPANEL_API_TOKEN non
     * configurés" or the raw cPanel API error. Surfaced to admins only
     * (see EmailController::openWebmail()) so a misconfiguration can be
     * diagnosed without needing hosting-level log access.
     */
    private static string $lastError = '';

    public static function getLastError(): string
    {
        return self::$lastError;
    }

    /**
     * Request a one-time webmail login session for the given mailbox.
     * Returns ['post_url' => string, 'session' => string] on success, or
     * null if SSO isn't configured or the API call fails — callers must
     * fall back to a plain (manual login) webmail link in that case.
     *
     * @return array{post_url: string, session: string}|null
     */
    public static function createSession(string $email): ?array
    {
        self::$lastError = '';

        $host = trim((string) Settings::get('CPANEL_HOST', ''));
        $username = trim((string) Settings::get('CPANEL_USERNAME', ''));
        $apiToken = trim((string) Settings::get('CPANEL_API_TOKEN', ''));

        $atPos = strrpos($email, '@');
        if ($host === '' || $username === '' || $apiToken === '') {
            self::$lastError = 'CPANEL_HOST/CPANEL_USERNAME/CPANEL_API_TOKEN non configurés (page Configuration du serveur de messagerie).';
            return null;
        }
        if ($atPos === false) {
            self::$lastError = 'Adresse email utilisateur invalide : "' . $email . '".';
            return null;
        }

        $login = substr($email, 0, $atPos);
        $domain = substr($email, $atPos + 1);
        if ($login === '' || $domain === '') {
            self::$lastError = 'Adresse email utilisateur invalide : "' . $email . '".';
            return null;
        }

        $url = 'https://' . $host . ':2083/execute/Session/create_webmail_session_for_mail_user'
            . '?' . http_build_query(['login' => $login, 'domain' => $domain, 'service' => 'webmail']);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: cpanel ' . $username . ':' . $apiToken,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            self::$lastError = 'Requête cURL vers ' . $host . ' échouée : ' . $curlError;
            error_log('[WebmailSso] cPanel API request failed: ' . $curlError);
            return null;
        }

        if ($httpCode !== 200) {
            self::$lastError = 'cPanel a répondu HTTP ' . $httpCode . ' (vérifiez CPANEL_HOST/CPANEL_USERNAME/CPANEL_API_TOKEN) : ' . substr((string) $response, 0, 300);
            error_log('[WebmailSso] cPanel API returned HTTP ' . $httpCode . ': ' . substr((string) $response, 0, 500));
            return null;
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || empty($data['status'])) {
            $errorDetail = is_array($data) ? ($data['errors'][0] ?? json_encode($data)) : (string) $response;
            self::$lastError = 'Appel API cPanel refusé : ' . (is_string($errorDetail) ? $errorDetail : json_encode($errorDetail));
            error_log('[WebmailSso] cPanel API call unsuccessful: ' . $errorDetail);
            return null;
        }

        $session = $data['data']['session'] ?? null;
        $sessionToken = $data['data']['token'] ?? null;
        // The API can return a null hostname (observed in practice) when it
        // can't determine the account's own server hostname — fall back to
        // the configured CPANEL_HOST (the vanity webmail subdomain) in that
        // case, since it resolves to the same server.
        $hostname = $data['data']['hostname'] ?? null;
        if (!is_string($hostname) || $hostname === '') {
            $hostname = $host;
        }

        if (!is_string($session) || $session === '' || !is_string($sessionToken) || $sessionToken === '') {
            self::$lastError = 'Réponse cPanel inattendue (session/token manquant) : ' . substr((string) $response, 0, 300);
            return null;
        }

        return [
            'post_url' => 'https://' . $hostname . ':2096' . $sessionToken . '/login',
            'session' => $session,
        ];
    }
}
