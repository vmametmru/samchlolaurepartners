import { Router, Response } from 'express';
import pool from '../db/connection';
import { authMiddleware, AuthRequest } from '../middleware/authMiddleware';
import type { RowDataPacket, ResultSetHeader } from 'mysql2';

const router = Router();

// GET /api/email-templates — partner
router.get('/', authMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  const partnerId = req.user?.role === 'admin'
    ? (req.query.partner_id ?? req.user.partner_id)
    : req.user?.partner_id;

  try {
    const [rows] = await pool.execute<RowDataPacket[]>(
      'SELECT * FROM email_templates WHERE partner_id = ? ORDER BY type',
      [partnerId]
    );
    res.json({ data: rows });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch templates' });
  }
});

// GET /api/email-templates/:id
router.get('/:id', authMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  try {
    const [rows] = await pool.execute<RowDataPacket[]>(
      'SELECT * FROM email_templates WHERE id = ? AND partner_id = ? LIMIT 1',
      [req.params.id, req.user?.partner_id]
    );
    if (rows.length === 0) {
      res.status(404).json({ error: 'Not Found', message: 'Template not found' });
      return;
    }
    res.json({ data: rows[0] });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch template' });
  }
});

// POST /api/email-templates
router.post('/', authMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  const { type, subject, body_html } = req.body as { type?: string; subject?: string; body_html?: string };

  if (!type || !subject || !body_html) {
    res.status(400).json({ error: 'Bad Request', message: 'type, subject, body_html are required' });
    return;
  }

  try {
    const [result] = await pool.execute<ResultSetHeader>(
      'INSERT INTO email_templates (partner_id, type, subject, body_html) VALUES (?, ?, ?, ?)',
      [req.user?.partner_id, type, subject, body_html]
    );
    res.status(201).json({ data: { id: result.insertId }, message: 'Template created' });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to create template' });
  }
});

// PUT /api/email-templates/:id
router.put('/:id', authMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  const { subject, body_html } = req.body as { subject?: string; body_html?: string };

  try {
    await pool.execute(
      'UPDATE email_templates SET subject=?, body_html=?, updated_at=NOW() WHERE id=? AND partner_id=?',
      [subject, body_html, req.params.id, req.user?.partner_id]
    );
    res.json({ data: null, message: 'Template updated' });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to update template' });
  }
});

// DELETE /api/email-templates/:id
router.delete('/:id', authMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  try {
    await pool.execute(
      'DELETE FROM email_templates WHERE id=? AND partner_id=?',
      [req.params.id, req.user?.partner_id]
    );
    res.json({ data: null, message: 'Template deleted' });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to delete template' });
  }
});

export default router;
