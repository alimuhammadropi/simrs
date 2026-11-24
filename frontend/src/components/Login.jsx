import React, { useState } from 'react';
import { Activity } from 'lucide-react';
import api from '../api';
import { firebaseConfigured, getRegistrationToken } from '../firebase';
import { Alert, Button } from '../components/Ui';

const demos = [
  ['petugas', 'Pendaftaran', 'registration'],
  ['dokter', 'Dokter', 'doctor'],
  ['farmasi', 'Farmasi', 'pharmacy'],
  ['lab', 'Laboratorium', 'laboratory'],
  ['kasir', 'Kasir', 'cashier'],
];

export default function Login({ onLogin }) {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [notify, setNotify] = useState(true);
  const notificationsAvailable = firebaseConfigured();

  async function submit(event) {
    event.preventDefault();
    setError('');
    setLoading(true);
    try {
      const fcmToken = notify && notificationsAvailable ? await getRegistrationToken() : null;
      const response = await api.post('/auth/login', {
        username,
        password,
        ...(fcmToken ? { fcm_token: fcmToken } : {}),
      });
      if (fcmToken) localStorage.setItem('simrs_fcm_token', fcmToken);
      localStorage.setItem('simrs_token', response.data.data.token);
      onLogin(response.data.data.user);
    } catch (err) {
      setError(err.message || 'Login gagal');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="grid min-h-screen place-items-center bg-gradient-to-br from-slate-100 to-blue-100 p-4">
      <form onSubmit={submit} className="card w-full max-w-md space-y-4">
        <div className="text-center">
          <div className="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-xl bg-blue-700 text-white"><Activity /></div>
          <h1 className="text-2xl font-bold">SIMRS Rawat Jalan</h1>
          <p className="text-sm text-slate-500">Masuk untuk mengelola pelayanan pasien</p>
        </div>
        <Alert tone="red" onClose={() => setError('')}>{error}</Alert>
        <label className="block text-sm font-medium">Username
          <input className="mt-1" required value={username} onChange={(event) => setUsername(event.target.value)} autoComplete="username" />
        </label>
        <label className="block text-sm font-medium">Password
          <input className="mt-1" required type="password" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="current-password" />
        </label>
        {notificationsAvailable ? (
          <label className="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" className="h-4 w-4" checked={notify} onChange={(event) => setNotify(event.target.checked)} />
            Aktifkan notifikasi di browser ini
          </label>
        ) : (
          <p className="rounded-lg bg-slate-50 p-2 text-xs text-slate-500">
            Notifikasi browser tidak tersedia. Isi konfigurasi Firebase di <code>frontend/.env</code> untuk mengaktifkannya.
          </p>
        )}
        <Button className="w-full" disabled={loading}>{loading ? 'Memeriksa...' : 'Masuk'}</Button>
        <div className="rounded-lg bg-slate-50 p-3">
          <div className="flex flex-wrap gap-2">
            {demos.map(([user, label]) => (
              <button key={user} type="button" onClick={() => { setUsername(user); setPassword('Admin123!'); }}
                className="rounded border bg-white px-2 py-1 text-xs text-slate-600 hover:border-blue-400">
                {label}
              </button>
            ))}
          </div>
        </div>
      </form>
    </div>
  );
}
