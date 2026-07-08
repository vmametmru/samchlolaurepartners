declare module 'nodemailer' {
  export interface SendMailOptions {
    from?: string;
    to?: string;
    replyTo?: string;
    subject?: string;
    html?: string;
  }

  export interface Transporter {
    sendMail(options: SendMailOptions): Promise<unknown>;
  }

  export function createTransport(options: Record<string, unknown>): Transporter;
}
