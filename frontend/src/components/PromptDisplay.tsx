interface PromptDisplayProps {
  prompt: string;
  refs?: { sessionId: string; sessionTitle: string }[];
  webSources?: { title: string; url: string; snippet: string }[];
  webAnswer?: string;
}

export default function PromptDisplay({ prompt, refs = [], webSources = [], webAnswer }: PromptDisplayProps) {
  return (
    <div
      className="mx-3 mt-4 md:mx-6 md:mt-5"
      style={{
        borderRadius: "var(--radius-lg)",
        border: "1px solid var(--rule)",
        background: "var(--bg-elev)",
        padding: "16px 20px",
      }}
    >
      <p className="mb-1.5 text-xs font-semibold uppercase tracking-widest text-text-primary/50">
        Prompt
      </p>
      {refs.length > 0 && (
        <div className="mb-3 flex flex-wrap gap-2">
          {refs.map((r) => (
            <span
              key={r.sessionId}
              className="inline-flex items-center gap-1 rounded-full border border-[var(--rule)] bg-white/60 px-2 py-1 text-[11px] font-semibold text-text-primary/70"
              title="Referenced chat"
            >
              @ {r.sessionTitle}
            </span>
          ))}
        </div>
      )}
      {webSources.length > 0 && (
        <div className="mb-3 flex flex-col gap-1">
          <p className="text-[10px] font-semibold uppercase tracking-widest text-[#888780]">Web Sources</p>
          {webAnswer?.trim() && (
            <div className="text-xs text-[#2C2C2A]">
              <span className="font-semibold">Answer:</span> {webAnswer.trim()}
            </div>
          )}
          <div className="flex flex-wrap gap-2">
            {webSources.slice(0, 5).map((s, idx) => (
              <a
                key={s.url + idx}
                href={s.url}
                target="_blank"
                rel="noreferrer"
                className="inline-flex max-w-[100%] items-center gap-1 rounded-full border border-[var(--rule)] bg-white/60 px-2 py-1 text-[11px] font-semibold text-text-primary/70 hover:bg-white"
                title={s.title}
              >
                <span className="truncate">[{idx + 1}] {s.title || s.url}</span>
              </a>
            ))}
          </div>
        </div>
      )}
      <p className="text-sm leading-relaxed text-text-primary">{prompt}</p>
    </div>
  );
}
