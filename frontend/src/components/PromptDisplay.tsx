interface PromptDisplayProps {
  prompt: string;
  refs?: { sessionId: string; sessionTitle: string }[];
}

export default function PromptDisplay({ prompt, refs = [] }: PromptDisplayProps) {
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
      <p className="text-sm leading-relaxed text-text-primary">{prompt}</p>
    </div>
  );
}
