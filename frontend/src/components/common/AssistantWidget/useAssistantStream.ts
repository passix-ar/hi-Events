import {api} from "../../../api/client.ts";
import {AssistantChatContext, AssistantChatMessage} from "../../../api/assistant.client.ts";
import {AssistantEntity, IdParam} from "../../../types.ts";

/**
 * Streaming twin of assistantClient.chat(). Talks to
 * POST /organizers/{id}/assistant/chat/stream and parses our own SSE protocol:
 *
 *   event: delta   data: {"text": "..."}
 *   event: tool    data: {"name": "...", "arguments": {...}}
 *   event: done    data: {"reply", "tool_calls", "entities", "input_tokens", "output_tokens"}
 *   event: error   data: {"message": "...", "status": 404 | 429 | 503}
 *
 * Auth, validation and authorization failures happen before the stream opens
 * and arrive as ordinary JSON responses (401 / 403 / 422); those reach onError
 * with the HTTP status. Everything after that is an `error` event with an
 * http-like `status` field.
 */

export interface AssistantStreamToolCall {
    name: string;
    arguments: Record<string, unknown>;
}

export interface AssistantStreamDone {
    reply: string;
    tool_calls: AssistantStreamToolCall[];
    entities?: AssistantEntity[];
    input_tokens: number;
    output_tokens: number;
}

export interface AssistantStreamError {
    message: string;
    status: number;
}

export interface StreamAssistantChatOptions {
    organizerId: IdParam;
    messages: AssistantChatMessage[];
    context?: AssistantChatContext;
    onDelta?: (text: string) => void;
    onTool?: (call: AssistantStreamToolCall) => void;
    onDone?: (done: AssistantStreamDone) => void;
    onError?: (error: AssistantStreamError) => void;
    signal?: AbortSignal;
}

interface SseEvent {
    event: string;
    data: string;
}

const trimSlashes = (value: string) => value.replace(/\/+$/, '');

/**
 * Same auth the axios client sends: the session cookie (withCredentials) plus
 * the Authorization header when one was set with setAuthToken() (impersonation, SSR).
 */
const authHeaders = (): Record<string, string> => {
    const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        'Accept': 'text/event-stream',
    };

    const common = api.defaults.headers.common as Record<string, unknown> | undefined;
    const authorization = common?.['Authorization'];

    if (typeof authorization === 'string' && authorization !== '') {
        headers['Authorization'] = authorization;
    }

    return headers;
};

/** Splits a raw SSE block (lines up to a blank line) into its event name and joined data. */
const parseBlock = (block: string): SseEvent | null => {
    let event = 'message';
    const data: string[] = [];

    for (const rawLine of block.split('\n')) {
        const line = rawLine.endsWith('\r') ? rawLine.slice(0, -1) : rawLine;

        if (line === '' || line.startsWith(':')) {
            continue;
        }

        const colon = line.indexOf(':');
        const field = colon === -1 ? line : line.slice(0, colon);
        let value = colon === -1 ? '' : line.slice(colon + 1);
        if (value.startsWith(' ')) {
            value = value.slice(1);
        }

        if (field === 'event') {
            event = value;
        } else if (field === 'data') {
            data.push(value);
        }
    }

    if (data.length === 0) {
        return null;
    }

    return {event, data: data.join('\n')};
};

const parseJson = <T, >(raw: string): T | null => {
    try {
        return JSON.parse(raw) as T;
    } catch {
        return null;
    }
};

/**
 * Resolves with the `done` payload, or with null when the stream ended in an
 * `error` event (already reported through onError) or was aborted.
 */
export const streamAssistantChat = async ({
                                              organizerId,
                                              messages,
                                              context,
                                              onDelta,
                                              onTool,
                                              onDone,
                                              onError,
                                              signal,
                                          }: StreamAssistantChatOptions): Promise<AssistantStreamDone | null> => {
    const url = `${trimSlashes(api.defaults.baseURL ?? '')}/organizers/${organizerId}/assistant/chat/stream`;

    let response: Response;
    try {
        response = await fetch(url, {
            method: 'POST',
            headers: authHeaders(),
            credentials: 'include',
            body: JSON.stringify({messages, context}),
            signal,
        });
    } catch (e) {
        if (signal?.aborted) {
            return null;
        }
        onError?.({message: e instanceof Error ? e.message : 'Network error', status: 0});
        return null;
    }

    if (!response.ok) {
        const body = parseJson<{ message?: string }>(await response.text().catch(() => ''));
        onError?.({message: body?.message ?? response.statusText, status: response.status});
        return null;
    }

    if (!response.body) {
        onError?.({message: 'Streaming is not supported by this browser', status: 0});
        return null;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let result: AssistantStreamDone | null = null;
    let finished = false;

    const handle = (sse: SseEvent) => {
        switch (sse.event) {
            case 'delta': {
                const data = parseJson<{ text: string }>(sse.data);
                if (data?.text) {
                    onDelta?.(data.text);
                }
                break;
            }
            case 'tool': {
                const data = parseJson<AssistantStreamToolCall>(sse.data);
                if (data?.name) {
                    onTool?.({name: data.name, arguments: data.arguments ?? {}});
                }
                break;
            }
            case 'done': {
                const data = parseJson<AssistantStreamDone>(sse.data);
                if (data) {
                    result = data;
                    finished = true;
                    onDone?.(data);
                }
                break;
            }
            case 'error': {
                const data = parseJson<AssistantStreamError>(sse.data);
                finished = true;
                onError?.({message: data?.message ?? 'Unknown error', status: data?.status ?? 500});
                break;
            }
        }
    };

    const drain = (flushAll: boolean) => {
        buffer = buffer.replace(/\r\n/g, '\n');
        let separator: number;
        while ((separator = buffer.indexOf('\n\n')) !== -1) {
            const block = buffer.slice(0, separator);
            buffer = buffer.slice(separator + 2);
            const parsed = parseBlock(block);
            if (parsed) {
                handle(parsed);
            }
        }
        if (flushAll && buffer.trim() !== '') {
            const parsed = parseBlock(buffer);
            buffer = '';
            if (parsed) {
                handle(parsed);
            }
        }
    };

    try {
        while (!finished) {
            const {value, done} = await reader.read();
            if (done) {
                buffer += decoder.decode();
                drain(true);
                break;
            }
            buffer += decoder.decode(value, {stream: true});
            drain(false);
        }
    } catch (e) {
        if (!signal?.aborted) {
            onError?.({message: e instanceof Error ? e.message : 'Stream interrupted', status: 0});
        }
        return null;
    } finally {
        reader.cancel().catch(() => undefined);
    }

    if (!finished) {
        // The connection closed without a done/error event (proxy timeout, worker killed).
        onError?.({message: 'The assistant stopped answering', status: 0});
    }

    return result;
};
