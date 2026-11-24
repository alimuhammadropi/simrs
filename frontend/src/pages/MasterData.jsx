import React, { useEffect, useState } from 'react';
import api from '../api';
import { useFetch } from '../hooks';
import { Alert, Button, Card, Field, Modal, Table, dayName, money } from '../components/Ui';

const resources = [
  {
    key: 'polyclinics', label: 'Poliklinik / Unit Pelayanan', endpoint: '/polyclinics',
    fields: [
      { key: 'code', label: 'Kode' }, { key: 'name', label: 'Nama' }, { key: 'description', label: 'Deskripsi' },
      { key: 'is_active', label: 'Aktif', type: 'checkbox' },
    ],
  },
  {
    key: 'doctors', label: 'Dokter', endpoint: '/doctors',
    fields: [
      { key: 'name', label: 'Nama' }, { key: 'sip_number', label: 'No. SIP' }, { key: 'specialty', label: 'Spesialisasi' },
      { key: 'is_active', label: 'Aktif', type: 'checkbox' },
    ],
  },
  {
    key: 'schedules', label: 'Jadwal praktik', endpoint: '/schedules',
    fields: [
      { key: 'doctor_id', label: 'Dokter', type: 'select', optionsFrom: 'doctors' },
      { key: 'polyclinic_id', label: 'Poliklinik / Unit Pelayanan', type: 'select', optionsFrom: 'polyclinics' },
      { key: 'day_of_week', label: 'Hari', type: 'select', options: [0, 1, 2, 3, 4, 5, 6].map((value) => ({ value, label: dayName(value) })) },
      { key: 'start_time', label: 'Mulai (HH:MM)' }, { key: 'end_time', label: 'Selesai (HH:MM)' },
      { key: 'is_active', label: 'Aktif', type: 'checkbox' },
    ],
  },
  {
    key: 'medical-actions', label: 'Tindakan & tarif', endpoint: '/medical-actions',
    fields: [
      { key: 'code', label: 'Kode' }, { key: 'name', label: 'Nama' }, { key: 'price', label: 'Tarif', type: 'number' },
      { key: 'is_active', label: 'Aktif', type: 'checkbox' },
    ],
  },
  {
    key: 'drugs', label: 'Obat & stok', endpoint: '/drugs',
    fields: [
      { key: 'code', label: 'Kode' }, { key: 'name', label: 'Nama' }, { key: 'unit', label: 'Satuan' },
      { key: 'price', label: 'Harga', type: 'number' }, { key: 'stock', label: 'Stok', type: 'number' },
      { key: 'min_stock', label: 'Stok minimum', type: 'number' }, { key: 'is_active', label: 'Aktif', type: 'checkbox' },
    ],
  },
  {
    key: 'diagnoses', label: 'Diagnosis', endpoint: '/diagnoses',
    fields: [{ key: 'code', label: 'Kode' }, { key: 'name', label: 'Nama' }, { key: 'is_active', label: 'Aktif', type: 'checkbox' }],
  },
  {
    key: 'lab-tests', label: 'Jenis laboratorium', endpoint: '/lab-tests',
    fields: [{ key: 'code', label: 'Kode' }, { key: 'name', label: 'Nama' }, { key: 'price', label: 'Tarif', type: 'number' }, { key: 'is_active', label: 'Aktif', type: 'checkbox' }],
  },
  {
    key: 'medical-staff', label: 'Tenaga medis', endpoint: '/medical-staff',
    fields: [{ key: 'employee_number', label: 'No. pegawai' }, { key: 'name', label: 'Nama' }, { key: 'role', label: 'Peran' }, { key: 'is_active', label: 'Aktif', type: 'checkbox' }],
  },
];

function ResourceTable({ resource, lookups }) {
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(null);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState('');
  const list = useFetch(() => api.get(resource.endpoint, { params: { all: true, q: query } }).then((r) => r.data.data), [query]);

  useEffect(() => {
    const timer = setTimeout(() => setQuery(search.trim()), 250);
    return () => clearTimeout(timer);
  }, [search]);

  function displayValue(row, field) {
    if (field.key === 'price') return money(row.price);
    if (field.type === 'checkbox') return row.is_active === false ? 'Nonaktif' : 'Aktif';
    if (field.type === 'select') {
      const source = field.options || lookups[field.optionsFrom] || [];
      const found = source.find((item) => String(item.value ?? item.id) === String(row[field.key]));
      return (found?.label || found?.name || row[field.key]) ?? '-';
    }
    return row[field.key] ?? '-';
  }

  const rows = (list.data || []).filter((row) => {
    if (status === '1' && row.is_active === false) return false;
    if (status === '0' && row.is_active !== false) return false;
    if (!query) return true;
    return resource.fields.map((field) => displayValue(row, field)).join(' ').toLowerCase().includes(query.toLowerCase());
  });

  function blank() {
    const row = {};
    resource.fields.forEach((field) => { row[field.key] = field.type === 'checkbox' ? true : ''; });
    return row;
  }

  function open(row) {
    if (row) {
      const copy = {};
      resource.fields.forEach((field) => { copy[field.key] = row[field.key] ?? (field.type === 'checkbox' ? true : ''); });
      setForm(copy); setEditing(row.id);
    } else { setForm(blank()); setEditing('new'); }
  }

  function close() {
    setEditing(null); setForm(null); setError('');
  }

  async function save(event) {
    event.preventDefault();
    setError('');
    try {
      const payload = { ...form };
      resource.fields.filter((field) => field.type === 'number').forEach((field) => { payload[field.key] = Number(payload[field.key]); });
      if (editing === 'new') await api.post(resource.endpoint, payload);
      else await api.put(`${resource.endpoint}/${editing}`, payload);
      setMessage('Data master disimpan');
      setEditing(null); setForm(null); list.reload();
    } catch (err) { setError(err.message); }
  }

  async function remove(row) {
    if (!window.confirm(`Hapus ${row.name || row.code || 'data'}?`)) return;
    try { await api.delete(`${resource.endpoint}/${row.id}`); setMessage('Data dinonaktifkan'); list.reload(); }
    catch (err) { setError(err.message); }
  }

  function renderCell(row, field) {
    return displayValue(row, field);
  }

  return (
    <div>
      <Alert tone="green" onClose={() => setMessage('')}>{message}</Alert>
      <Alert tone="red" onClose={() => setError('')}>{error}</Alert>
      <div className="mb-3 flex flex-wrap items-end gap-3">
        <div className="min-w-64 flex-1">
          <Field label="Cari data">
            <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={`Cari ${resource.label.toLowerCase()}...`} />
          </Field>
        </div>
        <div className="w-40">
          <Field label="Status">
            <select value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="">Semua status</option>
              <option value="1">Aktif</option>
              <option value="0">Nonaktif</option>
            </select>
          </Field>
        </div>
        <Button onClick={() => open(null)}>Tambah</Button>
      </div>
      <Table rows={rows} empty={query || status ? 'Tidak ada data yang cocok.' : 'Belum ada data.'} columns={[
        ...resource.fields.map((field) => ({ key: field.key, title: field.label, render: (row) => renderCell(row, field) })),
        { key: 'actions', title: 'Aksi', render: (row) => (
          <div className="flex gap-2"><Button variant="secondary" onClick={() => open(row)}>Ubah</Button><Button variant="danger" onClick={() => remove(row)}>Hapus</Button></div>
        ) },
      ]} />
      {form && (
        <Modal title={editing === 'new' ? `Tambah ${resource.label}` : `Ubah ${resource.label}`} onClose={close}>
          <form onSubmit={save} className="grid gap-3 md:grid-cols-2">
            {resource.fields.map((field) => (
              <Field key={field.key} label={field.label}>
                {field.type === 'checkbox' ? (
                  <input type="checkbox" className="h-4 w-4" checked={Boolean(form[field.key])} onChange={(e) => setForm({ ...form, [field.key]: e.target.checked })} />
                ) : field.type === 'select' ? (
                  <select value={form[field.key] ?? ''} onChange={(e) => setForm({ ...form, [field.key]: e.target.value })}>
                    <option value="">Pilih...</option>
                    {(field.options || lookups[field.optionsFrom] || []).map((item) => {
                      const value = item.value ?? item.id;
                      return <option key={value} value={value}>{item.label || item.name}</option>;
                    })}
                  </select>
                ) : (
                  <input type={field.type === 'number' ? 'number' : 'text'} value={form[field.key] ?? ''} onChange={(e) => setForm({ ...form, [field.key]: e.target.value })} />
                )}
              </Field>
            ))}
            <div className="md:col-span-2 flex justify-end gap-2"><Button variant="secondary" type="button" onClick={close}>Batal</Button><Button>Simpan</Button></div>
          </form>
        </Modal>
      )}
    </div>
  );
}

export default function MasterData() {
  const [tab, setTab] = useState(resources[0].key);
  const polyclinics = useFetch(() => api.get('/polyclinics', { params: { all: true } }).then((r) => r.data.data), []);
  const doctors = useFetch(() => api.get('/doctors', { params: { all: true } }).then((r) => r.data.data), []);
  const lookups = { polyclinics: polyclinics.data || [], doctors: doctors.data || [] };
  const current = resources.find((resource) => resource.key === tab);

  return (
    <Card title="Data master" subtitle="Kelola data referensi, jadwal, tarif, obat, dan stok">
      <div className="mb-5 flex flex-wrap gap-2">
        {resources.map((resource) => (
          <button key={resource.key} onClick={() => setTab(resource.key)}
            className={`rounded-lg px-3 py-2 text-sm ${tab === resource.key ? 'bg-blue-700 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>
            {resource.label}
          </button>
        ))}
      </div>
      <ResourceTable key={current.key} resource={current} lookups={lookups} />
    </Card>
  );
}
