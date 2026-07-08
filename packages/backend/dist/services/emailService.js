"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.sendTemplatedEmail = sendTemplatedEmail;
exports.sendRawEmail = sendRawEmail;
exports.sendContactEmail = sendContactEmail;
const nodemailer_1 = require("nodemailer");
function renderTemplate(template, variables) {
    return template.replace(/\{\{(\w+)\}\}/g, (_, key) => variables[key] ?? `{{${key}}}`);
}
function createTransporter(partner) {
    if (partner.smtp_host && partner.smtp_port && partner.smtp_user && partner.smtp_pass) {
        return (0, nodemailer_1.createTransport)({
            host: partner.smtp_host,
            port: partner.smtp_port,
            secure: partner.smtp_port === 465,
            auth: { user: partner.smtp_user, pass: partner.smtp_pass },
        });
    }
    // Fallback to env-configured transport
    return (0, nodemailer_1.createTransport)({
        host: process.env.SMTP_HOST ?? 'localhost',
        port: parseInt(process.env.SMTP_PORT ?? '1025', 10),
        secure: false,
    });
}
async function sendTemplatedEmail(partner, template, to, variables) {
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
async function sendRawEmail(partner, to, subject, html) {
    const transporter = createTransporter(partner);
    await transporter.sendMail({
        from: `"${partner.name}" <${partner.email}>`,
        to,
        subject,
        html,
    });
}
async function sendContactEmail(partner, replyTo, subject, html) {
    const transporter = createTransporter(partner);
    await transporter.sendMail({
        from: `"${partner.name}" <${partner.email}>`,
        to: partner.email,
        replyTo,
        subject,
        html,
    });
}
//# sourceMappingURL=emailService.js.map