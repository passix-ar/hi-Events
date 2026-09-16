import {useEffect, useRef, useState} from "react";
import {ActionIcon, Button, Loader, Textarea, Tooltip} from "@mantine/core";
import {useMediaQuery} from "@mantine/hooks";
import {IconArrowUp, IconMessageChatbot, IconRobot, IconTool, IconTrash, IconUser, IconX} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {useSendAssistantMessage} from "../../../mutations/useSendAssistantMessage.ts";
import {IdParam} from "../../../types.ts";
import {AssistantMessage} from "./AssistantMessage.tsx";
import {useAssistantConversation} from "./useAssistantConversation.ts";
import classes from './AssistantWidget.module.scss';

// Tools that change data, highlighted so a turn that created something is
// visibly different from one that only read.
const WRITE_TOOLS = ['create_draft_event', 'create_ticket'];

interface AssistantWidgetProps {
    organizerId: IdParam;
    /** The event open in the panel, so "this event" needs no lookup. */
    focusedEvent?: { id: IdParam; title: string } | null;
}

export const AssistantWidget = ({organizerId, focusedEvent = null}: AssistantWidgetProps) => {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState('');
    const [error, setError] = useState<string | null>(null);
    const {entries, append, clear, historyForApi} = useAssistantConversation(organizerId);
    const sendMessage = useSendAssistantMessage();
    const bottomRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLTextAreaElement>(null);
    const isMobile = useMediaQuery('(max-width: 600px)');

    useEffect(() => {
        if (open) {
            bottomRef.current?.scrollIntoView({behavior: 'smooth'});
        }
    }, [entries, sendMessage.isPending, open]);

    useEffect(() => {
        if (!open) {
            return;
        }

        inputRef.current?.focus();

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open]);

    const suggestions = focusedEvent
        ? [
            t`How many tickets are left for this event?`,
            t`Which ticket type sells best here?`,
            t`How is check-in going?`,
        ]
        : [
            t`How much did I sell this month?`,
            t`Show me my latest sales`,
            t`Create a new event`,
        ];

    const send = (text: string) => {
        const question = text.trim();

        if (question === '' || sendMessage.isPending) {
            return;
        }

        setError(null);
        setDraft('');

        const userEntry = {role: 'user' as const, content: question};
        append(userEntry);

        sendMessage.mutate({
            organizerId,
            messages: [...historyForApi(), userEntry],
            context: focusedEvent ? {event_id: focusedEvent.id} : undefined,
        }, {
            onSuccess: ({data}) => {
                append({role: 'assistant', content: data.reply, toolCalls: data.tool_calls});
            },
            onError: (mutationError: any) => {
                const status = mutationError?.response?.status;
                // A 429 is either the per-minute limit or the daily budget; the API says which.
                const serverMessage = mutationError?.response?.data?.message;

                if (status === 404) {
                    setError(t`The assistant is not enabled for this account yet.`);
                } else if (status === 429) {
                    setError(serverMessage || t`Too many questions in a row. Please wait a moment and try again.`);
                } else {
                    setError(t`The assistant is temporarily unavailable. Please try again.`);
                }
            },
        });
    };

    return (
        <>
            {!open && (
                <Tooltip label={t`Assistant`} position="left">
                    <button
                        type="button"
                        className={classes.launcher}
                        aria-label={t`Open the assistant`}
                        onClick={() => setOpen(true)}
                    >
                        <IconMessageChatbot size={26}/>
                    </button>
                </Tooltip>
            )}

            {open && (
                <div className={`${classes.panel} ${isMobile ? classes.panelMobile : ''}`} role="dialog" aria-label={t`Assistant`}>
                    <div className={classes.header}>
                        <div className={classes.headerTitle}>
                            <IconRobot size={18}/>
                            <span>{t`Assistant`}</span>
                        </div>
                        {focusedEvent && (
                            <span className={classes.contextChip} title={focusedEvent.title}>
                                {focusedEvent.title}
                            </span>
                        )}
                        <div className={classes.headerActions}>
                            {entries.length > 0 && (
                                <ActionIcon variant="subtle" color="gray" aria-label={t`Clear conversation`} onClick={clear}>
                                    <IconTrash size={16}/>
                                </ActionIcon>
                            )}
                            <ActionIcon variant="subtle" color="gray" aria-label={t`Close`} onClick={() => setOpen(false)}>
                                <IconX size={18}/>
                            </ActionIcon>
                        </div>
                    </div>

                    <div className={classes.messages}>
                        {entries.length === 0 && (
                            <div className={classes.empty}>
                                <IconRobot size={32}/>
                                <p>
                                    <Trans>
                                        Ask about your sales, tickets and events, or ask me to set up a new one. I only read your own data.
                                    </Trans>
                                </p>
                                <div className={classes.suggestions}>
                                    {suggestions.map(suggestion => (
                                        <Button key={suggestion} variant="light" size="compact-sm" onClick={() => send(suggestion)}>
                                            {suggestion}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {entries.map((entry, index) => {
                            const isUser = entry.role === 'user';

                            return (
                                <div key={index} className={isUser ? classes.userRow : classes.assistantRow}>
                                    <div className={classes.avatar}>
                                        {isUser ? <IconUser size={14}/> : <IconRobot size={14}/>}
                                    </div>
                                    <div className={isUser ? classes.userBubble : classes.bubble}>
                                        {isUser
                                            ? <div className={classes.content}>{entry.content}</div>
                                            : <AssistantMessage content={entry.content}/>}
                                        {!isUser && entry.toolCalls && entry.toolCalls.length > 0 && (
                                            <div className={classes.toolCalls}>
                                                <IconTool size={11}/>
                                                {entry.toolCalls.map((call, callIndex) => (
                                                    <span
                                                        key={callIndex}
                                                        className={WRITE_TOOLS.includes(call.name) ? classes.writeCall : undefined}
                                                    >
                                                        {call.name}
                                                    </span>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            );
                        })}

                        {sendMessage.isPending && (
                            <div className={classes.assistantRow}>
                                <div className={classes.avatar}><IconRobot size={14}/></div>
                                <div className={classes.bubble}>
                                    <div className={classes.pending}>
                                        <Loader size="xs" type="dots"/>
                                        <span><Trans>Checking your data…</Trans></span>
                                    </div>
                                </div>
                            </div>
                        )}

                        {error && <div className={classes.error}>{error}</div>}
                        <div ref={bottomRef}/>
                    </div>

                    <div className={classes.composer}>
                        <Textarea
                            ref={inputRef}
                            autosize
                            minRows={1}
                            maxRows={4}
                            value={draft}
                            maxLength={4000}
                            disabled={sendMessage.isPending}
                            placeholder={focusedEvent ? t`Ask about this event…` : t`Ask something about your events…`}
                            onChange={(event) => setDraft(event.currentTarget.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' && !event.shiftKey) {
                                    event.preventDefault();
                                    send(draft);
                                }
                            }}
                            className={classes.input}
                        />
                        <ActionIcon
                            size="lg"
                            aria-label={t`Send`}
                            disabled={draft.trim() === '' || sendMessage.isPending}
                            onClick={() => send(draft)}
                        >
                            <IconArrowUp size={18}/>
                        </ActionIcon>
                    </div>
                </div>
            )}
        </>
    );
};
