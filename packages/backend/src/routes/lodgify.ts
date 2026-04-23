import { Router, Response } from 'express';
import { authMiddleware, AuthRequest } from '../middleware/authMiddleware';
import { adminMiddleware } from '../middleware/adminMiddleware';
import * as lodgify from '../services/lodgifyService';
import { invalidateCache } from '../middleware/lodgifyCache';
import { LodgifyRate, RateWithMarkup } from '@samchlolaurepartners/shared';
import pool from '../db/connection';
import type { RowDataPacket } from 'mysql2';

const router = Router();

// GET /api/lodgify/properties
router.get('/properties', authMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  try {
    const properties = await lodgify.getProperties();
    res.json({ data: properties });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch properties' });
  }
});

// GET /api/lodgify/properties/:id
router.get('/properties/:id', authMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  try {
    const property = await lodgify.getProperty(parseInt(req.params.id, 10));
    res.json({ data: property });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch property' });
  }
});

// GET /api/lodgify/properties/:id/availability?from=&to=
router.get('/properties/:id/availability', authMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  const { from, to } = req.query as { from?: string; to?: string };

  if (!from || !to) {
    res.status(400).json({ error: 'Bad Request', message: 'from and to query params are required' });
    return;
  }

  try {
    const availability = await lodgify.getAvailability(parseInt(req.params.id, 10), from, to);
    res.json({ data: availability });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch availability' });
  }
});

// GET /api/lodgify/properties/:id/rates?from=&to=&guests=&partner_id=
// Applies partner markup — real Lodgify rates are NEVER exposed
router.get('/properties/:id/rates', authMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  const { from, to, guests } = req.query as { from?: string; to?: string; guests?: string };

  if (!from || !to) {
    res.status(400).json({ error: 'Bad Request', message: 'from and to query params are required' });
    return;
  }

  try {
    const rawRates = await lodgify.getRates(
      parseInt(req.params.id, 10),
      from,
      to,
      parseInt(guests ?? '2', 10)
    );

    // Determine partner markup
    let markupPercent = 0;
    if (req.user?.partner_id) {
      const [rows] = await pool.execute<RowDataPacket[]>(
        'SELECT markup_percent FROM partners WHERE id = ? LIMIT 1',
        [req.user.partner_id]
      );
      if (rows.length > 0) markupPercent = Number(rows[0].markup_percent);
    }

    const ratesWithMarkup: RateWithMarkup[] = rawRates.map((r: LodgifyRate) => ({
      date_from: r.date_from,
      date_to: r.date_to,
      currency: r.currency,
      // Real price is hidden — only the marked-up price is returned
      price_per_night: parseFloat(
        (r.price_per_night * (1 + markupPercent / 100)).toFixed(2)
      ),
      price_per_night_with_markup: parseFloat(
        (r.price_per_night * (1 + markupPercent / 100)).toFixed(2)
      ),
      markup_percent: markupPercent,
    }));

    res.json({ data: ratesWithMarkup });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch rates' });
  }
});

// POST /api/lodgify/sync — admin only
router.post('/sync', authMiddleware, adminMiddleware, async (_req: AuthRequest, res: Response): Promise<void> => {
  try {
    invalidateCache('lodgify:');
    await lodgify.getProperties(); // re-warm cache
    res.json({ data: null, message: 'Lodgify cache cleared and refreshed' });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Sync failed' });
  }
});

export default router;
