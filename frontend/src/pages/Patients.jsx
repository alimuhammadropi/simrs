import React, { useState } from 'react';
import api from '../api';
import { useFetch } from '../hooks';
import { can } from '../auth';
import { Alert, Badge, Button, Card, Field, Modal, Table, dateTime } from '../components/Ui';

const emptyForm = { name: '', nik: '', gender: 'L', birth_date: '', phone: '', address: '' };

export default function Patients({ user }) {
  const [query, setQuery] = useState('');
  const [form, setForm] = useState(emptyForm);
  const [editing, setEditing] = useState(null);
  const [showForm, setShowForm] = useState(false);
  const [visits, setVisits] = useState(null);
  const [medications, setMedications] = useState(null);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const list = useFetch(() => api.get('/patients', { params: { q: query, all: true } }).then((r) => r.data.data), [query]);

  async function save(event) {
    event.preventDefault();
    setError('');
    try {
      if (editing) await api.put(`/patients/${editing}`, form);
      else await api.post('/patients', form);
      setMessage(editing ? 'Data pasien diperbarui' : 'Pasien berhasil ditambahkan');
      setForm(emptyForm);
      setEditing(null);
      setShowForm(false);
      list.reload();
    } catch (err) {
      setError(err.message);
    }
  }

  async function remove(row) {
    if (!window.confirm(`Nonaktifkan pasien ${row.name}?`)) return;
    try {
      await api.delete(`/patients/${row.id}`);
      setMessage('Pasien dinonaktifkan');
      list.reload();
    } catch (err) { setError(err.message); }
  }

  async function openVisits(row) {
    try {
      setVisits({ name: row.name, rows: (await api.get(`/patients/${row.id}/visits`)).data.data });
    } catch (err) { setError(err.message); }
  }

  async function openMedications(row) {
    try {
      setMedications({ name: row.name, rows: (await api.get(`/patients/${row.id}/medications`)).data.data });
    } catch (err) { setError(err.message); }
  }

  function edit(row) {
    setEditing(row.id);
    setForm({ name: row.name || '', nik: row.nik || '', gender: row.gender || 'L', birth_date: row.birth_date || '', phone: row.phone || '', address: row.address || '' });
    setShowForm(true);
  }

  function closeForm() {
    setShowForm(false); setEditing(null); setForm(emptyForm); setError('');
  }

  return (
    <div className="space-y-5">
      <Alert tone="green" onClose={() => setMessage('')}>{message}</Alert>
      <Alert tone="red" onClose={() => setError('')}>{error}</Alert>
      <Card title="Data pasien" subtitle="Pencarian berdasarkan nama, No. RM, atau NIK"
        actions={<div className="flex gap-2">
          <input className="w-56" placeholder="Cari pasien..." value={query} onChange={(event) => setQuery(event.target.value)} />
          {can(user, 'patient.create') && <Button onClick={() => { setForm(emptyForm); setEditing(null); setShowForm(true); }}>Tambah pasien</Button>}
        </div>}>
        <Table rows={list.data} columns={[
          { key: 'medical_record_number', title: 'No. RM' },
          { key: 'name', title: 'Nama' },
          { key: 'nik', title: 'NIK' },
          { key: 'gender', title: 'L/P' },
          { key: 'phone', title: 'Telepon' },
          { key: 'is_active', title: 'Status', render: (row) => <Badge>{row.is_active === false ? 'inactive' : 'active'}</Badge> },
          { key: 'actions', title: 'Aksi', render: (row) => (
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => openVisits(row)}>Kunjungan</Button>
              <Button variant="secondary" onClick={() => openMedications(row)}>Riwayat obat</Button>
              {can(user, 'patient.create') && <Button variant="secondary" onClick={() => edit(row)}>Ubah</Button>}
              {can(user, 'patient.create') && row.is_active !== false && <Button variant="danger" onClick={() => remove(row)}>Nonaktifkan</Button>}
            </div>
          ) },
        ]} />
        {list.loading && <p className="p-4 text-sm text-slate-500">Memuat...</p>}
        {list.error && <Alert tone="red">{list.error}</Alert>}
      </Card>

      {showForm && (
        <Modal title={editing ? 'Ubah pasien' : 'Tambah pasien'} onClose={closeForm}>
          <form onSubmit={save} className="grid gap-3 md:grid-cols-2">
            <Field label="Nama lengkap"><input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
            <Field label="NIK"><input inputMode="numeric" pattern="[0-9]{16}" maxLength={16} title="NIK harus 16 digit angka" value={form.nik} onChange={(e) => setForm({ ...form, nik: e.target.value.replace(/\D/g, '').slice(0, 16) })} /></Field>
            <Field label="Jenis kelamin"><select value={form.gender} onChange={(e) => setForm({ ...form, gender: e.target.value })}><option value="L">Laki-laki</option><option value="P">Perempuan</option></select></Field>
            <Field label="Tanggal lahir"><input type="date" value={form.birth_date} onChange={(e) => setForm({ ...form, birth_date: e.target.value })} /></Field>
            <Field label="Telepon"><input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></Field>
            <Field label="Alamat"><input value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} /></Field>
            <div className="md:col-span-2 flex justify-end gap-2"><Button variant="secondary" type="button" onClick={closeForm}>Batal</Button><Button>Simpan</Button></div>
          </form>
        </Modal>
      )}

      {visits && (
        <Modal title={`Riwayat kunjungan — ${visits.name}`} onClose={() => setVisits(null)}>
          <Table rows={visits.rows} columns={[
            { key: 'registration_number', title: 'No. registrasi' },
            { key: 'registration_date', title: 'Tanggal', render: (row) => dateTime(row.registration_date) },
            { key: 'polyclinic_name', title: 'Poli' },
            { key: 'doctor_name', title: 'Dokter' },
            { key: 'queue_number', title: 'Antrean' },
            { key: 'status', title: 'Status', render: (row) => <Badge>{row.status}</Badge> },
            { key: 'assessment', title: 'Assessment' },
          ]} />
        </Modal>
      )}

      {medications && (
        <Modal title={`Riwayat obat — ${medications.name}`} onClose={() => setMedications(null)}>
          <Table rows={medications.rows} empty="Belum ada riwayat obat pasien ini." columns={[
            { key: 'registration_number', title: 'No. registrasi' },
            { key: 'prescribed_at', title: 'Diresepkan', render: (row) => dateTime(row.prescribed_at) },
            { key: 'drug_name', title: 'Obat', render: (row) => `${row.drug_name} ${row.quantity}${row.unit || ''}` },
            { key: 'dose', title: 'Dosis' },
            { key: 'frequency', title: 'Frekuensi' },
            { key: 'doctor_name', title: 'Dokter' },
            { key: 'status', title: 'Status', render: (row) => <Badge>{row.status}</Badge> },
            { key: 'dispensed_at', title: 'Diserahkan', render: (row) => row.dispensed_at ? `${dateTime(row.dispensed_at)} · ${row.dispensed_by_name || '-'}` : '-' },
          ]} />
        </Modal>
      )}
    </div>
  );
}
