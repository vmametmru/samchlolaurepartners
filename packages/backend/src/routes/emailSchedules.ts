import { Router, Response } from 'express';
import pool from '../db/connection';
import { authMiddleware, AuthRequest } from '../middleware/authMiddleware';
import type { RowDataPacket, ResultSetHeader } from 'mysql2';

const router = Router();

// GET /api/email-schedules
router.get('/', authMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  const partnerId = req.user?.role === 'admin'
    ? (req.query.partner_id ?? req.user.partner_id)
    : req.user?.partner_id;

  try {
    const [rows] = await pool.execute<RowDataPacket[]>(
      'SELECT * FROM email_schedules WHERE partner_id = ? ORDER BY days_before_arrival',
      [partnerId]
    );
    res.json({ data: rows });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch schedules' });
  }
});

// POST /api/email-schedules
router.post('/', authMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  const { days_before_arrival, template_type } = req.body as { days_before_arrival?: number; template_type?: string };

  if (days_before_arrival === undefined || !template_type) {
    res.status(400).json({ error: 'Bad Request', message: 'days_before_arrival and template_type are required' });
    return;
  }

  try {
    const [result] = await pool.execute<ResultSetHeader>(
      'INSERT INTO email_schedules (partner_id, days_before_arrival, template_type) VALUES (?, ?, ?)',
      [req.user?.partner_id, days_before_arrival, template_type]
    );
    res.status(201).json({ data: { id: result.insertId }, message: 'Schedule created' });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to create schedule' });
  }
});

// PUT /api/email-schedules/:id
router.put('/:id', authMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  const { days_before_arrival, template_type, active } = req.body as Record<string, unknown>;

  try {
    await pool.execute(
      'UPDATE email_schedules SET days_before_arrival=?, template_type=?, active=?, updated_at=NOW() WHERE id=? AND partner_id=?',
      [days_before_arrival, template_type, active ?? 1, req.params.id, req.user?.partner_id]
    );
    res.json({ data: null, message: 'Schedule updated' });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to update schedule' });
  }
});

// DELETE /api/email-schedules/:id
router.delete('/:id', authMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  try {
    await pool.execute(
      'DELETE FROM email_schedules WHERE id=? AND partner_id=?',
      [req.params.id, req.user?.partner_id]
    );
    res.json({ data: null, message: 'Schedule deleted' });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to delete schedule' });
  }
});

export default router;
