import { Router, Response } from 'express';
import axios from 'axios';
import { authMiddleware, AuthRequest } from '../middleware/authMiddleware';
import { adminMiddleware } from '../middleware/adminMiddleware';
import { getCache } from '../middleware/lodgifyCache';
import pool from '../db/connection';

const router = Router();

// GET /api/diagnostic — admin only
router.get('/', authMiddleware, adminMiddleware, async (_req: AuthRequest, res: Response): Promise<void> => {
  const results: Record<string, unknown> = {};

  // ── Database connectivity ───────────────────────────────────────────────────
  try {
    await pool.execute('SELECT 1');
    results.database = { ok: true };
  } catch (err) {
    results.database = { ok: false, error: String(err) };
  }

  // ── Lodgify API ─────────────────────────────────────────────────────────────
  const lodgifyKey = process.env.LODGIFY_API_KEY ?? '';
  const lodgifyBase = process.env.LODGIFY_BASE_URL ?? 'https://api.lodgify.com/v2';

  if (!lodgifyKey) {
    results.lodgify = { ok: false, error: 'LODGIFY_API_KEY is not set' };
  } else {
    try {
      const { data, status } = await axios.get(`${lodgifyBase}/properties`, {
        headers: { 'X-ApiKey': lodgifyKey, Accept: 'application/json' },
        timeout: 10000,
      });
      const items: unknown[] = Array.isArray(data) ? data : (data?.items ?? []);
      results.lodgify = {
        ok: true,
        http_status: status,
        property_count: items.length,
        response_keys: data && typeof data === 'object' ? Object.keys(data) : null,
        sample: items.slice(0, 2),
      };
    } catch (err) {
      const axiosErr = err as { response?: { status: number; data: unknown }; message?: string };
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
    properties_cached: getCache('lodgify:properties') !== null,
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

export default router;
