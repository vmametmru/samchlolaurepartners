<?php

declare(strict_types=1);

namespace App;

/**
 * Single sign-on into the hosting provider's cPanel Webmail (Roundcube)
 * portal, using cPanel's official UAPI `Email::create_user_session` call.
 *
 * This lets an already-authenticated admin/partner open their inbox
 * without retyping their mailbox password: cPanel issues a short-lived,
 * one-time authenticated URL for a given mailbox, using a privileged
 * cPanel API token (account-level credential, configured once by the
 * hosting owner) rather than the mailbox's own IMAP password.
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
     * Request a one-time authenticated webmail URL for the given mailbox.
     * Returns null if SSO isn't configured or the API call fails — callers
     * must fall back to a plain (manual login) webmail link in that case.
     */
    public static function createSessionUrl(string $email): ?string
    {
        $host = trim((string) Settings::get('CPANEL_HOST', ''));
        $username = trim((string) Settings::get('CPANEL_USERNAME', ''));
        $token = trim((string) Settings::get('CPANEL_API_TOKEN', ''));

        if ($host === '' || $username === '' || $token === '' || $email === '') {
            return null;
        }

        $url = 'https://' . $host . ':2083/execute/Email/create_user_session'
            . '?' . http_build_query(['user' => $email, 'service' => 'webmail']);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: cpanel ' . $username . ':' . $token,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log('[WebmailSso] cPanel API request failed: ' . $curlError);
            return null;
        }

        if ($httpCode !== 200) {
            error_log('[WebmailSso] cPanel API returned HTTP ' . $httpCode . ': ' . substr((string) $response, 0, 500));
            return null;
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || empty($data['status'])) {
            $errorDetail = is_array($data) ? ($data['errors'][0] ?? json_encode($data)) : (string) $response;
            error_log('[WebmailSso] cPanel API call unsuccessful: ' . $errorDetail);
            return null;
        }

        $sessionUrl = $data['data']['url'] ?? null;
        return is_string($sessionUrl) && $sessionUrl !== '' ? $sessionUrl : null;
    }
}
