"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || (function () {
    var ownKeys = function(o) {
        ownKeys = Object.getOwnPropertyNames || function (o) {
            var ar = [];
            for (var k in o) if (Object.prototype.hasOwnProperty.call(o, k)) ar[ar.length] = k;
            return ar;
        };
        return ownKeys(o);
    };
    return function (mod) {
        if (mod && mod.__esModule) return mod;
        var result = {};
        if (mod != null) for (var k = ownKeys(mod), i = 0; i < k.length; i++) if (k[i] !== "default") __createBinding(result, mod, k[i]);
        __setModuleDefault(result, mod);
        return result;
    };
})();
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const express_1 = require("express");
const authMiddleware_1 = require("../middleware/authMiddleware");
const adminMiddleware_1 = require("../middleware/adminMiddleware");
const lodgify = __importStar(require("../services/lodgifyService"));
const lodgifyCache_1 = require("../middleware/lodgifyCache");
const connection_1 = __importDefault(require("../db/connection"));
const router = (0, express_1.Router)();
// GET /api/lodgify/properties
router.get('/properties', authMiddleware_1.authMiddleware, async (req, res) => {
    try {
        const properties = await lodgify.getProperties();
        res.json({ data: properties });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch properties' });
    }
});
// GET /api/lodgify/properties/:id
router.get('/properties/:id', authMiddleware_1.authMiddleware, async (req, res) => {
    try {
        const property = await lodgify.getProperty(parseInt(req.params.id, 10));
        res.json({ data: property });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch property' });
    }
});
// GET /api/lodgify/properties/:id/availability?from=&to=
router.get('/properties/:id/availability', authMiddleware_1.authMiddleware, async (req, res) => {
    const { from, to } = req.query;
    if (!from || !to) {
        res.status(400).json({ error: 'Bad Request', message: 'from and to query params are required' });
        return;
    }
    try {
        const availability = await lodgify.getAvailability(parseInt(req.params.id, 10), from, to);
        res.json({ data: availability });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch availability' });
    }
});
// GET /api/lodgify/properties/:id/rates?from=&to=&guests=&partner_id=
// Applies partner markup — real Lodgify rates are NEVER exposed
router.get('/properties/:id/rates', authMiddleware_1.authMiddleware, async (req, res) => {
    const { from, to, guests } = req.query;
    if (!from || !to) {
        res.status(400).json({ error: 'Bad Request', message: 'from and to query params are required' });
        return;
    }
    try {
        const rawRates = await lodgify.getRates(parseInt(req.params.id, 10), from, to, parseInt(guests ?? '2', 10));
        // Determine partner markup
        let markupPercent = 0;
        if (req.user?.partner_id) {
            const [rows] = await connection_1.default.execute('SELECT markup_percent FROM partners WHERE id = ? LIMIT 1', [req.user.partner_id]);
            if (rows.length > 0)
                markupPercent = Number(rows[0].markup_percent);
        }
        // IMPORTANT: Lodgify real rates are NEVER exposed to the frontend.
        // price_per_night is already the marked-up rate the partner will charge customers.
        const markedUpRate = (baseRate) => parseFloat((baseRate * (1 + markupPercent / 100)).toFixed(2));
        const ratesWithMarkup = rawRates.map((r) => ({
            date_from: r.date_from,
            date_to: r.date_to,
            currency: r.currency,
            price_per_night: markedUpRate(r.price_per_night),
            price_per_night_with_markup: markedUpRate(r.price_per_night),
            markup_percent: markupPercent,
        }));
        res.json({ data: ratesWithMarkup });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch rates' });
    }
});
// POST /api/lodgify/sync — admin only
router.post('/sync', authMiddleware_1.authMiddleware, adminMiddleware_1.adminMiddleware, async (_req, res) => {
    try {
        (0, lodgifyCache_1.invalidateCache)('lodgify:');
        await lodgify.getProperties(); // re-warm cache
        res.json({ data: null, message: 'Lodgify cache cleared and refreshed' });
    }
    catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Internal Server Error', message: 'Sync failed' });
    }
});
exports.default = router;
//# sourceMappingURL=lodgify.js.map