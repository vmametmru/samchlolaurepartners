import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '',
});

function getAlternateApiPath(url: string): string | null {
  if (url.startsWith('/api/api/')) return url.replace('/api/api/', '/api/');
  if (url.startsWith('/api/')) return `/api${url}`;
  if (url.startsWith('api/api/')) return url.replace('api/api/', 'api/');
  if (url.startsWith('api/')) return `api/${url}`;
  return null;
}

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

api.interceptors.response.use(
  (res) => res,
  async (err) => {
    const status = err.response?.status;
    const cfg = err.config as (typeof err.config & { _apiPathRetried?: boolean }) | undefined;
    const url = typeof cfg?.url === 'string' ? cfg.url : '';

    if (status === 404 && cfg && !cfg._apiPathRetried) {
      const alternateUrl = getAlternateApiPath(url);
      if (alternateUrl) {
        cfg._apiPathRetried = true;
        cfg.url = alternateUrl;
        return api.request(cfg);
      }
    }

    if (err.response?.status === 401) {
      localStorage.removeItem('auth_token');
      window.location.href = '/login';
    }
    return Promise.reject(err);
  }
);

export default api;
