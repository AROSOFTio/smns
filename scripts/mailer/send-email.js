#!/usr/bin/env node
'use strict';

const nodemailer = require('nodemailer');

function parseBool(value, defaultValue = false) {
  if (value === undefined || value === null || value === '') return defaultValue;
  const normalized = String(value).toLowerCase().trim();
  return normalized === '1' || normalized === 'true' || normalized === 'yes';
}

function fail(message, details) {
  const payload = { ok: false, message };
  if (details) payload.details = details;
  process.stderr.write(JSON.stringify(payload) + '\n');
  process.exit(1);
}

async function main() {
  const encodedPayload = process.argv[2];
  if (!encodedPayload) {
    fail('Missing payload argument');
  }

  let payload;
  try {
    const decoded = Buffer.from(encodedPayload, 'base64').toString('utf8');
    payload = JSON.parse(decoded);
  } catch (error) {
    fail('Invalid payload', error.message);
  }

  const recipients = Array.isArray(payload.to) ? payload.to.filter(Boolean) : [];
  if (!recipients.length) {
    fail('No recipients provided');
  }

  const smtp = payload.smtp || {};
  const smtpHost = smtp.host || process.env.SMTP_HOST;
  const smtpPort = Number(smtp.port || process.env.SMTP_PORT || 587);
  const smtpUser = smtp.username || process.env.SMTP_USERNAME || process.env.SMTP_USER || '';
  const smtpPass = smtp.password || process.env.SMTP_PASSWORD || process.env.SMTP_PASS || '';
  const smtpSecure = parseBool(
    smtp.secure !== undefined ? smtp.secure : process.env.SMTP_SECURE,
    smtpPort === 465
  );

  if (!smtpHost) {
    fail('SMTP host is missing');
  }

  const from = payload.from || {};
  const fromEmail = from.email || process.env.SMTP_FROM_EMAIL || smtpUser;
  const fromName = from.name || process.env.SMTP_FROM_NAME || 'SMNS';
  if (!fromEmail) {
    fail('From email is missing');
  }

  const transportOptions = {
    host: smtpHost,
    port: smtpPort,
    secure: smtpSecure
  };

  if (smtpUser && smtpPass) {
    transportOptions.auth = {
      user: smtpUser,
      pass: smtpPass
    };
  }

  const transporter = nodemailer.createTransport(transportOptions);
  const info = await transporter.sendMail({
    from: `"${fromName}" <${fromEmail}>`,
    to: recipients.join(','),
    subject: payload.subject || '(No subject)',
    text: payload.text || '',
    html: payload.html || undefined
  });

  process.stdout.write(JSON.stringify({ ok: true, messageId: info.messageId }) + '\n');
}

main().catch((error) => fail('Send failed', error.message));
