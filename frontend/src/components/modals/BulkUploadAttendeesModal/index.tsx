import {Modal} from "../../common/Modal";
import {GenericModalProps, IdParam} from "../../../types.ts";
import {Button} from "../../common/Button";
import {useParams} from "react-router";
import {useForm} from "@mantine/form";
import {Alert, Group, List, LoadingOverlay, Switch, Text} from "@mantine/core";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {t, Trans} from "@lingui/macro";
import {useState} from "react";
import {getProductsFromEvent} from "../../../utilites/helpers.ts";
import {Dropzone, MIME_TYPES} from "@mantine/dropzone";
import {IconUpload, IconX, IconFile, IconAlertCircle} from "@tabler/icons-react";
import {useImportAttendees} from "../../../mutations/useImportAttendees.ts";
import {ImportAttendeesRequest} from "../../../api/attendee.client.ts";

interface BulkUploadForm {
    send_confirmation_email: boolean;
    file: File | null;
}

export const BulkUploadAttendeesModal = ({onClose}: GenericModalProps) => {
    const {eventId} = useParams();
    const {data: event, isFetched: isEventFetched} = useGetEvent(eventId);
    const mutation = useImportAttendees();
    const eventProducts = getProductsFromEvent(event);
    const eventHasProducts = eventProducts && eventProducts?.length > 0;
    const [importResult, setImportResult] = useState<{
        successful: number;
        failed: number;
        errors: Array<{row: number; message: string}>;
    } | null>(null);

    const form = useForm<BulkUploadForm>({
        initialValues: {
            send_confirmation_email: false,
            file: null,
        },
        validate: {
            file: (value) => !value ? t`Please select a CSV file` : null,
        },
    });

    const handleDrop = (files: File[]) => {
        if (files.length > 0) {
            form.setFieldValue('file', files[0]);
        }
    };

    const handleSubmit = (values: BulkUploadForm) => {
        if (!values.file) {
            return;
        }

        const data: ImportAttendeesRequest = {
            file: values.file,
            send_confirmation_email: values.send_confirmation_email,
        };

        mutation.mutate({
            eventId: eventId as IdParam,
            data,
        }, {
            onSuccess: (result) => {
                setImportResult(result);
                if (result.successful > 0 && result.failed === 0) {
                    showSuccess(t`Successfully imported ${result.successful} attendees`);
                } else if (result.successful > 0) {
                    showSuccess(t`Imported ${result.successful} attendees with ${result.failed} failures`);
                } else {
                    showError(t`Failed to import attendees`);
                }
            },
            onError: (error: any) => {
                const message = error?.response?.data?.message || t`Failed to import attendees`;
                showError(message);
            },
        });
    };

    if (!event?.product_categories) {
        return (
            <LoadingOverlay visible/>
        )
    }

    if (isEventFetched && !eventHasProducts) {
        return (
            <Modal opened onClose={onClose} heading={t`Bulk Upload Attendees`}>
                <p>{t`You must create a ticket before you can bulk upload attendees.`}</p>
            </Modal>
        )
    }

    if (importResult) {
        return (
            <Modal opened onClose={onClose} heading={t`Import Results`}>
                <Alert 
                    color={importResult.failed > 0 ? "yellow" : "green"} 
                    title={t`Import Complete`}
                    mb="md"
                >
                    <Text>
                        <Trans>Successfully imported: {importResult.successful} attendees</Trans>
                    </Text>
                    {importResult.failed > 0 && (
                        <Text c="red">
                            <Trans>Failed: {importResult.failed} rows</Trans>
                        </Text>
                    )}
                </Alert>
                
                {importResult.errors.length > 0 && (
                    <>
                        <Text fw={500} mb="xs">{t`Errors:`}</Text>
                        <List size="sm" spacing="xs">
                            {importResult.errors.slice(0, 10).map((error, index) => (
                                <List.Item key={index} c="red">
                                    <Trans>Row {error.row}: {error.message}</Trans>
                                </List.Item>
                            ))}
                            {importResult.errors.length > 10 && (
                                <List.Item c="dimmed">
                                    <Trans>...and {importResult.errors.length - 10} more errors</Trans>
                                </List.Item>
                            )}
                        </List>
                    </>
                )}
                
                <Button fullWidth mt="xl" onClick={onClose}>
                    {t`Close`}
                </Button>
            </Modal>
        );
    }

    return (
        <Modal opened onClose={onClose} heading={t`Bulk Upload Attendees`}>
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <Alert icon={<IconAlertCircle size="1rem"/>} mb="md" color="blue">
                    <Text size="sm">
                        {t`Upload a CSV file with required columns: firstName, lastName, email, language, ticket, paid`}
                    </Text>
                    <Text size="xs" c="dimmed" mt={4}>
                        {t`Example: firstName, lastName, email, language, ticket, paid`}
                    </Text>
                    <Text size="xs" c="dimmed">
                        {t`Kirolos, Fahem, km@example.com, English, free access, 0.0`}
                    </Text>
                </Alert>

                <Dropzone
                    onDrop={handleDrop}
                    accept={[MIME_TYPES.csv, 'text/csv']}
                    maxSize={5 * 1024 * 1024}
                    maxFiles={1}
                    mb="md"
                >
                    <Group justify="center" gap="xl" mih={100} style={{pointerEvents: 'none'}}>
                        <Dropzone.Accept>
                            <IconUpload size={50} stroke={1.5}/>
                        </Dropzone.Accept>
                        <Dropzone.Reject>
                            <IconX size={50} stroke={1.5}/>
                        </Dropzone.Reject>
                        <Dropzone.Idle>
                            {form.values.file ? (
                                <Group>
                                    <IconFile size={50} stroke={1.5}/>
                                    <div>
                                        <Text size="sm" fw={500}>{form.values.file.name}</Text>
                                        <Text size="xs" c="dimmed">
                                            {(form.values.file.size / 1024).toFixed(1)} KB
                                        </Text>
                                    </div>
                                </Group>
                            ) : (
                                <IconUpload size={50} stroke={1.5}/>
                            )}
                        </Dropzone.Idle>
                        {!form.values.file && (
                            <div>
                                <Text size="lg" inline fw={500}>
                                    {t`Drag CSV file here or click to select`}
                                </Text>
                                <Text size="sm" c="dimmed" inline mt={7}>
                                    {t`File should not exceed 5MB`}
                                </Text>
                            </div>
                        )}
                    </Group>
                </Dropzone>
                {form.errors.file && (
                    <Text size="sm" c="red" mb="md">{form.errors.file}</Text>
                )}

                <Switch
                    mt={20}
                    label={t`Send order confirmation and ticket email to each attendee`}
                    {...form.getInputProps('send_confirmation_email', {type: 'checkbox'})}
                />

                <Button type="submit" fullWidth mt="xl" disabled={mutation.isPending}>
                    {mutation.isPending ? t`Uploading...` : t`Upload Attendees`}
                </Button>
            </form>
        </Modal>
    );
}
