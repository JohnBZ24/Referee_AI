import { useEffect, useRef, useState } from 'react';
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
  onRenameSession: (sessionId: string, nextTitle: string) => void;
  pinnedSessionIds: Set<string>;
  onTogglePinSession: (sessionId: string) => void;
  collapsed: boolean;
  onToggle: () => void;
  open: boolean;
  onClose: () => void;
  isMobile: boolean;
  desktopWidth: number;
  minDesktopWidth: number;
  maxDesktopWidth: number;
  onChangeDesktopWidth: (px: number) => void;
  onResizeStart?: () => void;
  onResizeEnd?: () => void;
}

export default function Sidebar({
  sessions,
  streamingSessionIds,
  onNewSession,
  onSelectSession,
  onDeleteSession,
  onRenameSession,
  pinnedSessionIds,
  onTogglePinSession,
  collapsed,
  onToggle,
  open,
  onClose,
  isMobile,
  desktopWidth,
  minDesktopWidth,
  maxDesktopWidth,
  onChangeDesktopWidth,
  onResizeStart,
  onResizeEnd,
}: SidebarProps) {
  const [searchOpen, setSearchOpen] = useState(false);

  const resizeStateRef = useRef<{
    startX: number;
    startWidth: number;
    active: boolean;
    raf: number | null;
    nextWidth: number;
    prevCursor: string;
    prevUserSelect: string;
  } | null>(null);

  const isCollapsed = !isMobile && collapsed;

  function clampWidth(px: number): number {
    return Math.max(minDesktopWidth, Math.min(maxDesktopWidth, px));
  }

  useEffect(() => {
    return () => {
      if (resizeStateRef.current?.raf) {
        cancelAnimationFrame(resizeStateRef.current.raf);
      }
    };
  }, []);

  function endResize() {
    const state = resizeStateRef.current;
    if (!state) {
      return;
    }

    state.active = false;
    if (state.raf) {
      cancelAnimationFrame(state.raf);
    }

    document.body.style.cursor = state.prevCursor;
    document.body.style.userSelect = state.prevUserSelect;

    resizeStateRef.current = null;
    onResizeEnd?.();
  }

  function startResize(e: React.PointerEvent) {
    if (isMobile || isCollapsed) {
      return;
    }

    const prevCursor = document.body.style.cursor;
    const prevUserSelect = document.body.style.userSelect;
    document.body.style.cursor = 'col-resize';
    document.body.style.userSelect = 'none';

    resizeStateRef.current = {
      startX: e.clientX,
      startWidth: desktopWidth,
      active: true,
      raf: null,
      nextWidth: desktopWidth,
      prevCursor,
      prevUserSelect,
    };

    onResizeStart?.();

    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
  }

  function onResizeMove(e: React.PointerEvent) {
    const state = resizeStateRef.current;
    if (!state?.active) {
      return;
    }

    const delta = e.clientX - state.startX;
    state.nextWidth = clampWidth(state.startWidth + delta);

    if (state.raf) {
      return;
    }

    state.raf = requestAnimationFrame(() => {
      const s = resizeStateRef.current;
      if (!s?.active) {
        return;
      }
      onChangeDesktopWidth(s.nextWidth);
      s.raf = null;
    });
  }

  return (
    <>
      <SearchModal
        sessions={sessions}
        isOpen={searchOpen}
        onClose={() => setSearchOpen(false)}
        onSelectSession={(sessionId) => {
          onSelectSession(sessionId);
          if (isMobile) {
            onClose();
          }
        }}
      />

      <aside
        className="group fixed left-0 top-0 z-40 flex h-screen flex-col overflow-hidden border-r border-[#D3D1C8] bg-[#F0EEE8] transition-all duration-300 ease-in-out"
        style={{
          width: isMobile ? 280 : collapsed ? 70 : desktopWidth,
          transform: isMobile && !open ? 'translateX(-100%)' : 'translateX(0)',
        }}
      >
        {/* ── Logo ── */}
        <div
          className={`flex items-center border-b border-[#D3D1C8] px-4 py-4 ${
            isCollapsed ? 'justify-center' : 'justify-between'
          }`}
        >
          <button
            type="button"
            onClick={() => {
              if (isMobile) {
                onClose();
                return;
              }
              onToggle();
            }}
            className="flex items-center gap-2 text-left"
            aria-label={isMobile ? 'Close sidebar' : isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            title={isMobile ? 'Close sidebar' : isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            <MessageCircle size={20} className="flex-shrink-0 text-[#2C2C2A]" />
            {!isCollapsed && (
              <span className="text-sm font-semibold text-[#2C2C2A]">Referee AI</span>
            )}
          </button>
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
                id={s.id}
                title={s.title}
                date={s.date}
                active={s.active}
                isStreaming={streamingSessionIds.has(s.id)}
                pinned={pinnedSessionIds.has(s.id)}
                collapsed={isCollapsed}
                onSelect={() => {
                  onSelectSession(s.id);
                  if (isMobile) {
                    onClose();
                  }
                }}
                onDelete={() => onDeleteSession(s.id)}
                onRename={(nextTitle) => onRenameSession(s.id, nextTitle)}
                onTogglePin={() => onTogglePinSession(s.id)}
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

        {/* ── Resize handle (desktop expanded only) ── */}
        {!isMobile && !isCollapsed && (
          <div
            role="separator"
            aria-orientation="vertical"
            aria-label="Resize sidebar"
            className="absolute right-0 top-0 h-full w-[10px] cursor-col-resize touch-none"
            onPointerDown={startResize}
            onPointerMove={onResizeMove}
            onPointerUp={endResize}
            onPointerCancel={endResize}
            onDoubleClick={() => onChangeDesktopWidth(280)}
          >
            <div className="absolute right-[4px] top-0 h-full w-px bg-[#D3D1C8] opacity-40 transition-opacity group-hover:opacity-100" />
          </div>
        )}
      </aside>
    </>
  );
}
