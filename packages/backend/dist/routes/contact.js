"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const express_1 = require("express");
const connection_1 = __importDefault(require("../db/connection"));
const emailService_1 = require("../services/emailService");
const router = (0, express_1.Router)();
// POST /api/contact — public
router.post('/', async (req, res) => {
    const { name, email, phone, checkin_date, checkout_date, adults, children, guests, message } = req.body;
    if (!name || !email || !message) {
        res.status(400).json({ error: 'Bad Request', message: 'name, email, message are required' });
        return;
    }
    const partner = req.partner;
    if (!partner) {
        res.status(400).json({ error: 'Bad Request', message: 'No partner context' });
        return;
    }
    const html = `
    <h2>Nouveau message de contact</h2>
    <p><strong>Nom:</strong> ${name}</p>
    <p><strong>Email:</strong> ${email}</p>
    ${phone ? `<p><strong>Téléphone:</strong> ${phone}</p>` : ''}
    ${checkin_date ? `<p><strong>Arrivée:</strong> ${checkin_date}</p>` : ''}
    ${checkout_date ? `<p><strong>Départ:</strong> ${checkout_date}</p>` : ''}
    ${adults ? `<p><strong>Adultes:</strong> ${adults}</p>` : ''}
    ${children ? `<p><strong>Enfants:</strong> ${children}</p>` : ''}
    ${guests ? `<p><strong>Voyageurs:</strong> ${JSON.stringify(guests)}</p>` : ''}
    <p><strong>Message:</strong><br>${String(message).replace(/\n/g, '<br>')}</p>
  `;
    try {
        await (0, emailService_1.sendContactEmail)(partner, String(email), `Contact de ${name} - ${partner.name}`, html);
        // Also fetch full partner config including SMTP
        const [partnerRows] = await connection_1.default.execute('SELECT * FROM partners WHERE id = ? LIMIT 1', [partner.id]);
        const fullPartner = partnerRows[0];
        // Auto-reply to sender
        await (0, emailService_1.sendContactEmail)(fullPartner, String(email), `Confirmation de votre message - ${fullPartner.name}`, `<p>Bonjour ${name},</p><p>Nous avons bien reçu votre message et nous vous répondrons dans les plus brefs délais.</p><p>Cordialement,<br>${fullPartner.name}</p>`);
        res.json({ data: null, message: 'Message sent successfully' });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to send message' });
    }
});
exports.default = router;
//# sourceMappingURL=contact.js.map