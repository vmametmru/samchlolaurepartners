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
     */
    public static function openWebmail(): never
    {
        $user = Auth::requireUser();

        if (!self::isEmailAllowed($user)) {
            self::redirect('/account', 'Accès non autorisé.', 'error');
        }

        $email = (string) ($user['email'] ?? '');
        $ssoUrl = $email !== '' ? WebmailSso::createSessionUrl($email) : null;

        if ($ssoUrl !== null) {
            self::redirect($ssoUrl);
        }

        $domain = trim((string) Settings::get('EMAIL_DOMAIN', 'grand-baie-maurice.com'));
        self::redirect(
            'https://webmail.' . $domain,
            'Connexion automatique indisponible : connectez-vous avec votre email et le mot de passe configuré dans votre profil.',
            'info'
        );
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
     * Check if user is allowed to access the email feature
     */
    private static function isEmailAllowed(array $user): bool
    {
        return ($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'partner';
    }
}
