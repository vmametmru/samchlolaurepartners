import { Response, NextFunction } from 'express';
import { AuthRequest } from './authMiddleware';

export function adminMiddleware(req: AuthRequest, res: Response, next: NextFunction): void {
  if (!req.user) {
    res.status(401).json({ error: 'Unauthorized', message: 'Authentication required' });
    return;
  }

  if (req.user.role !== 'admin') {
    res.status(403).json({ error: 'Forbidden', message: 'Admin access required' });
    return;
  }

  next();
}
