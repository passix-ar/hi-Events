import {useCallback, useEffect, useState} from "react";
import {AssistantChatMessage} from "../../../api/assistant.client.ts";
import {AssistantToolCall, IdParam} from "../../../types.ts";

export interface ChatEntry extends AssistantChatMessage {
    toolCalls?: AssistantToolCall[];
}

const MAX_HISTORY_MESSAGES = 20;

const storageKey = (organizerId: IdParam) => `passix.assistant.${organizerId}`;

const read = (organizerId: IdParam): ChatEntry[] => {
    try {
        const raw = window.sessionStorage.getItem(storageKey(organizerId));
        return raw ? (JSON.parse(raw) as ChatEntry[]) : [];
    } catch {
        return [];
    }
};

const write = (organizerId: IdParam, entries: ChatEntry[]) => {
    try {
        window.sessionStorage.setItem(storageKey(organizerId), JSON.stringify(entries.slice(-MAX_HISTORY_MESSAGES)));
    } catch {
        // Storage can be full or blocked; the conversation still works for the page.
    }
};

/**
 * Keeps the conversation per organizer in sessionStorage so it survives moving
 * between the organizer pages and an event's pages, which mount different
 * layouts. It is scoped to the tab and dies with it: nothing here needs to
 * outlive the session, and the server keeps no history at all.
 */
export const useAssistantConversation = (organizerId: IdParam) => {
    const [entries, setEntries] = useState<ChatEntry[]>(() => (typeof window === 'undefined' ? [] : read(organizerId)));

    useEffect(() => {
        setEntries(read(organizerId));
    }, [organizerId]);

    const append = useCallback((entry: ChatEntry) => {
        setEntries(previous => {
            const next = [...previous, entry].slice(-MAX_HISTORY_MESSAGES);
            write(organizerId, next);
            return next;
        });
    }, [organizerId]);

    const clear = useCallback(() => {
        setEntries([]);
        write(organizerId, []);
    }, [organizerId]);

    return {entries, append, clear, historyForApi: () => entries.map(({role, content}) => ({role, content}))};
};
