<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\ImapManager;
use App\Settings;
use App\WebmailSso;

/**
 * Webmail entry point for partners and admins.
 *
 * The feature no longer implements its own inbox/read/compose UI (that
 * required re-parsing raw MIME email bodies in-house, which proved fragile
 * — see git history). Instead it opens the hosting provider's own cPanel
 * Webmail/Roundcube portal, ideally already authenticated via cPanel's
 * official SSO API (WebmailSso), falling back to the plain login page
 * (manual login with the same email/password already used for IMAP) if SSO
 * isn't configured or fails.
 */
final class EmailController extends Controller
{
    /**
     * Open the hosting's webmail portal, single-signed-on when possible.
     *
     * cPanel's webmail SSO isn't a simple redirect: the browser itself must
     * POST the session token to the login endpoint so the resulting session
     * cookie is set for the user's own browser (see WebmailSso). This
     * renders a tiny auto-submitting HTML form to do that; on any failure
     * it falls back to a plain redirect to the manual webmail login page.
     */
    public static function openWebmail(): void
    {
        $user = Auth::requireUser();

        if (!self::isEmailAllowed($user)) {
            self::redirect('/account', 'Accès non autorisé.', 'error');
        }

        $email = (string) ($user['email'] ?? '');
        $session = $email !== '' ? WebmailSso::createSession($email) : null;

        if ($session !== null) {
            self::renderAutoSubmit($session['post_url'], $session['session']);
            return;
        }

        $domain = trim((string) Settings::get('EMAIL_DOMAIN', 'grand-baie-maurice.com'));
        $message = 'Connexion automatique indisponible : connectez-vous avec votre email et le mot de passe configuré dans votre profil.';
        // Surface the technical reason too: this page is already gated to
        // admins/partners on the configured EMAIL_DOMAIN (isEmailAllowed),
        // a small, trusted internal audience, so showing the raw cPanel
        // API diagnostic here (instead of admin-only) lets a partner
        // report the exact failure without needing an admin to reproduce it.
        $reason = WebmailSso::getLastError();
        if ($reason !== '') {
            $message .= ' [diagnostic] ' . $reason;
        }
        self::redirect('https://webmail.' . $domain, $message, 'info');
    }

    /**
     * Render a minimal HTML page that auto-submits the cPanel webmail
     * session token via POST, landing the browser directly in Roundcube
     * (goto_uri) once cPanel accepts the session.
     */
    private static function renderAutoSubmit(string $postUrl, string $session): void
    {
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>Connexion au Webmail…</title></head>
<body>
  <p>Connexion au Webmail en cours…</p>
  <form id="webmail-sso-form" method="post" action="<?= htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="session" value="<?= htmlspecialchars($session, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="goto_uri" value="/3rdparty/roundcube/index.php">
  </form>
  <script>document.getElementById('webmail-sso-form').submit();</script>
</body>
</html>
        <?php
        exit;
    }

    /**
     * Get unread email count (AJAX, used by the navbar badge)
     */
    public static function unreadCount(): void
    {
        $user = Auth::user();
        if (!$user || !self::isEmailAllowed($user)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['unread_count' => 0], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $count = ImapManager::getUnreadCount((int) $user['id']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['unread_count' => $count], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Check if user is allowed to access the email feature: must be an
     * admin/partner AND have a mailbox on the configured domain (see
     * ImapManager::isEmailDomainAllowed()) — a partner with, say, a Gmail
     * address has no mailbox on this mail server, so the feature must stay
     * unavailable to them even though their role would otherwise qualify.
     */
    private static function isEmailAllowed(array $user): bool
    {
        $roleOk = ($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'partner';
        return $roleOk && ImapManager::isEmailDomainAllowed((string) ($user['email'] ?? ''));
    }
}
