/**
 * The model ends a message with `[[opciones: A | B | C]]` when the next step
 * is a closed choice; the widget shows those as buttons and hides the marker.
 * A click sends the option text as the organizer's message, so the model
 * needs nothing special to understand the answer.
 */
const MARKER = /\n?\s*\[\[\s*(?:opciones|options)\s*:\s*([^\]]+?)\s*\]\]\s*$/i;
const MAX_OPTIONS = 5;
const MAX_LENGTH = 60;

export interface ParsedReply {
    text: string;
    options: string[];
}

export const parseQuickReplies = (content: string): ParsedReply => {
    const match = content.match(MARKER);
    if (!match) {
        return {text: content, options: []};
    }

    const options = match[1]
        .split('|')
        .map(option => option.trim())
        .filter(option => option !== '' && option.length <= MAX_LENGTH)
        .slice(0, MAX_OPTIONS);

    return {text: content.replace(MARKER, '').trimEnd(), options};
};

/** While streaming, hide a marker that is still being written so it never flashes. */
export const stripPartialMarker = (content: string): string =>
    content.replace(/\n?\s*\[\[[^\]]*$/, '').replace(MARKER, '').trimEnd();
