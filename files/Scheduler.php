<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Scheduler
{
    public static function runOnce(): array
    {
        $pdo = Database::connection();
        $sql = <<<'SQL'
SELECT
  es.id AS schedule_id,
  es.days_before_arrival,
  es.template_type,
  r.id AS reservation_id,
  rr.client_name,
  rr.client_email,
  rr.checkin_date,
  rr.checkout_date,
  rr.adults,
  rr.children,
  rr.property_name,
  p.*
FROM email_schedules es
JOIN partners p ON p.id = es.partner_id
JOIN reservations r ON r.partner_id = p.id
JOIN reservation_requests rr ON rr.id = r.request_id
WHERE es.active = 1
  AND r.cancelled_at IS NULL
  AND DATE(rr.checkin_date) = DATE_ADD(CURDATE(), INTERVAL es.days_before_arrival DAY)
  AND NOT EXISTS (
    SELECT 1 FROM sent_schedule_emails sse
    WHERE sse.schedule_id = es.id AND sse.reservation_id = r.id
  )
SQL;

        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $sent = 0;
        $errors = [];

        foreach ($rows as $row) {
            $templateStmt = $pdo->prepare('SELECT * FROM email_templates WHERE partner_id = ? AND type = ? LIMIT 1');
            $templateStmt->execute([(int) $row['partner_id'], (string) $row['template_type']]);
            $template = $templateStmt->fetch(PDO::FETCH_ASSOC);
            if (!$template) {
                continue;
            }

            $variables = [
                'nom_client' => (string) $row['client_name'],
                'email_client' => (string) $row['client_email'],
                'dates' => (string) $row['checkin_date'] . ' → ' . (string) $row['checkout_date'],
                'date_arrivee' => (string) $row['checkin_date'],
                'date_depart' => (string) $row['checkout_date'],
                'adultes' => (string) $row['adults'],
                'enfants' => (string) $row['children'],
                'hebergement' => (string) $row['property_name'],
                'partenaire' => (string) $row['name'],
            ];

            try {
                Mailer::sendTemplatedEmail($row, $template, (string) $row['client_email'], $variables);
                $markStmt = $pdo->prepare('INSERT IGNORE INTO sent_schedule_emails (schedule_id, reservation_id) VALUES (?, ?)');
                $markStmt->execute([(int) $row['schedule_id'], (int) $row['reservation_id']]);
                $sent++;
            } catch (\Throwable $e) {
                $errors[] = 'Reservation ' . $row['reservation_id'] . ': ' . $e->getMessage();
            }
        }

        return ['checked' => count($rows), 'sent' => $sent, 'errors' => $errors];
    }
}
