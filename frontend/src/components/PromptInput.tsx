import { useRef, useEffect, useState } from 'react';
import { ArrowUp, Paperclip, X } from 'lucide-react';

interface InputAreaProps {
  value: string;
  onChange: (val: string) => void;
  onSend: (attachments: File[]) => void;
  disabled?: boolean;
  leftOffset?: number;
}

export default function InputArea({
  value,
  onChange,
  onSend,
  disabled = false,
  leftOffset = 0,
}: InputAreaProps) {
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [attachments, setAttachments] = useState<File[]>([]);

  function adjustHeight() {
    const el = textareaRef.current;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
  }

  // Reset height when value is cleared (after send)
  useEffect(() => {
    adjustHeight();
  }, [value]);

  useEffect(() => {
    // Clear attachments after send (value is cleared by parent)
    if (value === '') {
      setAttachments([]);
      if (fileInputRef.current) {
        fileInputRef.current.value = '';
      }
    }
  }, [value]);

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
    <div
      className="fixed bottom-0 right-0 z-20 border-t border-[#D3D1C8] bg-[#F5F3F0] p-4 transition-[left] duration-300"
      style={{ left: leftOffset }}
    >
      {attachments.length > 0 && (
        <div className="mb-3 flex flex-wrap gap-2">
          {attachments.map((f, idx) => (
            <div
              key={`${f.name}-${f.size}-${idx}`}
              className="flex items-center gap-2 rounded-md border border-[#D3D1C8] bg-white px-2 py-1.5 text-xs text-[#2C2C2A]"
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
          {attachments.length >= 3 && (
            <span className="self-center text-xs text-[#888780]">Max 3 files</span>
          )}
        </div>
      )}

      <div className="flex items-end gap-3">
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

        <button
          type="button"
          onClick={() => fileInputRef.current?.click()}
          disabled={disabled || attachments.length >= 3}
          className="flex h-[44px] w-10 flex-shrink-0 items-center justify-center rounded-md border border-[#D3D1C8] bg-white text-[#2C2C2A] transition-colors hover:bg-[#F9F8F6] disabled:opacity-40"
          title="Attach files"
        >
          <Paperclip size={18} />
        </button>

        <textarea
          ref={textareaRef}
          rows={1}
          className="flex-1 resize-none rounded-md border border-[#D3D1C8] bg-white px-3 py-2.5 text-sm text-[#2C2C2A] placeholder-[#888780] outline-none focus:border-[#888780] disabled:opacity-40"
          style={{ minHeight: '44px', maxHeight: '200px' }}
          placeholder="Ask all models a question…"
          value={value}
          disabled={disabled}
          onChange={e => { onChange(e.target.value); adjustHeight(); }}
          onKeyDown={e => {
            if (disabled) {
              return;
            }

            // Enter submits. Shift+Enter inserts a newline.
            if (e.key === 'Enter' && !e.shiftKey) {
              e.preventDefault();
              onSend(attachments);
            }
          }}
        />
        <button
          onClick={() => onSend(attachments)}
          disabled={!value.trim() || disabled}
          className="flex h-[44px] w-10 flex-shrink-0 items-center justify-center rounded-md bg-[#2C2C2A] text-white transition-colors hover:bg-[#404040] disabled:opacity-30"
        >
          <ArrowUp size={18} />
        </button>
      </div>
      <p className="mt-2 text-center text-xs text-[#888780]">
        Enter to send · Shift + Enter for new line · Up to 3 attachments (10MB each)
      </p>
    </div>
  );
}
