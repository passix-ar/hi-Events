import {useCallback, useEffect, useState} from "react";
import {AssistantChatMessage} from "../../../api/assistant.client.ts";
import {AssistantToolCall, IdParam} from "../../../types.ts";

export interface ChatEntry extends AssistantChatMessage {
    toolCalls?: AssistantToolCall[];
    /** Small data URL of the image sent with this turn, for the bubble only. */
    imagePreview?: string;
}

const MAX_HISTORY_MESSAGES = 20;

const storageKey = (organizerId: IdParam) => `passix.assistant.${organizerId}`;
const attachmentKey = (organizerId: IdParam) => `passix.assistant.${organizerId}.attachment`;

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

    // A flyer stays available to the tools until one consumes it (or the chat is
    // cleared): the confirmation always comes a turn after the upload.
    const [pendingAttachmentId, setPendingAttachmentIdState] = useState<string | null>(() => {
        try {
            return typeof window === 'undefined' ? null : window.sessionStorage.getItem(attachmentKey(organizerId));
        } catch {
            return null;
        }
    });

    const setPendingAttachmentId = useCallback((id: string | null) => {
        setPendingAttachmentIdState(id);
        try {
            if (id) {
                window.sessionStorage.setItem(attachmentKey(organizerId), id);
            } else {
                window.sessionStorage.removeItem(attachmentKey(organizerId));
            }
        } catch {
            // best effort
        }
    }, [organizerId]);

    const clear = useCallback(() => {
        setEntries([]);
        write(organizerId, []);
        setPendingAttachmentId(null);
    }, [organizerId, setPendingAttachmentId]);

    return {
        entries,
        append,
        clear,
        pendingAttachmentId,
        setPendingAttachmentId,
        historyForApi: () => entries.map(({role, content}) => ({role, content})),
    };
};
