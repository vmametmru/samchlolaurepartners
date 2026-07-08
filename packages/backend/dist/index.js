"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const express_1 = __importDefault(require("express"));
const cors_1 = __importDefault(require("cors"));
const helmet_1 = __importDefault(require("helmet"));
const morgan_1 = __importDefault(require("morgan"));
const express_rate_limit_1 = __importDefault(require("express-rate-limit"));
const dotenv_1 = __importDefault(require("dotenv"));
const tenantMiddleware_1 = require("./middleware/tenantMiddleware");
const auth_1 = __importDefault(require("./routes/auth"));
const lodgify_1 = __importDefault(require("./routes/lodgify"));
const partners_1 = __importDefault(require("./routes/partners"));
const reservations_1 = __importDefault(require("./routes/reservations"));
const emailTemplates_1 = __importDefault(require("./routes/emailTemplates"));
const emailSchedules_1 = __importDefault(require("./routes/emailSchedules"));
const contact_1 = __importDefault(require("./routes/contact"));
const fees_1 = __importDefault(require("./routes/fees"));
const versions_1 = __importDefault(require("./routes/versions"));
const diagnostic_1 = __importDefault(require("./routes/diagnostic"));
const schedulerService_1 = require("./services/schedulerService");
dotenv_1.default.config();
const app = (0, express_1.default)();
const PORT = parseInt(process.env.PORT ?? '3000', 10);
// ─── Security Middleware ───────────────────────────────────────────────────────
app.use((0, helmet_1.default)());
const allowedOrigins = process.env.CORS_ORIGIN
    ? process.env.CORS_ORIGIN.split(',').map((o) => o.trim())
    : ['http://localhost:5173'];
app.use((0, cors_1.default)({
    origin: (origin, callback) => {
        // Allow requests with no origin (e.g. server-to-server, curl)
        if (!origin)
            return callback(null, true);
        if (allowedOrigins.includes(origin))
            return callback(null, true);
        callback(new Error(`CORS: origin not allowed — ${origin}`));
    },
    credentials: true,
}));
app.use((0, morgan_1.default)('combined'));
app.use(express_1.default.json({ limit: '1mb' }));
app.use(express_1.default.urlencoded({ extended: true }));
// ─── Rate Limiting ─────────────────────────────────────────────────────────────
const limiter = (0, express_rate_limit_1.default)({
    windowMs: 15 * 60 * 1000, // 15 minutes
    max: 200,
    standardHeaders: true,
    legacyHeaders: false,
});
app.use('/api/', limiter);
// ─── Tenant Middleware ─────────────────────────────────────────────────────────
app.use(tenantMiddleware_1.tenantMiddleware);
// ─── Health Check ──────────────────────────────────────────────────────────────
app.get('/health', (_req, res) => {
    res.json({ status: 'ok', timestamp: new Date().toISOString() });
});
// ─── Routes ───────────────────────────────────────────────────────────────────
app.use('/api/auth', auth_1.default);
app.use('/api/lodgify', lodgify_1.default);
app.use('/api/partners', partners_1.default);
app.use('/api/reservations', reservations_1.default);
app.use('/api/email-templates', emailTemplates_1.default);
app.use('/api/email-schedules', emailSchedules_1.default);
app.use('/api/contact', contact_1.default);
app.use('/api/fees', fees_1.default);
app.use('/api/versions', versions_1.default);
app.use('/api/diagnostic', diagnostic_1.default);
// ─── 404 Handler ──────────────────────────────────────────────────────────────
app.use((_req, res) => {
    res.status(404).json({ error: 'Not Found', message: 'Route not found' });
});
// ─── Error Handler ────────────────────────────────────────────────────────────
app.use((err, _req, res, _next) => {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: err.message });
});
// ─── Start Server ─────────────────────────────────────────────────────────────
app.listen(PORT, () => {
    console.log(`[server] Backend running on port ${PORT}`);
    (0, schedulerService_1.startScheduler)();
    console.log('[scheduler] Email scheduler started');
});
exports.default = app;
//# sourceMappingURL=index.js.map