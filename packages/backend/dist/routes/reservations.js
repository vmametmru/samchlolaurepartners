"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const express_1 = require("express");
const connection_1 = __importDefault(require("../db/connection"));
const authMiddleware_1 = require("../middleware/authMiddleware");
const emailService_1 = require("../services/emailService");
const router = (0, express_1.Router)();
// POST /api/reservations/request — public
router.post('/request', async (req, res) => {
    const { property_id, property_name, client_name, client_email, client_phone, checkin_date, checkout_date, adults, children, guests, message, } = req.body;
    if (!client_name || !client_email || !checkin_date || !checkout_date || !adults) {
        res.status(400).json({ error: 'Bad Request', message: 'Required fields missing' });
        return;
    }
    // Require partner context via tenant middleware
    const partner = req.partner;
    if (!partner) {
        res.status(400).json({ error: 'Bad Request', message: 'No partner context' });
        return;
    }
    try {
        const [result] = await connection_1.default.execute(`INSERT INTO reservation_requests
       (partner_id, property_id, property_name, client_name, client_email, client_phone,
        checkin_date, checkout_date, adults, children, guests, message)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`, [
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
        ]);
        // Send email to partner
        const [templateRows] = await connection_1.default.execute('SELECT * FROM email_templates WHERE partner_id = ? AND type = ? LIMIT 1', [partner.id, 'REQUEST_RECEIVED_PARTNER']);
        const variables = {
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
            await (0, emailService_1.sendTemplatedEmail)(partner, templateRows[0], partner.email, variables);
        }
        else {
            await (0, emailService_1.sendRawEmail)(partner, partner.email, `Nouvelle demande de réservation - ${client_name}`, `<p>Nouvelle demande de ${client_name} (${client_email}) pour ${property_name ?? 'hébergement non spécifié'} du ${checkin_date} au ${checkout_date}.</p>`);
        }
        // Send confirmation to client
        const [clientTemplateRows] = await connection_1.default.execute('SELECT * FROM email_templates WHERE partner_id = ? AND type = ? LIMIT 1', [partner.id, 'REQUEST_RECEIVED_CLIENT']);
        if (clientTemplateRows.length > 0) {
            await (0, emailService_1.sendTemplatedEmail)(partner, clientTemplateRows[0], client_email, variables);
        }
        else {
            await (0, emailService_1.sendRawEmail)(partner, client_email, `Confirmation de votre demande - ${partner.name}`, `<p>Bonjour ${client_name},</p><p>Nous avons bien reçu votre demande de réservation pour ${property_name ?? 'l\'hébergement'} du ${checkin_date} au ${checkout_date}. Nous vous contacterons très prochainement.</p><p>Cordialement,<br>${partner.name}</p>`);
        }
        res.status(201).json({ data: { id: result.insertId }, message: 'Reservation request submitted' });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to submit request' });
    }
});
// GET /api/reservations — partner dashboard
router.get('/', authMiddleware_1.authMiddleware, async (req, res) => {
    const partnerId = req.user?.role === 'admin'
        ? (typeof req.query.partner_id === 'string' ? req.query.partner_id : null)
        : req.user?.partner_id ?? null;
    try {
        const [rows] = await connection_1.default.execute(`SELECT rr.*, r.id AS reservation_id, r.confirmed_at, r.cancelled_at
       FROM reservation_requests rr
       LEFT JOIN reservations r ON r.request_id = rr.id
       WHERE rr.partner_id = ?
       ORDER BY rr.created_at DESC`, [partnerId]);
        res.json({ data: rows });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch reservations' });
    }
});
// GET /api/reservations/:id — partner dashboard
router.get('/:id', authMiddleware_1.authMiddleware, async (req, res) => {
    const partnerId = req.user?.partner_id ?? null;
    try {
        const [rows] = await connection_1.default.execute(`SELECT rr.*, r.id AS reservation_id, r.confirmed_at, r.cancelled_at, r.notes
       FROM reservation_requests rr
       LEFT JOIN reservations r ON r.request_id = rr.id
       WHERE rr.id = ? AND rr.partner_id = ?
       LIMIT 1`, [req.params.id, partnerId]);
        if (rows.length === 0) {
            res.status(404).json({ error: 'Not Found', message: 'Reservation not found' });
            return;
        }
        res.json({ data: rows[0] });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch reservation' });
    }
});
// PUT /api/reservations/:id/confirm — partner dashboard
router.put('/:id/confirm', authMiddleware_1.authMiddleware, async (req, res) => {
    const { notes } = req.body;
    const partnerId = req.user?.partner_id ?? null;
    try {
        // Validate ownership
        const [reqRows] = await connection_1.default.execute('SELECT * FROM reservation_requests WHERE id = ? AND partner_id = ? LIMIT 1', [req.params.id, partnerId]);
        if (reqRows.length === 0) {
            res.status(404).json({ error: 'Not Found', message: 'Reservation request not found' });
            return;
        }
        const reqRow = reqRows[0];
        // Upsert reservation
        await connection_1.default.execute(`INSERT INTO reservations (request_id, partner_id, confirmed_at, notes)
       VALUES (?, ?, NOW(), ?)
       ON DUPLICATE KEY UPDATE confirmed_at = NOW(), cancelled_at = NULL, notes = VALUES(notes)`, [req.params.id, partnerId, notes ?? null]);
        await connection_1.default.execute("UPDATE reservation_requests SET status = 'confirmed', updated_at = NOW() WHERE id = ?", [req.params.id]);
        // Fetch partner config
        const [partnerRows] = await connection_1.default.execute('SELECT * FROM partners WHERE id = ? LIMIT 1', [partnerId]);
        const partner = partnerRows[0];
        // Send confirmation email to client
        const [templateRows] = await connection_1.default.execute('SELECT * FROM email_templates WHERE partner_id = ? AND type = ? LIMIT 1', [partnerId, 'RESERVATION_CONFIRMED']);
        const variables = {
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
            await (0, emailService_1.sendTemplatedEmail)(partner, templateRows[0], reqRow.client_email, variables);
        }
        else {
            await (0, emailService_1.sendRawEmail)(partner, reqRow.client_email, `Votre réservation est confirmée - ${partner.name}`, `<p>Bonjour ${reqRow.client_name},</p><p>Votre réservation pour ${reqRow.property_name} du ${reqRow.checkin_date} au ${reqRow.checkout_date} est confirmée.</p><p>Cordialement,<br>${partner.name}</p>`);
        }
        res.json({ data: null, message: 'Reservation confirmed' });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to confirm reservation' });
    }
});
// PUT /api/reservations/:id/cancel — partner dashboard
router.put('/:id/cancel', authMiddleware_1.authMiddleware, async (req, res) => {
    const partnerId = req.user?.partner_id ?? null;
    try {
        const [reqRows] = await connection_1.default.execute('SELECT * FROM reservation_requests WHERE id = ? AND partner_id = ? LIMIT 1', [req.params.id, partnerId]);
        if (reqRows.length === 0) {
            res.status(404).json({ error: 'Not Found', message: 'Reservation request not found' });
            return;
        }
        await connection_1.default.execute('UPDATE reservations SET cancelled_at = NOW() WHERE request_id = ?', [req.params.id]);
        await connection_1.default.execute("UPDATE reservation_requests SET status = 'cancelled', updated_at = NOW() WHERE id = ?", [req.params.id]);
        const reqRow = reqRows[0];
        const [partnerRows] = await connection_1.default.execute('SELECT * FROM partners WHERE id = ? LIMIT 1', [partnerId]);
        const partner = partnerRows[0];
        const [templateRows] = await connection_1.default.execute('SELECT * FROM email_templates WHERE partner_id = ? AND type = ? LIMIT 1', [partnerId, 'RESERVATION_CANCELLED']);
        const variables = {
            nom_client: String(reqRow.client_name),
            dates: `${reqRow.checkin_date} → ${reqRow.checkout_date}`,
            hebergement: String(reqRow.property_name),
        };
        if (templateRows.length > 0) {
            await (0, emailService_1.sendTemplatedEmail)(partner, templateRows[0], reqRow.client_email, variables);
        }
        res.json({ data: null, message: 'Reservation cancelled' });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to cancel reservation' });
    }
});
exports.default = router;
//# sourceMappingURL=reservations.js.map