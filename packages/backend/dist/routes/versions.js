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
// GET /api/versions — admin
router.get('/', authMiddleware_1.authMiddleware, adminMiddleware_1.adminMiddleware, async (_req, res) => {
    try {
        const [rows] = await connection_1.default.execute('SELECT * FROM app_versions ORDER BY deployed_at DESC');
        res.json({ data: rows });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch versions' });
    }
});
// POST /api/versions/deploy — admin
router.post('/deploy', authMiddleware_1.authMiddleware, adminMiddleware_1.adminMiddleware, async (req, res) => {
    const { version, notes } = req.body;
    if (!version) {
        res.status(400).json({ error: 'Bad Request', message: 'version is required' });
        return;
    }
    try {
        const [result] = await connection_1.default.execute('INSERT INTO app_versions (version, deployed_by, notes) VALUES (?, ?, ?)', [version, req.user?.email ?? 'system', notes ?? null]);
        res.status(201).json({ data: { id: result.insertId }, message: `Version ${version} deployed` });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to record deployment' });
    }
});
// POST /api/versions/rollback — admin
router.post('/rollback', authMiddleware_1.authMiddleware, adminMiddleware_1.adminMiddleware, async (req, res) => {
    const { version_id } = req.body;
    if (!version_id) {
        res.status(400).json({ error: 'Bad Request', message: 'version_id is required' });
        return;
    }
    try {
        await connection_1.default.execute('UPDATE app_versions SET rolled_back_at = NOW() WHERE id = ?', [version_id]);
        res.json({ data: null, message: 'Rollback recorded' });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to rollback' });
    }
});
// GET /api/versions/migrations — admin
router.get('/migrations', authMiddleware_1.authMiddleware, adminMiddleware_1.adminMiddleware, async (_req, res) => {
    try {
        const [rows] = await connection_1.default.execute('SELECT * FROM db_migrations ORDER BY applied_at DESC');
        res.json({ data: rows });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch migrations' });
    }
});
exports.default = router;
//# sourceMappingURL=versions.js.map