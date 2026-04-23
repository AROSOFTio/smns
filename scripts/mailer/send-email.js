#!/usr/bin/env node
'use strict';

const dns = require('dns');
const net = require('net');
const nodemailer = require('nodemailer');

if (typeof dns.setDefaultResultOrder === 'function') {
  dns.setDefaultResultOrder('ipv4first');
}

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

async function resolveSmtpHostCandidates(hostname) {
  if (!hostname || net.isIP(hostname)) {
    return {
      candidates: [
        {
          host: hostname,
          tlsServername: null,
          source: 'literal'
        }
      ],
      lookupErrors: []
    };
  }

  const candidates = [];
  const seen = new Set();
  const lookupErrors = [];

  function addCandidate(host, source, tlsServername = hostname) {
    const key = `${host}|${tlsServername || ''}`;
    if (!host || seen.has(key)) {
      return;
    }
    seen.add(key);
    candidates.push({
      host,
      tlsServername,
      source
    });
  }

  try {
    const record = await dns.promises.lookup(hostname, { family: 4 });
    if (record && record.address) {
      addCandidate(record.address, 'lookup4');
    }
  } catch (error) {
    lookupErrors.push('lookup4: ' + (error && error.message ? error.message : String(error)));
  }

  try {
    const records = await dns.promises.resolve4(hostname);
    for (const address of records) {
      addCandidate(address, 'resolve4');
    }
  } catch (error) {
    lookupErrors.push('resolve4: ' + (error && error.message ? error.message : String(error)));
  }

  try {
    const records = await dns.promises.resolve6(hostname);
    for (const address of records) {
      addCandidate(address, 'resolve6');
    }
  } catch (error) {
    lookupErrors.push('resolve6: ' + (error && error.message ? error.message : String(error)));
  }

  addCandidate(hostname, 'hostname', hostname);

  return {
    candidates,
    lookupErrors
  };
}

function normalizeProvidedCandidates(rawCandidates, fallbackHostname) {
  if (!Array.isArray(rawCandidates) || !rawCandidates.length) {
    return [];
  }

  const normalized = [];
  const seen = new Set();

  for (const candidate of rawCandidates) {
    if (!candidate || !candidate.host) continue;

    const host = String(candidate.host).trim();
    const tlsServername = candidate.tls_servername === null || candidate.tls_servername === undefined
      ? null
      : String(candidate.tls_servername).trim();
    const source = candidate.source ? String(candidate.source).trim() : 'php';
    const key = `${host}|${tlsServername || ''}`;

    if (!host || seen.has(key)) continue;
    seen.add(key);

    normalized.push({
      host,
      tlsServername: tlsServername || fallbackHostname || null,
      source
    });
  }

  return normalized;
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
  const connectionTimeout = Number(
    smtp.connection_timeout || process.env.SMTP_CONNECTION_TIMEOUT || 12000
  );
  const greetingTimeout = Number(
    smtp.greeting_timeout || process.env.SMTP_GREETING_TIMEOUT || 9000
  );
  const socketTimeout = Number(
    smtp.socket_timeout || process.env.SMTP_SOCKET_TIMEOUT || 12000
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

  const providedCandidates = normalizeProvidedCandidates(smtp.host_candidates, smtpHost);
  const resolved = providedCandidates.length
    ? {
        candidates: providedCandidates,
        lookupErrors: Array.isArray(smtp.lookup_warnings) ? smtp.lookup_warnings : []
      }
    : await resolveSmtpHostCandidates(smtpHost);
  const attemptErrors = [];

  for (const candidate of resolved.candidates) {
    const transportOptions = {
      host: candidate.host,
      port: smtpPort,
      secure: smtpSecure,
      connectionTimeout: connectionTimeout,
      greetingTimeout: greetingTimeout,
      socketTimeout: socketTimeout
    };

    if (candidate.tlsServername) {
      transportOptions.tls = {
        servername: candidate.tlsServername
      };
    }

    if (smtpUser && smtpPass) {
      transportOptions.auth = {
        user: smtpUser,
        pass: smtpPass
      };
    }

    const transporter = nodemailer.createTransport(transportOptions);

    try {
      const info = await transporter.sendMail({
        from: `"${fromName}" <${fromEmail}>`,
        to: recipients.join(','),
        subject: payload.subject || '(No subject)',
        text: payload.text || '',
        html: payload.html || undefined
      });

      process.stdout.write(
        JSON.stringify({
          ok: true,
          messageId: info.messageId,
          smtpHostTried: candidate.host,
          smtpHostSource: candidate.source,
          lookupWarnings: resolved.lookupErrors
        }) + '\n'
      );
      return;
    } catch (error) {
      const message = error && error.message ? error.message : String(error);
      attemptErrors.push(`${candidate.host} [${candidate.source}]: ${message}`);
    }
  }

  let details = attemptErrors.join(' | ');
  if (resolved.lookupErrors.length) {
    details += (details ? ' | ' : '') + 'DNS lookup warnings: ' + resolved.lookupErrors.join('; ');
  }
  fail('Send failed', details || 'No SMTP connection attempt succeeded');
}

main().catch((error) => fail('Send failed', error.message));
