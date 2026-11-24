const crypto = require('crypto');

class AppError extends Error {
  constructor(status, message, details) {
    super(message);
    this.status = status;
    this.details = details;
  }
}

function serviceKey(req, _res, next) {
  const expected = process.env.NODE_SERVICE_KEY || '';
  const provided = req.header('x-service-key') || '';
  const a = Buffer.from(provided);
  const b = Buffer.from(expected);
  if (!expected || a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
    return next(new AppError(401, 'Unauthorized service'));
  }
  return next();
}

function validateBody(fields) {
  return (req, _res, next) => {
    const body = req.body || {};
    const missing = fields.filter((field) => body[field] === undefined || body[field] === null || body[field] === '');
    if (missing.length) return next(new AppError(400, 'Payload not valid', { missing }));
    return next();
  };
}

function asyncHandler(handler) {
  return (req, res, next) => Promise.resolve(handler(req, res, next)).catch(next);
}

function notFound(_req, _res, next) {
  next(new AppError(404, 'Endpoint not found'));
}

function errorHandler(err, _req, res, _next) {
  const status = err instanceof AppError ? err.status : (err.status || 500);
  if (status >= 500) console.error('UNHANDLED_ERROR', err);
  return res.status(status).json({
    success: false,
    message: err.message || 'Internal server error',
    ...(err.details ? { details: err.details } : {}),
  });
}

function corsOrigins() {
  const raw = process.env.CORS_ORIGIN || 'http://localhost:5173';
  return raw.split(',').map((item) => item.trim()).filter(Boolean);
}

module.exports = { AppError, serviceKey, validateBody, asyncHandler, notFound, errorHandler, corsOrigins };
