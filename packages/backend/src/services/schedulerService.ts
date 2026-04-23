import pool from '../db/connection';
import { sendTemplatedEmail } from './emailService';
import { EmailTemplate, Partner } from '@samchlolaurepartners/shared';
import type { RowDataPacket } from 'mysql2';

let running = false;
let schedulerHandle: NodeJS.Timeout | null = null;

export function startScheduler(intervalMs = 60 * 60 * 1000): NodeJS.Timeout {
  schedulerHandle = setInterval(() => {
    if (!running) {
      running = true;
      processScheduledEmails().catch(console.error).finally(() => {
        running = false;
      });
    }
  }, intervalMs);
  return schedulerHandle;
}

export function stopScheduler(): void {
  if (schedulerHandle !== null) {
    clearInterval(schedulerHandle);
    schedulerHandle = null;
  }
}

async function processScheduledEmails(): Promise<void> {
  // Find confirmed reservations where a scheduled email is due
  const [rows] = await pool.execute<RowDataPacket[]>(`
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
      p.id AS partner_id,
      p.name AS partner_name,
      p.email AS partner_email,
      p.smtp_host, p.smtp_port, p.smtp_user, p.smtp_pass,
      p.logo_url, p.primary_color, p.subdomain, p.markup_percent, p.active
    FROM email_schedules es
    JOIN partners p ON p.id = es.partner_id
    JOIN reservations r ON r.partner_id = p.id
    JOIN reservation_requests rr ON rr.id = r.request_id
    WHERE
      es.active = 1
      AND r.cancelled_at IS NULL
      AND DATE(rr.checkin_date) = DATE_ADD(CURDATE(), INTERVAL es.days_before_arrival DAY)
      AND NOT EXISTS (
        SELECT 1 FROM sent_schedule_emails sse
        WHERE sse.schedule_id = es.id AND sse.reservation_id = r.id
      )
  `);

  for (const row of rows) {
    const partner: Partner = {
      id: row.partner_id,
      name: row.partner_name,
      email: row.partner_email,
      smtp_host: row.smtp_host,
      smtp_port: row.smtp_port,
      smtp_user: row.smtp_user,
      smtp_pass: row.smtp_pass,
      logo_url: row.logo_url,
      primary_color: row.primary_color,
      subdomain: row.subdomain,
      markup_percent: row.markup_percent,
      active: Boolean(row.active),
      created_at: '',
      updated_at: '',
    };

    const [templateRows] = await pool.execute<RowDataPacket[]>(
      'SELECT * FROM email_templates WHERE partner_id = ? AND type = ? LIMIT 1',
      [row.partner_id, row.template_type]
    );

    if (templateRows.length === 0) continue;

    const template = templateRows[0] as unknown as EmailTemplate;
    const variables: Record<string, string> = {
      nom_client: String(row.client_name),
      email_client: String(row.client_email),
      dates: `${row.checkin_date} → ${row.checkout_date}`,
      date_arrivee: String(row.checkin_date),
      date_depart: String(row.checkout_date),
      adultes: String(row.adults),
      enfants: String(row.children),
      hebergement: String(row.property_name),
      partenaire: String(row.partner_name),
    };

    try {
      await sendTemplatedEmail(partner, template, row.client_email, variables);

      // Mark as sent
      await pool.execute(
        'INSERT IGNORE INTO sent_schedule_emails (schedule_id, reservation_id) VALUES (?, ?)',
        [row.schedule_id, row.reservation_id]
      );

      console.log(`[scheduler] Sent ${row.template_type} to ${row.client_email}`);
    } catch (err) {
      console.error(`[scheduler] Failed to send email for reservation ${row.reservation_id}:`, err);
    }
  }
}
