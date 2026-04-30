interface PromptDisplayProps {
  prompt: string;
}

export default function PromptDisplay({ prompt }: PromptDisplayProps) {
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
      <p className="text-sm leading-relaxed text-text-primary">{prompt}</p>
    </div>
  );
}
