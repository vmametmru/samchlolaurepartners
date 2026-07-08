"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const express_1 = require("express");
const connection_1 = __importDefault(require("../db/connection"));
const authMiddleware_1 = require("../middleware/authMiddleware");
const adminMiddleware_1 = require("../middleware/adminMiddleware");
const router = (0, express_1.Router)();
// GET /api/partners — admin only
router.get('/', authMiddleware_1.authMiddleware, adminMiddleware_1.adminMiddleware, async (_req, res) => {
    try {
        const [rows] = await connection_1.default.execute('SELECT id, subdomain, name, logo_url, primary_color, email, markup_percent, active, created_at, updated_at FROM partners ORDER BY name');
        res.json({ data: rows });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch partners' });
    }
});
// GET /api/partners/current — public, uses tenant middleware result
router.get('/current', async (req, res) => {
    if (!req.partner) {
        res.status(404).json({ error: 'Not Found', message: 'No partner context' });
        return;
    }
    // Never expose SMTP credentials or markup to public
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    const { smtp_host: _h, smtp_port: _p, smtp_user: _u, smtp_pass: _pw, markup_percent: _m, ...publicFields } = req.partner;
    res.json({ data: publicFields });
});
// GET /api/partners/:id — admin only
router.get('/:id', authMiddleware_1.authMiddleware, adminMiddleware_1.adminMiddleware, async (req, res) => {
    try {
        const [rows] = await connection_1.default.execute('SELECT * FROM partners WHERE id = ? LIMIT 1', [req.params.id]);
        if (rows.length === 0) {
            res.status(404).json({ error: 'Not Found', message: 'Partner not found' });
            return;
        }
        // Don't expose markup in public API — admin context only
        res.json({ data: rows[0] });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch partner' });
    }
});
// POST /api/partners — admin only
router.post('/', authMiddleware_1.authMiddleware, adminMiddleware_1.adminMiddleware, async (req, res) => {
    const { subdomain, name, logo_url, primary_color, email, markup_percent, smtp_host, smtp_port, smtp_user, smtp_pass } = req.body;
    if (!subdomain || !name || !email) {
        res.status(400).json({ error: 'Bad Request', message: 'subdomain, name, email are required' });
        return;
    }
    try {
        const [result] = await connection_1.default.execute(`INSERT INTO partners (subdomain, name, logo_url, primary_color, email, markup_percent, smtp_host, smtp_port, smtp_user, smtp_pass)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`, [
            String(subdomain),
            String(name),
            typeof logo_url === 'string' ? logo_url : null,
            typeof primary_color === 'string' ? primary_color : '#E61E4D',
            String(email),
            typeof markup_percent === 'number' ? markup_percent : 0,
            typeof smtp_host === 'string' ? smtp_host : null,
            typeof smtp_port === 'number' ? smtp_port : null,
            typeof smtp_user === 'string' ? smtp_user : null,
            typeof smtp_pass === 'string' ? smtp_pass : null,
        ]);
        res.status(201).json({ data: { id: result.insertId }, message: 'Partner created' });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to create partner' });
    }
});
// PUT /api/partners/:id — admin only
router.put('/:id', authMiddleware_1.authMiddleware, adminMiddleware_1.adminMiddleware, async (req, res) => {
    const { name, logo_url, primary_color, email, markup_percent, smtp_host, smtp_port, smtp_user, smtp_pass, active } = req.body;
    try {
        await connection_1.default.execute(`UPDATE partners SET name=?, logo_url=?, primary_color=?, email=?, markup_percent=?,
       smtp_host=?, smtp_port=?, smtp_user=?, smtp_pass=?, active=?, updated_at=NOW()
       WHERE id=?`, [
            typeof name === 'string' ? name : null,
            typeof logo_url === 'string' ? logo_url : null,
            typeof primary_color === 'string' ? primary_color : null,
            typeof email === 'string' ? email : null,
            typeof markup_percent === 'number' ? markup_percent : 0,
            typeof smtp_host === 'string' ? smtp_host : null,
            typeof smtp_port === 'number' ? smtp_port : null,
            typeof smtp_user === 'string' ? smtp_user : null,
            typeof smtp_pass === 'string' ? smtp_pass : null,
            active === false ? 0 : 1,
            req.params.id,
        ]);
        res.json({ data: null, message: 'Partner updated' });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to update partner' });
    }
});
// DELETE /api/partners/:id — admin only
router.delete('/:id', authMiddleware_1.authMiddleware, adminMiddleware_1.adminMiddleware, async (req, res) => {
    try {
        await connection_1.default.execute('UPDATE partners SET active = 0, updated_at = NOW() WHERE id = ?', [req.params.id]);
        res.json({ data: null, message: 'Partner deactivated' });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to delete partner' });
    }
});
exports.default = router;
//# sourceMappingURL=partners.js.map