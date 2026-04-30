import type { ModelStatus } from '../types';
import { Streamdown } from 'streamdown';

interface ModelPanelProps {
  modelName: string;
  status: ModelStatus;
  content: string;
}

const STATUS: Record<ModelStatus, { dot: string; label: string; labelColor: string }> = {
  idle:      { dot: 'bg-[#D3D1C8]',                       label: 'Idle',      labelColor: 'text-[#888780]' },
  streaming: { dot: 'bg-[#D85A30] animate-pulse',          label: 'Streaming', labelColor: 'text-[#D85A30]' },
  complete:  { dot: 'bg-[#2C2C2A]',                        label: 'Complete',  labelColor: 'text-[#2C2C2A]' },
};

export default function ModelPanel({ modelName, status, content }: ModelPanelProps) {
  const { dot, label, labelColor } = STATUS[status];

  return (
    <div className="flex min-h-[300px] flex-col rounded-md border border-[#D3D1C8] bg-white p-4">
      {/* Header */}
      <div className="mb-3 flex items-center justify-between">
        <h3 className="text-sm font-semibold text-[#2C2C2A]">{modelName}</h3>
        <div className="flex items-center gap-1.5">
          <span className={`h-[7px] w-[7px] flex-shrink-0 rounded-full ${dot}`} />
          <span className={`text-[11px] font-medium ${labelColor}`}>{label}</span>
        </div>
      </div>

      {/* Response */}
      <div className="flex-1 overflow-y-auto rounded-md bg-[#F9F8F6] p-3 text-sm leading-relaxed text-[#2C2C2A]">
        {content ? (
          <Streamdown animated isAnimating={status === 'streaming'}>
            {content}
          </Streamdown>
        ) : (
          <span className="text-[#888780]">Waiting for response…</span>
        )}
        {status === 'streaming' && (
          <span className="ml-px inline-block h-[1em] w-0.5 animate-pulse bg-[#D85A30] align-text-bottom" />
        )}
      </div>
    </div>
  );
}
