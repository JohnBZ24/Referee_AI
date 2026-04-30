import { Trash2 } from 'lucide-react';
import type { Session } from '../types';

type SessionItemProps = Omit<Session, 'id'> & {
  collapsed?: boolean;
  isStreaming?: boolean;
  onSelect?: () => void;
  onDelete?: () => void;
};

export default function SessionItem({ title, date, active = false, collapsed = false, isStreaming = false, onSelect, onDelete }: SessionItemProps) {
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
      onClick={onSelect}
      onKeyDown={(e) => {
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
            <p className={`truncate text-sm leading-snug ${active ? 'font-medium text-[#2C2C2A]' : 'text-[#555450]'}`}>
              {title}
            </p>
            {isStreaming && (
              <span className="h-1.5 w-1.5 flex-shrink-0 animate-pulse rounded-full bg-[#D85A30]" title="Streaming..." />
            )}
          </div>
          <p className="mt-0.5 text-xs text-[#888780]">{date}</p>
        </div>

        {onDelete && (
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              onDelete();
            }}
            aria-label="Delete session"
            className="rounded p-1 text-[#888780] transition-colors hover:bg-white/60 hover:text-[#2C2C2A]"
          >
            <Trash2 size={14} />
          </button>
        )}
      </div>
    </div>
  );
}
