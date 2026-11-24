import React, { useState } from 'react';
import { Activity, ClipboardList, FlaskConical, Wallet } from 'lucide-react';
import api from '../api';
import servicesApi from '../servicesApi';
import { can } from '../auth';
import { useFetch } from '../hooks';
import { Alert, Badge, Card, Spinner, Stat, Table, dateTime, money } from '../components/Ui';

export default function Dashboard({ user }) {
  const today = new Date().toISOString().slice(0, 10);
  const first = today.slice(0, 8) + '01';
  const [from, setFrom] = useState(first);
  const [to, setTo] = useState(today);
  const canReport = can(user, 'report.view');
  const canViewRegistrations = can(user, 'registration.view');

  const report = useFetch(
    () => canReport ? api.get('/reports/dashboard', { params: { from, to } }).then((r) => r.data.data) : Promise.resolve(null),
    [from, to, canReport]
  );
  const node = useFetch(
    () => canReport ? servicesApi.get('/dashboard/summary', { params: { from, to } }).then((r) => r.data.data) : Promise.resolve(null),
    [from, to, canReport]
  );
  const recent = useFetch(
    () => canViewRegistrations ? api.get('/registrations', { params: { per_page: 8 } }).then((r) => r.data.data) : Promise.resolve([]),
    [canViewRegistrations]
  );
  const data = report.data || {};

  return (
    <div className="space-y-5">
      {canReport && (
        <>
          <Card title="Ringkasan operasional" subtitle="Data dihimpun dari database transaksional Yii dan layanan Node">
            <div className="grid gap-3 md:grid-cols-4">
              <input type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
              <input type="date" value={to} onChange={(event) => setTo(event.target.value)} />
              <span className="self-center text-sm text-slate-500">Periode {from} s/d {to}</span>
              <span className="self-center text-xs text-slate-400">
                Integrasi Node: {node.loading ? 'memuat...' : node.error ? 'tidak tersedia' : 'terhubung'}
              </span>
            </div>
          </Card>
          <Alert tone="red">{report.error}</Alert>
          {report.loading && <Spinner />}
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Stat label="Kunjungan" value={data.visits} hint="Pendaftaran aktif" icon={ClipboardList} />
            <Stat label="Tindakan" value={data.medical_actions} hint="Tindakan kunjungan" icon={Activity} />
            <Stat label="Pemeriksaan lab" value={data.lab_requests} hint="Permintaan penunjang" icon={FlaskConical} />
            <Stat label="Pendapatan" value={money(data.revenue)} hint={`Ditagihkan ${money(data.billed)}`} icon={Wallet} />
          </div>
          <div className="grid gap-5 xl:grid-cols-2">
            <Card title="Kunjungan per poliklinik / unit pelayanan">
              <Table rows={data.visits_by_polyclinic} columns={[
                { key: 'polyclinic_name', title: 'Poliklinik / Unit Pelayanan' },
                { key: 'total', title: 'Jumlah' },
              ]} />
            </Card>
            <Card title="Status tagihan">
              <Table rows={data.payment_status} columns={[
                { key: 'status', title: 'Status', render: (row) => <Badge>{row.status}</Badge> },
                { key: 'total', title: 'Jumlah invoice' },
                { key: 'amount', title: 'Nominal', render: (row) => money(row.amount) },
              ]} />
            </Card>
          </div>
          <Card title="Penggunaan obat" subtitle="Dari penyerahan resep pada periode terpilih">
            <Table rows={data.drug_usage} columns={[
              { key: 'drug_name', title: 'Obat' },
              { key: 'quantity', title: 'Jumlah' },
              { key: 'unit', title: 'Satuan' },
            ]} />
          </Card>
        </>
      )}
      {canViewRegistrations && (
        <Card title="Pendaftaran terbaru">
          <Table rows={recent.data} columns={[
            { key: 'registration_number', title: 'Nomor' },
            { key: 'patient_name', title: 'Pasien' },
            { key: 'polyclinic_name', title: 'Poli' },
            { key: 'queue_number', title: 'Antrean' },
            { key: 'status', title: 'Status', render: (row) => <Badge>{row.status}</Badge> },
            { key: 'created_at', title: 'Waktu', render: (row) => dateTime(row.created_at) },
          ]} />
        </Card>
      )}
      {!canReport && !canViewRegistrations && (
        <Card title="Ringkasan">
          <p className="text-sm text-slate-500">Anda tidak memiliki hak akses untuk melihat laporan atau pendaftaran.</p>
        </Card>
      )}
    </div>
  );
}
