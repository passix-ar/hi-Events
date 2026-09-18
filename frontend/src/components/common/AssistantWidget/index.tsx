import {useEffect, useRef, useState} from "react";
import {ActionIcon, Button, Loader, Textarea, Tooltip} from "@mantine/core";
import {useMediaQuery} from "@mantine/hooks";
import {IconArrowUp, IconMessageChatbot, IconPhotoPlus, IconRobot, IconTool, IconTrash, IconUser, IconX} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {useSendAssistantMessage} from "../../../mutations/useSendAssistantMessage.ts";
import {useUploadAssistantAttachment} from "../../../mutations/useUploadAssistantAttachment.ts";
import {AssistantStreamToolCall, streamAssistantChat} from "./useAssistantStream.ts";
import {AssistantEntity, IdParam} from "../../../types.ts";
import {AssistantMessage} from "./AssistantMessage.tsx";
import {useAssistantConversation} from "./useAssistantConversation.ts";
import {rememberLastAssistantOrganizer} from "./lastOrganizer.ts";
import classes from './AssistantWidget.module.scss';

// Tools that change data, highlighted so a turn that created something is
// visibly different from one that only read.
const WRITE_TOOLS = ['create_draft_event', 'create_ticket', 'attach_flyer_to_event', 'apply_flyer_palette', 'publish_event', 'create_promo_code', 'message_buyers', 'update_event', 'update_ticket', 'delete_ticket', 'delete_event'];

interface AssistantWidgetProps {
    organizerId: IdParam;
    /** The event open in the panel, so "this event" needs no lookup. */
    focusedEvent?: { id: IdParam; title: string } | null;
}

export const AssistantWidget = ({organizerId, focusedEvent = null}: AssistantWidgetProps) => {
    const OPEN_KEY = 'passix.assistant.open';
    const [open, setOpenState] = useState(() => {
        try {
            return typeof window !== 'undefined' && window.sessionStorage.getItem(OPEN_KEY) === '1';
        } catch {
            return false;
        }
    });
    // Navigating between the organizer and an event mounts a different layout,
    // so the open state has to live outside the component to survive the trip.
    const setOpen = (value: boolean) => {
        setOpenState(value);
        try {
            window.sessionStorage.setItem(OPEN_KEY, value ? '1' : '0');
        } catch {
            // best effort
        }
    };
    const [draft, setDraft] = useState('');
    const [error, setError] = useState<string | null>(null);
    const {entries, append, clear, historyForApi, pendingAttachmentId, setPendingAttachmentId} = useAssistantConversation(organizerId);
    const sendMessage = useSendAssistantMessage();
    const uploadAttachment = useUploadAssistantAttachment();
    const [attachment, setAttachment] = useState<{ id: string; name: string; preview: string } | null>(null);
    // The answer being streamed: text so far and the tools called so far. Null when idle.
    const [live, setLive] = useState<{ text: string; tools: AssistantStreamToolCall[] } | null>(null);
    const abortRef = useRef<AbortController | null>(null);
    const [dragging, setDragging] = useState(false);
    const bottomRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLTextAreaElement>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const isMobile = useMediaQuery('(max-width: 600px)');

    useEffect(() => {
        rememberLastAssistantOrganizer(organizerId);
    }, [organizerId]);

    useEffect(() => {
        if (open) {
            bottomRef.current?.scrollIntoView({behavior: 'smooth'});
        }
    }, [entries, sendMessage.isPending, live, open]);

    useEffect(() => () => abortRef.current?.abort(), []);

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
            t`Set up an event from a flyer`,
        ];

    /**
     * A flyer dropped, pasted or picked. It is uploaded right away so the model
     * can read it with the next message; the preview stays local to this tab.
     */
    const attachFile = (file: File | null | undefined) => {
        if (!file || !file.type.startsWith('image/')) {
            return;
        }

        setError(null);

        const reader = new FileReader();
        reader.onload = () => {
            const preview = typeof reader.result === 'string' ? reader.result : '';

            uploadAttachment.mutate({organizerId, file}, {
                onSuccess: ({data}) => setAttachment({id: data.id, name: data.name, preview}),
                onError: (mutationError: any) => {
                    const message = mutationError?.response?.data?.errors?.image?.[0]
                        ?? mutationError?.response?.data?.message;
                    setError(message || t`That image could not be attached. Use a JPG, PNG or WebP under 5 MB.`);
                },
            });
        };
        reader.readAsDataURL(file);
    };

    const send = (text: string) => {
        const question = text.trim();

        if ((question === '' && !attachment) || sendMessage.isPending || live !== null || uploadAttachment.isPending) {
            return;
        }

        setError(null);
        setDraft('');

        const content = question !== '' ? question : t`Here is the flyer, set the event up from it.`;
        const userEntry = {role: 'user' as const, content};
        const sentAttachment = attachment;
        const attachmentId = sentAttachment?.id ?? pendingAttachmentId ?? undefined;

        append({...userEntry, imagePreview: sentAttachment?.preview});
        setAttachment(null);
        if (sentAttachment) {
            setPendingAttachmentId(sentAttachment.id);
        }

        const request = {
            organizerId,
            messages: [...historyForApi(), userEntry],
            context: {
                event_id: focusedEvent?.id,
                attachment_id: attachmentId,
            },
        };

        const finish = (reply: string, toolCalls: AssistantStreamToolCall[], entities: AssistantEntity[] = []) => {
            setLive(null);
            append({role: 'assistant', content: reply, toolCalls, entities});
            if (toolCalls.some(call => call.name === 'attach_flyer_to_event')) {
                setPendingAttachmentId(null);
            }
        };

        const fail = (status: number | undefined, serverMessage?: string) => {
            setLive(null);
            // A 429 is either the per-minute limit or the daily budget; the API says which.
            if (status === 404) {
                setError(t`The assistant is not enabled for this account yet.`);
            } else if (status === 429) {
                setError(serverMessage || t`Too many questions in a row. Please wait a moment and try again.`);
            } else {
                setError(t`The assistant is temporarily unavailable. Please try again.`);
            }
        };

        // Streaming shows the answer as it is written and each tool as it is called;
        // if the browser cannot stream, the plain request gives the same answer at once.
        if (typeof window !== 'undefined' && 'ReadableStream' in window) {
            abortRef.current?.abort();
            const controller = new AbortController();
            abortRef.current = controller;
            setLive({text: '', tools: []});

            void streamAssistantChat({
                ...request,
                signal: controller.signal,
                onDelta: (text) => setLive(previous => ({text: (previous?.text ?? '') + text, tools: previous?.tools ?? []})),
                onTool: (call) => setLive(previous => ({text: previous?.text ?? '', tools: [...(previous?.tools ?? []), call]})),
                onDone: (done) => finish(done.reply, done.tool_calls, done.entities),
                onError: (streamError) => fail(streamError.status, streamError.message),
            });
            return;
        }

        sendMessage.mutate(request, {
            onSuccess: ({data}) => finish(data.reply, data.tool_calls, data.entities),
            onError: (mutationError: any) => fail(mutationError?.response?.status, mutationError?.response?.data?.message),
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
                <div
                    className={`${classes.panel} ${isMobile ? classes.panelMobile : ''} ${dragging ? classes.panelDragging : ''}`}
                    role="dialog"
                    aria-label={t`Assistant`}
                    onDragOver={(event) => { event.preventDefault(); setDragging(true); }}
                    onDragLeave={() => setDragging(false)}
                    onDrop={(event) => {
                        event.preventDefault();
                        setDragging(false);
                        attachFile(event.dataTransfer.files?.[0]);
                    }}
                    onPaste={(event) => {
                        const item = Array.from(event.clipboardData?.items ?? []).find(i => i.type.startsWith('image/'));
                        if (item) {
                            event.preventDefault();
                            attachFile(item.getAsFile());
                        }
                    }}
                >
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
                                        Ask about your sales, tickets and events, or drop a flyer here and I will set the event up from it. I only read your own data.
                                    </Trans>
                                </p>
                                <div className={classes.suggestions}>
                                    {suggestions.map(suggestion => (
                                        <Button
                                            key={suggestion}
                                            variant="light"
                                            size="compact-sm"
                                            onClick={() => suggestion === t`Set up an event from a flyer`
                                                ? fileInputRef.current?.click()
                                                : send(suggestion)}
                                        >
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
                                        {isUser && entry.imagePreview && (
                                            <img src={entry.imagePreview} alt="" className={classes.sentImage}/>
                                        )}
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

                        {(sendMessage.isPending || live !== null) && (
                            <div className={classes.assistantRow}>
                                <div className={classes.avatar}><IconRobot size={14}/></div>
                                <div className={classes.bubble}>
                                    {live && live.text !== '' && <AssistantMessage content={live.text}/>}
                                    {live && live.tools.length > 0 && (
                                        <div className={classes.toolCalls}>
                                            <IconTool size={11}/>
                                            {live.tools.map((call, callIndex) => (
                                                <span key={callIndex} className={WRITE_TOOLS.includes(call.name) ? classes.writeCall : undefined}>
                                                    {call.name}
                                                </span>
                                            ))}
                                        </div>
                                    )}
                                    {(!live || live.text === '') && (
                                        <div className={classes.pending}>
                                            <Loader size="xs" type="dots"/>
                                            <span>
                                                {live && live.tools.length > 0
                                                    ? <Trans>Running {live.tools[live.tools.length - 1].name}…</Trans>
                                                    : <Trans>Checking your data…</Trans>}
                                            </span>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                        {error && <div className={classes.error}>{error}</div>}
                        <div ref={bottomRef}/>
                    </div>

                    {attachment && (
                        <div className={classes.attachmentChip}>
                            <img src={attachment.preview} alt="" className={classes.attachmentThumb}/>
                            <span className={classes.attachmentName}>{attachment.name}</span>
                            <ActionIcon size="sm" variant="subtle" color="gray" aria-label={t`Remove image`} onClick={() => setAttachment(null)}>
                                <IconX size={14}/>
                            </ActionIcon>
                        </div>
                    )}

                    <div className={classes.composer}>
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            hidden
                            onChange={(event) => {
                                attachFile(event.target.files?.[0]);
                                event.target.value = '';
                            }}
                        />
                        <ActionIcon
                            size="lg"
                            variant="subtle"
                            color="gray"
                            aria-label={t`Attach a flyer`}
                            loading={uploadAttachment.isPending}
                            disabled={sendMessage.isPending}
                            onClick={() => fileInputRef.current?.click()}
                        >
                            <IconPhotoPlus size={18}/>
                        </ActionIcon>
                        <Textarea
                            ref={inputRef}
                            autosize
                            minRows={1}
                            maxRows={4}
                            value={draft}
                            maxLength={4000}
                            disabled={sendMessage.isPending || live !== null}
                            placeholder={attachment
                                ? t`Anything to add about the flyer? (optional)`
                                : focusedEvent ? t`Ask about this event…` : t`Ask something about your events…`}
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
                            disabled={(draft.trim() === '' && !attachment) || sendMessage.isPending || live !== null || uploadAttachment.isPending}
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
