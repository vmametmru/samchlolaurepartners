"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.authMiddleware = authMiddleware;
const jsonwebtoken_1 = __importDefault(require("jsonwebtoken"));
function authMiddleware(req, res, next) {
    const authHeader = req.headers.authorization;
    if (!authHeader?.startsWith('Bearer ')) {
        res.status(401).json({ error: 'Unauthorized', message: 'Missing or invalid Authorization header' });
        return;
    }
    const token = authHeader.slice(7);
    const secret = process.env.JWT_SECRET ?? 'default-secret';
    try {
        const payload = jsonwebtoken_1.default.verify(token, secret);
        req.user = payload;
        next();
    }
    catch {
        res.status(401).json({ error: 'Unauthorized', message: 'Invalid or expired token' });
    }
}
//# sourceMappingURL=authMiddleware.js.map