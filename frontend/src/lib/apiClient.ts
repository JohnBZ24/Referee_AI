import axios from 'axios';

function normalizeBaseUrl(url: string): string {
  const trimmed = (url || '').trim();
  if (!trimmed) {
    return '/api/v1';
  }

  // If an absolute URL is provided, keep it.
  if (/^https?:\/\//i.test(trimmed)) {
    return trimmed.replace(/\/$/, '');
  }

  // Otherwise treat as a path.
  const path = trimmed.startsWith('/') ? trimmed : `/${trimmed}`;
  return path.replace(/\/$/, '');
}

// Prefer same-origin + Vite proxy in dev. Override with VITE_API_BASE_URL when needed.
const API_BASE_URL = normalizeBaseUrl(import.meta.env.VITE_API_BASE_URL);

export const apiClient = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
  },
  withCredentials: true,
});
