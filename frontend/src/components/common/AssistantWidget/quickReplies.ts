/**
 * The model ends a message with `[[opciones: A | B | C]]` when the next step
 * is a closed choice; the widget shows those as buttons and hides the marker.
 * A click sends the option text as the organizer's message, so the model
 * needs nothing special to understand the answer. A marker that lands in the
 * middle of a message is stripped too; the last one wins.
 */
const MARKER = /\n?[ \t]*\[\[\s*(?:opciones|options)\s*:\s*([^\]]+?)\s*\]\][ \t]*/gi;
const MAX_OPTIONS = 5;
const MAX_LENGTH = 60;

export interface ParsedReply {
    text: string;
    options: string[];
}

export const parseQuickReplies = (content: string): ParsedReply => {
    let options: string[] = [];
    const text = content.replace(MARKER, (_match, list: string) => {
        options = list
            .split('|')
            .map(option => option.trim())
            .filter(option => option !== '' && option.length <= MAX_LENGTH)
            .slice(0, MAX_OPTIONS);
        return '\n';
    });

    return {text: text.replace(/\n{3,}/g, '\n\n').trim(), options};
};

/** While streaming, hide a marker that is still being written so it never flashes. */
export const stripPartialMarker = (content: string): string =>
    parseQuickReplies(content.replace(/\n?[ \t]*\[\[[^\]]*$/, '')).text;
