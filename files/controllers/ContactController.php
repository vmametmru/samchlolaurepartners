<?php

declare(strict_types=1);

namespace App\controllers;

use App\Controller;
use App\Database;
use App\Mailer;
use PDO;
use Throwable;

final class ContactController extends Controller
{
    public static function submit(): never
    {
        $input = self::input();
        $name = trim((string) ($input['name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));

        if ($name === '' || $email === '' || $message === '') {
            self::json(['error' => 'Bad Request', 'message' => 'name, email, message are required'], 400);
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            self::json(['error' => 'Bad Request', 'message' => 'Invalid email'], 400);
        }

        $partner = self::requirePartnerContext();
        $html = '<h2>Nouveau message de contact</h2>'
            . '<p><strong>Nom:</strong> ' . htmlspecialchars($name) . '</p>'
            . '<p><strong>Email:</strong> ' . htmlspecialchars($email) . '</p>'
            . (!empty($input['phone']) ? '<p><strong>Téléphone:</strong> ' . htmlspecialchars((string) $input['phone']) . '</p>' : '')
            . (!empty($input['checkin_date']) ? '<p><strong>Arrivée:</strong> ' . htmlspecialchars((string) $input['checkin_date']) . '</p>' : '')
            . (!empty($input['checkout_date']) ? '<p><strong>Départ:</strong> ' . htmlspecialchars((string) $input['checkout_date']) . '</p>' : '')
            . (!empty($input['adults']) ? '<p><strong>Adultes:</strong> ' . htmlspecialchars((string) $input['adults']) . '</p>' : '')
            . (!empty($input['children']) ? '<p><strong>Enfants:</strong> ' . htmlspecialchars((string) $input['children']) . '</p>' : '')
            . (!empty($input['guests']) ? '<p><strong>Voyageurs:</strong> ' . htmlspecialchars(json_encode($input['guests'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</p>' : '')
            . '<p><strong>Message:</strong><br>' . nl2br(htmlspecialchars($message)) . '</p>';

        try {
            Mailer::sendContactEmail($partner, $email, 'Contact de ' . $name . ' - ' . $partner['name'], $html);
            $stmt = Database::connection()->prepare('SELECT * FROM partners WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $partner['id']]);
            $fullPartner = $stmt->fetch(PDO::FETCH_ASSOC) ?: $partner;
            Mailer::sendContactEmail($fullPartner, $email, 'Confirmation de votre message - ' . $fullPartner['name'], '<p>Bonjour ' . htmlspecialchars($name) . ',</p><p>Nous avons bien reçu votre message et nous vous répondrons dans les plus brefs délais.</p><p>Cordialement,<br>' . htmlspecialchars((string) $fullPartner['name']) . '</p>');
            self::json(['data' => null, 'message' => 'Message sent successfully']);
        } catch (Throwable $e) {
            error_log((string) $e);
            self::json(['error' => 'Internal Server Error', 'message' => 'Failed to send message'], 500);
        }
    }
}
