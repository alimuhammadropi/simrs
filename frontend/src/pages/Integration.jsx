import React, { useEffect, useState } from 'react';
import api from '../api';
import { Alert, Badge, Button, Card, Table, dateTime } from '../components/Ui';

const servicesBase = (import.meta.env.VITE_SERVICES_URL || '/services/api/v1').replace(/\/$/, '');
const servicesRoot = servicesBase.replace(/\/api\/v1$/, '');

const endpoints = [
  { method: 'GET', url: `${servicesBase}/lab/requests`, purpose: 'Daftar permintaan penunjang dari Yii; query opsional status', source: 'services/src/app.js:37' },
  { method: 'POST', url: `${servicesBase}/lab/results`, purpose: 'Teruskan hasil pemeriksaan ke Yii', source: 'services/src/app.js:42' },
  { method: 'POST', url: '/api/internal/lab/results', purpose: 'Penerima internal Yii; dilindungi x-service-key', source: 'backend/controllers/api/InternalController.php:26' },
  { method: 'POST', url: '/api/lab/requests/<id>/result', purpose: 'Input hasil langsung dari petugas lab', source: 'backend/controllers/api/LabController.php:93' },
];

const payload = `POST /services/api/v1/lab/results
{
  "lab_request_id": 1,
  "result": "Hb 13 g/dL",
  "reference_range": "12-16",
  "created_by": 4
}`;

async function jsonOrThrow(response) {
  if (!response.ok) throw new Error(`HTTP ${response.status}`);
  return response.json();
}

export default function Integration() {
  const [health, setHealth] = useState({ node: null, yii: null });
  const [requests, setRequests] = useState([]);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  async function load() {
    setLoading(true);
    setError('');
    const next = { node: null, yii: null };
    try {
      const data = await jsonOrThrow(await fetch(`${servicesRoot}/health`));
      next.node = data.status === 'healthy' ? 'Aktif' : data.status;
    } catch (err) {
      next.node = `Gagal: ${err.message}`;
    }
    try {
      await api.get('/auth/me');
      next.yii = 'Aktif';
    } catch (err) {
      next.yii = `Gagal: ${err.message}`;
    }
    setHealth(next);
    try {
      const data = await jsonOrThrow(await fetch(`${servicesBase}/lab/requests`));
      setRequests(data.data || []);
    } catch (err) {
      setRequests([]);
      setError(`Node tidak dapat dihubungi: ${err.message}`);
    }
    setLoading(false);
  }

  useEffect(() => { load(); }, []);

  return (
    <div className="space-y-5">
      <Card
        title="Integrasi data pemeriksaan penunjang"
        subtitle="Pemantauan layanan API Node/Express dan penerima internal Yii"
        actions={<Button variant="secondary" onClick={load} disabled={loading}>Perbarui status</Button>}
      >
        <p className="text-sm text-slate-600">
          Node hanya meneruskan dan mengagregasi data. Yii tetap pemilik transaksi klinis,
          menyimpan hasil lab, menulis audit, dan menyusun ulang tagihan.
        </p>
      </Card>

      <Card title="Status layanan">
        <Table rows={[
          { service: 'Node/Express :3010', status: health.node, probe: '/services/health' },
          { service: 'Yii API :8086', status: health.yii, probe: '/api/auth/me' },
        ]} columns={[
          { key: 'service', title: 'Layanan' },
          { key: 'probe', title: 'Probe' },
          { key: 'status', title: 'Status', render: (row) => <Badge tone={row.status === 'Aktif' ? 'green' : 'red'}>{row.status || 'Memeriksa...'}</Badge> },
        ]} />
      </Card>

      <Alert tone="red">{error}</Alert>

      <Card title="Endpoint integrasi">
        <Table rows={endpoints} columns={[
          { key: 'method', title: 'Metode', render: (row) => <Badge tone="blue">{row.method}</Badge> },
          { key: 'url', title: 'Endpoint' },
          { key: 'purpose', title: 'Fungsi' },
          { key: 'source', title: 'Sumber' },
        ]} />
      </Card>

      <Card title="Contoh payload hasil lab">
        <pre className="overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100">{payload}</pre>
      </Card>

      <Card title="Permintaan lab terbaru via Node">
        <Table
          rows={requests.slice(0, 20)}
          empty={loading ? 'Memuat data...' : 'Belum ada permintaan lab.'}
          columns={[
            { key: 'id', title: 'ID' },
            { key: 'registration_number', title: 'No. registrasi' },
            { key: 'test_name', title: 'Pemeriksaan' },
            { key: 'status', title: 'Status', render: (row) => <Badge>{row.status}</Badge> },
            { key: 'created_at', title: 'Waktu', render: (row) => dateTime(row.created_at) },
          ]}
        />
      </Card>
    </div>
  );
}
