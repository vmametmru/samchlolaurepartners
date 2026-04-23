import { createTransport } from 'nodemailer';
import type { Transporter } from 'nodemailer';
import { Partner, EmailTemplate } from '@samchlolaurepartners/shared';

function renderTemplate(template: string, variables: Record<string, string>): string {
  return template.replace(/\{\{(\w+)\}\}/g, (_, key: string) => variables[key] ?? `{{${key}}}`);
}

function createTransporter(partner: Partner): Transporter {
  if (partner.smtp_host && partner.smtp_port && partner.smtp_user && partner.smtp_pass) {
    return createTransport({
      host: partner.smtp_host,
      port: partner.smtp_port,
      secure: partner.smtp_port === 465,
      auth: { user: partner.smtp_user, pass: partner.smtp_pass },
    });
  }

  // Fallback to env-configured transport
  return createTransport({
    host: process.env.SMTP_HOST ?? 'localhost',
    port: parseInt(process.env.SMTP_PORT ?? '1025', 10),
    secure: false,
  });
}

export async function sendTemplatedEmail(
  partner: Partner,
  template: EmailTemplate,
  to: string,
  variables: Record<string, string>
): Promise<void> {
  const transporter = createTransporter(partner);
  const subject = renderTemplate(template.subject, variables);
  const html = renderTemplate(template.body_html, variables);

  await transporter.sendMail({
    from: `"${partner.name}" <${partner.email}>`,
    to,
    subject,
    html,
  });
}

export async function sendRawEmail(
  partner: Partner,
  to: string,
  subject: string,
  html: string
): Promise<void> {
  const transporter = createTransporter(partner);
  await transporter.sendMail({
    from: `"${partner.name}" <${partner.email}>`,
    to,
    subject,
    html,
  });
}

export async function sendContactEmail(
  partner: Partner,
  replyTo: string,
  subject: string,
  html: string
): Promise<void> {
  const transporter = createTransporter(partner);
  await transporter.sendMail({
    from: `"${partner.name}" <${partner.email}>`,
    to: partner.email,
    replyTo,
    subject,
    html,
  });
}
