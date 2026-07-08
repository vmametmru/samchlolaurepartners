"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const express_1 = require("express");
const bcryptjs_1 = __importDefault(require("bcryptjs"));
const jsonwebtoken_1 = __importDefault(require("jsonwebtoken"));
const connection_1 = __importDefault(require("../db/connection"));
const router = (0, express_1.Router)();
// POST /api/auth/login
router.post('/login', async (req, res) => {
    const { email, password } = req.body;
    if (!email || !password) {
        res.status(400).json({ error: 'Bad Request', message: 'email and password are required' });
        return;
    }
    try {
        const [rows] = await connection_1.default.execute(`SELECT u.*, p.id AS partner_id_val, p.name AS partner_name,
              p.subdomain, p.logo_url, p.primary_color, p.email AS partner_email,
              p.markup_percent, p.active AS partner_active
       FROM users u
       LEFT JOIN partners p ON p.id = u.partner_id
       WHERE u.email = ? LIMIT 1`, [email]);
        if (rows.length === 0) {
            res.status(401).json({ error: 'Unauthorized', message: 'Invalid credentials' });
            return;
        }
        const user = rows[0];
        const valid = await bcryptjs_1.default.compare(password, user.password_hash);
        if (!valid) {
            res.status(401).json({ error: 'Unauthorized', message: 'Invalid credentials' });
            return;
        }
        const payload = {
            id: user.id,
            partner_id: user.partner_id,
            email: user.email,
            role: user.role,
            created_at: user.created_at,
            updated_at: user.updated_at,
        };
        const secret = process.env.JWT_SECRET ?? 'default-secret';
        const token = jsonwebtoken_1.default.sign(payload, secret, { expiresIn: '7d' });
        res.json({ token, user: payload });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Login failed' });
    }
});
// GET /api/auth/me
router.get('/me', async (req, res) => {
    const authHeader = req.headers.authorization;
    if (!authHeader?.startsWith('Bearer ')) {
        res.status(401).json({ error: 'Unauthorized', message: 'Missing token' });
        return;
    }
    const token = authHeader.slice(7);
    const secret = process.env.JWT_SECRET ?? 'default-secret';
    try {
        const payload = jsonwebtoken_1.default.verify(token, secret);
        res.json({ data: payload });
    }
    catch {
        res.status(401).json({ error: 'Unauthorized', message: 'Invalid token' });
    }
});
exports.default = router;
//# sourceMappingURL=auth.js.map