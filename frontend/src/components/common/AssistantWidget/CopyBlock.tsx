import {ReactNode, useEffect, useRef, useState} from "react";
import {t} from "@lingui/macro";
import {IconCheck, IconCopy} from "@tabler/icons-react";
import classes from './AssistantWidget.module.scss';

/**
 * Plain text of a hast node (react-markdown hands every component its `node`).
 * Used to copy exactly what the model wrote, without the React wrappers.
 */
export const hastText = (node: unknown): string => {
    if (!node || typeof node !== 'object') {
        return '';
    }
    const n = node as { type?: string; value?: string; children?: unknown[] };
    if (n.type === 'text') {
        return n.value ?? '';
    }
    return (n.children ?? []).map(hastText).join('');
};

interface CopyButtonProps {
    text: string;
}

export const CopyButton = ({text}: CopyButtonProps) => {
    const [copied, setCopied] = useState(false);
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            window.clearTimeout(timer.current);
            timer.current = window.setTimeout(() => setCopied(false), 1500);
        } catch {
            // Clipboard denied (insecure context / permissions): nothing to show.
        }
    };

    const label = copied ? t`Copied` : t`Copy`;

    return (
        <button
            type="button"
            className={`${classes.copyButton} ${copied ? classes.copyButtonDone : ''}`}
            onClick={copy}
            aria-label={label}
            title={label}
        >
            {copied ? <IconCheck size={12}/> : <IconCopy size={12}/>}
            <span>{label}</span>
        </button>
    );
};

interface CopyBlockProps {
    /** The text the button copies (stripped of markup). */
    text: string;
    /** Set when the block is a blockquote rather than a fenced code block. */
    quote?: boolean;
    children: ReactNode;
}

/**
 * Wrapper for a fenced code block or a blockquote: the model uses both to hand
 * over copy-worthy text (Instagram caption, WhatsApp message), so each one gets
 * a small "Copy" affordance in its top-right corner.
 */
export const CopyBlock = ({text, quote = false, children}: CopyBlockProps) => (
    <div className={`${classes.copyBlock} ${quote ? classes.copyBlockQuote : classes.copyBlockCode}`}>
        {children}
        {text.trim() !== '' && <CopyButton text={text}/>}
    </div>
);
