import React, { useState } from 'react';
import api from '../api';
import servicesApi from '../servicesApi';
import { can } from '../auth';
import { useFetch } from '../hooks';
import { Alert, Badge, Button, Card, Field, Modal, Select, Table } from '../components/Ui';

export default function Laboratory({ user }) {
  const [registrationId, setRegistrationId] = useState('');
  const [labTestId, setLabTestId] = useState('');
  const [notes, setNotes] = useState('');
  const [resultRow, setResultRow] = useState(null);
  const [result, setResult] = useState('');
  const [referenceRange, setReferenceRange] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const registrations = useFetch(() => api.get('/registrations', { params: { per_page: 200 } }).then((r) => r.data.data.items || r.data.data), []);
  const tests = useFetch(() => api.get('/lab-tests').then((r) => r.data.data), []);
  const requests = useFetch(() => api.get('/lab/requests').then((r) => r.data.data), []);
  const nodeRequests = useFetch(() => servicesApi.get('/lab/requests').then((r) => r.data.data), []);

  async function requestLab(event) {
    event.preventDefault();
    setError('');
    try {
      await api.post('/lab/requests', { registration_id: registrationId, lab_test_id: labTestId, notes });
      setMessage('Permintaan pemeriksaan dibuat');
      setRegistrationId(''); setLabTestId(''); setNotes('');
      requests.reload(); nodeRequests.reload();
    } catch (err) { setError(err.message); }
  }

  async function start(row) {
    try { await api.post(`/lab/requests/${row.id}/start`, {}); requests.reload(); nodeRequests.reload(); }
    catch (err) { setError(err.message); }
  }

  function openResult(row) {
    setResultRow(row);
    setResult(row.result || '');
    setReferenceRange(row.reference_range || '');
  }

  function closeResult() {
    setResultRow(null); setResult(''); setReferenceRange(''); setError('');
  }

  async function saveResult(useNode) {
    setError('');
    try {
      if (useNode) {
        await servicesApi.post('/lab/results', { lab_request_id: resultRow.id, result, reference_range: referenceRange, created_by: user.id });
      } else {
        await api.post(`/lab/requests/${resultRow.id}/result`, { result, reference_range: referenceRange });
      }
      setMessage(useNode ? 'Hasil terkirim melalui layanan Node' : 'Hasil pemeriksaan tersimpan');
      setResultRow(null);
      requests.reload(); nodeRequests.reload();
    } catch (err) { setError(err.message); }
  }

  return (
    <div className="space-y-5">
      <Alert tone="green" onClose={() => setMessage('')}>{message}</Alert>
      <Alert tone="red" onClose={() => setError('')}>{error}</Alert>

      {can(user, 'lab.request') && (
        <Card title="Permintaan pemeriksaan penunjang">
          <form onSubmit={requestLab} className="grid gap-3 md:grid-cols-4">
            <Select label="Pendaftaran" required value={registrationId} onChange={(e) => setRegistrationId(e.target.value)}
              options={(registrations.data || []).map((row) => ({ value: row.id, label: `${row.registration_number} — ${row.patient_name}` }))} />
            <Select label="Jenis pemeriksaan" required value={labTestId} onChange={(e) => setLabTestId(e.target.value)}
              options={(tests.data || []).map((test) => ({ value: test.id, label: `${test.code} — ${test.name}` }))} />
            <Field label="Catatan"><input value={notes} onChange={(e) => setNotes(e.target.value)} /></Field>
            <div className="flex items-end"><Button>Buat permintaan</Button></div>
          </form>
        </Card>
      )}

      <Card title="Antrean laboratorium" subtitle={`Terhubung Node: ${nodeRequests.error ? 'tidak tersedia' : 'ya'}`}>
        <Table rows={requests.data} columns={[
          { key: 'test_code', title: 'Kode' },
          { key: 'test_name', title: 'Pemeriksaan' },
          { key: 'patient_name', title: 'Pasien' },
          { key: 'doctor_name', title: 'Dokter' },
          { key: 'status', title: 'Status', render: (row) => <Badge>{row.status}</Badge> },
          { key: 'result', title: 'Hasil' },
          { key: 'actions', title: 'Aksi', render: (row) => can(user, 'lab.manage') && !['completed', 'cancelled'].includes(row.status) && (
            <div className="flex gap-2">
              {row.status === 'requested' && <Button variant="secondary" onClick={() => start(row)}>Mulai</Button>}
              <Button onClick={() => openResult(row)}>Input hasil</Button>
            </div>
          ) },
        ]} />
      </Card>

      {resultRow && (
        <Modal title={`Hasil — ${resultRow.test_name}`} onClose={closeResult}>
          <div className="space-y-3">
            <Field label="Hasil"><textarea rows="5" required value={result} onChange={(e) => setResult(e.target.value)} /></Field>
            <Field label="Nilai rujukan"><input value={referenceRange} onChange={(e) => setReferenceRange(e.target.value)} /></Field>
            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={closeResult}>Batal</Button>
              <Button onClick={() => saveResult(false)}>Simpan via Yii</Button>
              <Button variant="success" onClick={() => saveResult(true)}>Kirim via Node</Button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
