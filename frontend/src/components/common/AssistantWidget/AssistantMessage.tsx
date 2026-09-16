import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";
import {useNavigate} from "react-router";
import {IconArrowRight} from "@tabler/icons-react";
import classes from './AssistantWidget.module.scss';

const isPanelPath = (href?: string) => !!href && /^\/(manage|account)(\/|$)/.test(href);

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
                a: ({href, children}) => isPanelPath(href) ? (
                    <button type="button" className={classes.panelLink} onClick={() => navigate(href as string)}>
                        {children}
                        <IconArrowRight size={14}/>
                    </button>
                ) : (
                    <a href={href} target="_blank" rel="noopener noreferrer">{children}</a>
                ),
                img: () => null,
                table: ({children}) => (
                    <div className={classes.tableWrap}>
                        <table>{children}</table>
                    </div>
                ),
            }}
        >
            {content}
        </ReactMarkdown>
    </div>
    );
};
