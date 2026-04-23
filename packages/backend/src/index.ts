import express from 'express';
import cors from 'cors';
import helmet from 'helmet';
import morgan from 'morgan';
import rateLimit from 'express-rate-limit';
import dotenv from 'dotenv';

import { tenantMiddleware } from './middleware/tenantMiddleware';
import authRoutes from './routes/auth';
import lodgifyRoutes from './routes/lodgify';
import partnerRoutes from './routes/partners';
import reservationRoutes from './routes/reservations';
import emailTemplateRoutes from './routes/emailTemplates';
import emailScheduleRoutes from './routes/emailSchedules';
import contactRoutes from './routes/contact';
import feeRoutes from './routes/fees';
import versionRoutes from './routes/versions';
import { startScheduler } from './services/schedulerService';

dotenv.config();

const app = express();
const PORT = parseInt(process.env.PORT ?? '3000', 10);

// ─── Security Middleware ───────────────────────────────────────────────────────
app.use(helmet());
app.use(cors({
  origin: process.env.CORS_ORIGIN ?? '*',
  credentials: true,
}));
app.use(morgan('combined'));
app.use(express.json({ limit: '1mb' }));
app.use(express.urlencoded({ extended: true }));

// ─── Rate Limiting ─────────────────────────────────────────────────────────────
const limiter = rateLimit({
  windowMs: 15 * 60 * 1000, // 15 minutes
  max: 200,
  standardHeaders: true,
  legacyHeaders: false,
});
app.use('/api/', limiter);

// ─── Tenant Middleware ─────────────────────────────────────────────────────────
app.use(tenantMiddleware);

// ─── Health Check ──────────────────────────────────────────────────────────────
app.get('/health', (_req, res) => {
  res.json({ status: 'ok', timestamp: new Date().toISOString() });
});

// ─── Routes ───────────────────────────────────────────────────────────────────
app.use('/api/auth', authRoutes);
app.use('/api/lodgify', lodgifyRoutes);
app.use('/api/partners', partnerRoutes);
app.use('/api/reservations', reservationRoutes);
app.use('/api/email-templates', emailTemplateRoutes);
app.use('/api/email-schedules', emailScheduleRoutes);
app.use('/api/contact', contactRoutes);
app.use('/api/fees', feeRoutes);
app.use('/api/versions', versionRoutes);

// ─── 404 Handler ──────────────────────────────────────────────────────────────
app.use((_req, res) => {
  res.status(404).json({ error: 'Not Found', message: 'Route not found' });
});

// ─── Error Handler ────────────────────────────────────────────────────────────
app.use((err: Error, _req: express.Request, res: express.Response, _next: express.NextFunction) => {
  console.error(err);
  res.status(500).json({ error: 'Internal Server Error', message: err.message });
});

// ─── Start Server ─────────────────────────────────────────────────────────────
app.listen(PORT, () => {
  console.log(`[server] Backend running on port ${PORT}`);
  startScheduler();
  console.log('[scheduler] Email scheduler started');
});

export default app;
