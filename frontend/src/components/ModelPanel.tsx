// Displays a single AI model's name, streaming status, and response text.
import React from "react";

type Status = "idle" | "streaming" | "complete";

interface ModelPanelProps {
  modelName: string;
  status: Status;
  response: string;
}

const statusConfig: Record<Status, { label: string; classes: string }> = {
  idle: { label: "Idle", classes: "bg-gray-100 text-gray-500" },
  streaming: { label: "Streaming", classes: "bg-orange-100 text-orange-600" },
  complete: { label: "Complete", classes: "bg-green-100 text-green-600" },
};

export default function ModelPanel({ modelName, status, response }: ModelPanelProps) {
  const { label, classes } = statusConfig[status];

  return (
    <div className="rounded-2xl border border-gray-200 bg-gray-50 p-5 shadow-sm">
      {/* Header row: model name + status badge */}
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-lg font-semibold text-gray-800">{modelName}</h2>
        <span className={`rounded-full px-3 py-0.5 text-sm font-medium ${classes}`}>
          {label}
        </span>
      </div>

      {/* Response box */}
      <div className="min-h-[6rem] rounded-xl border border-gray-200 bg-white p-4 text-sm leading-relaxed text-gray-700">
        {response}
        {/* Blinking cursor shown only while streaming */}
        {status === "streaming" && (
          <span className="ml-0.5 inline-block w-0.5 animate-pulse bg-orange-500 align-middle text-transparent">
            |
          </span>
        )}
      </div>
    </div>
  );
}
