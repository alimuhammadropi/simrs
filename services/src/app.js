require('dotenv').config();
const express = require('express');
const cors = require('cors');
const helmet = require('helmet');
const { AppError, serviceKey, validateBody, asyncHandler, notFound, errorHandler, corsOrigins } = require('./middleware');
const { requestYii } = require('./yiiClient');

const app = express();
const origins = corsOrigins();
const corsOptions = {
  origin(origin, callback) {
    if (!origin || origins.includes('*') || origins.includes(origin)) return callback(null, true);
    return callback(new AppError(403, 'Origin tidak diizinkan'));
  },
  methods: ['GET', 'POST', 'OPTIONS'],
  allowedHeaders: ['Content-Type', 'Authorization', 'x-service-key'],
  maxAge: 600,
};

app.use(helmet());
app.use(cors(corsOptions));
app.use(express.json({ limit: '256kb' }));

const notifications = new Set();

app.get('/health', (_req, res) => res.json({ success: true, service: 'simrs-services', status: 'healthy' }));

app.post('/internal/notifications/queue', serviceKey, validateBody(['registration_number', 'queue_number']), (req, res) => {
  const { registration_number, queue_number, polyclinic_name, idempotency_key } = req.body;
  const key = idempotency_key || `${registration_number}:${queue_number}`;
  if (notifications.has(key)) return res.json({ success: true, message: 'Notification already accepted', replayed: true });
  notifications.add(key);
  console.log('QUEUE_NOTIFICATION', { registration_number, queue_number, polyclinic_name, at: new Date().toISOString() });
  return res.status(202).json({ success: true, message: 'Notification accepted', replayed: false });
});

app.get('/api/v1/lab/requests', asyncHandler(async (req, res) => {
  const data = await requestYii('GET', 'internal/lab/requests', { query: { status: req.query.status } });
  res.json(data);
}));

app.post('/api/v1/lab/results', validateBody(['lab_request_id', 'result', 'created_by']), asyncHandler(async (req, res) => {
  const { lab_request_id, result, reference_range, created_by } = req.body;
  const data = await requestYii('POST', 'internal/lab/results', {
    body: { lab_request_id, result, reference_range, created_by },
  });
  res.status(201).json(data);
}));

app.get('/api/v1/dashboard/summary', asyncHandler(async (req, res) => {
  const data = await requestYii('GET', 'internal/reports/dashboard', {
    query: { from: req.query.from, to: req.query.to },
  });
  res.json(data);
}));

app.use(notFound);
app.use(errorHandler);

const port = Number(process.env.PORT || 3010);
app.listen(port, () => console.log(`SIMRS services listening on :${port}`));
