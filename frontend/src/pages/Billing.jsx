import React, { useState } from 'react';
import api from '../api';
import { can } from '../auth';
import { useFetch } from '../hooks';
import { Alert, Badge, Button, Card, Field, Modal, Select, Table, dateTime, money } from '../components/Ui';

export default function Billing({ user }) {
  const [registrationId, setRegistrationId] = useState('');
  const [detailId, setDetailId] = useState(null);
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState('cash');
  const [referenceNo, setReferenceNo] = useState('');
  const [idempotencyKey, setIdempotencyKey] = useState(crypto.randomUUID());
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const registrations = useFetch(() => api.get('/registrations', { params: { per_page: 200 } }).then((r) => r.data.data.items || r.data.data), []);
  const invoices = useFetch(() => api.get('/invoices').then((r) => r.data.data), []);
  const detail = useFetch(
    () => detailId ? api.get(`/invoices/${detailId}`).then((r) => r.data.data) : Promise.resolve(null),
    [detailId]
  );

  async function createInvoice(event) {
    event.preventDefault();
    setError('');
    try {
      const response = await api.post('/invoices', { registration_id: registrationId });
      setMessage(`Tagihan ${response.data.data.invoice.invoice_number} tersedia`);
      setRegistrationId('');
      invoices.reload();
      setDetailId(response.data.data.invoice.id);
    } catch (err) { setError(err.message); }
  }

  function closeDetail() {
    setDetailId(null);
    setAmount(''); setMethod('cash'); setReferenceNo(''); setIdempotencyKey(crypto.randomUUID());
    setError('');
  }

  async function pay(event) {
    event.preventDefault();
    setError('');
    try {
      const response = await api.post(`/invoices/${detailId}/pay`, {
        amount: Number(amount), method, reference_no: referenceNo, idempotency_key: idempotencyKey,
      });
      setMessage(response.data.message);
      setAmount(''); setReferenceNo(''); setIdempotencyKey(crypto.randomUUID());
      detail.reload(); invoices.reload();
    } catch (err) { setError(err.message); }
  }

  function printInvoice(id) {
    const token = localStorage.getItem('simrs_token');
    window.open(`${api.defaults.baseURL}/invoices/${id}/print?token=${encodeURIComponent(token)}`, '_blank', 'noopener,noreferrer');
  }

  const data = detail.data;
  const invoice = data?.invoice;
  const remaining = invoice ? Number(invoice.total) - Number(invoice.paid_amount) : 0;
  const cash = Number(amount) || 0;
  const estimatedChange = cash > remaining ? Math.round((cash - remaining) * 100) / 100 : 0;

  return (
    <div className="space-y-5">
      <Alert tone="green" onClose={() => setMessage('')}>{message}</Alert>
      <Alert tone="red" onClose={() => setError('')}>{error}</Alert>

      {can(user, 'billing.manage') && (
        <Card title="Buat / buka tagihan" subtitle="Tagihan dihitung ulang otomatis dari pendaftaran, tindakan, resep, dan laboratorium">
          <form onSubmit={createInvoice} className="grid gap-3 md:grid-cols-3">
            <Select label="Pendaftaran" required value={registrationId} onChange={(e) => setRegistrationId(e.target.value)}
              options={(registrations.data || []).map((row) => ({ value: row.id, label: `${row.registration_number} — ${row.patient_name}` }))} />
            <div className="flex items-end"><Button>Buka tagihan</Button></div>
          </form>
        </Card>
      )}

      <Card title="Daftar tagihan">
        <Table rows={invoices.data} columns={[
          { key: 'invoice_number', title: 'No. invoice' },
          { key: 'registration_number', title: 'Registrasi' },
          { key: 'patient_name', title: 'Pasien' },
          { key: 'total', title: 'Total', render: (row) => money(row.total) },
          { key: 'paid_amount', title: 'Dibayar', render: (row) => money(row.paid_amount) },
          { key: 'status', title: 'Status', render: (row) => <Badge>{row.status}</Badge> },
          { key: 'actions', title: 'Aksi', render: (row) => (
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => setDetailId(row.id)}>Detail</Button>
              <Button variant="secondary" onClick={() => printInvoice(row.id)}>Cetak</Button>
            </div>
          ) },
        ]} />
      </Card>

      {detailId && (
        <Modal title={`Tagihan ${invoice?.invoice_number || ''}`} onClose={closeDetail}>
          {detail.loading && <p className="text-sm text-slate-500">Memuat...</p>}
          {data && (
            <div className="space-y-4">
              <div className="grid gap-2 text-sm md:grid-cols-2">
                <p><b>Pasien:</b> {data.patient?.name} ({data.patient?.medical_record_number})</p>
                <p><b>Status:</b> <Badge>{invoice.status}</Badge></p>
                <p><b>Total:</b> {money(invoice.total)}</p>
                <p><b>Jumlah dibayar:</b> {money(invoice.paid_amount)}</p>
                <p><b>Sisa:</b> {money(remaining)}</p>
              </div>
              <Table rows={data.items} columns={[
                { key: 'description', title: 'Rincian' },
                { key: 'quantity', title: 'Qty' },
                { key: 'unit_price', title: 'Harga', render: (row) => money(row.unit_price) },
                { key: 'amount', title: 'Jumlah', render: (row) => money(row.amount) },
              ]} />
              <h4 className="font-semibold">Pembayaran</h4>
              <Table rows={data.payments} columns={[
                { key: 'payment_number', title: 'No. bayar' },
                { key: 'method', title: 'Metode' },
                { key: 'tendered_amount', title: 'Uang diterima', render: (row) => money(row.tendered_amount ?? Number(row.amount) + Number(row.change_amount)) },
                { key: 'amount', title: 'Diterapkan', render: (row) => money(row.amount) },
                { key: 'change_amount', title: 'Kembalian', render: (row) => money(row.change_amount) },
                { key: 'created_at', title: 'Waktu', render: (row) => dateTime(row.created_at) },
              ]} />
              {can(user, 'billing.manage') && remaining > 0 && (
                <form onSubmit={pay} className="grid gap-3 rounded-lg border p-3 md:grid-cols-2">
                  <Field label="Uang diterima">
                    <input required type="number" min="0.01" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} />
                    {estimatedChange > 0 && <span className="mt-1 block text-xs text-slate-500">Kembalian: {money(estimatedChange)}</span>}
                  </Field>
                  <Field label="Metode">
                    <select value={method} onChange={(e) => setMethod(e.target.value)}>
                      <option value="cash">Tunai</option><option value="transfer">Transfer</option><option value="qris">QRIS</option>
                    </select>
                  </Field>
                  <Field label="No. referensi"><input value={referenceNo} onChange={(e) => setReferenceNo(e.target.value)} placeholder="Opsional" /></Field>
                  <Field label="Kunci idempotensi"><input readOnly value={idempotencyKey} /></Field>
                  <div className="md:col-span-2 flex justify-end"><Button>Catat pembayaran</Button></div>
                </form>
              )}
            </div>
          )}
        </Modal>
      )}
    </div>
  );
}
