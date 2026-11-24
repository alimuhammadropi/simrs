import React, { useState } from 'react';
import api from '../api';
import { can } from '../auth';
import { useFetch } from '../hooks';
import { Alert, Button, Card, Field, Select, Table, money } from '../components/Ui';

export default function Actions({ user }) {
  const [registrationId, setRegistrationId] = useState('');
  const [medicalActionId, setMedicalActionId] = useState('');
  const [quantity, setQuantity] = useState(1);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const registrations = useFetch(() => api.get('/registrations', { params: { per_page: 200 } }).then((r) => r.data.data.items || r.data.data), []);
  const actionsMaster = useFetch(() => api.get('/medical-actions').then((r) => r.data.data), []);
  const rows = useFetch(
    () => registrationId ? api.get(`/registrations/${registrationId}/actions`).then((r) => r.data.data) : Promise.resolve([]),
    [registrationId]
  );

  async function add(event) {
    event.preventDefault();
    setError('');
    try {
      await api.post(`/registrations/${registrationId}/actions`, { medical_action_id: medicalActionId, quantity: Number(quantity) });
      setMessage('Tindakan ditambahkan');
      rows.reload();
    } catch (err) { setError(err.message); }
  }

  async function remove(row) {
    if (!window.confirm('Hapus tindakan ini?')) return;
    try { await api.delete(`/visit-actions/${row.id}`); rows.reload(); setMessage('Tindakan dihapus'); }
    catch (err) { setError(err.message); }
  }

  return (
    <div className="space-y-5">
      <Alert tone="green" onClose={() => setMessage('')}>{message}</Alert>
      <Alert tone="red" onClose={() => setError('')}>{error}</Alert>
      <Card title="Tindakan kunjungan" subtitle="Tarif disimpan sebagai snapshot pada saat tindakan dicatat">
        {can(user, 'examination.create') && (
          <form onSubmit={add} className="grid gap-3 md:grid-cols-4">
            <Select label="Pendaftaran" required value={registrationId} onChange={(e) => setRegistrationId(e.target.value)}
              options={(registrations.data || []).map((row) => ({ value: row.id, label: `${row.registration_number} — ${row.patient_name}` }))} />
            <Select label="Tindakan" required value={medicalActionId} onChange={(e) => setMedicalActionId(e.target.value)}
              options={(actionsMaster.data || []).map((row) => ({ value: row.id, label: `${row.code} — ${row.name} (${money(row.price)})` }))} />
            <Field label="Quantity"><input required min="1" type="number" value={quantity} onChange={(e) => setQuantity(e.target.value)} /></Field>
            <div className="flex items-end"><Button disabled={!registrationId}>Tambah tindakan</Button></div>
          </form>
        )}
      </Card>
      <Card title="Daftar tindakan">
        <Table rows={rows.data} columns={[
          { key: 'action_code', title: 'Kode' },
          { key: 'action_name', title: 'Tindakan' },
          { key: 'quantity', title: 'Qty' },
          { key: 'price_snapshot', title: 'Harga', render: (row) => money(row.price_snapshot) },
          { key: 'amount', title: 'Jumlah', render: (row) => money(row.price_snapshot * row.quantity) },
          { key: 'actions', title: 'Aksi', render: (row) => can(user, 'examination.create') && <Button variant="danger" onClick={() => remove(row)}>Hapus</Button> },
        ]} />
      </Card>
    </div>
  );
}
