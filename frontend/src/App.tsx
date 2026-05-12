import { useState, useEffect, useRef, useCallback } from 'react';
import { Menu } from 'lucide-react';
import Sidebar from './components/Sidebar';
import ModelSelector from './components/ModelSetSelector';
import ModelPicker from './components/ModelPicker';
import PromptDisplay from './components/PromptDisplay';
import PanelGrid from './components/PanelGrid';
import RefereeVerdict from './components/RefereeVerdict';
import InputArea from './components/PromptInput';
import type { HiddenSessionMessageRef, WebSearchMode } from './components/PromptInput';
import { modelsAPI, sessionsAPI, promptAPI } from './lib/api';
import type { Model, SSEEvent, Session, SessionRound } from './types';

export default function App() {
  const [sessions, setSessions] = useState<Session[]>([]);
  const [currentSession, setCurrentSession] = useState<Session | null>(null);
  const [models, setModels] = useState<Model[]>([]);

  // Per-session UI state
  const [sessionRounds, setSessionRounds] = useState<Record<string, SessionRound[]>>({});

  const [inputValue, setInputValue] = useState('');
  const [loading, setLoading] = useState(true);
  const [modelPickerOpen, setModelPickerOpen] = useState(false);

  // Track active streaming requests per session (survives re-renders)
  const activeStreamsRef = useRef<Map<string, AbortController>>(new Map());

  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [sidebarResizing, setSidebarResizing] = useState(false);

  const [showDockedComposer, setShowDockedComposer] = useState(true);

  const [pinnedSessionIds, setPinnedSessionIds] = useState<Set<string>>(() => {
    if (typeof window === 'undefined') {
      return new Set();
    }
    try {
      const raw = window.localStorage.getItem('refereeai.pinnedSessions');
      const ids = raw ? (JSON.parse(raw) as unknown) : [];
      if (!Array.isArray(ids)) {
        return new Set();
      }
      return new Set(ids.map((x) => String(x)).filter(Boolean));
    } catch {
      return new Set();
    }
  });

  const SIDEBAR_MIN = 240;
  const SIDEBAR_MAX = 460;
  const SIDEBAR_DEFAULT = 280;

  const [sidebarWidth, setSidebarWidth] = useState(() => {
    if (typeof window === 'undefined') {
      return SIDEBAR_DEFAULT;
    }
    const raw = window.localStorage.getItem('refereeai.sidebarWidth');
    const n = raw ? Number(raw) : SIDEBAR_DEFAULT;
    if (!Number.isFinite(n)) {
      return SIDEBAR_DEFAULT;
    }
    return Math.max(SIDEBAR_MIN, Math.min(SIDEBAR_MAX, Math.round(n)));
  });
  const [windowWidth, setWindowWidth] = useState(
    typeof window !== 'undefined' ? window.innerWidth : 1280
  );

  useEffect(() => {
    const onResize = () => setWindowWidth(window.innerWidth);
    window.addEventListener('resize', onResize);
    return () => window.removeEventListener('resize', onResize);
  }, []);

  const isMobile = windowWidth < 768;
  const sidebarPx = sidebarCollapsed ? 70 : sidebarWidth;
  const contentLeft = isMobile ? 0 : sidebarPx;

  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }
    window.localStorage.setItem('refereeai.sidebarWidth', String(sidebarWidth));
  }, [sidebarWidth]);

  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }
    window.localStorage.setItem('refereeai.pinnedSessions', JSON.stringify([...pinnedSessionIds]));
  }, [pinnedSessionIds]);

  useEffect(() => {
    initializeApp().catch(err => {
      console.error('App initialization failed:', err);
      setLoading(false);
    });
  }, []);

  useEffect(() => {
    const sid = currentSession?.id || '';
    if (!sid) {
      setShowDockedComposer(true);
      return;
    }
    const roundsArr = sessionRounds[sid] || [];
    setShowDockedComposer(roundsArr.length > 0);
  }, [currentSession?.id, sessionRounds]);

  async function initializeApp() {
    try {
      setLoading(true);
      const [modelsData, sessionsData] = await Promise.all([
        modelsAPI.list(),
        sessionsAPI.list(),
      ]);

      setModels(modelsData);
      
      if (sessionsData.length > 0) {
        setSessions(sessionsData.map((s, i) => ({ ...s, active: i === 0 })));
        const activeSession = sessionsData[0];
        setCurrentSession({ ...activeSession, active: true });
        ensureSessionState(activeSession);
        sessionsAPI.get(activeSession.id).then((full) => {
          if (full.messages) {
            hydrateFromMessages(full, full.messages);
          }
        }).catch(() => {});
      } else {
        await handleNewSession();
      }
    } catch (error) {
      console.error('Failed to initialize app:', error);
      // Show error UI but let the app continue
      setModels([]); // Set empty models so UI can render
      setSessions([]);
    } finally {
      setLoading(false);
    }
  }

  function ensureSessionState(session: Session) {
    const sid = session.id;

    setSessionRounds((prev) => (prev[sid] ? prev : { ...prev, [sid]: [] }));
  }

  function hydrateFromMessages(session: Session, messages: any[]) {
    const sid = session.id;
    const panelists = session.model_set?.panelists || [];

    const sorted = [...messages].sort((a, b) => (a.created_at || '').localeCompare(b.created_at || ''));

    // Group by round_id if present, otherwise by user-message boundaries.
    const rounds: SessionRound[] = [];
    const roundMap = new Map<string, SessionRound>();

    function ensureRound(id: string, prompt: string): SessionRound {
      const existing = roundMap.get(id);
      if (existing) {
        return existing;
      }
      const models: SessionRound['models'] = {};
      for (const mid of panelists) {
        models[mid] = { status: 'idle', content: '' };
      }
      const r: SessionRound = {
        id,
        prompt,
        createdAt: Date.now(),
        modelIds: panelists,
        models,
        verdictText: '',
        verdictReady: false,
      };
      roundMap.set(id, r);
      rounds.push(r);
      return r;
    }

    let currentLegacyRoundId: string | null = null;
    let currentLegacyPrompt = '';

    for (const m of sorted) {
      const rid = m.round_id ? String(m.round_id) : '';

      if (!rid) {
        // Legacy: create a new round on each user message.
        if (m.role === 'user') {
          currentLegacyRoundId = String(m.id);
          currentLegacyPrompt = m.content || '';
          ensureRound(currentLegacyRoundId, currentLegacyPrompt);
          continue;
        }

        if (!currentLegacyRoundId) {
          continue;
        }

        const r = ensureRound(currentLegacyRoundId, currentLegacyPrompt);
        if (m.role === 'panelist' && m.model_name && r.models[m.model_name]) {
          r.models[m.model_name] = {
            ...r.models[m.model_name],
            status: m.status === 'streaming' ? 'streaming' : 'complete',
            content: m.content || '',
            messageId: String(m.id),
            tokens: m.tokens_used ?? undefined,
          };
        }

        if (m.role === 'referee') {
          r.verdictText = m.content || '';
          r.verdictReady = true;
        }

        continue;
      }

      // round_id present: use it.
      const r = ensureRound(rid, '');
      if (m.role === 'user') {
        r.prompt = m.content || '';
      }
      if (m.role === 'panelist' && m.model_name && r.models[m.model_name]) {
        r.models[m.model_name] = {
          ...r.models[m.model_name],
          status: m.status === 'streaming' ? 'streaming' : 'complete',
          content: m.content || '',
          messageId: String(m.id),
          tokens: m.tokens_used ?? undefined,
        };
      }
      if (m.role === 'referee') {
        r.verdictText = m.content || '';
        r.verdictReady = true;
      }
    }

    setSessionRounds((prev) => ({ ...prev, [sid]: rounds }));
  }

  function newId(): string {
    return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
  }

  function handleNewSession() {
    return sessionsAPI.create().then(newSession => {
      setSessions(prev => [{ ...newSession, active: true }, ...prev.map((s) => ({ ...s, active: false }))]);
      setCurrentSession({ ...newSession, active: true });
      ensureSessionState(newSession);
      setSidebarOpen(false);
    });
  }

  function handleSelectSession(sessionId: string) {
    setSessions((prev) => {
      const selected = prev.find((s) => s.id === sessionId);
      if (selected) {
        setCurrentSession({ ...selected, active: true });
        ensureSessionState(selected);

        sessionsAPI.get(sessionId).then((full) => {
          if (full.messages) {
            hydrateFromMessages(full, full.messages);
          }
        }).catch(() => {});
      } else {
        sessionsAPI.get(sessionId)
          .then((s) => {
            setCurrentSession({ ...s, active: true });
            ensureSessionState(s);
            if (s.messages) {
              hydrateFromMessages(s, s.messages);
            }
          })
          .catch((e) => console.error('Failed to load session', e));
      }

      return prev.map((s) => ({ ...s, active: s.id === sessionId }));
    });
  }

  async function handleDeleteSession(sessionId: string) {
    try {
      await sessionsAPI.delete(sessionId);
      setSessions((prev) => prev.filter((s) => s.id !== sessionId));

      setCurrentSession((prev) => {
        if (!prev || prev.id !== sessionId) {
          return prev;
        }

        const remaining = sessions.filter((s) => s.id !== sessionId);
        const next = remaining[0] || null;
        if (next) {
          handleSelectSession(next.id);
        } else {
          // Create a fresh session if none remain.
          handleNewSession();
        }
        return next;
      });
    } catch (e) {
      console.error('Failed to delete session', e);
    }
  }

  async function handleModelChange(panelists: string[], referee: string) {
    if (!currentSession) return;
    try {
      const updated = await sessionsAPI.update(currentSession.id, {
        model_set: { panelists },
        referee_model: referee,
      });
      setCurrentSession({ ...updated, active: true });
      setSessions((prev) => prev.map((s) => (s.id === updated.id ? { ...s, model_set: updated.model_set, referee_model: updated.referee_model } : s)));
    } catch (e) {
      console.error('Failed to update models:', e);
      alert('Failed to update models. Please try again.');
    }
  }

  function displayNameForModelId(modelId: string): string {
    const model = models.find(m => m.id === modelId);
    return model?.name || modelId;
  }

  async function handleSend(payload: { attachments: File[]; refs: HiddenSessionMessageRef[]; webSearchMode: WebSearchMode }) {
    const attachments = Array.isArray(payload.attachments) ? payload.attachments : [];
    const refs = Array.isArray(payload.refs) ? payload.refs : [];
    const webSearchMode = payload.webSearchMode || 'auto';
    const prompt = inputValue.trim();

    if (!prompt || !currentSession) {
      console.error('Cannot send: missing prompt or session');
      return;
    }

    if (!currentSession.id) {
      console.error('Session ID is undefined');
      alert('Error: Session not initialized. Please create a new session.');
      return;
    }

    const sid = currentSession.id;
    const panelists = currentSession.model_set?.panelists || [];

    ensureSessionState(currentSession);

    // If we're in the "empty session" centered composer state, dock the composer before streaming starts.
    setShowDockedComposer(true);

    setInputValue('');

    const roundId = newId();

    const models: SessionRound['models'] = {};
    for (const mid of panelists) {
      models[mid] = { status: 'streaming', content: '' };
    }

    const round: SessionRound = {
      id: roundId,
      prompt,
      createdAt: Date.now(),
      refs: refs.map((r) => ({ sessionId: r.sessionId, sessionTitle: r.sessionTitle })),
      modelIds: panelists,
      models,
      verdictText: '',
      verdictReady: false,
    };

    setSessionRounds((prev) => ({
      ...prev,
      [sid]: [...(prev[sid] || []), round],
    }));

    // Cancel any existing stream for this session (shouldn't happen, but safety)
    const existing = activeStreamsRef.current.get(sid);
    if (existing) {
      existing.abort();
      activeStreamsRef.current.delete(sid);
    }

    const controller = new AbortController();
    activeStreamsRef.current.set(sid, controller);

    try {
      await promptAPI.submit(
        currentSession.id,
        prompt,
        (event: SSEEvent) => {
          handleSSEEvent(event);
        },
        roundId,
        controller.signal,
        attachments,
        refs.map((r) => ({ session_id: String(r.sessionId || '') })).filter((r) => r.session_id),
        webSearchMode,
      );
    } catch (error: any) {
      if (error.name === 'AbortError') {
        // ignore
      } else {
        console.error('Prompt submission failed:', error);
        setSessionRounds((prev) => {
          const rounds = [...(prev[sid] || [])];
          const idx = rounds.findIndex((r) => r.id === roundId);
          if (idx < 0) {
            return prev;
          }
          const r = rounds[idx];
          const nextModels = { ...r.models };
          for (const mid of r.modelIds) {
            const st = nextModels[mid];
            nextModels[mid] = {
              ...st,
              status: 'complete',
              content: st?.content || 'Error: Failed to get response',
            };
          }
          rounds[idx] = { ...r, models: nextModels, verdictReady: true, verdictText: 'Error: prompt submission failed' };
          return { ...prev, [sid]: rounds };
        });
      }
    } finally {
      activeStreamsRef.current.delete(sid);
    }
  }

  function handleCancelPrompt() {
    if (!currentSession?.id) {
      return;
    }

    const sid = currentSession.id;
    const ctrl = activeStreamsRef.current.get(sid);
    if (ctrl) {
      ctrl.abort();
      activeStreamsRef.current.delete(sid);
    }

    setSessionRounds((prev) => {
      const roundsArr = [...(prev[sid] || [])];
      if (roundsArr.length === 0) {
        return prev;
      }

      const idx = roundsArr.length - 1;
      const r = roundsArr[idx];

      const nextModels = { ...r.models };
      for (const mid of r.modelIds) {
        const st = nextModels[mid];
        if (!st) {
          continue;
        }
        if (st.status === 'streaming') {
          nextModels[mid] = {
            ...st,
            status: 'complete',
            content: (st.content || '').trim() ? `${st.content}\n\n[Cancelled]` : '[Cancelled]',
          };
        }
      }

      roundsArr[idx] = {
        ...r,
        models: nextModels,
        verdictReady: true,
        verdictText: r.verdictText || '[Cancelled]',
      };

      return { ...prev, [sid]: roundsArr };
    });
  }

  const handleSSEEvent = useCallback((event: SSEEvent) => {
    const sid = String(event.data?.session_id || '');
    if (!sid) {
      return;
    }

    // Always use round_id from event data (sent by backend) - never rely on stale React state
    const roundId = String(event.data?.round_id || '');
    if (!roundId) {
      return;
    }

    switch (event.event) {
      case 'web_sources':
        setSessionRounds((prev) => {
          const rounds = [...(prev[sid] || [])];
          const idx = rounds.findIndex((r) => r.id === roundId);
          if (idx < 0) {
            return prev;
          }
          const r = rounds[idx];
          const sources = Array.isArray(event.data.sources) ? event.data.sources : [];
          rounds[idx] = {
            ...r,
            webSources: sources.map((s: any) => ({
              title: String(s?.title || ''),
              url: String(s?.url || ''),
              snippet: String(s?.snippet || ''),
            })).filter((s: any) => s.url),
          };
          return { ...prev, [sid]: rounds };
        });
        break;
      case 'panelist_chunk':
        if (!roundId) {
          break;
        }
        setSessionRounds((prev) => {
          const rounds = [...(prev[sid] || [])];
          const idx = rounds.findIndex((r) => r.id === roundId);
          if (idx < 0) {
            return prev;
          }

          const mid = event.data.model_name;
          if (!mid) {
            return prev;
          }

          const r = rounds[idx];
          const state = r.models[mid];
          if (!state) {
            return prev;
          }
          if (state.status === 'complete') {
            return prev;
          }

          const nextRound: SessionRound = {
            ...r,
            models: {
              ...r.models,
              [mid]: {
                ...state,
                status: 'streaming',
                content: (state.content || '') + (event.data.content || ''),
                messageId: String(event.data.message_id),
              },
            },
          };

          rounds[idx] = nextRound;
          return { ...prev, [sid]: rounds };
        });
        break;

      case 'panelist_complete':
        if (!roundId) {
          break;
        }
        setSessionRounds((prev) => {
          const rounds = [...(prev[sid] || [])];
          const idx = rounds.findIndex((r) => r.id === roundId);
          if (idx < 0) {
            return prev;
          }
          const mid = event.data.model_name;
          if (!mid) {
            return prev;
          }
          const r = rounds[idx];
          const state = r.models[mid];
          if (!state) {
            return prev;
          }
          rounds[idx] = {
            ...r,
            models: {
              ...r.models,
              [mid]: {
                ...state,
                status: 'complete',
                tokens: event.data.tokens,
                messageId: String(event.data.message_id),
              },
            },
          };
          return { ...prev, [sid]: rounds };
        });
        break;

      case 'panelist_error':
        if (!roundId) {
          break;
        }
        setSessionRounds((prev) => {
          const rounds = [...(prev[sid] || [])];
          const idx = rounds.findIndex((r) => r.id === roundId);
          if (idx < 0) {
            return prev;
          }
          const mid = event.data.model_name;
          if (!mid) {
            return prev;
          }
          const r = rounds[idx];
          const state = r.models[mid];
          if (!state) {
            return prev;
          }
          const msg =
            typeof event.data.user_message === 'string' && event.data.user_message.trim() !== ''
              ? event.data.user_message
              : (() => {
                  const httpCode = event.data.http_code;
                  const err = event.data.error;
                  return `Error: HTTP ${httpCode}${err ? ` - ${err}` : ''}`;
                })();
          rounds[idx] = {
            ...r,
            models: {
              ...r.models,
              [mid]: {
                ...state,
                status: 'complete',
                content: msg,
                messageId: String(event.data.message_id),
              },
            },
          };
          return { ...prev, [sid]: rounds };
        });
        break;

      case 'referee_chunk':
        if (!roundId) {
          break;
        }
        setSessionRounds((prev) => {
          const rounds = [...(prev[sid] || [])];
          const idx = rounds.findIndex((r) => r.id === roundId);
          if (idx < 0) {
            return prev;
          }
          const r = rounds[idx];
          rounds[idx] = { ...r, verdictText: (r.verdictText || '') + (event.data.content || '') };
          return { ...prev, [sid]: rounds };
        });
        break;

      case 'referee_complete':
        if (!roundId) {
          break;
        }
        setSessionRounds((prev) => {
          const rounds = [...(prev[sid] || [])];
          const idx = rounds.findIndex((r) => r.id === roundId);
          if (idx < 0) {
            return prev;
          }
          const r = rounds[idx];
          rounds[idx] = {
            ...r,
            verdictText: (event.data.winner ? event.data.winner + '\n\n' : '') + (event.data.summary || ''),
            verdictReady: true,
          };
          return { ...prev, [sid]: rounds };
        });
        break;

      case 'done':
        // Reconcile final state from the DB (fixes missed SSE events).
        if (event.data?.session_id) {
          sessionsAPI
            .get(String(event.data.session_id))
            .then((updated) => {
              setSessions((prev) => prev.map((s) => (s.id === updated.id ? { ...s, title: updated.title } : s)));
              setCurrentSession((prev) => (prev && prev.id === updated.id ? { ...prev, title: updated.title } : prev));
              if (updated.messages) {
                hydrateFromMessages(updated, updated.messages as any[]);
              }
            })
            .catch(() => {});
        }
        break;
    }
  }, []); // Empty deps: handler only uses event data and functional state updates

  const activeSessionId = currentSession?.id || '';
  const rounds = activeSessionId ? (sessionRounds[activeSessionId] || []) : [];
  const lastRound = rounds.length > 0 ? rounds[rounds.length - 1] : null;
  const panelistIds = currentSession?.model_set?.panelists || [];
  const isStreaming = !!lastRound && lastRound.modelIds.some((id) => lastRound.models[id]?.status === 'streaming');

  const isEmptySession = !!activeSessionId && rounds.length === 0 && !isStreaming;

  // Determine which sessions are actively streaming (for sidebar indicator)
  const streamingSessionIds = new Set<string>();
  for (const [sid, roundsArr] of Object.entries(sessionRounds)) {
    const hasStreaming = roundsArr.some(r => r.modelIds.some(mid => r.models[mid]?.status === 'streaming'));
    if (hasStreaming || activeStreamsRef.current.has(sid)) {
      streamingSessionIds.add(sid);
    }
  }

  const sortedSessions = (() => {
    const pinned = pinnedSessionIds;
    const list = [...sessions];
    list.sort((a, b) => {
      const ap = pinned.has(a.id) ? 1 : 0;
      const bp = pinned.has(b.id) ? 1 : 0;
      if (ap !== bp) {
        return bp - ap;
      }
      return 0;
    });
    return list;
  })();

  if (loading) {
    return (
      <div className="min-h-screen bg-[#FAFAF8] flex items-center justify-center">
        <div className="text-[#888780]">Loading Referee AI...</div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-[#FAFAF8] text-[#2C2C2A]">
      {isMobile && sidebarOpen && (
        <div
          className="fixed inset-0 z-30 bg-black/30"
          onClick={() => setSidebarOpen(false)}
        />
      )}

      <Sidebar
        sessions={sortedSessions}
        streamingSessionIds={streamingSessionIds}
        onNewSession={handleNewSession}
        onSelectSession={handleSelectSession}
        onDeleteSession={handleDeleteSession}
        onRenameSession={(sessionId, nextTitle) => {
          sessionsAPI
            .update(sessionId, { title: nextTitle })
            .then((updated) => {
              setSessions((prev) => prev.map((s) => (s.id === updated.id ? { ...s, title: updated.title } : s)));
              setCurrentSession((prev) => (prev && prev.id === updated.id ? { ...prev, title: updated.title } : prev));
            })
            .catch((e) => {
              console.error('Failed to rename session', e);
              alert('Failed to rename session. Please try again.');
            });
        }}
        pinnedSessionIds={pinnedSessionIds}
        onTogglePinSession={(sessionId) => {
          setPinnedSessionIds((prev) => {
            const next = new Set(prev);
            if (next.has(sessionId)) {
              next.delete(sessionId);
            } else {
              next.add(sessionId);
            }
            return next;
          });
        }}
        collapsed={sidebarCollapsed}
        onToggle={() => setSidebarCollapsed(c => !c)}
        open={sidebarOpen}
        onClose={() => setSidebarOpen(false)}
        isMobile={isMobile}
        desktopWidth={sidebarWidth}
        minDesktopWidth={SIDEBAR_MIN}
        maxDesktopWidth={SIDEBAR_MAX}
        onChangeDesktopWidth={(px) => setSidebarWidth(Math.max(SIDEBAR_MIN, Math.min(SIDEBAR_MAX, Math.round(px))))}
        onResizeStart={() => setSidebarResizing(true)}
        onResizeEnd={() => setSidebarResizing(false)}
      />

      <div
        className={`min-h-screen pb-[100px] ${sidebarResizing ? '' : 'transition-[margin-left] duration-300'}`}
        style={{ marginLeft: contentLeft }}
      >
        {isMobile && (
         <div className="sticky top-0 z-10 flex items-center gap-3 border-b border-[#D3D1C8] bg-[#FAFAF8] px-4 py-3">
            <button
              onClick={() => setSidebarOpen(true)}
              className="text-[#888780] hover:text-[#2C2C2A]"
            >
              <Menu size={20} />
            </button>
            <button
              type="button"
              onClick={() => setSidebarOpen(true)}
              className="text-sm font-semibold text-[#2C2C2A]"
              aria-label="Open sidebar"
            >
              Referee AI
            </button>
          </div>
        )}

        <ModelSelector
          models={panelistIds.map(displayNameForModelId)}
          onChangeModels={() => setModelPickerOpen(true)}
        />

        <ModelPicker
          isOpen={modelPickerOpen}
          onClose={() => setModelPickerOpen(false)}
          models={models}
          currentPanelists={currentSession?.model_set?.panelists || []}
          currentReferee={
            currentSession?.referee_model ||
            currentSession?.model_set?.panelists?.[0] ||
            models[0]?.id ||
            ''
          }
          onSave={handleModelChange}
        />

        <div className="relative p-5 pb-[200px]">
          {isEmptySession && (
            <div className="pointer-events-auto mx-auto flex min-h-[calc(100vh-220px)] max-w-[980px] items-center justify-center">
              <div className="w-full">
                <InputArea
                  value={inputValue}
                  onChange={setInputValue}
                  onSend={handleSend}
                  onCancel={handleCancelPrompt}
                  disabled={isStreaming}
                  isStreaming={isStreaming}
                  variant="center"
                  sessions={sessions.map((s) => ({ id: s.id, title: s.title }))}
                  currentSessionId={currentSession?.id}
                />
              </div>
            </div>
          )}

          {rounds.map((r) => {
            const complete = r.modelIds.length > 0 && r.modelIds.every((id) => r.models[id]?.status === 'complete');
            return (
              <div key={r.id} className="mb-8">
                <PromptDisplay prompt={r.prompt} refs={r.refs} webSources={r.webSources} webAnswer={r.webAnswer} />
                <div className="mt-6">
                  <PanelGrid
                    modelIds={r.modelIds}
                    models={r.models}
                    displayNameForModelId={displayNameForModelId}
                  />
                </div>
                {(r.verdictReady || r.verdictText) && complete && (
                  <RefereeVerdict verdict={r.verdictText || ''} isStreaming={!r.verdictReady} />
                )}
              </div>
            );
          })}
        </div>
      </div>

      <div
        className={`transition-opacity duration-300 ${
          showDockedComposer && !isEmptySession ? 'opacity-100' : 'pointer-events-none opacity-0'
        }`}
      >
        <InputArea
          value={inputValue}
          onChange={setInputValue}
          onSend={handleSend}
          onCancel={handleCancelPrompt}
          disabled={isStreaming}
          isStreaming={isStreaming}
          leftOffset={contentLeft}
          disableTransition={sidebarResizing}
          sessions={sessions.map((s) => ({ id: s.id, title: s.title }))}
          currentSessionId={currentSession?.id}
        />
      </div>
    </div>
  );
}
