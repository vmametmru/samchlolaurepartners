import { Request, Response, NextFunction } from 'express';
import { User } from '@samchlolaurepartners/shared';
export interface AuthRequest extends Request {
    user?: Omit<User, 'password_hash'>;
}
export declare function authMiddleware(req: AuthRequest, res: Response, next: NextFunction): void;
//# sourceMappingURL=authMiddleware.d.ts.map