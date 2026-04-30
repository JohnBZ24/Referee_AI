import { Award } from 'lucide-react';
import { Streamdown } from 'streamdown';

interface RefereeVerdictProps {
  verdict: string;
  isStreaming?: boolean;
}

export default function RefereeVerdict({ verdict, isStreaming = false }: RefereeVerdictProps) {
  const safeVerdict = verdict?.trim() ? verdict : 'Analyzing responses...';

  return (
    <div className="mt-5 rounded-md border border-[#D3D1C8] bg-[#F9F8F6] p-4">
      <div className="mb-3 flex items-center gap-2">
        <Award size={16} className="flex-shrink-0 text-[#A8851F]" />
        <span className="text-[10px] font-semibold uppercase tracking-widest text-[#A8851F]">
          Referee Verdict
          {isStreaming && (
            <span className="ml-2 inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-[#D85A30]" />
          )}
        </span>
      </div>
      <div className="text-sm leading-relaxed text-[#2C2C2A]">
        <Streamdown animated isAnimating={isStreaming}>
          {safeVerdict}
        </Streamdown>
        {isStreaming && (
          <span className="ml-px inline-block h-[1em] w-0.5 animate-pulse bg-[#D85A30] align-text-bottom" />
        )}
      </div>
    </div>
  );
}
