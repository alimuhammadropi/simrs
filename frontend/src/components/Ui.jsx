import React from 'react';

export function Card({ title, subtitle, actions, children, className = '' }) {
  return (
    <section className={`card ${className}`}>
      {(title || actions) && (
        <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
          <div>
            {title && <h2 className="font-semibold text-slate-800">{title}</h2>}
            {subtitle && <p className="text-xs text-slate-500">{subtitle}</p>}
          </div>
          {actions}
        </div>
      )}
      {children}
    </section>
  );
}

export function Field({ label, children }) {
  return <label className="block text-sm font-medium text-slate-600">{label}{children}</label>;
}

export function Select({ label, value, onChange, options, placeholder = 'Pilih...', ...rest }) {
  return (
    <Field label={label}>
      <select value={value} onChange={onChange} {...rest}>
        <option value="">{placeholder}</option>
        {(options || []).map((option) => (
          <option key={option.value} value={option.value}>{option.label}</option>
        ))}
      </select>
    </Field>
  );
}

export function Table({ rows, columns, empty = 'Belum ada data.' }) {
  if (!rows?.length) return <p className="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">{empty}</p>;
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead>
          <tr className="border-b text-xs uppercase tracking-wide text-slate-500">
            {columns.map((column) => <th key={column.key} className="whitespace-nowrap p-2">{column.title}</th>)}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={row.id ?? index} className="border-b last:border-0 hover:bg-slate-50">
              {columns.map((column) => (
                <td key={column.key} className="p-2 align-top">
                  {column.render ? column.render(row, index) : (row[column.key] ?? '-')}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function Badge({ children, tone = 'slate' }) {
  const tones = {
    slate: 'bg-slate-100 text-slate-700',
    blue: 'bg-blue-50 text-blue-700',
    green: 'bg-emerald-50 text-emerald-700',
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-red-50 text-red-700',
  };
  const status = String(children || '').toLowerCase();
  const auto = status.includes('paid') || status.includes('completed') || status.includes('verified') || status.includes('active') ? 'green'
    : status.includes('pending') || status.includes('waiting') || status.includes('draft') ? 'amber'
    : status.includes('cancelled') || status.includes('failed') ? 'red' : tone;
  return <span className={`rounded-full px-2 py-1 text-xs font-medium ${tones[auto] || tones.slate}`}>{children || '-'}</span>;
}

export function Stat({ label, value, hint, icon: Icon }) {
  return (
    <div className="card flex items-start justify-between gap-3">
      <div>
        <p className="text-sm text-slate-500">{label}</p>
        <p className="mt-2 text-2xl font-bold text-slate-800">{value ?? 0}</p>
        {hint && <p className="mt-1 text-xs text-slate-400">{hint}</p>}
      </div>
      {Icon && <span className="rounded-lg bg-blue-50 p-2 text-blue-700"><Icon size={20} /></span>}
    </div>
  );
}

export function Alert({ children, tone = 'blue', onClose }) {
  const tones = {
    blue: 'bg-blue-50 text-blue-800',
    red: 'bg-red-50 text-red-700',
    green: 'bg-emerald-50 text-emerald-700',
  };
  if (!children) return null;
  return (
    <div className={`mb-4 flex items-start justify-between gap-3 rounded-lg p-3 text-sm ${tones[tone] || tones.blue}`}>
      <span>{children}</span>
      {onClose && <button type="button" onClick={onClose} aria-label="Tutup">×</button>}
    </div>
  );
}

export function Spinner() {
  return <p className="p-6 text-center text-sm text-slate-500">Memuat...</p>;
}

export function Button({ children, variant = 'primary', ...props }) {
  const variants = {
    primary: 'bg-blue-700 text-white hover:bg-blue-800',
    secondary: 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50',
    danger: 'bg-red-600 text-white hover:bg-red-700',
    success: 'bg-emerald-600 text-white hover:bg-emerald-700',
  };
  return <button className={`rounded-lg px-3 py-2 text-sm font-medium disabled:opacity-50 ${variants[variant]}`} {...props}>{children}</button>;
}

export function Modal({ title, onClose, children }) {
  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-slate-900/50 p-4" onClick={(event) => { if (event.target === event.currentTarget) onClose(); }}>
      <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-5 shadow-xl">
        <div className="mb-4 flex items-center justify-between">
          <h3 className="font-semibold">{title}</h3>
          <button type="button" aria-label="Tutup" onClick={(event) => { event.stopPropagation(); onClose(); }} className="text-slate-400">×</button>
        </div>
        {children}
      </div>
    </div>
  );
}

export function dayName(value) {
  return ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][Number(value)] || '-';
}

export function money(value) {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value || 0));
}

export function dateTime(value) {
  if (!value) return '-';
  return new Date(value).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' });
}
