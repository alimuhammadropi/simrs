const { AppError } = require('./middleware');

const BASE = () => (process.env.YII_API_URL || process.env.NODE_SERVICE_URL || 'http://127.0.0.1:8086/api').replace(/\/$/, '');

async function requestYii(method, path, { query = {}, body, timeout = 3000 } = {}) {
  const url = new URL(BASE() + '/' + path.replace(/^\//, ''));
  Object.entries(query).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, value);
  });
  let response;
  try {
    response = await fetch(url, {
      method,
      headers: {
        'Content-Type': 'application/json',
        'x-service-key': process.env.NODE_SERVICE_KEY || '',
      },
      body: body ? JSON.stringify(body) : undefined,
      signal: AbortSignal.timeout(timeout),
    });
  } catch (error) {
    throw new AppError(503, 'Layanan SIMRS tidak dapat diakses', { cause: error.message });
  }
  let payload;
  try {
    payload = await response.json();
  } catch {
    throw new AppError(502, 'Respons layanan SIMRS tidak valid');
  }
  if (!response.ok) throw new AppError(response.status, payload.message || 'Permintaan ke SIMRS gagal', payload.details);
  return payload;
}

module.exports = { requestYii };
