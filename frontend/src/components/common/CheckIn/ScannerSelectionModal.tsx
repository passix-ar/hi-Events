import {Button, Modal, Stack} from "@mantine/core";
import {IconCamera, IconScan} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {showSuccess} from "../../../utilites/notifications.tsx";

interface ScannerSelectionModalProps {
    isOpen: boolean;
    isHidScannerActive: boolean;
    onClose: () => void;
    onCameraSelect: () => void;
    onHidScannerSelect: () => void;
}

export const ScannerSelectionModal = ({
    isOpen,
    isHidScannerActive,
    onClose,
    onCameraSelect,
    onHidScannerSelect
}: ScannerSelectionModalProps) => {
    return (
        <Modal
            opened={isOpen}
            onClose={onClose}
            title={t`Select Scanner Type`}
            size="sm"
            // This modal hands control to a scanner, so returning focus to the button that opened
            // it is wrong in both branches: the camera opens its own full-screen modal, and the USB
            // reader types straight into the page — with the focus back on "Scan", the reader's
            // first Enter activated that button and reopened this modal on top of the door.
            returnFocus={false}
        >
            <Stack>
                <Button
                    leftSection={<IconCamera size={20}/>}
                    onClick={onCameraSelect}
                    fullWidth
                    variant="light"
                >
                    {t`Camera Scanner`}
                </Button>
                <Button
                    leftSection={<IconScan size={20}/>}
                    onClick={() => {
                        onHidScannerSelect();
                        if (!isHidScannerActive) {
                            showSuccess(t`USB Scanner mode activated. Start scanning tickets now.`);
                        }
                    }}
                    fullWidth
                    variant="light"
                    color={isHidScannerActive ? "gray" : undefined}
                    disabled={isHidScannerActive}
                >
                    {isHidScannerActive ? t`USB Scanner Already Active` : t`USB/HID Scanner`}
                </Button>
                <Button
                    onClick={onClose}
                    variant="subtle"
                    fullWidth
                >
                    {t`Cancel`}
                </Button>
            </Stack>
        </Modal>
    );
};