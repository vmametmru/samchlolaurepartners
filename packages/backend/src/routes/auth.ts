import { Router, Request, Response } from 'express';
import bcrypt from 'bcryptjs';
import jwt from 'jsonwebtoken';
import pool from '../db/connection';
import type { RowDataPacket } from 'mysql2';
import { User } from '@samchlolaurepartners/shared';

const router = Router();

// POST /api/auth/login
router.post('/login', async (req: Request, res: Response): Promise<void> => {
  const { email, password } = req.body as { email?: string; password?: string };

  if (!email || !password) {
    res.status(400).json({ error: 'Bad Request', message: 'email and password are required' });
    return;
  }

  try {
    const [rows] = await pool.execute<RowDataPacket[]>(
      `SELECT u.*, p.id AS partner_id_val, p.name AS partner_name,
              p.subdomain, p.logo_url, p.primary_color, p.email AS partner_email,
              p.markup_percent, p.active AS partner_active
       FROM users u
       LEFT JOIN partners p ON p.id = u.partner_id
       WHERE u.email = ? LIMIT 1`,
      [email]
    );

    if (rows.length === 0) {
      res.status(401).json({ error: 'Unauthorized', message: 'Invalid credentials' });
      return;
    }

    const user = rows[0] as RowDataPacket & User;
    const valid = await bcrypt.compare(password, user.password_hash);

    if (!valid) {
      res.status(401).json({ error: 'Unauthorized', message: 'Invalid credentials' });
      return;
    }

    const payload: Omit<User, 'password_hash'> = {
      id: user.id,
      partner_id: user.partner_id,
      email: user.email,
      role: user.role,
      created_at: user.created_at,
      updated_at: user.updated_at,
    };

    const secret = process.env.JWT_SECRET ?? 'default-secret';
    const token = jwt.sign(payload, secret, { expiresIn: '7d' });

    res.json({ token, user: payload });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Internal Server Error', message: 'Login failed' });
  }
});

// GET /api/auth/me
router.get('/me', async (req: Request, res: Response): Promise<void> => {
  const authHeader = req.headers.authorization;

  if (!authHeader?.startsWith('Bearer ')) {
    res.status(401).json({ error: 'Unauthorized', message: 'Missing token' });
    return;
  }

  const token = authHeader.slice(7);
  const secret = process.env.JWT_SECRET ?? 'default-secret';

  try {
    const payload = jwt.verify(token, secret) as Omit<User, 'password_hash'>;
    res.json({ data: payload });
  } catch {
    res.status(401).json({ error: 'Unauthorized', message: 'Invalid token' });
  }
});

export default router;
