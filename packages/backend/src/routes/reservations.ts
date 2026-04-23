import { Router, Request, Response } from 'express';
import pool from '../db/connection';
import { authMiddleware, AuthRequest } from '../middleware/authMiddleware';
import { sendTemplatedEmail, sendRawEmail } from '../services/emailService';
import { Partner, EmailTemplate, GuestNationality } from '@samchlolaurepartners/shared';
import type { RowDataPacket, ResultSetHeader } from 'mysql2';

const router = Router();

// POST /api/reservations/request — public
router.post('/request', async (req: Request, res: Response): Promise<void> => {
  const {
    property_id,
    property_name,
    client_name,
    client_email,
    client_phone,
    checkin_date,
    checkout_date,
    adults,
    children,
    guests,
    message,
  } = req.body as {
    property_id?: string;
    property_name?: string;
    client_name?: string;
    client_email?: string;
    client_phone?: string;
    checkin_date?: string;
    checkout_date?: string;
    adults?: number;
    children?: number;
    guests?: GuestNationality[];
    message?: string;
  };

  if (!client_name || !client_email || !checkin_date || !checkout_date || !adults) {
    res.status(400).json({ error: 'Bad Request', message: 'Required fields missing' });
    return;
  }

  // Require partner context via tenant middleware
  const partner = (req as AuthRequest).partner;
  if (!partner) {
    res.status(400).json({ error: 'Bad Request', message: 'No partner context' });
    return;
  }

  try {
    const [result] = await pool.execute<ResultSetHeader>(
      `INSERT INTO reservation_requests
       (partner_id, property_id, property_name, client_name, client_email, client_phone,
        checkin_date, checkout_date, adults, children, guests, message)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        partner.id,
        property_id ?? null,
        property_name ?? '',
        client_name,
        client_email,
        client_phone ?? null,
        checkin_date,
        checkout_date,
        adults,
        children ?? 0,
        JSON.stringify(guests ?? []),
        message ?? null,
      ]
    );

    // Send email to partner
    const [templateRows] = await pool.execute<RowDataPacket[]>(
      'SELECT * FROM email_templates WHERE partner_id = ? AND type = ? LIMIT 1',
      [partner.id, 'REQUEST_RECEIVED_PARTNER']
    );

    const variables: Record<string, string> = {
      nom_client: client_name,
      email_client: client_email,
      telephone_client: client_phone ?? '',
      dates: `${checkin_date} → ${checkout_date}`,
      date_arrivee: checkin_date,
      date_depart: checkout_date,
      adultes: String(adults),
      enfants: String(children ?? 0),
      hebergement: property_name ?? '',
      message: message ?? '',
    };

    if (templateRows.length > 0) {
      await sendTemplatedEmail(partner, templateRows[0] as unknown as EmailTemplate, partner.email, variables);
    } else {
      await sendRawEmail(
        partner,
        partner.email,
        `Nouvelle demande de réservation - ${client_name}`,
        `<p>Nouvelle demande de ${client_name} (${client_email}) pour ${property_name ?? 'hébergement non spécifié'} du ${checkin_date} au ${checkout_date}.</p>`
      );
    }

    // Send confirmation to client
    const [clientTemplateRows] = await pool.execute<RowDataPacket[]>(
      'SELECT * FROM email_templates WHERE partner_id = ? AND type = ? LIMIT 1',
      [partner.id, 'REQUEST_RECEIVED_CLIENT']
    );

    if (clientTemplateRows.length > 0) {
      await sendTemplatedEmail(partner, clientTemplateRows[0] as unknown as EmailTemplate, client_email, variables);
    } else {
      await sendRawEmail(
        partner,
        client_email,
        `Confirmation de votre demande - ${partner.name}`,
        `<p>Bonjour ${client_name},</p><p>Nous avons bien reçu votre demande de réservation pour ${property_name ?? 'l\'hébergement'} du ${checkin_date} au ${checkout_date}. Nous vous contacterons très prochainement.</p><p>Cordialement,<br>${partner.name}</p>`
      );
    }

    res.status(201).json({ data: { id: result.insertId }, message: 'Reservation request submitted' });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to submit request' });
  }
});

// GET /api/reservations — partner dashboard
router.get('/', authMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  const partnerId = req.user?.role === 'admin'
    ? (req.query.partner_id ?? null)
    : req.user?.partner_id;

  try {
    const [rows] = await pool.execute<RowDataPacket[]>(
      `SELECT rr.*, r.id AS reservation_id, r.confirmed_at, r.cancelled_at
       FROM reservation_requests rr
       LEFT JOIN reservations r ON r.request_id = rr.id
       WHERE rr.partner_id = ?
       ORDER BY rr.created_at DESC`,
      [partnerId]
    );
    res.json({ data: rows });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch reservations' });
  }
});

// GET /api/reservations/:id — partner dashboard
router.get('/:id', authMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  try {
    const [rows] = await pool.execute<RowDataPacket[]>(
      `SELECT rr.*, r.id AS reservation_id, r.confirmed_at, r.cancelled_at, r.notes
       FROM reservation_requests rr
       LEFT JOIN reservations r ON r.request_id = rr.id
       WHERE rr.id = ? AND rr.partner_id = ?
       LIMIT 1`,
      [req.params.id, req.user?.partner_id]
    );

    if (rows.length === 0) {
      res.status(404).json({ error: 'Not Found', message: 'Reservation not found' });
      return;
    }
    res.json({ data: rows[0] });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch reservation' });
  }
});

// PUT /api/reservations/:id/confirm — partner dashboard
router.put('/:id/confirm', authMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  const { notes } = req.body as { notes?: string };

  try {
    // Validate ownership
    const [reqRows] = await pool.execute<RowDataPacket[]>(
      'SELECT * FROM reservation_requests WHERE id = ? AND partner_id = ? LIMIT 1',
      [req.params.id, req.user?.partner_id]
    );

    if (reqRows.length === 0) {
      res.status(404).json({ error: 'Not Found', message: 'Reservation request not found' });
      return;
    }

    const reqRow = reqRows[0];

    // Upsert reservation
    await pool.execute(
      `INSERT INTO reservations (request_id, partner_id, confirmed_at, notes)
       VALUES (?, ?, NOW(), ?)
       ON DUPLICATE KEY UPDATE confirmed_at = NOW(), cancelled_at = NULL, notes = VALUES(notes)`,
      [req.params.id, req.user?.partner_id, notes ?? null]
    );

    await pool.execute(
      "UPDATE reservation_requests SET status = 'confirmed', updated_at = NOW() WHERE id = ?",
      [req.params.id]
    );

    // Fetch partner config
    const [partnerRows] = await pool.execute<RowDataPacket[]>(
      'SELECT * FROM partners WHERE id = ? LIMIT 1',
      [req.user?.partner_id]
    );
    const partner = partnerRows[0] as unknown as Partner;

    // Send confirmation email to client
    const [templateRows] = await pool.execute<RowDataPacket[]>(
      'SELECT * FROM email_templates WHERE partner_id = ? AND type = ? LIMIT 1',
      [req.user?.partner_id, 'RESERVATION_CONFIRMED']
    );

    const variables: Record<string, string> = {
      nom_client: String(reqRow.client_name),
      email_client: String(reqRow.client_email),
      dates: `${reqRow.checkin_date} → ${reqRow.checkout_date}`,
      date_arrivee: String(reqRow.checkin_date),
      date_depart: String(reqRow.checkout_date),
      adultes: String(reqRow.adults),
      enfants: String(reqRow.children),
      hebergement: String(reqRow.property_name),
      notes: notes ?? '',
    };

    if (templateRows.length > 0) {
      await sendTemplatedEmail(partner, templateRows[0] as unknown as EmailTemplate, reqRow.client_email as string, variables);
    } else {
      await sendRawEmail(
        partner,
        reqRow.client_email as string,
        `Votre réservation est confirmée - ${partner.name}`,
        `<p>Bonjour ${reqRow.client_name},</p><p>Votre réservation pour ${reqRow.property_name} du ${reqRow.checkin_date} au ${reqRow.checkout_date} est confirmée.</p><p>Cordialement,<br>${partner.name}</p>`
      );
    }

    res.json({ data: null, message: 'Reservation confirmed' });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to confirm reservation' });
  }
});

// PUT /api/reservations/:id/cancel — partner dashboard
router.put('/:id/cancel', authMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  try {
    const [reqRows] = await pool.execute<RowDataPacket[]>(
      'SELECT * FROM reservation_requests WHERE id = ? AND partner_id = ? LIMIT 1',
      [req.params.id, req.user?.partner_id]
    );

    if (reqRows.length === 0) {
      res.status(404).json({ error: 'Not Found', message: 'Reservation request not found' });
      return;
    }

    await pool.execute(
      'UPDATE reservations SET cancelled_at = NOW() WHERE request_id = ?',
      [req.params.id]
    );

    await pool.execute(
      "UPDATE reservation_requests SET status = 'cancelled', updated_at = NOW() WHERE id = ?",
      [req.params.id]
    );

    const reqRow = reqRows[0];
    const [partnerRows] = await pool.execute<RowDataPacket[]>(
      'SELECT * FROM partners WHERE id = ? LIMIT 1',
      [req.user?.partner_id]
    );
    const partner = partnerRows[0] as unknown as Partner;

    const [templateRows] = await pool.execute<RowDataPacket[]>(
      'SELECT * FROM email_templates WHERE partner_id = ? AND type = ? LIMIT 1',
      [req.user?.partner_id, 'RESERVATION_CANCELLED']
    );

    const variables: Record<string, string> = {
      nom_client: String(reqRow.client_name),
      dates: `${reqRow.checkin_date} → ${reqRow.checkout_date}`,
      hebergement: String(reqRow.property_name),
    };

    if (templateRows.length > 0) {
      await sendTemplatedEmail(partner, templateRows[0] as unknown as EmailTemplate, reqRow.client_email as string, variables);
    }

    res.json({ data: null, message: 'Reservation cancelled' });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to cancel reservation' });
  }
});

export default router;
