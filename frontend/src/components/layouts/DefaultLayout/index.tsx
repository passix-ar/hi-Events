import {useEffect, useState} from "react";
import {Outlet} from "react-router";
import {Header} from "../../common/Header";
import {Container} from "@mantine/core";
import {GlobalMenu} from "../../common/GlobalMenu";
import ImpersonationBanner from "../../common/ImpersonationBanner";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {AssistantWidget} from "../../common/AssistantWidget";
import {readLastAssistantOrganizer} from "../../common/AssistantWidget/lastOrganizer.ts";

const DefaultLayout = () => {
    const {data: me} = useGetMe();
    // Keeps the chat alive when the assistant sends the user to /account/*.
    // Read after mount: sessionStorage does not exist during SSR.
    const [lastOrganizer, setLastOrganizer] = useState<string | null>(null);
    useEffect(() => setLastOrganizer(readLastAssistantOrganizer()), []);

    return (
        <>
            <ImpersonationBanner />
            <Header rightContent={<GlobalMenu/>}/>
            <Container>
                <Outlet/>
            </Container>
            {me && lastOrganizer && <AssistantWidget organizerId={lastOrganizer}/>}
        </>
    );
}

export default DefaultLayout;
