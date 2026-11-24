import React, { useState } from 'react';
import api from '../api';
import { useFetch } from '../hooks';
import { Alert, Button, Card, Field, Table, dateTime, money } from '../components/Ui';

export default function Reports() {
  const today = new Date().toISOString().slice(0, 10);
  const [from, setFrom] = useState(today.slice(0, 8) + '01');
  const [to, setTo] = useState(today);
  const summary = useFetch(() => api.get('/reports/dashboard', { params: { from, to } }).then((r) => r.data.data), [from, to]);
  const payments = useFetch(() => api.get('/reports/payments', { params: { from, to } }).then((r) => r.data.data), [from, to]);

  function downloadCsv() {
    const header = ['No. Bayar', 'Invoice', 'Pasien', 'Metode', 'Jumlah', 'Waktu'];
    const rows = (payments.data || []).map((row) => [row.payment_number, row.invoice_number, row.patient_name, row.method, row.amount, row.created_at]);
    const csv = [header, ...rows].map((row) => row.map((cell) => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(',')).join('\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `pembayaran-${from}-${to}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  }

  return (
    <div className="space-y-5">
      <Card title="Laporan keuangan & operasional" actions={<Button variant="secondary" onClick={downloadCsv}>Ekspor CSV</Button>}>
        <div className="grid gap-3 md:grid-cols-4">
          <Field label="Dari"><input type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
          <Field label="Sampai"><input type="date" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
          <div className="self-center text-sm text-slate-500">Ditagihkan: <b>{money(summary.data?.billed)}</b></div>
          <div className="self-center text-sm text-slate-500">Diterima: <b>{money(summary.data?.revenue)}</b></div>
        </div>
      </Card>
      <Alert tone="red">{summary.error || payments.error}</Alert>
      <Card title="Rincian pembayaran">
        <Table rows={payments.data} columns={[
          { key: 'payment_number', title: 'No. bayar' },
          { key: 'invoice_number', title: 'Invoice' },
          { key: 'patient_name', title: 'Pasien' },
          { key: 'method', title: 'Metode' },
          { key: 'amount', title: 'Jumlah', render: (row) => money(row.amount) },
          { key: 'created_at', title: 'Waktu', render: (row) => dateTime(row.created_at) },
        ]} />
      </Card>
    </div>
  );
}
