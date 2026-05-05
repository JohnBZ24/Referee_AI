// Full-screen search overlay — rendered via React portal so it escapes
// the sidebar's stacking context and always sits above everything.
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Search, X, MessageCircle } from 'lucide-react';
import type { Session } from '../types';

interface SearchModalProps {
  sessions: Session[];
  isOpen: boolean;
  onClose: () => void;
  onSelectSession: (sessionId: string) => void;
}

export default function SearchModal({ sessions, isOpen, onClose, onSelectSession }: SearchModalProps) {
  const [query, setQuery] = useState('');
  const inputRef = useRef<HTMLInputElement>(null);

  // Auto-focus + reset query each time the modal opens
  useEffect(() => {
    if (isOpen) {
      setQuery('');
      const t = setTimeout(() => inputRef.current?.focus(), 40);
      return () => clearTimeout(t);
    }
  }, [isOpen]);

  // Close on Escape
  useEffect(() => {
    if (!isOpen) return;
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  const results = query.trim()
    ? sessions.filter(s => s.title.toLowerCase().includes(query.toLowerCase()))
    : sessions;

  return createPortal(
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/30 px-4"
      onClick={onClose}
    >
      <div
        className="w-full max-w-[400px] overflow-hidden rounded-lg bg-white shadow-xl"
        onClick={e => e.stopPropagation()}
      >
        {/* ── Search input ── */}
        <div className="flex items-center gap-3 border-b border-[#D3D1C8] px-4 py-3">
          <Search size={16} className="flex-shrink-0 text-[#888780]" />
          <input
            ref={inputRef}
            type="text"
            value={query}
            onChange={e => setQuery(e.target.value)}
            placeholder="Search sessions..."
            className="flex-1 bg-transparent text-sm text-[#2C2C2A] placeholder-[#888780] outline-none"
          />
          <button
            onClick={onClose}
            aria-label="Close"
            className="rounded p-1 text-[#888780] hover:text-[#2C2C2A]"
          >
            <X size={16} />
          </button>
        </div>

        {/* ── Results ── */}
        <div className="max-h-64 overflow-y-auto">
          {!query.trim() && (
            <p className="px-4 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-widest text-[#888780]">
              Recent
            </p>
          )}

          {results.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-[#888780]">
              No sessions match &ldquo;{query}&rdquo;
            </p>
          ) : (
            results.map(session => (
              <button
                key={session.id}
                onClick={() => {
                  onSelectSession(session.id);
                  onClose();
                }}
                className="flex w-full items-center gap-3 rounded-md px-3 py-2 text-left transition-colors hover:bg-[#F5F3F0]"
              >
               <MessageCircle size={16} className="shrink-0 text-[#888780]" />
               <span className="truncate text-sm text-[#2C2C2A]">{session.title}</span>
             </button>
           ))
          )}
        </div>

        {/* ── Hint footer ── */}
        <div className="border-t border-[#D3D1C8] px-4 py-2.5">
          <p className="text-xs text-[#888780]">
            Press{' '}
            <kbd className="rounded bg-[#F5F3F0] px-1.5 py-0.5 font-mono text-[10px] text-[#2C2C2A]">
              Esc
            </kbd>{' '}
            to close
          </p>
        </div>
      </div>
    </div>,
    document.body
  );
}
