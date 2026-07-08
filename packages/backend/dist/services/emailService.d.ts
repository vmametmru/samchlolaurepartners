import { Partner, EmailTemplate } from '@samchlolaurepartners/shared';
export declare function sendTemplatedEmail(partner: Partner, template: EmailTemplate, to: string, variables: Record<string, string>): Promise<void>;
export declare function sendRawEmail(partner: Partner, to: string, subject: string, html: string): Promise<void>;
export declare function sendContactEmail(partner: Partner, replyTo: string, subject: string, html: string): Promise<void>;
//# sourceMappingURL=emailService.d.ts.map