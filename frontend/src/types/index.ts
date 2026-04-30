export interface Session {
  id: string;
  title: string;
  date: string;
  active: boolean;

  model_set?: {
    panelists: string[];
  };

  referee_model?: string;

  messages?: Message[];
}

export type MessageRole = 'user' | 'panelist' | 'referee';

export interface Message {
  id: string;
  session_id: string;
  role: MessageRole;
  model_name?: string | null;
  panel_position?: number | null;
  content?: string | null;
  status?: string | null;
  tokens_used?: number | null;
  created_at?: string;
 }

export interface Model {
  id: string;
  name: string;
  provider: string;
  model_id: string;
}

export type ModelStatus = 'idle' | 'streaming' | 'complete';

export interface ChatLine {
  id?: string;
  role: 'user' | 'model';
  content: string;
  status?: ModelStatus;
  tokens?: number;
}

export interface SSEEvent {
  event: 'panelist_chunk' | 'panelist_complete' | 'panelist_error' | 'referee_start' | 'referee_chunk' | 'referee_complete' | 'done';
  data: any;
}

export interface RoundModelState {
  status: ModelStatus;
  content: string;
  messageId?: string;
  tokens?: number;
}

export interface SessionRound {
  id: string;
  prompt: string;
  createdAt: number;
  modelIds: string[];
  models: Record<string, RoundModelState>;
  verdictText: string;
  verdictReady: boolean;
}
