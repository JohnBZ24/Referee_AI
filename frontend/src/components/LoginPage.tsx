import { useState } from 'react';
import { MessageCircle } from 'lucide-react';
import { authAPI } from '../lib/api';

interface Props {
  onSuccess: () => void;
}

export default function LoginPage({ onSuccess }: Props) {
  const [mode, setMode] = useState<'login' | 'register'>('login');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      let data: { token: string };

      if (mode === 'register') {
        data = await authAPI.register(email, password, name);
      } else {
        data = await authAPI.login(email, password);
      }

      localStorage.setItem('auth_token', data.token);
      onSuccess();
    } catch (err: any) {
      const msg =
        err?.response?.data?.message ||
        err?.response?.data?.errors?.email?.[0] ||
        err?.response?.data?.errors?.password?.[0] ||
        'Something went wrong. Please try again.';
      setError(msg);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-[#FAFAF8] px-4">
      <div className="w-full max-w-sm">
        {/* Logo */}
        <div className="mb-8 flex flex-col items-center gap-2">
          <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-[#2C2C2A]">
            <MessageCircle size={20} className="text-white" />
          </div>
          <h1 className="text-xl font-semibold text-[#2C2C2A]">Referee AI</h1>
          <p className="text-sm text-[#888780]">
            {mode === 'login' ? 'Sign in to your account' : 'Create a new account'}
          </p>
        </div>

        {/* Card */}
        <div className="rounded-xl border border-[#D3D1C8] bg-white p-6 shadow-sm">
          <form onSubmit={handleSubmit} className="flex flex-col gap-4">
            {mode === 'register' && (
              <div className="flex flex-col gap-1.5">
                <label className="text-xs font-medium text-[#2C2C2A]">Name</label>
                <input
                  type="text"
                  value={name}
                  onChange={e => setName(e.target.value)}
                  required
                  placeholder="Your name"
                  className="rounded-lg border border-[#D3D1C8] bg-[#FAFAF8] px-3 py-2.5 text-sm text-[#2C2C2A] outline-none transition placeholder:text-[#C0BDB5] focus:border-[#2C2C2A] focus:ring-1 focus:ring-[#2C2C2A]"
                />
              </div>
            )}

            <div className="flex flex-col gap-1.5">
              <label className="text-xs font-medium text-[#2C2C2A]">Email</label>
              <input
                type="email"
                value={email}
                onChange={e => setEmail(e.target.value)}
                required
                placeholder="you@example.com"
                className="rounded-lg border border-[#D3D1C8] bg-[#FAFAF8] px-3 py-2.5 text-sm text-[#2C2C2A] outline-none transition placeholder:text-[#C0BDB5] focus:border-[#2C2C2A] focus:ring-1 focus:ring-[#2C2C2A]"
              />
            </div>

            <div className="flex flex-col gap-1.5">
              <label className="text-xs font-medium text-[#2C2C2A]">Password</label>
              <input
                type="password"
                value={password}
                onChange={e => setPassword(e.target.value)}
                required
                minLength={8}
                placeholder="••••••••"
                className="rounded-lg border border-[#D3D1C8] bg-[#FAFAF8] px-3 py-2.5 text-sm text-[#2C2C2A] outline-none transition placeholder:text-[#C0BDB5] focus:border-[#2C2C2A] focus:ring-1 focus:ring-[#2C2C2A]"
              />
            </div>

            {error && (
              <p className="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-600">{error}</p>
            )}

            <button
              type="submit"
              disabled={loading}
              className="mt-1 rounded-lg bg-[#2C2C2A] py-2.5 text-sm font-semibold text-white transition hover:bg-[#404040] disabled:opacity-50"
            >
              {loading ? 'Please wait…' : mode === 'login' ? 'Sign in' : 'Create account'}
            </button>
          </form>
        </div>

        {/* Toggle mode */}
        <p className="mt-4 text-center text-sm text-[#888780]">
          {mode === 'login' ? "Don't have an account? " : 'Already have an account? '}
          <button
            onClick={() => { setMode(mode === 'login' ? 'register' : 'login'); setError(''); }}
            className="font-medium text-[#2C2C2A] underline-offset-2 hover:underline"
          >
            {mode === 'login' ? 'Register' : 'Sign in'}
          </button>
        </p>
      </div>
    </div>
  );
}
