import React, { useEffect, useState } from 'react';
import {
  Activity, Users, ClipboardList, Stethoscope, Syringe, Pill, FlaskConical,
  Wallet, Database, BarChart3, Shield, LogOut, Menu, RefreshCw, Bell,
} from 'lucide-react';
import api from './api';
import { disableNotifications, enableNotifications, firebaseConfigured, notificationState, startForegroundListener, syncToken } from './firebase';
import { can } from './auth';
import Login from './components/Login';
import Dashboard from './pages/Dashboard';
import Patients from './pages/Patients';
import Registrations from './pages/Registrations';
import Examinations from './pages/Examinations';
import Actions from './pages/Actions';
import Pharmacy from './pages/Pharmacy';
import Laboratory from './pages/Laboratory';
import Billing from './pages/Billing';
import Reports from './pages/Reports';
import MasterData from './pages/MasterData';
import UsersPage from './pages/Users';
import Integration from './pages/Integration';

const navigation = [
  { id: 'dashboard', label: 'Dashboard', icon: Activity, permission: null },
  { id: 'patients', label: 'Pasien', icon: Users, permission: 'patient.view' },
  { id: 'registrations', label: 'Pendaftaran', icon: ClipboardList, permission: 'registration.view' },
  { id: 'examinations', label: 'Pemeriksaan', icon: Stethoscope, permission: 'examination.view' },
  { id: 'actions', label: 'Tindakan', icon: Syringe, permission: 'examination.view' },
  { id: 'pharmacy', label: 'Farmasi', icon: Pill, permission: 'pharmacy.view' },
  { id: 'laboratory', label: 'Laboratorium', icon: FlaskConical, permission: 'lab.view' },
  { id: 'integration', label: 'Integrasi Penunjang', icon: FlaskConical, permission: 'master.manage' },
  { id: 'billing', label: 'Tagihan', icon: Wallet, permission: 'billing.view' },
  { id: 'reports', label: 'Laporan', icon: BarChart3, permission: 'report.view' },
  { id: 'master', label: 'Data master', icon: Database, permission: 'master.manage' },
  { id: 'users', label: 'Pengguna', icon: Shield, permission: 'user.manage' },
];

const pages = {
  dashboard: Dashboard,
  patients: Patients,
  registrations: Registrations,
  examinations: Examinations,
  actions: Actions,
  pharmacy: Pharmacy,
  laboratory: Laboratory,
  integration: Integration,
  billing: Billing,
  reports: Reports,
  master: MasterData,
  users: UsersPage,
};

export default function App() {
  const [user, setUser] = useState(null);
  const [booting, setBooting] = useState(() => Boolean(localStorage.getItem('simrs_token')));
  const [page, setPage] = useState(() => {
    const requested = new URLSearchParams(window.location.search).get('page');
    return navigation.some((item) => item.id === requested) ? requested : 'dashboard';
  });
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [tick, setTick] = useState(0);
  const [notifications, setNotifications] = useState([]);
  const [toast, setToast] = useState(null);
  const [notifOpen, setNotifOpen] = useState(false);
  const [notifState, setNotifState] = useState(notificationState());
  const [notifBusy, setNotifBusy] = useState(false);
  const [notifInfo, setNotifInfo] = useState('');

  useEffect(() => {
    const unauthorized = () => {
      localStorage.removeItem('simrs_token');
      setUser(null); setPage('dashboard'); setBooting(false);
    };
    window.addEventListener('simrs:unauthorized', unauthorized);

    const token = localStorage.getItem('simrs_token');
    if (!token) {
      setBooting(false);
      return () => window.removeEventListener('simrs:unauthorized', unauthorized);
    }

    let active = true;
    api.get('/auth/me')
      .then((response) => {
        if (!active) return;
        const nextUser = response.data.data;
        setUser(nextUser);
        const requested = new URLSearchParams(window.location.search).get('page');
        if (requested && navigation.some((item) => item.id === requested && (!item.permission || can(nextUser, item.permission)))) {
          setPage(requested);
        }
        setBooting(false);
      })
      .catch(() => {
        localStorage.removeItem('simrs_token');
        if (active) setBooting(false);
      });

    return () => {
      active = false;
      window.removeEventListener('simrs:unauthorized', unauthorized);
    };
  }, []);

  useEffect(() => {
    if (!user) return;
    setNotifState(notificationState());
    startForegroundListener().catch(() => {});
    syncToken().then((active) => { if (active) setNotifState('aktif'); }).catch(() => {});
    const listener = (event) => {
      const payload = event.detail || {};
      const item = {
        id: Date.now() + Math.random(),
        title: payload.notification?.title || 'Notifikasi SIMRS',
        body: payload.notification?.body || '',
        type: payload.data?.type || '',
        at: new Date(),
      };
      setNotifications((items) => [item, ...items].slice(0, 10));
      setToast(item);
      window.setTimeout(() => setToast((current) => (current?.id === item.id ? null : current)), 6000);
    };
    window.addEventListener('simrs:notification', listener);
    return () => window.removeEventListener('simrs:notification', listener);
  }, [user]);

  function login(nextUser) {
    setUser(nextUser);
    setBooting(false);
    const allowed = navigation.filter((item) => !item.permission || can(nextUser, item.permission));
    if (!allowed.some((item) => item.id === page)) setPage('dashboard');
    setTick((value) => value + 1);
  }

  function logout() {
    localStorage.removeItem('simrs_token');
    setUser(null);
    setPage('dashboard');
  }

  async function toggleNotifications() {
    setNotifInfo('');
    if (!firebaseConfigured()) {
      setNotifState(notificationState());
      setNotifInfo('Notifikasi tidak tersedia: Firebase belum dikonfigurasi di frontend.');
      setNotifOpen(true);
      return;
    }
    if (notificationState() === 'aktif') {
      setNotifOpen((open) => !open);
      return;
    }
    setNotifBusy(true);
    try {
      await enableNotifications();
      setNotifState('aktif');
      setNotifOpen(true);
    } catch (err) {
      setNotifInfo(err.message);
      setNotifState(notificationState());
    } finally {
      setNotifBusy(false);
    }
  }

  async function sendTestNotification() {
    setNotifInfo('');
    try {
      await api.post('/notifications/test');
      setNotifInfo('Notifikasi tes dikirim.');
    } catch (err) {
      setNotifInfo(err.message);
    }
  }

  async function turnOffNotifications() {
    try {
      await disableNotifications();
      setNotifOpen(false);
      setNotifInfo('Notifikasi dimatikan.');
    } catch (err) {
      setNotifInfo(err.message);
    }
  }

  if (booting) return <div className="grid min-h-screen place-items-center bg-slate-50 text-sm text-slate-500">Memuat sesi...</div>;
  if (!user) return <Login onLogin={login} />;

  const allowed = navigation.filter((item) => !item.permission || can(user, item.permission));
  const active = allowed.find((item) => item.id === page) || allowed[0];
  const Page = pages[active.id];

  return (
    <div className="min-h-screen md:flex">
      {sidebarOpen && <div className="fixed inset-0 z-20 bg-slate-900/50 md:hidden" onClick={() => setSidebarOpen(false)} />}
      <aside className={`fixed inset-y-0 left-0 z-30 w-64 bg-slate-900 p-4 text-white transition-transform md:static md:min-h-screen md:translate-x-0 ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}`}>
        <div className="mb-8 flex items-center gap-3">
          <Activity className="text-blue-300" />
          <div><b>SIMRS</b><p className="text-xs text-slate-400">Rawat Jalan</p></div>
        </div>
        <nav className="space-y-1">
          {allowed.map((item) => {
            const Icon = item.icon;
            return (
              <button key={item.id} onClick={() => { setPage(item.id); setSidebarOpen(false); }}
                className={`flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm ${active.id === item.id ? 'bg-blue-700' : 'hover:bg-slate-800'}`}>
                <Icon size={18} />{item.label}
              </button>
            );
          })}
        </nav>
        <div className="mt-10 border-t border-slate-700 pt-4 text-sm">
          <p className="font-medium">{user.name}</p>
          <p className="mb-3 text-xs text-slate-400">@{user.username} · {user.roles?.join(', ')}</p>
          <button onClick={logout} className="flex items-center gap-2 text-slate-300 hover:text-white"><LogOut size={16} /> Keluar</button>
        </div>
      </aside>

      <main className="min-w-0 flex-1 p-4 md:p-8">
        <header className="mb-6 flex items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <button className="rounded-lg border bg-white p-2 md:hidden" onClick={() => setSidebarOpen(true)} aria-label="Buka menu"><Menu size={18} /></button>
            <div>
              <h1 className="text-2xl font-bold">{active.label}</h1>
              <p className="text-sm text-slate-500">Sistem Informasi Manajemen Rumah Sakit</p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <div className="relative">
              <button onClick={toggleNotifications} disabled={notifBusy}
                className="flex items-center gap-2 rounded-lg border bg-white px-3 py-2 text-sm hover:bg-slate-50 disabled:opacity-50">
                <Bell size={16} />
                <span className="hidden sm:inline">Notifikasi</span>
                {notifState === 'aktif' && <span className="h-2 w-2 rounded-full bg-green-500" />}
              </button>
              {notifOpen && (
                <div className="absolute right-0 z-40 mt-2 w-80 rounded-lg border bg-white p-3 shadow-lg">
                  <p className="mb-2 text-xs text-slate-500">Status: <b>{notifState}</b></p>
                  {!firebaseConfigured() && (
                    <p className="mb-2 text-xs text-slate-500">
                      Isi <code>VITE_FIREBASE_*</code> di <code>frontend/.env</code> lalu jalankan ulang build.
                    </p>
                  )}
                  {notifInfo && <p className="mb-2 text-xs text-blue-600">{notifInfo}</p>}
                  <div className="mb-3 flex gap-2">
                    {notifState === 'aktif' && (
                      <>
                        <button onClick={sendTestNotification} className="rounded border px-2 py-1 text-xs hover:bg-slate-50">Kirim tes</button>
                        <button onClick={turnOffNotifications} className="rounded border px-2 py-1 text-xs hover:bg-slate-50">Matikan</button>
                      </>
                    )}
                  </div>
                  {notifications.length === 0 ? (
                    <p className="text-xs text-slate-400">Belum ada notifikasi masuk.</p>
                  ) : notifications.map((item) => (
                    <div key={item.id} className="border-t py-2 first:border-0">
                      <p className="text-sm font-medium">{item.title}</p>
                      <p className="text-xs text-slate-600">{item.body}</p>
                      <p className="text-[10px] text-slate-400">{item.at.toLocaleTimeString('id-ID')}{item.type ? ` · ${item.type}` : ''}</p>
                    </div>
                  ))}
                </div>
              )}
            </div>
            <button className="rounded-lg border bg-white p-2" onClick={() => setTick((value) => value + 1)} aria-label="Muat ulang halaman"><RefreshCw size={18} /></button>
          </div>
        </header>
        <Page key={`${active.id}-${tick}`} user={user} />
      </main>
      {toast && (
        <div className="fixed bottom-4 right-4 z-50 w-80 rounded-lg border border-blue-200 bg-white p-4 shadow-xl">
          <p className="text-sm font-semibold">{toast.title}</p>
          <p className="mt-1 text-sm text-slate-600">{toast.body}</p>
        </div>
      )}
    </div>
  );
}
