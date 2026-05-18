import {useMutation, useQueryClient} from "@tanstack/react-query";
import {attendeesClient, ImportAttendeesRequest} from "../api/attendee.client.ts";
import {GET_ATTENDEES_QUERY_KEY} from "../queries/useGetAttendees.ts";
import {IdParam} from "../types.ts";
import {GET_EVENT_ORDERS_QUERY_KEY} from "../queries/useGetEventOrders.ts";

export const useImportAttendees = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, data}: {
            eventId: IdParam,
            data: ImportAttendeesRequest,
        }) => attendeesClient.import(eventId, data),

        onSuccess: () => {
            queryClient.invalidateQueries({queryKey: [GET_EVENT_ORDERS_QUERY_KEY]});
            queryClient.invalidateQueries({queryKey: [GET_ATTENDEES_QUERY_KEY]});
        }
    });
}
