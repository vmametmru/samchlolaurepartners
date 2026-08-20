<?php

declare(strict_types=1);

namespace App\controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\HttpException;
use App\ImageCache;
use App\Mailer;
use App\Settings;
use App\View;
use PDO;
use Throwable;

/**
 * The individual account page reached by clicking on the connected user's
 * name in the navbar: lets any logged-in user (admin, partner, or a
 * partner's sub-user) update their own name/phone/password/profile photo,
 * plus the "Mot de passe oublié ?" self-service reset flow from /login.
 */
final class AccountController extends Controller
{
    private const ALLOWED_PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * The signature photo is only ever displayed at a small, fixed size
     * (64px) in reservation emails and the navbar, so there is no reason to
     * keep the original upload's full resolution: downscaling it to 100px
     * wide on upload noticeably shrinks the size of every reservation email
     * that embeds it (see ReservationsController::signaturePhotoTag()),
     * without any visible quality loss at its actual display size.
     */
    private const AVATAR_WIDTH = 100;

    public static function profile(): void
    {
        $user = Auth::requireUser();
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user;
        View::render('pages/account', [
            'pageTitle' => 'Mon profil',
            'userData' => $userData,
            'showEmailPassword' => in_array($user['role'] ?? '', ['admin', 'partner'], true),
        ]);
    }

    public static function updateProfile(): never
    {
        $user = Auth::requireUser();
        $userId = (int) $user['id'];

        $firstName = trim((string) ($_POST['first_name'] ?? '')) ?: null;
        $lastName = trim((string) ($_POST['last_name'] ?? '')) ?: null;
        $phone = trim((string) ($_POST['phone'] ?? '')) ?: null;

        $photoUrl = null;
        $photoProvided = false;
        if (!empty($_FILES['photo']['name'])) {
            $photoProvided = true;
            $photoUrl = self::storeUploadedPhoto($userId);
        }

        $pdo = Database::connection();
        if ($photoProvided) {
            $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, phone = ?, photo_url = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$firstName, $lastName, $phone, $photoUrl, $userId]);
        } else {
            $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, phone = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$firstName, $lastName, $phone, $userId]);
        }

        $newPassword = (string) ($_POST['new_password'] ?? '');
        if ($newPassword !== '') {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !password_verify($currentPassword, (string) $row['password_hash'])) {
                self::redirect('/account', 'Mot de passe actuel incorrect, les autres informations ont bien été enregistrées.', 'error');
            }
            if (strlen($newPassword) < 8) {
                self::redirect('/account', 'Le nouveau mot de passe doit contenir au moins 8 caractères.', 'error');
            }
            Auth::resetPassword($userId, $newPassword);
        }

        // Only admins may change their own login email from this page. A
        // partner's email is tied to their partner record and to IMAP
        // domain-matching (see ImapManager::isEmailDomainAllowed()), so it
        // isn't user-editable here — this only ever applies to admins.
        // Changing it never risks locking the admin out: Auth is stateless
        // JWT keyed by user id (not email), and the Auth::refreshSession()
        // call below always re-reads this row and reissues the cookie with
        // the new email, so the CURRENT session keeps working unchanged —
        // only the NEXT login needs the new address.
        $newEmail = null;
        if (($user['role'] ?? '') === 'admin') {
            $submittedEmail = trim((string) ($_POST['email'] ?? ''));
            if ($submittedEmail !== '' && strcasecmp($submittedEmail, (string) $user['email']) !== 0) {
                if (!filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
                    self::redirect('/account', 'Adresse email invalide : email non modifié (les autres informations ont bien été enregistrées).', 'error');
                }
                $currentPassword = (string) ($_POST['current_password'] ?? '');
                $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row || !password_verify($currentPassword, (string) $row['password_hash'])) {
                    self::redirect('/account', 'Mot de passe actuel incorrect : email non modifié (les autres informations ont bien été enregistrées).', 'error');
                }
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
                $stmt->execute([$submittedEmail, $userId]);
                if ($stmt->fetchColumn()) {
                    self::redirect('/account', 'Cette adresse email est déjà utilisée par un autre compte : email non modifié.', 'error');
                }
                $pdo->prepare('UPDATE users SET email = ?, updated_at = NOW() WHERE id = ?')->execute([$submittedEmail, $userId]);
                $newEmail = $submittedEmail;
            }
        }

        // Email (webmail/IMAP) password: only admins and partners have
        // access to the webmail feature (see EmailController), and the
        // centralized IMAP server (grand-baie-maurice.com) authenticates
        // each user with their own mailbox password.
        if (($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'partner') {
            $emailPassword = trim((string) ($_POST['email_password'] ?? ''));
            if ($emailPassword !== '') {
                \App\ImapManager::setEmailPassword($userId, $emailPassword);
            }
        }

        Auth::refreshSession($userId);
        self::redirect(
            '/account',
            $newEmail !== null
                ? "Profil mis à jour. Email de connexion changé pour {$newEmail} — utilisez cette adresse lors de votre prochaine connexion."
                : 'Profil mis à jour.'
        );
    }

    public static function forgotPassword(): void
    {
        $user = Auth::user();
        if ($user) {
            header('Location: ' . (($user['role'] ?? '') === 'admin' ? '/admin/partners' : '/partner/dashboard'));
            exit;
        }
        View::render('pages/forgot-password', ['pageTitle' => 'Mot de passe oublié']);
    }

    public static function submitForgotPassword(): never
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email !== '') {
            $result = Auth::createPasswordResetToken($email);
            if ($result !== null) {
                self::sendResetEmail($result['user'], $result['token']);
            }
        }
        self::redirect('/login', 'Si cette adresse existe, un email de réinitialisation vient d\'être envoyé.');
    }

    public static function resetPassword(string $token): void
    {
        $user = Auth::findByResetToken($token);
        if (!$user) {
            self::redirect('/login', 'Ce lien de réinitialisation est invalide ou a expiré.', 'error');
        }
        View::render('pages/reset-password', ['pageTitle' => 'Réinitialiser le mot de passe', 'token' => $token]);
    }

    public static function submitResetPassword(string $token): never
    {
        $user = Auth::findByResetToken($token);
        if (!$user) {
            self::redirect('/login', 'Ce lien de réinitialisation est invalide ou a expiré.', 'error');
        }

        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        if (strlen($password) < 8) {
            self::redirect('/reset-password/' . $token, 'Le mot de passe doit contenir au moins 8 caractères.', 'error');
        }
        if ($password !== $confirm) {
            self::redirect('/reset-password/' . $token, 'Les mots de passe ne correspondent pas.', 'error');
        }

        Auth::resetPassword((int) $user['id'], $password);
        self::redirect('/login', 'Mot de passe mis à jour, vous pouvez vous connecter.');
    }

    private static function sendResetEmail(array $user, string $token): void
    {
        $baseUrl = rtrim((string) (Settings::get('APP_URL', '') ?? ''), '/');
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $resetUrl = ($baseUrl !== '' ? $baseUrl : ($host !== '' ? $scheme . '://' . $host : '')) . '/reset-password/' . $token;

        $partner = null;
        if (!empty($user['partner_id'])) {
            $stmt = Database::connection()->prepare('SELECT * FROM partners WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $user['partner_id']]);
            $partner = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $partner ??= ['email' => (string) $user['email'], 'name' => 'samchlolaurepartners'];

        $html = '<p>Bonjour,</p>'
            . '<p>Une demande de réinitialisation de mot de passe a été effectuée pour ce compte.</p>'
            . '<p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES) . '">Cliquez ici pour définir un nouveau mot de passe</a></p>'
            . '<p>Ce lien est valable 1 heure. Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email.</p>';

        try {
            Mailer::sendRawEmail($partner, (string) $user['email'], 'Réinitialisation de votre mot de passe', $html);
        } catch (Throwable $e) {
            // Never let a mail-transport failure break the "forgot password"
            // flow's generic response — the user still sees a success
            // message, and the failure is logged for diagnostics.
            error_log('[password-reset] ' . $e->getMessage());
        }
    }

    private static function storeUploadedPhoto(int $userId): ?string
    {
        $file = $_FILES['photo'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            return null;
        }

        $originalName = (string) ($file['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_PHOTO_EXTENSIONS, true)) {
            throw new HttpException(400, 'Bad Request', 'Format de photo non supporté (jpg, png, gif, webp uniquement).');
        }
        if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
            throw new HttpException(400, 'Bad Request', 'La photo ne doit pas dépasser 5 Mo.');
        }

        $dir = BASE_PATH . '/images/others/avatars';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpException(500, 'Internal Server Error', 'Impossible de créer le dossier de stockage des photos.');
        }

        $data = @file_get_contents((string) $file['tmp_name']);
        if ($data === false || $data === '') {
            throw new HttpException(500, 'Internal Server Error', 'Impossible de lire la photo envoyée.');
        }
        $data = ImageCache::resizeIfTooWide($data, self::AVATAR_WIDTH);

        $filename = 'user-' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $destination = $dir . '/' . $filename;
        if (@file_put_contents($destination, $data) === false) {
            throw new HttpException(500, 'Internal Server Error', 'Impossible d\'enregistrer la photo.');
        }

        return '/images/others/avatars/' . $filename;
    }
}
