import { apiClient } from './apiClient';
import type { Message, Session } from '../types';

export interface Model {
  id: string;
  name: string;
  provider: string;
  model_id: string;
}

// Transform Laravel API format to frontend format
function cleanSessionTitle(title: any): string {
  if (typeof title !== 'string') {
    return 'New Session';
  }

  let t = title.trim();
  if (!t) {
    return 'New Session';
  }

  // Handle accidental JSON-ish titles like {"title":"..."}
  if (t.startsWith('{') && t.includes('"title"')) {
    try {
      const obj = JSON.parse(t);
      if (obj && typeof obj.title === 'string') {
        t = obj.title.trim();
      }
    } catch {
      // ignore
    }
  }

  // Strip common wrappers
  t = t.replace(/^[\s[{]+/, '').replace(/[\s}\]]+$/, '').trim();
  t = t.replace(/^["'`]+|["'`]+$/g, '').trim();

  return t || 'New Session';
}

function transformSession(apiSession: any): Session {
  return {
    id: String(apiSession.id || ''),
    title: cleanSessionTitle(apiSession.title),
    date: apiSession.created_at ? new Date(apiSession.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : 'Apr 23',
    active: false, // Will be set by the app logic

    model_set: apiSession.model_set,
    referee_model: apiSession.referee_model,
    messages: Array.isArray(apiSession.messages) ? apiSession.messages.map(transformMessage) : undefined,
  };
}

function transformMessage(apiMessage: any): Message {
  return {
    id: String(apiMessage.id || ''),
    session_id: String(apiMessage.session_id || ''),
    role: apiMessage.role,
    model_name: apiMessage.model_name ?? null,
    panel_position: apiMessage.panel_position ?? null,
    content: apiMessage.content ?? null,
    status: apiMessage.status ?? null,
    tokens_used: apiMessage.tokens_used ?? null,
    created_at: apiMessage.created_at,
  };
}

export const authAPI = {
  async login(email: string, password: string) {
    const response = await apiClient.post('/auth/login', { email, password });
    return response.data;
  },

  async register(email: string, password: string, name: string) {
    const response = await apiClient.post('/auth/register', { email, password, name });
    return response.data;
  },

  async logout() {
    await apiClient.post('/auth/logout');
    localStorage.removeItem('auth_token');
  },

  async me() {
    const response = await apiClient.get('/auth/me');
    return response.data;
  },
};

export const modelsAPI = {
  async list(): Promise<Model[]> {
    const response = await apiClient.get('/models');
    return response.data;
  },
};

export const sessionsAPI = {
  async list(): Promise<Session[]> {
    const response = await apiClient.get('/sessions');
    // Handle Laravel resource collection format
    const sessions = response.data?.data || response.data || [];
    return sessions.map(transformSession);
  },

  async create(): Promise<Session> {
    const response = await apiClient.post('/sessions');
    // Handle Laravel resource format
    const session = response.data?.data || response.data;
    return transformSession(session);
  },

  async get(id: string): Promise<Session> {
    const response = await apiClient.get(`/sessions/${id}`);
    const session = response.data?.data || response.data;
    return transformSession(session);
  },

  async delete(id: string): Promise<void> {
    await apiClient.delete(`/sessions/${id}`);
  },

  async update(id: string, data: { title?: string; model_set?: { panelists: string[] }; referee_model?: string }): Promise<Session> {
    const response = await apiClient.patch(`/sessions/${id}`, data);
    const session = response.data?.data || response.data;
    return transformSession(session);
  },
};

export interface PromptResponse {
  event: 'web_sources' | 'panelist_chunk' | 'panelist_complete' | 'panelist_error' | 'referee_start' | 'referee_chunk' | 'referee_complete' | 'done';
  data: any;
}

export type HiddenContextRef = {
  session_id: string;
};

export const promptAPI = {
  async submit(
    sessionId: string,
    prompt: string,
    onEvent: (event: PromptResponse) => void,
    roundId?: string,
    signal?: AbortSignal,
    attachments?: File[],
    refs?: HiddenContextRef[],
    webSearchMode: 'auto' | 'on' | 'off' = 'auto',
  ): Promise<void> {
    const url = `${apiClient.defaults.baseURL}/sessions/${sessionId}/prompt`;

    const hasAttachments = Array.isArray(attachments) && attachments.length > 0;
    const refsPayload = Array.isArray(refs) && refs.length > 0 ? { references: refs.slice(0, 3) } : null;

    const body = hasAttachments
      ? (() => {
          const fd = new FormData();
          fd.append('prompt', prompt);
          if (roundId) {
            fd.append('round_id', roundId);
          }
          if (webSearchMode) {
            fd.append('web_search_mode', webSearchMode);
          }
          if (refsPayload) {
            fd.append('context_json', JSON.stringify(refsPayload));
          }
          for (const f of attachments.slice(0, 3)) {
            fd.append('attachments[]', f);
          }
          return fd;
        })()
      : JSON.stringify({
          prompt,
          round_id: roundId,
          web_search_mode: webSearchMode,
          context_json: refsPayload ? JSON.stringify(refsPayload) : undefined,
        });

    const response = await fetch(url, {
      method: 'POST',
      headers: hasAttachments ? undefined : { 'Content-Type': 'application/json' },
      body,
      signal,
    });

    if (!response.ok) {
      const errorText = await response.text();
      console.error('[API] HTTP error response:', errorText);
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    const reader = response.body?.getReader();
    if (!reader) {
      throw new Error('Response body is not readable');
    }

    const decoder = new TextDecoder();
    let buffer = '';

    function parseSseBlock(block: string): { event: string; data: any } | null {
      const norm = block.replace(/\r\n/g, '\n').trim();
      if (!norm) return null;

      const lines = norm.split('\n');
      let event: string | undefined;
      const dataLines: string[] = [];

      for (const l of lines) {
        if (l.startsWith(':')) continue; // comment/keepalive
        if (l.startsWith('event:')) {
          event = l.slice('event:'.length).trim();
          continue;
        }
        if (l.startsWith('data:')) {
          dataLines.push(l.slice('data:'.length).trim());
        }
      }

      if (!event || dataLines.length === 0) {
        return null;
      }

      const dataText = dataLines.join('\n');
      try {
        return { event, data: JSON.parse(dataText) };
      } catch {
        return null;
      }
    }

    try {
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, { stream: true });

        // Support both LF and CRLF SSE separators
        const blocks = buffer.split(/\r?\n\r?\n/);
        buffer = blocks.pop() || '';

        for (const block of blocks) {
          const parsed = parseSseBlock(block);
          if (!parsed) continue;
          const validEvents = [
            'web_sources',
            'panelist_chunk',
            'panelist_complete',
            'panelist_error',
            'referee_start',
            'referee_chunk',
            'referee_complete',
            'done',
          ];
          if (validEvents.includes(parsed.event)) {
            onEvent({ event: parsed.event as any, data: parsed.data });
          }
        }
      }
    } finally {
      reader.releaseLock();
    }
  },
};
