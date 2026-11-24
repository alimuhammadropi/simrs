import React, { useState } from 'react';
import api from '../api';
import { useFetch } from '../hooks';
import { Alert, Badge, Button, Card, Field, Modal, Table } from '../components/Ui';

const roleOptions = ['admin', 'registration', 'doctor', 'nurse', 'management', 'pharmacy', 'laboratory', 'cashier'];
const emptyForm = { username: '', name: '', password: '', is_active: true, roles: [] };

export default function Users() {
  const [users, setUsers] = useState([]);
  const [form, setForm] = useState(emptyForm);
  const [editing, setEditing] = useState(null);
  const [show, setShow] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const list = useFetch(() => api.get('/users').then((r) => r.data.data), []);

  React.useEffect(() => { setUsers(list.data || []); }, [list.data]);

  function open(row) {
    if (row) {
      setEditing(row.id);
      setForm({ username: row.username, name: row.name, password: '', is_active: row.is_active, roles: row.roles || [] });
    } else { setEditing(null); setForm(emptyForm); }
    setShow(true);
  }

  function close() {
    setShow(false); setEditing(null); setForm(emptyForm); setError('');
  }

  function toggleRole(code) {
    setForm((old) => ({ ...old, roles: old.roles.includes(code) ? old.roles.filter((item) => item !== code) : [...old.roles, code] }));
  }

  async function save(event) {
    event.preventDefault();
    setError('');
    try {
      if (editing) await api.put(`/users/${editing}`, form);
      else await api.post('/users', form);
      setMessage('Pengguna disimpan');
      setShow(false); setEditing(null); setForm(emptyForm); list.reload();
    } catch (err) { setError(err.message); }
  }

  async function deactivate(row) {
    if (!window.confirm(`Nonaktifkan ${row.username}?`)) return;
    try { await api.delete(`/users/${row.id}`); setMessage('Pengguna dinonaktifkan'); list.reload(); }
    catch (err) { setError(err.message); }
  }

  async function resetToken(row) {
    if (!window.confirm(`Reset token notifikasi ${row.username}?`)) return;
    try { await api.post(`/users/${row.id}/reset-notification-token`); setMessage('Token notifikasi direset'); list.reload(); }
    catch (err) { setError(err.message); }
  }

  return (
    <div className="space-y-5">
      <Alert tone="green" onClose={() => setMessage('')}>{message}</Alert>
      <Alert tone="red" onClose={() => setError('')}>{error}</Alert>
      <Card title="Pengguna & hak akses" actions={<Button onClick={() => open(null)}>Tambah pengguna</Button>}>
        <Table rows={users} columns={[
          { key: 'username', title: 'Username' },
          { key: 'name', title: 'Nama' },
          { key: 'roles', title: 'Peran', render: (row) => (row.roles || []).map((role) => <Badge key={role}>{role}</Badge>) },
          { key: 'is_active', title: 'Status', render: (row) => <Badge>{row.is_active === false ? 'inactive' : 'active'}</Badge> },
          { key: 'fcm_token_set', title: 'Notifikasi', render: (row) => <Badge>{row.fcm_token_set ? 'aktif' : 'kosong'}</Badge> },
          { key: 'actions', title: 'Aksi', render: (row) => (
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" onClick={() => open(row)}>Ubah</Button>
              <Button variant="secondary" onClick={() => resetToken(row)}>Reset token</Button>
              <Button variant="danger" onClick={() => deactivate(row)}>Nonaktifkan</Button>
            </div>
          ) },
        ]} />
      </Card>
      {show && (
        <Modal title={editing ? 'Ubah pengguna' : 'Tambah pengguna'} onClose={close}>
          <form onSubmit={save} className="space-y-3">
            <div className="grid gap-3 md:grid-cols-2">
              <Field label="Username"><input required value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} /></Field>
              <Field label="Nama"><input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
              <Field label={editing ? 'Password baru (kosongkan jika tidak diubah)' : 'Password'}>
                <input type="password" required={!editing} value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
              </Field>
              <Field label="Status"><select value={form.is_active ? '1' : '0'} onChange={(e) => setForm({ ...form, is_active: e.target.value === '1' })}><option value="1">Aktif</option><option value="0">Nonaktif</option></select></Field>
            </div>
            <Field label="Peran">
              <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
                {roleOptions.map((role) => (
                  <label key={role} className="flex items-center gap-2 rounded border p-2 text-sm">
                    <input type="checkbox" className="h-4 w-4" checked={form.roles.includes(role)} onChange={() => toggleRole(role)} />
                    {role}
                  </label>
                ))}
              </div>
            </Field>
            <div className="flex justify-end gap-2"><Button variant="secondary" type="button" onClick={close}>Batal</Button><Button>Simpan</Button></div>
          </form>
        </Modal>
      )}
    </div>
  );
}
