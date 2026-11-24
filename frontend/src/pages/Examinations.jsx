import React, { useEffect, useState } from 'react';
import api from '../api';
import { can } from '../auth';
import { useFetch } from '../hooks';
import { Alert, Badge, Button, Card, Field, Modal, Select, Table, dateTime, money } from '../components/Ui';

const emptyForm = {
  registration_id: '', subjective: '', objective: '', physical_exam: '', assessment: '', plan: '',
  systolic: '', diastolic: '', pulse: '', temperature: '', respiratory: '', weight: '', height: '',
  primary_diagnosis_id: '', secondary_diagnoses: [],
};

function vitalText(value) {
  if (!value) return '-';
  let vital = value;
  if (typeof vital === 'string') {
    try { vital = JSON.parse(vital); } catch { return String(value); }
  }
  const labels = {
    systolic: 'Sistolik', diastolic: 'Diastolik', pulse: 'Nadi', temperature: 'Suhu',
    respiratory: 'Napas', weight: 'Berat (kg)', height: 'Tinggi (cm)',
  };
  const parts = Object.entries(labels)
    .filter(([key]) => vital[key] !== undefined && vital[key] !== '')
    .map(([key, label]) => `${label}: ${vital[key]}`);
  return parts.join(', ') || '-';
}

function availableStock(drug) {
  if (!drug) return 0;
  const value = drug.available_stock ?? drug.stock ?? 0;
  return Math.max(0, Number(value));
}

export default function Examinations({ user }) {
  const today = new Date().toISOString().slice(0, 10);
  const [form, setForm] = useState(emptyForm);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [detail, setDetail] = useState(null);
  const [prescription, setPrescription] = useState(null);
  const [prescriptionFromDetail, setPrescriptionFromDetail] = useState(false);
  const [rxItems, setRxItems] = useState([{ drug_id: '', dose: '', frequency: '', quantity: 1 }]);
  const [rxNotes, setRxNotes] = useState('');
  const [rxInfo, setRxInfo] = useState('');
  const [rxBase, setRxBase] = useState({});
  const [rxErrors, setRxErrors] = useState({});
  const waiting = useFetch(() => api.get('/examinations/waiting', { params: { date: today } }).then((r) => r.data.data), [today]);
  const exams = useFetch(() => api.get('/examinations').then((r) => r.data.data), []);
  const diagnoses = useFetch(() => api.get('/diagnoses').then((r) => r.data.data), []);
  const drugs = useFetch(() => api.get('/drugs').then((r) => r.data.data), []);
  const registrations = useFetch(() => api.get('/registrations', { params: { status: 'waiting', per_page: 200 } }).then((r) => r.data.data.items || r.data.data), []);

  useEffect(() => {
    if (!prescription) return undefined;
    drugs.reload();
    const timer = setInterval(() => drugs.reload(), 10000);
    return () => clearInterval(timer);
  }, [prescription]);

  function maxForDrug(drug, index = null, items = rxItems) {
    if (!drug) return 0;
    const own = prescription?.id ? Number(rxBase[drug.id] ?? 0) : 0;
    const capacity = availableStock(drug) + own;
    if (index === null) return capacity;
    const usedByOthers = items.reduce((sum, item, i) => (
      i !== index && String(item.drug_id) === String(drug.id) ? sum + Number(item.quantity || 0) : sum
    ), 0);
    return Math.max(0, capacity - usedByOthers);
  }

  function update(key, value) { setForm((old) => ({ ...old, [key]: value })); }

  async function submit(event) {
    event.preventDefault();
    setError('');
    const payload = {
      registration_id: form.registration_id,
      subjective: form.subjective,
      objective: form.objective,
      physical_exam: form.physical_exam,
      assessment: form.assessment,
      plan: form.plan,
      vital_signs: {
        systolic: form.systolic, diastolic: form.diastolic, pulse: form.pulse,
        temperature: form.temperature, respiratory: form.respiratory, weight: form.weight, height: form.height,
      },
      diagnoses: [
        ...(form.primary_diagnosis_id ? [{ diagnosis_id: form.primary_diagnosis_id, is_primary: true }] : []),
        ...form.secondary_diagnoses.filter((id) => id !== form.primary_diagnosis_id).map((id) => ({ diagnosis_id: id, is_primary: false })),
      ],
    };
    try {
      await api.post('/examinations', payload);
      setMessage('Pemeriksaan tersimpan sebagai draft');
      setForm(emptyForm);
      waiting.reload();
      exams.reload();
      registrations.reload();
    } catch (err) { setError(err.message); }
  }

  async function finalize(row) {
    try {
      await api.post(`/examinations/${row.id}/finalize`, {});
      setMessage(`Pemeriksaan ${row.registration_number} difinalisasi`);
      exams.reload();
      waiting.reload();
    } catch (err) { setError(err.message); }
  }

  async function openDetail(row) {
    try { setDetail({ ...row, ...(await api.get(`/examinations/${row.id}`)).data.data }); }
    catch (err) { setError(err.message); }
  }

  async function openPrescription(row, fromDetail = false) {
    setPrescriptionFromDetail(fromDetail);
    setPrescription({
      registration_id: row.registration_id,
      registration_number: row.registration_number || '',
      patient_name: row.patient_name || '',
      examination_id: row.id,
    });
    setRxItems([{ drug_id: '', dose: '', frequency: '', quantity: 1 }]);
    setRxNotes('');
    setRxInfo('');
    setRxBase({});
    setRxErrors({});
    setError('');
    try {
      const fresh = (await api.get(`/examinations/${row.id}`)).data.data;
      const all = fresh.prescriptions || [];
      const existing = all.find((item) => item.status === 'pending') || all[0];
      if (!existing) return;
      const editable = existing.status === 'pending';
      if (editable) setPrescription((old) => old ? { ...old, id: existing.id, status: existing.status } : old);
      setRxItems((existing.items || []).map((item) => ({
        drug_id: String(item.drug_id),
        dose: item.dose,
        frequency: item.frequency,
        quantity: Number(item.quantity),
      })));
      const base = {};
      (existing.items || []).forEach((item) => {
        base[item.drug_id] = (base[item.drug_id] || 0) + Number(item.quantity || 0);
      });
      setRxBase(editable ? base : {});
      setRxNotes(existing.notes || '');
      setRxInfo(editable
        ? `Resep tersimpan #${existing.prescription_number || existing.id} dimuat. Perubahan akan memperbarui resep ini.`
        : `Resep #${existing.prescription_number || existing.id} (${existing.status}) dimuat sebagai referensi. Menyimpan akan membuat resep baru.`);
    } catch (err) { setError(err.message); }
  }

  function changeRx(index, key, value) {
    setRxErrors((old) => {
      if (!old[index]) return old;
      const next = { ...old };
      delete next[index];
      return next;
    });
    setRxItems((old) => old.map((item, i) => {
      if (i !== index) return item;
      const next = { ...item, [key]: value };
      if (key === 'drug_id' || key === 'quantity') {
        const selected = (drugs.data || []).find((drug) => String(drug.id) === String(next.drug_id));
        if (selected && next.quantity !== '') {
          const max = maxForDrug(selected, index, old);
          const qty = Number(next.quantity);
          if (Number.isFinite(qty)) next.quantity = Math.max(0, Math.min(max, Math.floor(qty)));
        }
      }
      return next;
    }));
  }

  function validateRxStock(list) {
    const errors = {};
    const totals = {};
    const capacities = {};
    const names = {};
    rxItems.forEach((item, index) => {
      const drug = list.find((entry) => String(entry.id) === String(item.drug_id));
      if (!drug) { errors[index] = 'Pilih obat terlebih dahulu.'; return; }
      const qty = Number(item.quantity);
      if (!Number.isInteger(qty) || qty < 1) { errors[index] = 'Jumlah obat harus minimal 1.'; return; }
      const own = prescription?.id ? Number(rxBase[drug.id] ?? rxBase[String(drug.id)] ?? 0) : 0;
      const capacity = availableStock(drug) + own;
      capacities[drug.id] = capacity;
      names[drug.id] = drug;
      totals[drug.id] = (totals[drug.id] || 0) + qty;
    });
    Object.keys(totals).forEach((id) => {
      if (totals[id] <= capacities[id]) return;
      const drug = names[id];
      const message = `Stok ${drug.name} tersedia hanya ${capacities[id]}${drug.unit ? ' ' + drug.unit : ''}.`;
      rxItems.forEach((item, index) => {
        if (String(item.drug_id) === String(id) && !errors[index]) errors[index] = message;
      });
    });
    return errors;
  }

  async function savePrescription(event) {
    event.preventDefault();
    setError('');
    setRxErrors({});
    try {
      const freshDrugs = (await api.get('/drugs')).data.data;
      const errors = validateRxStock(freshDrugs);
      if (Object.keys(errors).length) {
        setRxErrors(errors);
        setError('Jumlah resep melebihi stok yang tersedia. Periksa jumlah yang ditandai.');
        return;
      }
      const payload = {
        registration_id: prescription.registration_id,
        examination_id: prescription.examination_id,
        notes: rxNotes,
        items: rxItems.map((item) => ({ ...item, quantity: Number(item.quantity) })),
      };
      if (prescription.id) await api.put(`/prescriptions/${prescription.id}`, payload);
      else await api.post('/prescriptions', payload);
      setMessage(prescription.id ? 'Resep diperbarui' : 'Resep dibuat dan diteruskan ke farmasi');
      setPrescription(null);
      setRxBase({});
      setRxErrors({});
      exams.reload();
      drugs.reload();
      if (prescriptionFromDetail) {
        const fresh = (await api.get(`/examinations/${prescription.examination_id}`)).data.data;
        setDetail((old) => ({ ...old, ...fresh }));
      }
      setPrescriptionFromDetail(false);
    } catch (err) {
      const message = err.message || 'Gagal menyimpan resep.';
      const fieldErrors = {};
      rxItems.forEach((item, index) => {
        const drug = (drugs.data || []).find((entry) => String(entry.id) === String(item.drug_id));
        if (drug && message.includes(drug.name)) fieldErrors[index] = message;
      });
      if (Object.keys(fieldErrors).length) setRxErrors(fieldErrors);
      setError(message);
    }
  }

  return (
    <div className="space-y-5">
      <Alert tone="green" onClose={() => setMessage('')}>{message}</Alert>
      <Alert tone="red" onClose={() => setError('')}>{error}</Alert>

      <Card title="Antrean pemeriksaan" subtitle={`Pasien menunggu / sedang diperiksa pada ${today}`}>
        <Table rows={waiting.data} empty="Tidak ada pasien dalam antrean pemeriksaan." columns={[
          { key: 'queue_number', title: 'No.' },
          { key: 'registration_number', title: 'Registrasi' },
          { key: 'patient_name', title: 'Pasien' },
          { key: 'polyclinic_name', title: 'Poli' },
          { key: 'status', title: 'Status', render: (row) => <Badge>{row.status}</Badge> },
          { key: 'actions', title: 'Aksi', render: (row) => row.examination_id
            ? <Button variant="secondary" onClick={() => openDetail({ ...row, id: row.examination_id })}>Lihat</Button>
            : <Button variant="secondary" onClick={() => update('registration_id', row.id)}>Isi EMR</Button> },
        ]} />
      </Card>

      {can(user, 'examination.create') && (
        <form onSubmit={submit} className="space-y-5">
          <Card title="1. Menginput anamnesis dan keluhan pasien" subtitle="Isi keluhan utama dan riwayat penyakit singkat (subjektif)">
            <div className="space-y-3">
              <Field label="Pendaftaran">
                <select required value={form.registration_id} onChange={(e) => update('registration_id', e.target.value)}>
                  <option value="">Pilih pendaftaran</option>
                  {(waiting.data || []).filter((row) => !row.examination_id).map((row) => (
                    <option key={row.id} value={row.id}>{row.queue_number} — {row.registration_number} — {row.patient_name}</option>
                  ))}
                  {(registrations.data || []).filter((row) => !String(form.registration_id) || String(form.registration_id) !== String(row.id)).map((row) => (
                    <option key={row.id} value={row.id}>{row.registration_number} — {row.patient_name}</option>
                  ))}
                </select>
              </Field>
              <Field label="Anamnesis / keluhan pasien"><textarea rows="4" required value={form.subjective} onChange={(e) => update('subjective', e.target.value)} placeholder="Keluhan utama, onset, dan riwayat singkat" /></Field>
            </div>
          </Card>

          <Card title="2. Menginput tanda-tanda vital" subtitle="Tekanan darah, nadi, suhu, napas, berat, dan tinggi">
            <div className="grid gap-3 md:grid-cols-4">
              {[
                ['systolic', 'Sistolik (mmHg)'], ['diastolic', 'Diastolik (mmHg)'], ['pulse', 'Nadi (x/menit)'],
                ['temperature', 'Suhu (°C)'], ['respiratory', 'Napas (x/menit)'], ['weight', 'Berat (kg)'], ['height', 'Tinggi (cm)'],
              ].map(([key, label]) => <Field key={key} label={label}><input value={form[key]} onChange={(e) => update(key, e.target.value)} /></Field>)}
            </div>
          </Card>

          <Card title="3. Menginput hasil pemeriksaan fisik" subtitle="Temuan objektif dan detail pemeriksaan fisik">
            <div className="grid gap-3 md:grid-cols-2">
              <Field label="Objektif / temuan pemeriksaan"><textarea rows="4" required value={form.objective} onChange={(e) => update('objective', e.target.value)} /></Field>
              <Field label="Detail pemeriksaan fisik"><textarea rows="4" value={form.physical_exam} onChange={(e) => update('physical_exam', e.target.value)} /></Field>
            </div>
          </Card>

          <Card title="4. Menentukan diagnosis utama dan diagnosis sekunder" subtitle="Pilih ICD dari master diagnosis, lalu isi assessment dan rencana">
            <div className="grid gap-3 md:grid-cols-2">
              <Field label="Diagnosis utama">
                <select value={form.primary_diagnosis_id} onChange={(e) => update('primary_diagnosis_id', e.target.value)}>
                  <option value="">Pilih diagnosis utama</option>
                  {(diagnoses.data || []).map((diagnosis) => <option key={diagnosis.id} value={diagnosis.id}>{diagnosis.code} — {diagnosis.name}</option>)}
                </select>
              </Field>
              <Field label="Assessment / hasil penilaian"><textarea rows="3" required value={form.assessment} onChange={(e) => update('assessment', e.target.value)} /></Field>
              <Field label="Rencana / terapi"><textarea rows="3" required value={form.plan} onChange={(e) => update('plan', e.target.value)} /></Field>
            </div>
            <div className="mt-3">
              <Field label="Diagnosis sekunder (ctrl + klik untuk beberapa)">
                <select multiple rows="4" value={form.secondary_diagnoses} onChange={(e) => update('secondary_diagnoses', Array.from(e.target.selectedOptions).map((option) => option.value))}>
                  {(diagnoses.data || []).map((diagnosis) => <option key={diagnosis.id} value={diagnosis.id}>{diagnosis.code} — {diagnosis.name}</option>)}
                </select>
              </Field>
            </div>
          </Card>

          <Card title="5. Menyimpan hasil pemeriksaan ke riwayat rekam medis pasien" subtitle="Satu transaksi menyimpan draft EMR, tanda tangan dokter, dan menautkannya ke kunjungan">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <p className="text-sm text-slate-500">Data tersimpan sebagai <b>draft</b> dan muncul pada tabel pemeriksaan serta riwayat kunjungan pasien. Klik <b>Finalisasi</b> setelah resep/tindakan selesai untuk mengunci EMR dan menyelesaikan kunjungan.</p>
              <Button>Simpan ke riwayat RME</Button>
            </div>
          </Card>
        </form>
      )}

      <Card title="Pemeriksaan terbaru">
        <Table rows={exams.data} columns={[
          { key: 'registration_number', title: 'Registrasi' },
          { key: 'patient_name', title: 'Pasien' },
          { key: 'doctor_name', title: 'Dokter' },
          { key: 'assessment', title: 'Assessment' },
          { key: 'status', title: 'Status', render: (row) => <Badge>{row.status}</Badge> },
          { key: 'created_at', title: 'Waktu', render: (row) => dateTime(row.created_at) },
          { key: 'actions', title: 'Aksi', render: (row) => (
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => openDetail(row)}>Detail</Button>
              {can(user, 'prescription.create') && row.status !== 'final' && <Button variant="secondary" onClick={() => openPrescription(row)}>Buat resep</Button>}
              {can(user, 'examination.create') && row.status !== 'final' && <Button variant="success" onClick={() => finalize(row)}>Finalisasi</Button>}
            </div>
          ) },
        ]} />
      </Card>

      {detail && (
        <Modal title={`Detail pemeriksaan #${detail.id}`} onClose={() => setDetail(null)}>
          <div className="space-y-3 text-sm">
            <p><b>Pasien:</b> {detail.patient_name || '-'}</p>
            <p><b>Status:</b> <Badge>{detail.status}</Badge> <b>Versi:</b> {detail.version}</p>
            <p><b>1. Anamnesis & keluhan pasien:</b> {detail.subjective || '-'}</p>
            <p><b>2. Tanda-tanda vital:</b> {vitalText(detail.vital_signs)}</p>
            <p><b>3. Hasil pemeriksaan fisik:</b> {detail.objective || '-'}{detail.physical_exam ? ` — ${detail.physical_exam}` : ''}</p>
            <p><b>4. Diagnosis utama & sekunder:</b> {(detail.diagnoses || []).map((d) => `${d.code} ${d.name}${d.is_primary ? ' (utama)' : ''}`).join(', ') || '-'}</p>
            <p><b>Assessment / rencana:</b> {detail.assessment || '-'} / {detail.plan || '-'}</p>
            <p><b>5. Riwayat RME:</b> pemeriksaan #{detail.id} · status {detail.status} · versi {detail.version} · tindakan: {(detail.actions || []).map((a) => a.action_name).join(', ') || '-'} · resep: {(detail.prescriptions || []).map((p) => `${p.prescription_number || '#' + p.id}${p.status ? ` (${p.status})` : ''}`).join(', ') || '-'}</p>
            <div>
              <b>Lab:</b>
              {(detail.lab_requests || []).length === 0 ? ' -' : (
                <ul className="mt-1 space-y-1">
                  {(detail.lab_requests || []).map((l) => (
                    <li key={l.id}>
                      {l.test_code ? `${l.test_code} — ` : ''}{l.test_name} <Badge>{l.status}</Badge>
                      {l.result
                        ? ` — ${l.result}${l.reference_range ? ` (rujukan: ${l.reference_range})` : ''}${l.result_by ? ` — oleh ${l.result_by}` : ''}`
                        : ' — hasil belum tersedia'}
                    </li>
                  ))}
                </ul>
              )}
            </div>
            {can(user, 'prescription.create') && detail.status !== 'final' && (
              <div className="flex justify-end pt-2">
                <Button onClick={() => openPrescription(detail, true)}>Buat resep</Button>
              </div>
            )}
          </div>
        </Modal>
      )}

      {prescription && (
        <Modal title={`${prescription.id ? 'Resep tersimpan' : 'Buat resep'} — ${prescription.patient_name}`} onClose={() => { setPrescription(null); setRxBase({}); setRxErrors({}); }}>
          <form onSubmit={savePrescription} className="space-y-3">
            <p className="text-xs text-slate-500">
              Registrasi {prescription.registration_number || prescription.registration_id}
            </p>
            {rxInfo && <Alert tone="blue">{rxInfo}</Alert>}
            {rxItems.map((item, index) => {
              const selected = (drugs.data || []).find((drug) => String(drug.id) === String(item.drug_id));
              const max = maxForDrug(selected, index);
              return (
                <div key={index} className={`grid gap-3 rounded-lg border p-3 md:grid-cols-[2fr_1fr_1fr_1fr_auto] ${rxErrors[index] ? 'border-red-300 bg-red-50/50' : ''}`}>
                  <Select label="Obat" required value={item.drug_id} onChange={(e) => changeRx(index, 'drug_id', e.target.value)}
                    options={(drugs.data || []).map((drug) => ({
                      value: drug.id,
                      label: `${drug.name} — tersedia ${availableStock(drug)} ${drug.unit} (stok ${drug.stock}, dipesan ${drug.reserved_stock ?? 0}) (${money(drug.price)})`,
                    }))} />
                  <Field label="Dosis"><input required value={item.dose} onChange={(e) => changeRx(index, 'dose', e.target.value)} placeholder="1 tablet" /></Field>
                  <Field label="Frekuensi"><input required value={item.frequency} onChange={(e) => changeRx(index, 'frequency', e.target.value)} placeholder="3x hari" /></Field>
                  <Field label="Jumlah">
                    <input required min="1" max={selected ? max : undefined} type="number" value={item.quantity}
                      className={rxErrors[index] ? '!border-red-500 !ring-2 !ring-red-100 bg-red-50' : ''}
                      onChange={(e) => changeRx(index, 'quantity', e.target.value)}
                      onBlur={() => { if (selected && item.quantity === '') changeRx(index, 'quantity', max > 0 ? 1 : 0); }} />
                    {rxErrors[index] ? (
                      <p className="mt-1 text-xs font-medium text-red-600">Periksa: {rxErrors[index]}</p>
                    ) : selected && (
                      <p className={`mt-1 text-xs ${max === 0 ? 'text-red-600' : Number(item.quantity) >= max ? 'text-amber-600' : 'text-slate-500'}`}>
                        Maks. {max} {selected.unit} · stok master {selected.stock}, dipesan {selected.reserved_stock ?? 0}{max === 0 ? ' · stok habis' : ''}
                      </p>
                    )}
                  </Field>
                  <div className="flex items-end">
                    <Button type="button" variant="danger" onClick={() => setRxItems(rxItems.filter((_, i) => i !== index))} disabled={rxItems.length === 1}>Hapus</Button>
                  </div>
                </div>
              );
            })}
            <div className="flex justify-between">
              <Button type="button" variant="secondary" onClick={() => setRxItems([...rxItems, { drug_id: '', dose: '', frequency: '', quantity: 1 }])}>+ Item</Button>
            </div>
            <Field label="Catatan untuk farmasi"><input value={rxNotes} onChange={(e) => setRxNotes(e.target.value)} /></Field>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={() => { setPrescription(null); setRxBase({}); setRxErrors({}); }}>Batal</Button>
              <Button>{prescription.id ? 'Perbarui resep' : 'Simpan resep'}</Button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  );
}
