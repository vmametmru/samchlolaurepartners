"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const express_1 = require("express");
const connection_1 = __importDefault(require("../db/connection"));
const authMiddleware_1 = require("../middleware/authMiddleware");
const router = (0, express_1.Router)();
// GET /api/email-templates — partner
router.get('/', authMiddleware_1.authMiddleware, async (req, res) => {
    const partnerId = req.user?.role === 'admin'
        ? (typeof req.query.partner_id === 'string' ? req.query.partner_id : req.user?.partner_id ?? null)
        : req.user?.partner_id ?? null;
    try {
        const [rows] = await connection_1.default.execute('SELECT * FROM email_templates WHERE partner_id = ? ORDER BY type', [partnerId]);
        res.json({ data: rows });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch templates' });
    }
});
// GET /api/email-templates/:id
router.get('/:id', authMiddleware_1.authMiddleware, async (req, res) => {
    const partnerId = req.user?.partner_id ?? null;
    try {
        const [rows] = await connection_1.default.execute('SELECT * FROM email_templates WHERE id = ? AND partner_id = ? LIMIT 1', [req.params.id, partnerId]);
        if (rows.length === 0) {
            res.status(404).json({ error: 'Not Found', message: 'Template not found' });
            return;
        }
        res.json({ data: rows[0] });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch template' });
    }
});
// POST /api/email-templates
router.post('/', authMiddleware_1.authMiddleware, async (req, res) => {
    const { type, subject, body_html } = req.body;
    if (!type || !subject || !body_html) {
        res.status(400).json({ error: 'Bad Request', message: 'type, subject, body_html are required' });
        return;
    }
    try {
        const [result] = await connection_1.default.execute('INSERT INTO email_templates (partner_id, type, subject, body_html) VALUES (?, ?, ?, ?)', [req.user?.partner_id ?? null, type, subject, body_html]);
        res.status(201).json({ data: { id: result.insertId }, message: 'Template created' });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to create template' });
    }
});
// PUT /api/email-templates/:id
router.put('/:id', authMiddleware_1.authMiddleware, async (req, res) => {
    const { subject, body_html } = req.body;
    const partnerId = req.user?.partner_id ?? null;
    try {
        await connection_1.default.execute('UPDATE email_templates SET subject=?, body_html=?, updated_at=NOW() WHERE id=? AND partner_id=?', [subject ?? null, body_html ?? null, req.params.id, partnerId]);
        res.json({ data: null, message: 'Template updated' });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to update template' });
    }
});
// DELETE /api/email-templates/:id
router.delete('/:id', authMiddleware_1.authMiddleware, async (req, res) => {
    const partnerId = req.user?.partner_id ?? null;
    try {
        await connection_1.default.execute('DELETE FROM email_templates WHERE id=? AND partner_id=?', [req.params.id, partnerId]);
        res.json({ data: null, message: 'Template deleted' });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to delete template' });
    }
});
exports.default = router;
//# sourceMappingURL=emailTemplates.js.map