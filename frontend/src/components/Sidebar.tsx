import { useState } from 'react';
import { MessageCircle, Plus, Search, X, ChevronLeft, ChevronRight } from 'lucide-react';
import SessionItem from './SessionItem';
import SearchModal from './SearchModal';
import type { Session } from '../types';

interface SidebarProps {
  sessions: Session[];
  streamingSessionIds: Set<string>;
  onNewSession: () => void;
  onSelectSession: (sessionId: string) => void;
  onDeleteSession: (sessionId: string) => void;
  collapsed: boolean;
  onToggle: () => void;
  open: boolean;
  onClose: () => void;
  isMobile: boolean;
}

export default function Sidebar({
  sessions,
  streamingSessionIds,
  onNewSession,
  onSelectSession,
  onDeleteSession,
  collapsed,
  onToggle,
  open,
  onClose,
  isMobile,
}: SidebarProps) {
  const [searchOpen, setSearchOpen] = useState(false);

  const isCollapsed = !isMobile && collapsed;

  return (
    <>
      <SearchModal sessions={sessions} isOpen={searchOpen} onClose={() => setSearchOpen(false)} />

      <aside
        className="fixed left-0 top-0 z-40 flex h-screen flex-col overflow-hidden border-r border-[#D3D1C8] bg-[#F0EEE8] transition-all duration-300 ease-in-out"
        style={{
          width: isMobile ? 280 : collapsed ? 70 : 280,
          transform: isMobile && !open ? 'translateX(-100%)' : 'translateX(0)',
        }}
      >
        {/* ── Logo ── */}
        <div
          className={`flex items-center border-b border-[#D3D1C8] px-4 py-4 ${
            isCollapsed ? 'justify-center' : 'justify-between'
          }`}
        >
          <div className="flex items-center gap-2">
            <MessageCircle size={20} className="flex-shrink-0 text-[#2C2C2A]" />
            {!isCollapsed && (
              <span className="text-sm font-semibold text-[#2C2C2A]">Referee AI</span>
            )}
          </div>
          {isMobile && (
            <button
              onClick={onClose}
              aria-label="Close menu"
              className="rounded p-1 text-[#888780] hover:text-[#2C2C2A]"
            >
              <X size={16} />
            </button>
          )}
        </div>

        {/* ── New Session button ── */}
        <div className={`px-3 pt-3 pb-2 ${isCollapsed ? 'flex justify-center' : ''}`}>
          {isCollapsed ? (
            <button
              onClick={() => { onNewSession(); onClose(); }}
              aria-label="New Session"
              className="flex h-9 w-9 items-center justify-center rounded-md bg-[#2C2C2A] text-white transition-colors hover:bg-[#404040]"
            >
              <Plus size={16} />
            </button>
          ) : (
            <button
              onClick={() => { onNewSession(); onClose(); }}
              className="flex w-full items-center gap-2 rounded-md bg-[#2C2C2A] px-3 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#404040]"
            >
              <Plus size={16} />
              New Session
            </button>
          )}
        </div>

        {/* ── Search ── */}
        <div className={`px-3 pb-3 ${isCollapsed ? 'flex justify-center' : ''}`}>
          {isCollapsed ? (
            <button
              onClick={() => setSearchOpen(true)}
              aria-label="Search"
              className="flex h-9 w-9 items-center justify-center rounded-md border border-[#D3D1C8] bg-white text-[#888780] transition-colors hover:text-[#2C2C2A]"
            >
              <Search size={15} />
            </button>
          ) : (
            <button
              onClick={() => setSearchOpen(true)}
              className="flex w-full items-center gap-2 rounded-md border border-[#D3D1C8] bg-white px-3 py-2 text-sm text-[#888780] transition-colors hover:border-[#888780] hover:text-[#2C2C2A]"
            >
              <Search size={15} className="flex-shrink-0" />
              <span>Search...</span>
            </button>
          )}
        </div>

        {/* ── Divider ── */}
        <div className="h-px bg-[#D3D1C8]" />

        {/* ── Sessions ── */}
        <div className="flex-1 overflow-y-auto px-2 py-3">
          {!isCollapsed && (
            <p className="mb-2 px-2 text-[10px] font-semibold uppercase tracking-widest text-[#888780]">
              Sessions
            </p>
          )}
          <div className="flex flex-col gap-0.5">
            {sessions.map(s => (
              <SessionItem
                key={s.id}
                title={s.title}
                date={s.date}
                active={s.active}
                isStreaming={streamingSessionIds.has(s.id)}
                collapsed={isCollapsed}
                onSelect={() => {
                  onSelectSession(s.id);
                  if (isMobile) {
                    onClose();
                  }
                }}
                onDelete={() => onDeleteSession(s.id)}
              />
            ))}
          </div>
        </div>

        {/* ── Divider ── */}
        <div className="h-px bg-[#D3D1C8]" />

        {/* ── Collapse toggle (desktop only) ── */}
        {!isMobile && (
          <div className={`px-3 py-3 ${isCollapsed ? 'flex justify-center' : ''}`}>
            <button
              onClick={onToggle}
              aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
              className={`flex items-center gap-2 rounded-md px-2 py-2 text-xs text-[#888780] transition-colors hover:bg-[#E8E4DC] hover:text-[#2C2C2A] ${
                isCollapsed ? 'justify-center' : 'w-full'
              }`}
            >
              {collapsed ? <ChevronRight size={16} /> : (
                <>
                  <ChevronLeft size={16} />
                  <span>Collapse</span>
                </>
              )}
            </button>
          </div>
        )}
      </aside>
    </>
  );
}
