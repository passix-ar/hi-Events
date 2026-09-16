import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";
import classes from './AssistantWidget.module.scss';

interface AssistantMessageProps {
    content: string;
}

/**
 * The model answers in markdown (tables, bold, lists). react-markdown builds
 * React elements from the text and never injects raw HTML, so a hostile string
 * that reached the model through a buyer name cannot become markup here.
 */
export const AssistantMessage = ({content}: AssistantMessageProps) => (
    <div className={classes.markdown}>
        <ReactMarkdown
            remarkPlugins={[remarkGfm]}
            skipHtml
            components={{
                a: ({href, children}) => (
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
