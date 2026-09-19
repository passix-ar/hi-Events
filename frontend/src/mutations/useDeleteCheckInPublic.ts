import {useMutation} from "@tanstack/react-query";
import {publicCheckInClient} from "../api/check-in.client.ts";
import {IdParam} from "../types.ts";

/**
 * Undoing a check-in from the door scanner.
 *
 * `attendeePublicId` is not sent anywhere — the endpoint is keyed by the check-in's own short id —
 * but it travels in the variables so the list can show the spinner on the row being undone, without
 * a second piece of state tracking the same thing.
 */
export const useDeleteCheckInPublic = () => {
    return useMutation({
        mutationFn: ({checkInListShortId, checkInShortId}: {
            checkInListShortId: IdParam,
            checkInShortId: IdParam,
            attendeePublicId: string,
        }) => {
            return publicCheckInClient.deleteCheckIn(checkInListShortId, checkInShortId);
        }
    });
}
