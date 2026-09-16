import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";
import {useNavigate} from "react-router";
import {IconArrowRight, IconExternalLink} from "@tabler/icons-react";
import classes from './AssistantWidget.module.scss';
import {CopyBlock, hastText} from "./CopyBlock";

const isPanelPath = (href?: string) => !!href && /^\/(manage|account)(\/|$)/.test(href);

const NUMERIC_CELL = /^[\d.,%$ ARS-]+$/;

const hostOf = (href?: string): string | undefined => {
    if (!href) {
        return undefined;
    }
    try {
        return new URL(href).host || undefined;
    } catch {
        return undefined;
    }
};

interface AssistantMessageProps {
    content: string;
}

/**
 * The model answers in markdown (tables, bold, lists). react-markdown builds
 * React elements from the text and never injects raw HTML, so a hostile string
 * that reached the model through a buyer name cannot become markup here.
 */
export const AssistantMessage = ({content}: AssistantMessageProps) => {
    const navigate = useNavigate();

    return (
    <div className={classes.markdown}>
        <ReactMarkdown
            remarkPlugins={[remarkGfm]}
            skipHtml
            components={{
                // A link into the panel (the paths get_panel_route hands out) becomes
                // a button that navigates in place; the chat stays open across pages.
                a: ({href, children}) => {
                    if (isPanelPath(href)) {
                        return (
                            <button type="button" className={classes.panelLink} onClick={() => navigate(href as string)}>
                                {children}
                                <IconArrowRight size={14}/>
                            </button>
                        );
                    }

                    // A relative path that is not a panel route is one the model made up
                    // (only get_panel_route hands out real ones): show the text, not a broken link.
                    if (href?.startsWith('/')) {
                        return <span>{children}</span>;
                    }

                    return (
                        <a href={href} target="_blank" rel="noopener noreferrer" title={hostOf(href)} className={classes.externalLink}>
                            {children}
                            <IconExternalLink size={12} className={classes.externalIcon} aria-hidden/>
                        </a>
                    );
                },
                img: () => null,
                // Fenced code blocks and blockquotes are how the model hands over
                // copy-worthy text (captions, WhatsApp messages): each gets a Copy button.
                pre: ({node, children}) => (
                    <CopyBlock text={hastText(node).replace(/\n$/, '')}>
                        <pre>{children}</pre>
                    </CopyBlock>
                ),
                blockquote: ({node, children}) => (
                    <CopyBlock quote text={hastText(node).trim()}>
                        <blockquote>{children}</blockquote>
                    </CopyBlock>
                ),
                table: ({children}) => (
                    <div className={classes.tableWrap}>
                        <table>{children}</table>
                    </div>
                ),
                // Numeric cells (counts, money, percentages) read better right-aligned.
                td: ({node, children, style}) => {
                    const text = hastText(node).trim();
                    const numeric = text !== '' && NUMERIC_CELL.test(text);
                    return <td style={style} className={numeric ? classes.numericCell : undefined}>{children}</td>;
                },
            }}
        >
            {content}
        </ReactMarkdown>
    </div>
    );
};
