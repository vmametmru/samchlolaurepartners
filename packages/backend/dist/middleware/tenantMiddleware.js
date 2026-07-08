"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.tenantMiddleware = tenantMiddleware;
const connection_1 = __importDefault(require("../db/connection"));
async function tenantMiddleware(req, res, next) {
    const host = req.hostname; // e.g. "partner1.domain.com"
    const parts = host.split('.');
    // Require at least two parts; first segment is subdomain
    if (parts.length < 2) {
        // No subdomain — admin / direct access is allowed without a partner context
        return next();
    }
    const subdomain = parts[0];
    // Skip lookup for "www", "admin", "api" etc.
    if (['www', 'admin', 'api', 'localhost'].includes(subdomain)) {
        return next();
    }
    try {
        const [rows] = await connection_1.default.execute('SELECT * FROM partners WHERE subdomain = ? AND active = 1 LIMIT 1', [subdomain]);
        if (rows.length === 0) {
            res.status(404).json({ error: 'Partner not found', message: `No active partner for subdomain: ${subdomain}` });
            return;
        }
        req.partner = rows[0];
        next();
    }
    catch (err) {
        next(err);
    }
}
//# sourceMappingURL=tenantMiddleware.js.map