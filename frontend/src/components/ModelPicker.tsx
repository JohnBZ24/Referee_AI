import { useEffect, useState } from 'react';
import { X, Check } from 'lucide-react';
import type { Model } from '../types';

interface ModelPickerProps {
  isOpen: boolean;
  onClose: () => void;
  models: Model[];
  currentPanelists: string[];
  currentReferee: string;
  onSave: (panelists: string[], referee: string) => void;
}

export default function ModelPicker({
  isOpen,
  onClose,
  models,
  currentPanelists,
  currentReferee,
  onSave,
}: ModelPickerProps) {
  const [selectedPanelists, setSelectedPanelists] = useState<string[]>(currentPanelists);
  const [selectedReferee, setSelectedReferee] = useState<string>(currentReferee);

  useEffect(() => {
    if (!isOpen) {
      return;
    }
    setSelectedPanelists(currentPanelists);
    setSelectedReferee(currentReferee);
  }, [isOpen, currentPanelists, currentReferee]);

  if (!isOpen) return null;

  const togglePanelist = (modelId: string) => {
    setSelectedPanelists((prev) => {
      if (prev.includes(modelId)) {
        return prev.filter((id) => id !== modelId);
      }
      if (prev.length >= 5) return prev; // Max 5 panelists
      return [...prev, modelId];
    });
  };

  const handleSave = () => {
    if (selectedPanelists.length < 1) return;
    onSave(selectedPanelists, selectedReferee);
    onClose();
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) {
          onClose();
        }
      }}
    >
      <div className="flex w-full max-w-lg flex-col overflow-hidden rounded-lg border border-[#D3D1C8] bg-white shadow-xl max-h-[90vh]">
        <div className="flex items-center justify-between border-b border-[#D3D1C8] px-6 py-4">
          <h2 className="text-lg font-semibold text-[#2C2C2A]">Change Models</h2>
          <button
            onClick={onClose}
            className="rounded p-1 text-[#888780] hover:bg-[#F5F3F0] hover:text-[#2C2C2A]"
          >
            <X size={18} />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto px-6 py-5">
          <div className="mb-6">
          <h3 className="mb-2 text-sm font-medium text-[#2C2C2A]">
            Panelists ({selectedPanelists.length} selected, max 5)
          </h3>
          <div className="flex flex-col gap-2">
            {models.map((model) => {
              const isSelected = selectedPanelists.includes(model.id);
              return (
                <button
                  key={model.id}
                  onClick={() => togglePanelist(model.id)}
                  className={`flex items-center gap-3 rounded-md border px-3 py-2.5 text-left transition-colors ${
                    isSelected
                      ? 'border-[#2C2C2A] bg-[#F5F3F0]'
                      : 'border-[#D3D1C8] hover:bg-[#F9F8F6]'
                  }`}
                >
                  <div
                    className={`flex h-5 w-5 items-center justify-center rounded border ${
                      isSelected ? 'border-[#2C2C2A] bg-[#2C2C2A]' : 'border-[#D3D1C8]'
                    }`}
                  >
                    {isSelected && <Check size={12} className="text-white" />}
                  </div>
                  <div className="flex-1">
                    <p className="text-sm font-medium text-[#2C2C2A]">{model.name}</p>
                    <p className="text-xs text-[#888780]">{model.provider}</p>
                  </div>
                </button>
              );
            })}
          </div>
          </div>

          <div className="mb-6">
          <h3 className="mb-2 text-sm font-medium text-[#2C2C2A]">Referee</h3>
          <div className="flex flex-col gap-2">
            {models.map((model) => {
              const isSelected = selectedReferee === model.id;
              return (
                <button
                  key={model.id}
                  onClick={() => setSelectedReferee(model.id)}
                  className={`flex items-center gap-3 rounded-md border px-3 py-2.5 text-left transition-colors ${
                    isSelected
                      ? 'border-[#A8851F] bg-[#FDF8E8]'
                      : 'border-[#D3D1C8] hover:bg-[#F9F8F6]'
                  }`}
                >
                  <div
                    className={`flex h-5 w-5 items-center justify-center rounded-full border ${
                      isSelected ? 'border-[#A8851F] bg-[#A8851F]' : 'border-[#D3D1C8]'
                    }`}
                  >
                    {isSelected && <div className="h-2 w-2 rounded-full bg-white" />}
                  </div>
                  <div className="flex-1">
                    <p className="text-sm font-medium text-[#2C2C2A]">{model.name}</p>
                    <p className="text-xs text-[#888780]">{model.provider}</p>
                  </div>
                </button>
              );
            })}
          </div>
          </div>
        </div>

        <div className="flex items-center justify-end gap-3 border-t border-[#D3D1C8] bg-white px-6 py-4">
          <button
            type="button"
            onClick={onClose}
            className="rounded-md border border-[#D3D1C8] px-4 py-2 text-sm font-medium text-[#888780] transition-colors hover:bg-[#F5F3F0] hover:text-[#2C2C2A]"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handleSave}
            disabled={selectedPanelists.length < 1}
            className="rounded-md bg-[#2C2C2A] px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-[#404040] disabled:opacity-40"
          >
            Save Changes
          </button>
        </div>
      </div>
    </div>
  );
}
