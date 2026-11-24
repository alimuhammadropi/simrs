import { useEffect, useState } from 'react';

export function useFetch(loader, deps = []) {
  const [state, setState] = useState({ data: null, loading: true, error: '' });
  const [tick, setTick] = useState(0);

  useEffect(() => {
    let active = true;
    setState((old) => ({ ...old, loading: true, error: '' }));
    Promise.resolve()
      .then(loader)
      .then((data) => active && setState({ data, loading: false, error: '' }))
      .catch((error) => active && setState({ data: null, loading: false, error: error.message || 'Gagal memuat data' }));
    return () => { active = false; };
  }, [...deps, tick]);

  return { ...state, reload: () => setTick((value) => value + 1) };
}
