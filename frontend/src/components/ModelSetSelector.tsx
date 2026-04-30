interface ModelSelectorProps {
  models: string[];
  onChangeModels?: () => void;
}

export default function ModelSelector({ models, onChangeModels }: ModelSelectorProps) {
  return (
    <div className="border-b border-[#D3D1C8] bg-white px-5 py-3">
      <div className="flex flex-wrap items-center justify-between gap-3">

        {/* "MODELS: Model A | Model B | Model C" */}
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-xs font-semibold uppercase tracking-widest text-[#888780]">
            Models:
          </span>
          <span className="text-sm text-[#2C2C2A]">
            {models.join(' | ')}
          </span>
        </div>

        {/* Gray "Change Models" button — explicitly not green */}
        <button
          type="button"
          onClick={onChangeModels}
          className="rounded-md border border-[#D3D1C8] bg-white px-3 py-1.5 text-xs font-medium text-[#888780] transition-colors hover:bg-[#F5F3F0] hover:text-[#2C2C2A]"
        >
          Change Models
        </button>

      </div>
    </div>
  );
}
