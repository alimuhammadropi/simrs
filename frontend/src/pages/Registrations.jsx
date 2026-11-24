import React, { useState } from 'react';
import api from '../api';
import { can } from '../auth';
import { useFetch } from '../hooks';
import { Alert, Badge, Button, Card, Field, Select, Table, dateTime, dayName } from '../components/Ui';

const STATUS = ['registered', 'waiting', 'in_exam', 'completed', 'cancelled'];
const NEXT = { registered: ['waiting', 'cancelled'], waiting: ['in_exam', 'cancelled'], in_exam: ['cancelled'], completed: [], cancelled: [] };

export default function Registrations({ user }) {
  const today = new Date().toISOString().slice(0, 10);
  const [form, setForm] = useState({ patient_id: '', polyclinic_id: '', doctor_id: '', schedule_id: '', registration_date: today, idempotency_key: crypto.randomUUID() });
  const [filters, setFilters] = useState({ date: today, polyclinic_id: '', status: '' });
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const patients = useFetch(() => api.get('/patients', { params: { per_page: 200 } }).then((r) => r.data.data), []);
  const polies = useFetch(() => api.get('/polyclinics').then((r) => r.data.data), []);
  const doctors = useFetch(() => api.get('/doctors').then((r) => r.data.data), []);
  const schedules = useFetch(() => api.get('/schedules').then((r) => r.data.data), []);
  const list = useFetch(() => api.get('/registrations', { params: filters }).then((r) => r.data.data.items || r.data.data), [filters.date, filters.polyclinic_id, filters.status]);

  const scheduleOptions = (schedules.data || []).filter((schedule) => {
    if (form.doctor_id && String(schedule.doctor_id) !== String(form.doctor_id)) return false;
    if (form.polyclinic_id && String(schedule.polyclinic_id) !== String(form.polyclinic_id)) return false;
    if (form.registration_date) {
      const day = new Date(`${form.registration_date}T00:00:00`).getDay();
      if (Number(schedule.day_of_week) !== day) return false;
    }
    return schedule.is_active !== false;
  }).map((schedule) => ({
    value: schedule.id,
    label: `${schedule.polyclinic_name || schedule.polyclinic_id} / ${schedule.doctor_name || schedule.doctor_id} / ${dayName(schedule.day_of_week)} ${schedule.start_time}-${schedule.end_time}`,
  }));

  async function submit(event) {
    event.preventDefault();
    setError('');
    try {
      const response = await api.post('/registrations', form);
      const data = response.data.data;
      setMessage(data.replayed ? `Pendaftaran sudah ada. Antrean: ${data.queue_number}` : `Pendaftaran berhasil. Antrean: ${data.queue_number}`);
      setForm({ ...form, patient_id: '', schedule_id: '', idempotency_key: crypto.randomUUID() });
      list.reload();
    } catch (err) { setError(err.message); }
  }

  async function updateStatus(row, status) {
    setError('');
    try {
      await api.post(`/registrations/${row.id}/status`, { status });
      setMessage(`Status ${row.registration_number} diperbarui`);
      list.reload();
    } catch (err) { setError(err.message); }
  }

  return (
    <div className="space-y-5">
      <Alert tone="green" onClose={() => setMessage('')}>{message}</Alert>
      <Alert tone="red" onClose={() => setError('')}>{error}</Alert>

      {can(user, 'registration.create') && (
        <Card title="Pendaftaran kunjungan" subtitle="Pilih pasien, poli/unit pelayanan, dokter, dan tanggal kunjungan">
          <form onSubmit={submit} className="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
            <Select label="Pasien" required value={form.patient_id} onChange={(e) => setForm({ ...form, patient_id: e.target.value })}
              options={(patients.data || []).map((patient) => ({ value: patient.id, label: `${patient.medical_record_number} — ${patient.name}` }))} />
            <Select label="Poliklinik / Unit Pelayanan" required value={form.polyclinic_id} onChange={(e) => setForm({ ...form, polyclinic_id: e.target.value, schedule_id: '' })}
              options={(polies.data || []).map((poly) => ({ value: poly.id, label: poly.name }))} />
            <Select label="Dokter" required value={form.doctor_id} onChange={(e) => setForm({ ...form, doctor_id: e.target.value, schedule_id: '' })}
              options={(doctors.data || []).map((doctor) => ({ value: doctor.id, label: doctor.name }))} />
            <Field label="Tanggal"><input required type="date" value={form.registration_date} onChange={(e) => setForm({ ...form, registration_date: e.target.value, schedule_id: '' })} /></Field>
            <Select label="Jadwal (opsional)" value={form.schedule_id} onChange={(e) => setForm({ ...form, schedule_id: e.target.value })}
              options={scheduleOptions} placeholder="Sesuai jadwal dokter" />
            <div className="flex items-end"><Button className="w-full">Daftarkan</Button></div>
          </form>
        </Card>
      )}

      <Card title="Antrean / pendaftaran" actions={<Button variant="secondary" onClick={list.reload}>Muat ulang</Button>}>
        <div className="mb-4 grid gap-3 md:grid-cols-4">
          <Field label="Tanggal"><input type="date" value={filters.date} onChange={(e) => setFilters({ ...filters, date: e.target.value })} /></Field>
          <Select label="Poli" value={filters.polyclinic_id} onChange={(e) => setFilters({ ...filters, polyclinic_id: e.target.value })} options={(polies.data || []).map((poly) => ({ value: poly.id, label: poly.name }))} placeholder="Semua poli" />
          <Select label="Status" value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })} options={STATUS.map((status) => ({ value: status, label: status }))} placeholder="Semua status" />
        </div>
        <Table rows={list.data} columns={[
          { key: 'registration_number', title: 'Nomor' },
          { key: 'patient_name', title: 'Pasien' },
          { key: 'polyclinic_name', title: 'Poli' },
          { key: 'doctor_name', title: 'Dokter' },
          { key: 'queue_number', title: 'Antrean' },
          { key: 'status', title: 'Status', render: (row) => <Badge>{row.status}</Badge> },
          { key: 'created_at', title: 'Waktu', render: (row) => dateTime(row.created_at) },
          { key: 'actions', title: 'Aksi', render: (row) => can(user, 'registration.create') && (
            <div className="flex flex-wrap gap-1">
              {(NEXT[row.status] || [])
                .filter((status) => status !== 'in_exam' || can(user, 'examination.create'))
                .map((status) => <Button key={status} variant="secondary" onClick={() => updateStatus(row, status)}>{status}</Button>)}
            </div>
          ) },
        ]} />
      </Card>
    </div>
  );
}
