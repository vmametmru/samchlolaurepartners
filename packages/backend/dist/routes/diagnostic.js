"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const express_1 = require("express");
const axios_1 = __importDefault(require("axios"));
const authMiddleware_1 = require("../middleware/authMiddleware");
const adminMiddleware_1 = require("../middleware/adminMiddleware");
const lodgifyCache_1 = require("../middleware/lodgifyCache");
const connection_1 = __importDefault(require("../db/connection"));
const router = (0, express_1.Router)();
// GET /api/diagnostic — admin only
router.get('/', authMiddleware_1.authMiddleware, adminMiddleware_1.adminMiddleware, async (_req, res) => {
    const results = {};
    // ── Database connectivity ───────────────────────────────────────────────────
    try {
        await connection_1.default.execute('SELECT 1');
        results.database = { ok: true };
    }
    catch (err) {
        results.database = { ok: false, error: String(err) };
    }
    // ── Lodgify API ─────────────────────────────────────────────────────────────
    const lodgifyKey = process.env.LODGIFY_API_KEY ?? '';
    const lodgifyBase = process.env.LODGIFY_BASE_URL ?? 'https://api.lodgify.com/v2';
    if (!lodgifyKey) {
        results.lodgify = { ok: false, error: 'LODGIFY_API_KEY is not set' };
    }
    else {
        try {
            const { data, status } = await axios_1.default.get(`${lodgifyBase}/properties`, {
                headers: { 'X-ApiKey': lodgifyKey, Accept: 'application/json' },
                timeout: 10000,
            });
            const items = Array.isArray(data) ? data : (data?.items ?? []);
            results.lodgify = {
                ok: true,
                http_status: status,
                property_count: items.length,
                response_keys: data && typeof data === 'object' ? Object.keys(data) : null,
                sample: items.slice(0, 2),
            };
        }
        catch (err) {
            const axiosErr = err;
            results.lodgify = {
                ok: false,
                error: axiosErr?.message ?? String(err),
                http_status: axiosErr?.response?.status ?? null,
                response_body: axiosErr?.response?.data ?? null,
            };
        }
    }
    // ── In-memory cache summary ─────────────────────────────────────────────────
    const cacheKeys = ['lodgify:properties'];
    results.cache = {
        properties_cached: (0, lodgifyCache_1.getCache)('lodgify:properties') !== null,
        keys_checked: cacheKeys,
    };
    // ── Environment (non-secret) ────────────────────────────────────────────────
    results.env = {
        NODE_ENV: process.env.NODE_ENV ?? '(not set)',
        PORT: process.env.PORT ?? '(not set)',
        LODGIFY_BASE_URL: lodgifyBase,
        LODGIFY_API_KEY_SET: lodgifyKey.length > 0,
        CORS_ORIGIN: process.env.CORS_ORIGIN ?? '(not set)',
        DB_HOST: process.env.DB_HOST ?? '(not set)',
        DB_NAME: process.env.DB_NAME ?? '(not set)',
    };
    res.json({ data: results });
});
exports.default = router;
//# sourceMappingURL=diagnostic.js.map