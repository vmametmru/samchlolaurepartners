import { Router, Response } from 'express';
import pool from '../db/connection';
import { authMiddleware, AuthRequest } from '../middleware/authMiddleware';
import { adminMiddleware } from '../middleware/adminMiddleware';
import type { RowDataPacket, ResultSetHeader } from 'mysql2';

const router = Router();

// GET /api/partners — admin only
router.get('/', authMiddleware, adminMiddleware, async (_req: AuthRequest, res: Response): Promise<void> => {
  try {
    const [rows] = await pool.execute<RowDataPacket[]>(
      'SELECT id, subdomain, name, logo_url, primary_color, email, markup_percent, active, created_at, updated_at FROM partners ORDER BY name'
    );
    res.json({ data: rows });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch partners' });
  }
});

// GET /api/partners/current — public, uses tenant middleware result
router.get('/current', async (req: AuthRequest, res: Response): Promise<void> => {
  if (!req.partner) {
    res.status(404).json({ error: 'Not Found', message: 'No partner context' });
    return;
  }

  // Never expose SMTP credentials or markup to public
  const { smtp_host, smtp_port, smtp_user, smtp_pass, markup_percent, ...publicFields } = req.partner;
  void smtp_host; void smtp_port; void smtp_user; void smtp_pass; void markup_percent;
  res.json({ data: publicFields });
});

// GET /api/partners/:id — admin only
router.get('/:id', authMiddleware, adminMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  try {
    const [rows] = await pool.execute<RowDataPacket[]>(
      'SELECT * FROM partners WHERE id = ? LIMIT 1',
      [req.params.id]
    );
    if (rows.length === 0) {
      res.status(404).json({ error: 'Not Found', message: 'Partner not found' });
      return;
    }
    // Don't expose markup in public API — admin context only
    res.json({ data: rows[0] });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch partner' });
  }
});

// POST /api/partners — admin only
router.post('/', authMiddleware, adminMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  const { subdomain, name, logo_url, primary_color, email, markup_percent, smtp_host, smtp_port, smtp_user, smtp_pass } = req.body as Record<string, unknown>;

  if (!subdomain || !name || !email) {
    res.status(400).json({ error: 'Bad Request', message: 'subdomain, name, email are required' });
    return;
  }

  try {
    const [result] = await pool.execute<ResultSetHeader>(
      `INSERT INTO partners (subdomain, name, logo_url, primary_color, email, markup_percent, smtp_host, smtp_port, smtp_user, smtp_pass)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [subdomain, name, logo_url ?? null, primary_color ?? '#E61E4D', email, markup_percent ?? 0, smtp_host ?? null, smtp_port ?? null, smtp_user ?? null, smtp_pass ?? null]
    );
    res.status(201).json({ data: { id: result.insertId }, message: 'Partner created' });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to create partner' });
  }
});

// PUT /api/partners/:id — admin only
router.put('/:id', authMiddleware, adminMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  const { name, logo_url, primary_color, email, markup_percent, smtp_host, smtp_port, smtp_user, smtp_pass, active } = req.body as Record<string, unknown>;

  try {
    await pool.execute(
      `UPDATE partners SET name=?, logo_url=?, primary_color=?, email=?, markup_percent=?,
       smtp_host=?, smtp_port=?, smtp_user=?, smtp_pass=?, active=?, updated_at=NOW()
       WHERE id=?`,
      [name, logo_url ?? null, primary_color, email, markup_percent, smtp_host ?? null, smtp_port ?? null, smtp_user ?? null, smtp_pass ?? null, active ?? 1, req.params.id]
    );
    res.json({ data: null, message: 'Partner updated' });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to update partner' });
  }
});

// DELETE /api/partners/:id — admin only
router.delete('/:id', authMiddleware, adminMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  try {
    await pool.execute('UPDATE partners SET active = 0, updated_at = NOW() WHERE id = ?', [req.params.id]);
    res.json({ data: null, message: 'Partner deactivated' });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to delete partner' });
  }
});

export default router;
