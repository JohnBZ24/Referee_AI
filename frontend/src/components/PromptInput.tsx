import { useMemo, useRef, useEffect, useState } from 'react';
import { ArrowUp, CircleStop, Paperclip, X, AtSign, Globe } from 'lucide-react';
import type { Session } from '../types';

export type HiddenSessionMessageRef = {
  sessionId: string;
  sessionTitle: string;
};

export type WebSearchMode = 'auto' | 'on' | 'off';

interface InputAreaProps {
  value: string;
  onChange: (val: string) => void;
  onSend: (payload: { attachments: File[]; refs: HiddenSessionMessageRef[]; webSearchMode: WebSearchMode }) => void;
  onCancel?: () => void;
  disabled?: boolean;
  isStreaming?: boolean;
  variant?: 'docked' | 'center';
  leftOffset?: number;
  disableTransition?: boolean;
  sessions?: Pick<Session, 'id' | 'title'>[];
  currentSessionId?: string;
}

export default function InputArea({
  value,
  onChange,
  onSend,
  onCancel,
  disabled = false,
  isStreaming = false,
  variant = 'docked',
  leftOffset = 0,
  disableTransition = false,
  sessions = [],
  currentSessionId,
}: InputAreaProps) {
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [attachments, setAttachments] = useState<File[]>([]);
  const [refs, setRefs] = useState<HiddenSessionMessageRef[]>([]);
  const [webSearchMode, setWebSearchMode] = useState<WebSearchMode>('off');

  const rootRef = useRef<HTMLDivElement>(null);
  const [mentionOpen, setMentionOpen] = useState(false);
  const [mentionTokenStart, setMentionTokenStart] = useState<number | null>(null);
  const [mentionCursor, setMentionCursor] = useState<number | null>(null);
  const [mentionQuery, setMentionQuery] = useState('');
  const [mentionHighlight, setMentionHighlight] = useState(0);
  const [mentionLoading, setMentionLoading] = useState(false);
  const [mentionError, setMentionError] = useState<string | null>(null);



  function openMentionPickerWithoutTyping() {
    if (disabled) {
      return;
    }

    setMentionOpen(true);
    setMentionTokenStart(null);
    setMentionCursor(null);
    setMentionQuery('');
    setMentionHighlight(0);
    setMentionError(null);

    queueMicrotask(() => {
      textareaRef.current?.focus();
    });
  }

  function adjustHeight() {
    const el = textareaRef.current;
    if (!el) return;
    el.style.height = 'auto';
    const cap = variant === 'center' ? 240 : 200;
    el.style.height = `${Math.min(el.scrollHeight, cap)}px`;
  }

  // Reset height when value is cleared (after send)
  useEffect(() => {
    adjustHeight();
  }, [value]);

  useEffect(() => {
    // Clear attachments after send (value is cleared by parent)
    if (value === '') {
      setAttachments([]);
      setRefs([]);
      setWebSearchMode('off');
      if (fileInputRef.current) {
        fileInputRef.current.value = '';
      }
    }
  }, [value]);

  function toggleWebSearchMode() {
    if (disabled) {
      return;
    }

    setWebSearchMode((m) => {
      if (m === 'on') {
        return 'off';
      }
      return 'on';
    });
  }

  const webSearchButtonTone =
    webSearchMode === 'on'
      ? 'border-[#0F6E56] bg-white text-[#0F6E56] hover:bg-[#F9F8F6]'
      : webSearchMode === 'auto'
        ? 'border-[#BDB7AC] bg-white text-[#2C2C2A] hover:bg-[#F9F8F6]'
        : 'border-[#D3D1C8] bg-white text-[#2C2C2A] hover:bg-[#F9F8F6]';

  const webSearchButtonPress =
    'active:translate-y-[1px] active:shadow-inner focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0F6E56]/30';

  useEffect(() => {
    function onDocPointerDown(e: PointerEvent) {
      if (!mentionOpen) {
        return;
      }
      const root = rootRef.current;
      if (!root) {
        return;
      }
      if (e.target instanceof Node && root.contains(e.target)) {
        return;
      }
      setMentionOpen(false);
      setMentionTokenStart(null);
      setMentionCursor(null);
      setMentionQuery('');
      setMentionHighlight(0);
      setMentionError(null);
    }

    document.addEventListener('pointerdown', onDocPointerDown);
    return () => document.removeEventListener('pointerdown', onDocPointerDown);
  }, [mentionOpen]);



  const filteredSessions = useMemo(() => {
    const q = mentionQuery.trim().toLowerCase();
    const list = sessions.filter((s) => {
      if (!q) {
        return true;
      }
      return (s.title || '').toLowerCase().includes(q);
    });
    list.sort((a, b) => (a.title || '').localeCompare(b.title || ''));
    return list;
  }, [sessions, mentionQuery]);

  function replaceMentionTokenWithChatLabel(start: number, end: number, label: string) {
    const liveText = textareaRef.current?.value ?? value;
    const before = liveText.slice(0, start);
    const after = liveText.slice(end);
    const insert = `@${label} `;
    const next = `${before}${insert}${after}`;
    onChange(next);
    queueMicrotask(() => {
      const el = textareaRef.current;
      if (!el) {
        return;
      }
      const nextPos = (before + insert).length;
      el.focus();
      el.setSelectionRange(nextPos, nextPos);
      adjustHeight();
    });
  }

  async function addLastMessageRefFromSession(sessionId: string) {
    const chosen = sessions.find((s) => s.id === sessionId);
    if (!chosen) {
      return;
    }

    const start = mentionTokenStart;
    const cursor = textareaRef.current?.selectionStart ?? mentionCursor;

    setMentionLoading(true);
    setMentionError(null);
    try {
      setRefs((prev) => {
        if (prev.some((r) => r.sessionId === sessionId)) {
          return prev;
        }
        return [
          ...prev,
          {
            sessionId,
            sessionTitle: chosen.title || 'Session',
          },
        ].slice(0, 3);
      });

      // Replace the typed @token with a readable chat label.
      if (start !== null && cursor !== null) {
        replaceMentionTokenWithChatLabel(start, cursor, chosen.title || 'Session');
      }

      setMentionOpen(false);
      setMentionTokenStart(null);
      setMentionCursor(null);
      setMentionQuery('');
      setMentionHighlight(0);
    } finally {
      setMentionLoading(false);
    }
  }

  function closeMention() {
    setMentionOpen(false);
    setMentionTokenStart(null);
    setMentionCursor(null);
    setMentionQuery('');
    setMentionHighlight(0);
    setMentionLoading(false);
    setMentionError(null);
  }

  function addFiles(files: File[]) {
    if (disabled) {
      return;
    }

    const next: File[] = [];
    for (const f of files) {
      if (next.length + attachments.length >= 3) {
        break;
      }
      next.push(f);
    }

    if (next.length === 0) {
      return;
    }

    setAttachments((prev) => [...prev, ...next].slice(0, 3));
  }

  function humanSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    const kb = bytes / 1024;
    if (kb < 1024) return `${kb.toFixed(0)} KB`;
    return `${(kb / 1024).toFixed(1)} MB`;
  }

  return (
    <>
      <div
        ref={rootRef}
        className={(() => {
          if (variant === 'center') {
            return 'w-full';
          }
          return `fixed bottom-0 right-0 z-20 border-t border-[#D3D1C8] bg-[#F5F3F0] p-4 ${
            disableTransition ? '' : 'transition-[left] duration-300'
          }`;
        })()}
        style={variant === 'center' ? undefined : { left: leftOffset }}
      >
        <div
          className={variant === 'center' ? 'mx-auto w-full max-w-[880px]' : ''}
        >
          {/* no header */}

          <div className={variant === 'center' ? 'p-5' : ''}>
      {(refs.length > 0 || attachments.length > 0) && (
        <div className={variant === 'center' ? 'mb-3 flex flex-wrap gap-2' : 'mb-3 grid grid-cols-[132px_1fr_40px] gap-3'}>
          {variant !== 'center' && <div />}
          <div className={variant === 'center' ? 'min-w-0 flex flex-wrap gap-2' : 'min-w-0 flex flex-wrap gap-2'}>
            {refs.map((r) => (
              <div
                key={r.sessionId}
                className="flex items-center gap-2 rounded-md border border-[#D3D1C8] bg-white/70 px-2 py-1.5 text-xs text-[#2C2C2A]"
                title="Will include last message from this chat (hidden)"
              >
                <span className="max-w-[220px] truncate">@ {r.sessionTitle}</span>
                <span className="text-[#888780]">last message</span>
                <button
                  type="button"
                  onClick={() => setRefs((prev) => prev.filter((x) => x.sessionId !== r.sessionId))}
                  className="rounded p-0.5 text-[#888780] hover:bg-[#F5F3F0] hover:text-[#2C2C2A]"
                  aria-label="Remove reference"
                >
                  <X size={12} />
                </button>
              </div>
            ))}
            {attachments.map((f, idx) => (
              <div
                key={`${f.name}-${f.size}-${idx}`}
                className="flex items-center gap-2 rounded-md border border-[#D3D1C8] bg-white/70 px-2 py-1.5 text-xs text-[#2C2C2A]"
                title={f.type || 'file'}
              >
                <span className="max-w-[220px] truncate">{f.name}</span>
                <span className="text-[#888780]">{humanSize(f.size)}</span>
                <button
                  type="button"
                  onClick={() => {
                    setAttachments((prev) => prev.filter((_, i) => i !== idx));
                  }}
                  className="rounded p-0.5 text-[#888780] hover:bg-[#F5F3F0] hover:text-[#2C2C2A]"
                  aria-label="Remove attachment"
                >
                  <X size={12} />
                </button>
              </div>
            ))}
            {refs.length >= 3 && <span className="self-center text-xs text-[#888780]">Max 3 refs</span>}
            {attachments.length >= 3 && <span className="self-center text-xs text-[#888780]">Max 3 files</span>}
          </div>
          {variant !== 'center' && <div />}
        </div>
      )}

        <div
          className={
            variant === 'center'
              ? 'relative rounded-2xl border border-[#D3D1C8] bg-transparent px-3 py-3'
              : 'relative grid grid-cols-[132px_1fr_40px] items-end gap-3'
          }
          style={variant === 'center' ? undefined : { alignItems: 'end' }}
        >
        <input
          ref={fileInputRef}
          type="file"
          multiple
          className="hidden"
          accept="application/pdf,image/png,image/jpeg,image/webp,image/gif,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain,text/csv,application/csv"
          onChange={(e) => {
            const files = Array.from(e.target.files || []);
            addFiles(files);
          }}
        />

        {variant !== 'center' && (
          <div className="flex w-[132px] items-end gap-2 self-end">
            <button
              type="button"
              onClick={openMentionPickerWithoutTyping}
              disabled={disabled || sessions.length === 0}
              className="flex h-[44px] w-10 items-center justify-center rounded-md border border-[#D3D1C8] bg-white text-[#2C2C2A] transition-colors hover:bg-[#F9F8F6] disabled:opacity-40"
              title="Reference another chat (@)"
              aria-label="Reference another chat"
            >
              <AtSign size={18} />
            </button>

            <button
              type="button"
              onClick={() => fileInputRef.current?.click()}
              disabled={disabled || attachments.length >= 3}
              className="flex h-[44px] w-10 items-center justify-center rounded-md border border-[#D3D1C8] bg-white text-[#2C2C2A] transition-colors hover:bg-[#F9F8F6] disabled:opacity-40"
              title="Attach files"
            >
              <Paperclip size={18} />
            </button>

            <button
              type="button"
              onClick={toggleWebSearchMode}
              disabled={disabled}
              aria-pressed={webSearchMode !== 'off'}
              className={`flex h-[44px] w-10 items-center justify-center rounded-md border transition-colors disabled:opacity-40 ${webSearchButtonTone} ${webSearchButtonPress}`}
              title={`Web search: ${webSearchMode.toUpperCase()}`}
              aria-label="Toggle web search"
            >
              <Globe size={18} />
            </button>
          </div>
        )}

        <div className={variant === 'center' ? 'flex items-start gap-2' : 'relative min-w-0 flex-1 self-end'}>
          {variant === 'center' && (
            <button
              type="button"
              onClick={openMentionPickerWithoutTyping}
              disabled={disabled || sessions.length === 0}
              className="mt-[6px] flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-transparent text-[#2C2C2A] transition-colors hover:bg-white/60 disabled:opacity-40"
              title="Reference another chat (@)"
              aria-label="Reference another chat"
            >
              <AtSign size={18} />
            </button>
          )}

          {variant === 'center' && (
            <button
              type="button"
              onClick={() => fileInputRef.current?.click()}
              disabled={disabled || attachments.length >= 3}
              className="mt-[6px] flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-transparent text-[#2C2C2A] transition-colors hover:bg-white/60 disabled:opacity-40"
              title="Attach files"
              aria-label="Attach files"
            >
              <Paperclip size={18} />
            </button>
          )}

          {variant === 'center' && (
            <button
              type="button"
              onClick={toggleWebSearchMode}
              disabled={disabled}
              aria-pressed={webSearchMode !== 'off'}
              className={`mt-[6px] flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg border transition-colors disabled:opacity-40 ${webSearchButtonTone} ${webSearchButtonPress}`}
              title={`Web search: ${webSearchMode.toUpperCase()}`}
              aria-label="Toggle web search"
            >
              <Globe size={18} />
            </button>
          )}

          {mentionOpen && (
            <div className="absolute bottom-[calc(100%+10px)] left-0 right-0 z-50 overflow-hidden rounded-md border border-[#D3D1C8] bg-white shadow-sm">
              <div className="flex items-center justify-between border-b border-[#EEEAE1] px-3 py-2">
                <div className="flex items-center gap-2 text-xs font-semibold text-[#2C2C2A]">
                  <AtSign size={14} />
                  Insert last message
                </div>
                <button
                  type="button"
                  className="rounded p-1 text-[#888780] hover:bg-[#F5F3F0] hover:text-[#2C2C2A]"
                  onClick={closeMention}
                  aria-label="Close"
                >
                  <X size={14} />
                </button>
              </div>

              {mentionError && (
                <div className="border-b border-[#EEEAE1] px-3 py-2 text-xs text-[#9A4A2F]">{mentionError}</div>
              )}

              <div className="max-h-[220px] overflow-y-auto py-1">
                {filteredSessions.length === 0 ? (
                  <div className="px-3 py-2 text-xs text-[#888780]">No chats match “{mentionQuery || ''}”</div>
                ) : (
                  filteredSessions.slice(0, 20).map((s, idx) => {
                    const active = idx === mentionHighlight;
                    const isCurrent = currentSessionId && s.id === currentSessionId;
                    return (
                      <button
                        key={s.id}
                        type="button"
                        className={`flex w-full items-center justify-between px-3 py-2 text-left text-sm transition-colors ${
                          active ? 'bg-[#F5F3F0] text-[#2C2C2A]' : 'bg-white text-[#2C2C2A]'
                        }`}
                        onMouseEnter={() => setMentionHighlight(idx)}
                        onMouseDown={(e) => {
                          // keep textarea focus
                          e.preventDefault();
                        }}
                        onClick={() => addLastMessageRefFromSession(s.id)}
                        disabled={mentionLoading}
                      >
                        <span className="min-w-0 flex-1 truncate">
                          {s.title || 'Untitled'}
                          {isCurrent ? <span className="ml-2 text-[11px] text-[#888780]">(current)</span> : null}
                        </span>
                        <span className="ml-3 text-xs text-[#888780]">Enter</span>
                      </button>
                    );
                  })
                )}
              </div>

              <div className="border-t border-[#EEEAE1] px-3 py-2 text-[11px] text-[#888780]">
                Type to filter · Enter to insert · Esc to close
              </div>
            </div>
          )}

          <textarea
            ref={textareaRef}
            rows={1}
            className={
              variant === 'center'
                ? 'block w-full flex-1 resize-none bg-transparent px-1 py-2.5 text-sm leading-relaxed text-[#2C2C2A] placeholder-[#888780] outline-none disabled:opacity-40'
                : 'block w-full resize-none rounded-xl border border-[#D3D1C8] bg-[#FAFAF8] px-4 py-3 text-sm text-[#2C2C2A] placeholder-[#888780] outline-none focus:border-[#888780] disabled:opacity-40'
            }
            style={{
              minHeight: variant === 'center' ? '96px' : '44px',
              maxHeight: variant === 'center' ? '240px' : '200px',
            }}
            placeholder={variant === 'center' ? 'Ask a question to get started…' : 'Ask all models a question…'}
            value={value}
            disabled={disabled}
            onFocus={() => {
              if (!disabled) {
                // no-op
              }
            }}
          onChange={(e) => {
            const next = e.target.value;
            onChange(next);
            adjustHeight();

            if (!mentionOpen) {
              return;
            }

            const start = mentionTokenStart;
            const cursor = e.target.selectionStart ?? null;
            if (start === null || cursor === null) {
              closeMention();
              return;
            }

            if (next[start] !== '@') {
              closeMention();
              return;
            }

            const token = next.slice(start + 1, cursor);
            if (/\s/.test(token)) {
              closeMention();
              return;
            }

            setMentionCursor(cursor);
            setMentionQuery(token);
            setMentionHighlight(0);
          }}
          onKeyDown={e => {
            if (disabled) {
              return;
            }

            if (mentionOpen) {
              if (e.key === 'Escape') {
                e.preventDefault();
                closeMention();
                return;
              }
              if (e.key === 'ArrowDown') {
                e.preventDefault();
                setMentionHighlight((h) => Math.min(filteredSessions.length - 1, h + 1));
                return;
              }
              if (e.key === 'ArrowUp') {
                e.preventDefault();
                setMentionHighlight((h) => Math.max(0, h - 1));
                return;
              }
              if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                const choice = filteredSessions[mentionHighlight];
                if (choice) {
                  addLastMessageRefFromSession(choice.id);
                }
                return;
              }
            }

            if (e.key === '@') {
              // open picker after @ is inserted
              const pos = textareaRef.current?.selectionStart ?? null;
              setMentionOpen(true);
              setMentionTokenStart(pos);
              setMentionCursor(pos !== null ? pos + 1 : null);
              setMentionQuery('');
              setMentionHighlight(0);
              setMentionError(null);

              queueMicrotask(() => {
                const el = textareaRef.current;
                if (!el) {
                  return;
                }
                setMentionCursor(el.selectionStart ?? null);
              });
            }

            // Enter submits. Shift+Enter inserts a newline.
            if (e.key === 'Enter' && !e.shiftKey) {
              e.preventDefault();
              if (isStreaming) {
                return;
              }
              onSend({ attachments, refs, webSearchMode });
            }
          }}
          />

          {variant === 'center' && (
            <div className="flex flex-shrink-0 items-end">
              {isStreaming ? (
                <button
                  type="button"
                  onClick={() => onCancel?.()}
                  disabled={!onCancel}
                  className="mt-[6px] flex h-10 w-10 items-center justify-center rounded-lg bg-[#D85A30] text-white transition-colors hover:bg-[#C74F28] disabled:opacity-40"
                  title="Cancel"
                  aria-label="Cancel"
                >
                  <CircleStop size={18} />
                </button>
              ) : (
                <button
                  type="button"
                  onClick={() => onSend({ attachments, refs, webSearchMode })}
                  disabled={!value.trim() || disabled}
                  className="mt-[6px] inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-[#2C2C2A] px-4 text-sm font-semibold text-white transition-colors hover:bg-[#404040] disabled:opacity-30"
                  title="Send"
                  aria-label="Send"
                >
                  Send
                  <ArrowUp size={16} />
                </button>
              )}
            </div>
          )}
        </div>
        {variant !== 'center' && (isStreaming ? (
          <button
            type="button"
            onClick={() => onCancel?.()}
            disabled={!onCancel}
            className="flex h-[44px] w-10 flex-shrink-0 items-center justify-center rounded-md bg-[#D85A30] text-white transition-colors hover:bg-[#C74F28] disabled:opacity-40 self-end"
            title="Cancel"
            aria-label="Cancel"
          >
            <CircleStop size={18} />
          </button>
        ) : (
          <button
            onClick={() => {
              onSend({ attachments, refs, webSearchMode });
            }}
            disabled={!value.trim() || disabled}
            className="flex h-[44px] w-10 flex-shrink-0 items-center justify-center rounded-md bg-[#2C2C2A] text-white transition-colors hover:bg-[#404040] disabled:opacity-30 self-end"
            title="Send"
            aria-label="Send"
            type="button"
          >
            <ArrowUp size={18} />
          </button>
        ))}
      </div>
      {variant !== 'center' && (
        <p className="mt-2 text-center text-xs text-[#888780]">
          {isStreaming
            ? 'Streaming… Click stop to cancel'
            : 'Enter to send · Shift + Enter for new line · Up to 3 attachments (10MB each)'}
        </p>
      )}
          </div>
        </div>
      </div>
    </>
  );
}
