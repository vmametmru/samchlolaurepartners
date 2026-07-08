import { Request, Response, NextFunction } from 'express';
import { Partner } from '@samchlolaurepartners/shared';
declare global {
    namespace Express {
        interface Request {
            partner?: Partner;
        }
    }
}
export declare function tenantMiddleware(req: Request, res: Response, next: NextFunction): Promise<void>;
//# sourceMappingURL=tenantMiddleware.d.ts.map