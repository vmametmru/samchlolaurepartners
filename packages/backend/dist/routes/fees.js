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
// GET /api/fees/cleaning
router.get('/cleaning', authMiddleware_1.authMiddleware, adminMiddleware_1.adminMiddleware, async (_req, res) => {
    try {
        const [rows] = await connection_1.default.execute('SELECT * FROM cleaning_fees ORDER BY property_id');
        res.json({ data: rows });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch cleaning fees' });
    }
});
// PUT /api/fees/cleaning/:propertyId — admin
router.put('/cleaning/:propertyId', authMiddleware_1.authMiddleware, adminMiddleware_1.adminMiddleware, async (req, res) => {
    const { per_person_per_night } = req.body;
    const propertyId = req.params.propertyId === 'default' ? null : req.params.propertyId;
    if (per_person_per_night === undefined) {
        res.status(400).json({ error: 'Bad Request', message: 'per_person_per_night is required' });
        return;
    }
    try {
        await connection_1.default.execute(`INSERT INTO cleaning_fees (property_id, per_person_per_night)
       VALUES (?, ?)
       ON DUPLICATE KEY UPDATE per_person_per_night = VALUES(per_person_per_night), updated_at = NOW()`, [propertyId, per_person_per_night]);
        res.json({ data: null, message: 'Cleaning fee updated' });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to update cleaning fee' });
    }
});
// GET /api/fees/tourist-tax
router.get('/tourist-tax', authMiddleware_1.authMiddleware, adminMiddleware_1.adminMiddleware, async (_req, res) => {
    try {
        const [rows] = await connection_1.default.execute('SELECT * FROM tourist_tax LIMIT 1');
        res.json({ data: rows[0] ?? null });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch tourist tax' });
    }
});
// PUT /api/fees/tourist-tax — admin
router.put('/tourist-tax', authMiddleware_1.authMiddleware, adminMiddleware_1.adminMiddleware, async (req, res) => {
    const { per_person_per_night, applies_to_foreigners_only, applies_to_children } = req.body;
    try {
        await connection_1.default.execute(`INSERT INTO tourist_tax (id, per_person_per_night, applies_to_foreigners_only, applies_to_children)
       VALUES (1, ?, ?, ?)
       ON DUPLICATE KEY UPDATE
         per_person_per_night = VALUES(per_person_per_night),
         applies_to_foreigners_only = VALUES(applies_to_foreigners_only),
         applies_to_children = VALUES(applies_to_children),
         updated_at = NOW()`, [per_person_per_night ?? 0, applies_to_foreigners_only ? 1 : 0, applies_to_children ? 1 : 0]);
        res.json({ data: null, message: 'Tourist tax updated' });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to update tourist tax' });
    }
});
exports.default = router;
//# sourceMappingURL=fees.js.map