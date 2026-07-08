import { Router, Response } from 'express';
import pool from '../db/connection';
import { authMiddleware, AuthRequest } from '../middleware/authMiddleware';
import { adminMiddleware } from '../middleware/adminMiddleware';
import type { RowDataPacket } from 'mysql2';

const router = Router();

// GET /api/fees/cleaning
router.get('/cleaning', authMiddleware, adminMiddleware, async (_req: AuthRequest, res: Response): Promise<void> => {
  try {
    const [rows] = await pool.execute<RowDataPacket[]>('SELECT * FROM cleaning_fees ORDER BY property_id');
    res.json({ data: rows });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch cleaning fees' });
  }
});

// PUT /api/fees/cleaning/:propertyId — admin
router.put('/cleaning/:propertyId', authMiddleware, adminMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  const { per_person_per_night } = req.body as { per_person_per_night?: number };
  const propertyId = req.params.propertyId === 'default' ? null : req.params.propertyId;

  if (per_person_per_night === undefined) {
    res.status(400).json({ error: 'Bad Request', message: 'per_person_per_night is required' });
    return;
  }

  try {
    await pool.execute(
      `INSERT INTO cleaning_fees (property_id, per_person_per_night)
       VALUES (?, ?)
       ON DUPLICATE KEY UPDATE per_person_per_night = VALUES(per_person_per_night), updated_at = NOW()`,
      [propertyId, per_person_per_night]
    );
    res.json({ data: null, message: 'Cleaning fee updated' });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to update cleaning fee' });
  }
});

// GET /api/fees/tourist-tax
router.get('/tourist-tax', authMiddleware, adminMiddleware, async (_req: AuthRequest, res: Response): Promise<void> => {
  try {
    const [rows] = await pool.execute<RowDataPacket[]>('SELECT * FROM tourist_tax LIMIT 1');
    res.json({ data: rows[0] ?? null });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch tourist tax' });
  }
});

// PUT /api/fees/tourist-tax — admin
router.put('/tourist-tax', authMiddleware, adminMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  const { per_person_per_night, applies_to_foreigners_only, applies_to_children } = req.body as Record<string, unknown>;
  const perPersonPerNight = typeof per_person_per_night === 'number' ? per_person_per_night : 0;
  const appliesToForeignersOnly = applies_to_foreigners_only === true;
  const appliesToChildren = applies_to_children === true;

  try {
    await pool.execute(
      `INSERT INTO tourist_tax (id, per_person_per_night, applies_to_foreigners_only, applies_to_children)
       VALUES (1, ?, ?, ?)
       ON DUPLICATE KEY UPDATE
         per_person_per_night = VALUES(per_person_per_night),
         applies_to_foreigners_only = VALUES(applies_to_foreigners_only),
         applies_to_children = VALUES(applies_to_children),
         updated_at = NOW()`,
      [perPersonPerNight, appliesToForeignersOnly ? 1 : 0, appliesToChildren ? 1 : 0]
    );
    res.json({ data: null, message: 'Tourist tax updated' });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to update tourist tax' });
  }
});

export default router;
