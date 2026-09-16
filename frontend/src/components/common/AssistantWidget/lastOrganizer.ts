import {IdParam} from "../../../types.ts";

// The organizer the assistant was last mounted for. Layouts without an
// organizer in the route (the account area) use it to keep the chat alive
// when the assistant itself sends the user there.
const LAST_ORGANIZER_KEY = 'passix.assistant.lastOrganizer';

export const rememberLastAssistantOrganizer = (organizerId: IdParam): void => {
    if (organizerId === undefined || organizerId === null || organizerId === '') {
        return;
    }

    try {
        window.sessionStorage.setItem(LAST_ORGANIZER_KEY, String(organizerId));
    } catch {
        // best effort
    }
};

export const readLastAssistantOrganizer = (): string | null => {
    try {
        if (typeof window === 'undefined') {
            return null;
        }
        return window.sessionStorage.getItem(LAST_ORGANIZER_KEY) || null;
    } catch {
        return null;
    }
};
