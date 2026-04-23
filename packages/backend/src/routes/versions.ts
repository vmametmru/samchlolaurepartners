import { Router, Response } from 'express';
import pool from '../db/connection';
import { authMiddleware, AuthRequest } from '../middleware/authMiddleware';
import { adminMiddleware } from '../middleware/adminMiddleware';
import type { RowDataPacket, ResultSetHeader } from 'mysql2';

const router = Router();

// GET /api/versions — admin
router.get('/', authMiddleware, adminMiddleware, async (_req: AuthRequest, res: Response): Promise<void> => {
  try {
    const [rows] = await pool.execute<RowDataPacket[]>(
      'SELECT * FROM app_versions ORDER BY deployed_at DESC'
    );
    res.json({ data: rows });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch versions' });
  }
});

// POST /api/versions/deploy — admin
router.post('/deploy', authMiddleware, adminMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  const { version, notes } = req.body as { version?: string; notes?: string };

  if (!version) {
    res.status(400).json({ error: 'Bad Request', message: 'version is required' });
    return;
  }

  try {
    const [result] = await pool.execute<ResultSetHeader>(
      'INSERT INTO app_versions (version, deployed_by, notes) VALUES (?, ?, ?)',
      [version, req.user?.email ?? 'system', notes ?? null]
    );
    res.status(201).json({ data: { id: result.insertId }, message: `Version ${version} deployed` });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to record deployment' });
  }
});

// POST /api/versions/rollback — admin
router.post('/rollback', authMiddleware, adminMiddleware, async (req: AuthRequest, res: Response): Promise<void> => {
  const { version_id } = req.body as { version_id?: number };

  if (!version_id) {
    res.status(400).json({ error: 'Bad Request', message: 'version_id is required' });
    return;
  }

  try {
    await pool.execute(
      'UPDATE app_versions SET rolled_back_at = NOW() WHERE id = ?',
      [version_id]
    );
    res.json({ data: null, message: 'Rollback recorded' });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to rollback' });
  }
});

// GET /api/versions/migrations — admin
router.get('/migrations', authMiddleware, adminMiddleware, async (_req: AuthRequest, res: Response): Promise<void> => {
  try {
    const [rows] = await pool.execute<RowDataPacket[]>(
      'SELECT * FROM db_migrations ORDER BY applied_at DESC'
    );
    res.json({ data: rows });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Failed to fetch migrations' });
  }
});

export default router;
