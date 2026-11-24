import { initializeApp } from 'firebase/app';
import { deleteToken, getMessaging, getToken, isSupported, onMessage } from 'firebase/messaging';
import api from './api';

const config = {
  apiKey: import.meta.env.VITE_FIREBASE_API_KEY || '',
  authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN || '',
  projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID || '',
  messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID || '',
  appId: import.meta.env.VITE_FIREBASE_APP_ID || '',
};
const vapidKey = import.meta.env.VITE_FIREBASE_VAPID_KEY || '';

let app;
let messaging;
let listenerStarted = false;

export function firebaseConfigured() {
  return Boolean(config.apiKey && config.projectId && config.messagingSenderId && config.appId && vapidKey);
}

async function messagingInstance() {
  if (!firebaseConfigured() || !(await isSupported())) return null;
  if (!app) app = initializeApp(config);
  if (!messaging) messaging = getMessaging(app);
  return messaging;
}

async function registerServiceWorker() {
  const query = new URLSearchParams(config).toString();
  const registration = await navigator.serviceWorker.register(`/firebase-messaging-sw.js?${query}`, { scope: '/' });
  await navigator.serviceWorker.ready;
  return registration;
}

async function requestRegistrationToken() {
  if (!firebaseConfigured()) throw new Error('Firebase belum dikonfigurasi di frontend (.env).');
  if (!('Notification' in window)) throw new Error('Browser tidak mendukung notifikasi.');
  const permission = Notification.permission === 'granted'
    ? 'granted'
    : await Notification.requestPermission();
  if (permission !== 'granted') throw new Error('Izin notifikasi ditolak.');
  const instance = await messagingInstance();
  if (!instance) throw new Error('Browser tidak mendukung Firebase Cloud Messaging.');
  const registration = await registerServiceWorker();
  const token = await getToken(instance, { serviceWorkerRegistration: registration, vapidKey });
  if (!token) throw new Error('Token notifikasi tidak diperoleh.');
  return token;
}

export async function getRegistrationToken() {
  try {
    return await requestRegistrationToken();
  } catch {
    return null;
  }
}

export async function enableNotifications() {
  const token = await requestRegistrationToken();
  await api.post('/notifications/token', { token, platform: 'web' });
  localStorage.setItem('simrs_fcm_token', token);
  await startForegroundListener();
  return token;
}

export async function syncToken() {
  if (!firebaseConfigured() || !('Notification' in window) || Notification.permission !== 'granted') return false;
  try {
    const token = await requestRegistrationToken();
    const previous = localStorage.getItem('simrs_fcm_token');
    if (previous !== token) {
      await api.post('/notifications/token', { token, platform: 'web' });
      localStorage.setItem('simrs_fcm_token', token);
    }
    await startForegroundListener();
    return true;
  } catch {
    return false;
  }
}

export async function disableNotifications() {
  const instance = await messagingInstance();
  if (instance) await deleteToken(instance).catch(() => {});
  const token = localStorage.getItem('simrs_fcm_token');
  if (token) await api.post('/notifications/token/delete', { token }).catch(() => {});
  localStorage.removeItem('simrs_fcm_token');
}

export async function startForegroundListener() {
  if (listenerStarted) return;
  const instance = await messagingInstance();
  if (!instance) return;
  onMessage(instance, (payload) => {
    window.dispatchEvent(new CustomEvent('simrs:notification', { detail: payload }));
  });
  listenerStarted = true;
}

export function notificationState() {
  if (!firebaseConfigured()) return 'belum dikonfigurasi';
  if (!('Notification' in window)) return 'tidak didukung';
  if (Notification.permission === 'denied') return 'ditolak';
  if (Notification.permission !== 'granted') return 'nonaktif';
  return localStorage.getItem('simrs_fcm_token') ? 'aktif' : 'nonaktif';
}
