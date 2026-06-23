import {useMutation} from "@tanstack/react-query";
import {authClient} from "../api/auth.client.ts";

export const useConfirmEmailAddressPublic = () => {
    return useMutation({
        mutationFn: ({token}: { token: string }) => authClient.confirmEmailAddress(token),
    });
};
