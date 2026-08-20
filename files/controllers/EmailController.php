<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\ImapClient;
use App\ImapManager;
use App\View;

/**
 * Email/Webmail controller for partners and admins.
 * Handles IMAP configuration, email reading, and sending.
 */
final class EmailController extends Controller
{
    /**
     * Show email inbox
     */
    public static function inbox(): void
    {
        $user = Auth::requireUser();
        
        // Only admins and partners can access email
        if (!self::isEmailAllowed($user)) {
            self::redirect('/account', 'Accès non autorisé.', 'error');
        }

        $params = ImapManager::getConnectionParams((int) $user['id']);
        $emails = [];

        if ($params) {
            $emails = ImapManager::getEmails((int) $user['id'], 'INBOX', 50, 0);
        }

        $unreadCount = ImapManager::getUnreadCount((int) $user['id']);

        View::render('pages/email/inbox', [
            'pageTitle' => 'Email',
            'account' => $params,
            'emails' => $emails,
            'unreadCount' => $unreadCount,
        ]);
    }

    /**
     * Show single email
     */
    public static function show(int $emailId): void
    {
        $user = Auth::requireUser();
        
        if (!self::isEmailAllowed($user)) {
            self::redirect('/account', 'Accès non autorisé.', 'error');
        }

        $email = ImapManager::getEmail((int) $user['id'], $emailId);
        if (!$email) {
            self::redirect('/email', 'Email non trouvé.', 'error');
        }

        // Mark as read
        ImapManager::markAsRead((int) $user['id'], $emailId);

        View::render('pages/email/show', [
            'pageTitle' => $email['subject'] ?? 'Email',
            'email' => $email,
        ]);
    }

    /**
     * Delete email
     */
    public static function delete(int $emailId): never
    {
        $user = Auth::requireUser();
        
        if (!self::isEmailAllowed($user)) {
            self::redirect('/email', 'Accès non autorisé.', 'error');
        }

        $email = ImapManager::getEmail((int) $user['id'], $emailId);
        if (!$email) {
            self::redirect('/email', 'Email non trouvé.', 'error');
        }

        ImapManager::deleteEmail((int) $user['id'], $emailId);
        self::redirect('/email', 'Email supprimé.');
    }

    /**
     * Show email composition form
     */
    public static function compose(): void
    {
        $user = Auth::requireUser();
        
        if (!self::isEmailAllowed($user)) {
            self::redirect('/account', 'Accès non autorisé.', 'error');
        }

        $replyTo = null;
        if (!empty($_GET['reply_to'])) {
            $emailId = (int) $_GET['reply_to'];
            $replyTo = ImapManager::getEmail((int) $user['id'], $emailId);
        }

        View::render('pages/email/compose', [
            'pageTitle' => 'Nouvel email',
            'replyTo' => $replyTo,
        ]);
    }

    /**
     * Send email
     */
    public static function send(): never
    {
        $user = Auth::requireUser();
        
        if (!self::isEmailAllowed($user)) {
            self::redirect('/email', 'Accès non autorisé.', 'error');
        }

        $to = trim((string) ($_POST['to'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = (string) ($_POST['body'] ?? '');

        if (empty($to) || empty($subject) || empty($body)) {
            self::redirect('/email/compose', 'Tous les champs sont requis.', 'error');
        }

        $params = ImapManager::getConnectionParams((int) $user['id']);
        if (!$params) {
            self::redirect('/email/compose', 'Configurez votre mot de passe email dans votre profil avant d\'envoyer un message.', 'error');
        }

        // Send via partner's email if configured
        $partner = null;
        if (!empty($user['partner_id'])) {
            $pdo = \App\Database::connection();
            $stmt = $pdo->prepare('SELECT * FROM partners WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $user['partner_id']]);
            $partner = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        if (!$partner) {
            $partner = ['email' => $params['email'], 'name' => 'Support'];
        }

        try {
            $html = nl2br(htmlspecialchars($body, ENT_QUOTES));
            \App\Mailer::sendRawEmail($partner, $to, $subject, $html);
            self::redirect('/email', 'Email envoyé avec succès.');
        } catch (\Throwable $e) {
            error_log('[EmailController] Send failed: ' . $e->getMessage());
            self::redirect('/email/compose', 'Erreur lors de l\'envoi de l\'email.', 'error');
        }
    }

    /**
     * Synchronize emails with IMAP server
     */
    public static function sync(): never
    {
        $user = Auth::requireUser();
        
        if (!self::isEmailAllowed($user)) {
            self::redirect('/email', 'Accès non autorisé.', 'error');
        }

        $params = ImapManager::getConnectionParams((int) $user['id']);
        if (!$params) {
            self::redirect('/account', 'Configurez votre mot de passe email dans votre profil avant de synchroniser.', 'error');
        }

        try {
            $client = new ImapClient(
                (string) $params['imap_host'],
                (int) $params['imap_port'],
                (string) $params['email'],
                (string) $params['password'],
                (string) $params['email']
            );

            if (!$client->connect()) {
                throw new \Exception('Failed to connect to IMAP server');
            }

            // Get recent emails (limit to last 100)
            $emails = $client->getEmails('INBOX', 100, 0);
            foreach ($emails as $email) {
                ImapManager::saveEmail((int) $user['id'], $email);
            }

            $client->disconnect();
            self::redirect('/email', 'Emails synchronisés avec succès (' . count($emails) . ' emails).');
        } catch (\Throwable $e) {
            error_log('[EmailController] Sync failed: ' . $e->getMessage());
            self::redirect('/email', 'Erreur lors de la synchronisation des emails. Vérifiez votre mot de passe email dans votre profil.', 'error');
        }
    }

    /**
     * Get unread email count (AJAX)
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
     * Check if user is allowed to access email feature
     */
    private static function isEmailAllowed(array $user): bool
    {
        return ($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'partner';
    }
}
