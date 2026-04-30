import ModelPanel from './ModelPanel';
import type { RoundModelState } from '../types';

interface PanelGridProps {
  modelIds: string[];
  models: Record<string, RoundModelState>;
  displayNameForModelId: (modelId: string) => string;
}

// Responsive: 1 col mobile → 2 col tablet → 3 col desktop
export default function PanelGrid({ modelIds, models, displayNameForModelId }: PanelGridProps) {
  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
      {modelIds.map((modelId) => {
        const state = models[modelId] || { status: 'idle', content: '' };
        return (
          <ModelPanel
            key={modelId}
            modelName={displayNameForModelId(modelId)}
            status={state.status}
            content={state.content}
          />
        );
      })}
    </div>
  );
}
