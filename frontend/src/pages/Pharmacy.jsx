import React, { useState } from 'react';
import api from '../api';
import { can } from '../auth';
import { useFetch } from '../hooks';
import { Alert, Badge, Button, Card, Field, Select, Table, money } from '../components/Ui';

export default function Pharmacy({ user }) {
  const [registrationId, setRegistrationId] = useState('');
  const [notes, setNotes] = useState('');
  const [items, setItems] = useState([{ drug_id: '', dose: '', frequency: '', quantity: 1 }]);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const registrations = useFetch(() => api.get('/registrations', { params: { per_page: 200 } }).then((r) => r.data.data.items || r.data.data), []);
  const drugs = useFetch(() => api.get('/drugs').then((r) => r.data.data), []);
  const prescriptions = useFetch(() => api.get('/prescriptions').then((r) => r.data.data), []);

  function changeItem(index, key, value) {
    setItems((old) => old.map((item, i) => (i === index ? { ...item, [key]: value } : item)));
  }

  async function submit(event) {
    event.preventDefault();
    setError('');
    try {
      await api.post('/prescriptions', { registration_id: registrationId, notes, items: items.map((item) => ({ ...item, quantity: Number(item.quantity) })) });
      setMessage('Resep dibuat');
      setRegistrationId('');
      setNotes('');
      setItems([{ drug_id: '', dose: '', frequency: '', quantity: 1 }]);
      prescriptions.reload();
    } catch (err) { setError(err.message); }
  }

  async function process(row, action) {
    setError('');
    try {
      await api.post(`/prescriptions/${row.id}/${action}`, {});
      setMessage(action === 'dispense' ? 'Obat diserahkan, stok diperbarui' : 'Resep dibatalkan');
      prescriptions.reload();
      drugs.reload();
    } catch (err) { setError(err.message); }
  }

  return (
    <div className="space-y-5">
      <Alert tone="green" onClose={() => setMessage('')}>{message}</Alert>
      <Alert tone="red" onClose={() => setError('')}>{error}</Alert>

      {can(user, 'prescription.create') && (
        <Card title="Buat resep" subtitle="Stok dicek saat resep dibuat dan dikurangi saat penyerahan">
          <form onSubmit={submit} className="space-y-3">
            <div className="grid gap-3 md:grid-cols-2">
              <Select label="Pendaftaran" required value={registrationId} onChange={(e) => setRegistrationId(e.target.value)}
                options={(registrations.data || []).map((row) => ({ value: row.id, label: `${row.registration_number} — ${row.patient_name}` }))} />
              <Field label="Catatan"><input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Catatan untuk farmasi" /></Field>
            </div>
            {items.map((item, index) => (
              <div key={index} className="grid gap-3 rounded-lg border p-3 md:grid-cols-[2fr_1fr_1fr_1fr_auto]">
                <Select label="Obat" required value={item.drug_id} onChange={(e) => changeItem(index, 'drug_id', e.target.value)}
                  options={(drugs.data || []).map((drug) => ({ value: drug.id, label: `${drug.name} — stok ${drug.stock} ${drug.unit} (${money(drug.price)})` }))} />
                <Field label="Dosis"><input required value={item.dose} onChange={(e) => changeItem(index, 'dose', e.target.value)} placeholder="1 tablet" /></Field>
                <Field label="Frekuensi"><input required value={item.frequency} onChange={(e) => changeItem(index, 'frequency', e.target.value)} placeholder="3x hari" /></Field>
                <Field label="Jumlah"><input required min="1" type="number" value={item.quantity} onChange={(e) => changeItem(index, 'quantity', e.target.value)} /></Field>
                <div className="flex items-end">
                  <Button type="button" variant="danger" onClick={() => setItems(items.filter((_, i) => i !== index))} disabled={items.length === 1}>Hapus</Button>
                </div>
              </div>
            ))}
            <div className="flex justify-between">
              <Button type="button" variant="secondary" onClick={() => setItems([...items, { drug_id: '', dose: '', frequency: '', quantity: 1 }])}>+ Item</Button>
              <Button disabled={!registrationId}>Simpan resep</Button>
            </div>
          </form>
        </Card>
      )}

      {can(user, 'pharmacy.view') && (
        <Card title="Antrian resep">
          <Table rows={prescriptions.data} columns={[
            { key: 'prescription_number', title: 'No. resep' },
            { key: 'patient_name', title: 'Pasien' },
            { key: 'doctor_name', title: 'Dokter' },
            { key: 'status', title: 'Status', render: (row) => <Badge>{row.status}</Badge> },
            { key: 'items', title: 'Obat', render: (row) => (row.items || []).map((item) => `${item.drug_name} ${item.quantity}${item.unit || ''} (${item.dose}, ${item.frequency})`).join(', ') },
            { key: 'actions', title: 'Aksi', render: (row) => can(user, 'pharmacy.manage') && row.status === 'pending' && (
              <div className="flex gap-2">
                <Button variant="success" onClick={() => process(row, 'dispense')}>Serahkan</Button>
                <Button variant="danger" onClick={() => process(row, 'cancel')}>Batalkan</Button>
              </div>
            ) },
          ]} />
        </Card>
      )}

      <Card title="Stok obat" subtitle="Stok minimum ditandai merah">
        <Table rows={drugs.data} columns={[
          { key: 'code', title: 'Kode' },
          { key: 'name', title: 'Obat' },
          { key: 'price', title: 'Harga', render: (row) => money(row.price) },
          { key: 'stock', title: 'Stok', render: (row) => <span className={row.min_stock != null && Number(row.stock) <= Number(row.min_stock) ? 'font-semibold text-red-600' : ''}>{row.stock} {row.unit}</span> },
        ]} />
      </Card>
    </div>
  );
}
