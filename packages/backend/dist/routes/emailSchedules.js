"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const express_1 = require("express");
const connection_1 = __importDefault(require("../db/connection"));
const authMiddleware_1 = require("../middleware/authMiddleware");
const router = (0, express_1.Router)();
// GET /api/email-schedules
router.get('/', authMiddleware_1.authMiddleware, async (req, res) => {
    const partnerId = req.user?.role === 'admin'
        ? (typeof req.query.partner_id === 'string' ? req.query.partner_id : req.user?.partner_id ?? null)
        : req.user?.partner_id ?? null;
    try {
        const [rows] = await connection_1.default.execute('SELECT * FROM email_schedules WHERE partner_id = ? ORDER BY days_before_arrival', [partnerId]);
        res.json({ data: rows });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch schedules' });
    }
});
// POST /api/email-schedules
router.post('/', authMiddleware_1.authMiddleware, async (req, res) => {
    const { days_before_arrival, template_type } = req.body;
    if (days_before_arrival === undefined || !template_type) {
        res.status(400).json({ error: 'Bad Request', message: 'days_before_arrival and template_type are required' });
        return;
    }
    try {
        const [result] = await connection_1.default.execute('INSERT INTO email_schedules (partner_id, days_before_arrival, template_type) VALUES (?, ?, ?)', [req.user?.partner_id ?? null, days_before_arrival, template_type]);
        res.status(201).json({ data: { id: result.insertId }, message: 'Schedule created' });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to create schedule' });
    }
});
// PUT /api/email-schedules/:id
router.put('/:id', authMiddleware_1.authMiddleware, async (req, res) => {
    const { days_before_arrival, template_type, active } = req.body;
    const partnerId = req.user?.partner_id ?? null;
    const daysBeforeArrival = typeof days_before_arrival === 'number' ? days_before_arrival : null;
    const templateType = typeof template_type === 'string' ? template_type : null;
    const activeValue = active === false ? 0 : 1;
    try {
        await connection_1.default.execute('UPDATE email_schedules SET days_before_arrival=?, template_type=?, active=?, updated_at=NOW() WHERE id=? AND partner_id=?', [daysBeforeArrival, templateType, activeValue, req.params.id, partnerId]);
        res.json({ data: null, message: 'Schedule updated' });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to update schedule' });
    }
});
// DELETE /api/email-schedules/:id
router.delete('/:id', authMiddleware_1.authMiddleware, async (req, res) => {
    const partnerId = req.user?.partner_id ?? null;
    try {
        await connection_1.default.execute('DELETE FROM email_schedules WHERE id=? AND partner_id=?', [req.params.id, partnerId]);
        res.json({ data: null, message: 'Schedule deleted' });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to delete schedule' });
    }
});
exports.default = router;
//# sourceMappingURL=emailSchedules.js.map