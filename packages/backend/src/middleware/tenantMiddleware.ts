import { Request, Response, NextFunction } from 'express';
import pool from '../db/connection';
import { Partner } from '@samchlolaurepartners/shared';

declare global {
  // eslint-disable-next-line @typescript-eslint/no-namespace
  namespace Express {
    interface Request {
      partner?: Partner;
    }
  }
}

export async function tenantMiddleware(
  req: Request,
  res: Response,
  next: NextFunction
): Promise<void> {
  const host = req.hostname; // e.g. "partner1.domain.com"
  const parts = host.split('.');

  // Require at least two parts; first segment is subdomain
  if (parts.length < 2) {
    // No subdomain — admin / direct access is allowed without a partner context
    return next();
  }

  const subdomain = parts[0];

  // Skip lookup for "www", "admin", "api" etc.
  if (['www', 'admin', 'api', 'localhost'].includes(subdomain)) {
    return next();
  }

  try {
    const [rows] = await pool.execute<import('mysql2').RowDataPacket[]>(
      'SELECT * FROM partners WHERE subdomain = ? AND active = 1 LIMIT 1',
      [subdomain]
    );

    if (rows.length === 0) {
      res.status(404).json({ error: 'Partner not found', message: `No active partner for subdomain: ${subdomain}` });
      return;
    }

    req.partner = rows[0] as unknown as Partner;
    next();
  } catch (err) {
    next(err);
  }
}
