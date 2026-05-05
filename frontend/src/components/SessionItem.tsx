import { useEffect, useRef, useState } from 'react';
import { MoreHorizontal, Pin, PinOff, Trash2, Type } from 'lucide-react';

type SessionItemProps = {
  id: string;
  title: string;
  date: string;
  active?: boolean;
  collapsed?: boolean;
  isStreaming?: boolean;
  pinned?: boolean;
  onSelect?: () => void;
  onDelete?: () => void;
  onRename?: (nextTitle: string) => void;
  onTogglePin?: () => void;
};

export default function SessionItem({
  title,
  date,
  active = false,
  collapsed = false,
  isStreaming = false,
  pinned = false,
  onSelect,
  onDelete,
  onRename,
  onTogglePin,
}: SessionItemProps) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(title);
  const inputRef = useRef<HTMLInputElement>(null);

  const [menuOpen, setMenuOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!editing) {
      setDraft(title);
    }
  }, [title, editing]);

  useEffect(() => {
    if (!menuOpen) {
      return;
    }

    function onDocPointerDown(e: PointerEvent) {
      const root = menuRef.current;
      if (!root) {
        return;
      }
      if (e.target instanceof Node && root.contains(e.target)) {
        return;
      }
      setMenuOpen(false);
    }

    function onDocKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        setMenuOpen(false);
      }
    }

    document.addEventListener('pointerdown', onDocPointerDown);
    document.addEventListener('keydown', onDocKeyDown);
    return () => {
      document.removeEventListener('pointerdown', onDocPointerDown);
      document.removeEventListener('keydown', onDocKeyDown);
    };
  }, [menuOpen]);

  useEffect(() => {
    if (!editing) {
      return;
    }
    const t = setTimeout(() => inputRef.current?.focus(), 0);
    return () => clearTimeout(t);
  }, [editing]);

  function commitRename() {
    const next = draft.trim();
    setEditing(false);
    if (!next || next === title) {
      setDraft(title);
      return;
    }
    onRename?.(next);
  }

  function cancelRename() {
    setEditing(false);
    setDraft(title);
  }

  if (collapsed) {
    return (
      <div className="flex justify-center py-1.5">
        <button
          type="button"
          onClick={onSelect}
          title={title}
          className={`h-2 w-2 rounded-full ${active ? 'bg-[#2C2C2A]' : isStreaming ? 'bg-[#D85A30]' : 'bg-[#C8C5BC]'}`}
        />
      </div>
    );
  }

  return (
    <div
      role="button"
      tabIndex={0}
      onClick={() => {
        if (editing) {
          return;
        }
        onSelect?.();
      }}
      onKeyDown={(e) => {
        if (editing) {
          return;
        }
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          onSelect?.();
        }
      }}
      className={`w-full rounded-md px-3 py-2.5 text-left transition-colors duration-100 ${
        active ? 'bg-[#E8E4DC]' : 'hover:bg-[#E8E4DC]'
      }`}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            {editing ? (
              <input
                ref={inputRef}
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                onClick={(e) => e.stopPropagation()}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    commitRename();
                  }
                  if (e.key === 'Escape') {
                    e.preventDefault();
                    cancelRename();
                  }
                }}
                onBlur={commitRename}
                className="w-full min-w-0 rounded-md border border-[#D3D1C8] bg-white px-2 py-1 text-sm text-[#2C2C2A] outline-none focus:border-[#888780]"
                aria-label="Rename session"
              />
            ) : (
              <p
                className={`truncate text-sm leading-snug ${active ? 'font-medium text-[#2C2C2A]' : 'text-[#555450]'}`}
                title={title}
                onDoubleClick={(e) => {
                  if (!onRename) {
                    return;
                  }
                  e.stopPropagation();
                  setEditing(true);
                }}
              >
                {title}
              </p>
            )}
            {pinned && (
              <span
                className="inline-flex items-center rounded-full border border-[#D3D1C8] bg-white/70 px-1.5 py-0.5 text-[10px] font-semibold text-[#555450]"
                title="Pinned"
              >
                <Pin size={12} className="text-[#888780]" />
              </span>
            )}
            {isStreaming && (
              <span className="h-1.5 w-1.5 flex-shrink-0 animate-pulse rounded-full bg-[#D85A30]" title="Streaming..." />
            )}
          </div>
          <p className="mt-0.5 text-xs text-[#888780]">{date}</p>
        </div>

        {(onRename || onDelete || onTogglePin) && !editing && (
          <div ref={menuRef} className="relative flex items-center">
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                setMenuOpen((v) => !v);
              }}
              aria-label="Session actions"
              className="rounded p-1 text-[#888780] transition-colors hover:bg-white/60 hover:text-[#2C2C2A]"
              title="Actions"
            >
              <MoreHorizontal size={18} />
            </button>

            {menuOpen && (
              <div className="absolute right-0 top-[calc(100%+6px)] z-50 w-40 overflow-hidden rounded-md border border-[#D3D1C8] bg-white shadow-lg">
                {onTogglePin && (
                  <button
                    type="button"
                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-[#2C2C2A] hover:bg-[#F5F3F0]"
                    onClick={(e) => {
                      e.stopPropagation();
                      setMenuOpen(false);
                      onTogglePin();
                    }}
                  >
                    {pinned ? (
                      <PinOff size={14} className="text-[#888780]" />
                    ) : (
                      <Pin size={14} className="text-[#888780]" />
                    )}
                    {pinned ? 'Unpin' : 'Pin'}
                  </button>
                )}

                {onRename && (
                  <button
                    type="button"
                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-[#2C2C2A] hover:bg-[#F5F3F0]"
                    onClick={(e) => {
                      e.stopPropagation();
                      setMenuOpen(false);
                      setEditing(true);
                    }}
                  >
                    <Type size={14} className="text-[#888780]" />
                    Rename
                  </button>
                )}

                {onDelete && (
                  <button
                    type="button"
                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-[#9A4A2F] hover:bg-[#F5F3F0]"
                    onClick={(e) => {
                      e.stopPropagation();
                      setMenuOpen(false);
                      onDelete();
                    }}
                  >
                    <Trash2 size={14} className="text-[#9A4A2F]" />
                    Delete
                  </button>
                )}
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
