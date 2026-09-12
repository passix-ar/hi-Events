import {useEffect, useRef, useState} from "react";
import {useParams} from "react-router";
import {ActionIcon, Button, Loader, Textarea} from "@mantine/core";
import {IconArrowUp, IconRobot, IconTool, IconUser} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {PageBody} from "../../../common/PageBody";
import {PageTitle} from "../../../common/PageTitle";
import {Card} from "../../../common/Card";
import {useSendAssistantMessage} from "../../../../mutations/useSendAssistantMessage.ts";
import {AssistantChatMessage} from "../../../../api/assistant.client.ts";
import {AssistantToolCall} from "../../../../types.ts";
import classes from './Assistant.module.scss';

const MAX_HISTORY_MESSAGES = 20;

interface ChatEntry extends AssistantChatMessage {
    toolCalls?: AssistantToolCall[];
}

const Assistant = () => {
    const {organizerId} = useParams();
    const [entries, setEntries] = useState<ChatEntry[]>([]);
    const [draft, setDraft] = useState('');
    const [error, setError] = useState<string | null>(null);
    const sendMessage = useSendAssistantMessage();
    const bottomRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({behavior: 'smooth'});
    }, [entries, sendMessage.isPending]);

    const suggestions = [
        t`How much did I sell this month?`,
        t`Show me my latest sales`,
        t`How is each of my events doing?`,
    ];

    const send = (text: string) => {
        const question = text.trim();

        if (question === '' || sendMessage.isPending) {
            return;
        }

        setError(null);
        setDraft('');

        const history = [...entries, {role: 'user' as const, content: question}];
        setEntries(history);

        sendMessage.mutate({
            organizerId,
            messages: history
                .slice(-MAX_HISTORY_MESSAGES)
                .map(({role, content}) => ({role, content})),
        }, {
            onSuccess: ({data}) => {
                setEntries(previous => [...previous, {
                    role: 'assistant',
                    content: data.reply,
                    toolCalls: data.tool_calls,
                }]);
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
        <PageBody>
            <PageTitle subheading={t`Ask about your sales, tickets and events in plain language.`}>
                {t`Assistant`}
            </PageTitle>

            <Card className={classes.chat}>
                <div className={classes.messages}>
                    {entries.length === 0 && (
                        <div className={classes.empty}>
                            <IconRobot size={36}/>
                            <p>
                                <Trans>
                                    I can only read your own data, and every figure comes from your account.
                                </Trans>
                            </p>
                            <div className={classes.suggestions}>
                                {suggestions.map(suggestion => (
                                    <Button
                                        key={suggestion}
                                        variant={'light'}
                                        size={'compact-sm'}
                                        onClick={() => send(suggestion)}
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
                                    {isUser ? <IconUser size={16}/> : <IconRobot size={16}/>}
                                </div>
                                <div className={isUser ? classes.userBubble : classes.bubble}>
                                    <div className={classes.content}>{entry.content}</div>
                                    {!isUser && entry.toolCalls && entry.toolCalls.length > 0 && (
                                        <div className={classes.toolCalls}>
                                            <IconTool size={12}/>
                                            {entry.toolCalls.map(call => call.name).join(', ')}
                                        </div>
                                    )}
                                </div>
                            </div>
                        );
                    })}

                    {sendMessage.isPending && (
                        <div className={classes.assistantRow}>
                            <div className={classes.avatar}><IconRobot size={16}/></div>
                            <div className={classes.bubble}>
                                <div className={classes.pending}>
                                    <Loader size={'xs'} type={'dots'}/>
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
                        autosize
                        minRows={1}
                        maxRows={5}
                        value={draft}
                        maxLength={4000}
                        disabled={sendMessage.isPending}
                        placeholder={t`Ask something about your events…`}
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
                        size={'lg'}
                        aria-label={t`Send`}
                        disabled={draft.trim() === '' || sendMessage.isPending}
                        onClick={() => send(draft)}
                    >
                        <IconArrowUp size={18}/>
                    </ActionIcon>
                </div>
            </Card>
        </PageBody>
    );
};

export default Assistant;
