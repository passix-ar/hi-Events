import {useEffect, useRef, useState} from 'react';
import QrScanner from 'qr-scanner';
import classes from './QrScanner.module.scss';
import {showError} from "../../../../utilites/notifications.tsx";
import {t} from "@lingui/macro";
import {QrScannerControls} from './QrScannerControls';
import {PermissionDeniedMessage} from './PermissionDeniedMessage';

// A code stays in front of the camera for a while after it is handled, and the scanner reads it
// over and over. Reads of the same code within this window (counted from the last time it was
// seen) are ignored, so it is neither re-submitted nor flagged again while it is still in frame.
const SAME_CODE_COOLDOWN_MS = 2500;

interface QRScannerComponentProps {
    onAttendeeScanned: (attendeePublicId: string) => Promise<boolean> | boolean;
    onClose: () => void;
    // Controlled by the page, which owns the preference and persists it. Held here as well, the
    // toggle inside the scanner changed a copy: it was lost on close and left the page's own
    // sound button showing the opposite of what the scanner was doing.
    isSoundOn: boolean;
    onSoundToggle: () => void;
    // A code was read and is being resolved. The page owns every sound the door makes — this
    // component used to keep its own copies and play them itself, which meant each scan was
    // announced twice, once by each side.
    onScanStart?: () => void;
}

export const QRScannerComponent = (props: QRScannerComponentProps) => {
    const videoRef = useRef<HTMLVideoElement>(null);
    const qrScannerRef = useRef<QrScanner | null>(null);
    const isSwitchingCameraRef = useRef(false);
    const [permissionDenied, setPermissionDenied] = useState(false);
    const [isCheckingIn, setIsCheckingIn] = useState(false);
    const [isFlashAvailable, setIsFlashAvailable] = useState(false);
    const [isFlashOn, setIsFlashOn] = useState(false);
    const [cameraList, setCameraList] = useState<QrScanner.Camera[]>();

    // The decode callback is registered once, when the camera starts, so everything it reads
    // lives in refs. Keeping the gate in state (a debounced value, an isCheckingIn flag read
    // from a render closure) let a code arrive while the gate was closed and never be looked
    // at again: the value did not change, so nothing re-ran, and only reopening unstuck it.
    const isBusyRef = useRef(false);
    const lastSeenRef = useRef<{ code: string, at: number } | null>(null);
    const checkedInIdsRef = useRef<Set<string>>(new Set());
    const onAttendeeScannedRef = useRef(props.onAttendeeScanned);
    onAttendeeScannedRef.current = props.onAttendeeScanned;
    const onScanStartRef = useRef(props.onScanStart);
    onScanStartRef.current = props.onScanStart;

    const [isScanFailed, setIsScanFailed] = useState(false);
    const [isScanSucceeded, setIsScanSucceeded] = useState(false);

    const feedbackTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const handleDecoded = (code: string) => {
        const now = Date.now();
        const lastSeen = lastSeenRef.current;
        const isSameCodeStillInFrame = lastSeen !== null
            && lastSeen.code === code
            && now - lastSeen.at < SAME_CODE_COOLDOWN_MS;

        if (isBusyRef.current) {
            if (lastSeen?.code === code) {
                lastSeenRef.current = {code, at: now};
            }
            return;
        }

        lastSeenRef.current = {code, at: now};

        if (isSameCodeStillInFrame) {
            return;
        }

        if (checkedInIdsRef.current.has(code)) {
            showError(t`You already scanned this ticket`);
            showScanFeedback(false);
            return;
        }

        isBusyRef.current = true;
        setIsCheckingIn(true);
        onScanStartRef.current?.();

        // The overlay must reflect what actually happened: a code this scanner cannot
        // resolve — a QR from another app, an unknown ticket — is a failed scan, and
        // showing it green would wave the person through.
        Promise.resolve(onAttendeeScannedRef.current(code))
            .then(checkedIn => {
                // Only a successful check-in blocks the code for the session; a failure (a
                // dropped connection at the door) must be retryable without reopening.
                if (checkedIn) {
                    checkedInIdsRef.current.add(code);
                }
                showScanFeedback(checkedIn);
            })
            .catch(() => showScanFeedback(false))
            .finally(() => {
                isBusyRef.current = false;
                setIsCheckingIn(false);
                lastSeenRef.current = {code, at: Date.now()};
            });
    };

    const startScanner = async () => {
        try {
            await navigator.mediaDevices.getUserMedia({video: true});
            if (videoRef.current) {
                qrScannerRef.current = new QrScanner(videoRef.current, (result) => {
                    handleDecoded(result.data);
                }, {
                    maxScansPerSecond: 5,
                });
                // Awaited: start() can reject on its own (another app holding the camera, a track
                // that ends as it opens) and unawaited that rejection escaped the try entirely,
                // leaving a scanner on screen that never reads anything and says nothing.
                await qrScannerRef.current.start();
            }
        } catch (error) {
            setPermissionDenied(true);
            console.error(error);
        }
    };

    const showScanFeedback = (succeeded: boolean) => {
        setIsScanSucceeded(succeeded);
        setIsScanFailed(!succeeded);

        // Visual only. The sound for this outcome is played by the page, which is where the
        // check-in is actually decided.
        if (feedbackTimeoutRef.current) {
            clearTimeout(feedbackTimeoutRef.current);
        }

        feedbackTimeoutRef.current = setTimeout(() => {
            setIsScanSucceeded(false);
            setIsScanFailed(false);
        }, 500);
    };

    const stopScanner = () => {
        const scanner = qrScannerRef.current;
        if (scanner) {
            qrScannerRef.current = null;
            scanner.stop();
            scanner.destroy();
        }
    };

    const handleClose = () => {
        stopScanner();
        props.onClose();
    };

    const handleFlashToggle = () => {
        if (!isFlashAvailable) {
            showError(t`Flash is not available on this device`);
            return;
        }
        if (qrScannerRef.current) {
            if (isFlashOn) {
                qrScannerRef.current.turnFlashOff();
            } else {
                qrScannerRef.current.turnFlashOn();
            }
            setIsFlashOn(!isFlashOn);
        }
    };

    const requestPermission = async () => {
        setPermissionDenied(false);
        await startScanner();
    };

    const updateFlashAvailability = async () => {
        if (qrScannerRef.current) {
            const hasFlash = await qrScannerRef.current.hasFlash();
            setIsFlashAvailable(hasFlash);
        }
    };

    useEffect(() => {
        startScanner().then(() => {
            updateFlashAvailability().catch(console.error);
            QrScanner.listCameras(true)
                .then(cameras => setCameraList(cameras));
        });

        // iOS Safari can pause the camera <video> when a sound plays, and qr-scanner stops
        // reading frames while the video is paused, with nothing to resume it: the preview
        // freezes and no further code is read until the scanner is closed and reopened.
        const video = videoRef.current;
        const resumeVideo = () => {
            if (qrScannerRef.current && video && !isSwitchingCameraRef.current
                && document.visibilityState === 'visible') {
                video.play().catch(() => {
                    // Ignore: qr-scanner resumes on its own when the page becomes visible
                });
            }
        };
        video?.addEventListener('pause', resumeVideo);

        return () => {
            video?.removeEventListener('pause', resumeVideo);
            if (feedbackTimeoutRef.current) {
                clearTimeout(feedbackTimeoutRef.current);
            }
            // permissionGranted was read from the first render's closure here, where it is
            // always false, so the camera was never released when the scanner closed.
            stopScanner();
        };
    }, []);

    const handleCameraSelection = (camera: QrScanner.Camera) => {
        isSwitchingCameraRef.current = true;
        return qrScannerRef.current?.setCamera(camera.id)
            .then(() => updateFlashAvailability().catch(console.error))
            .finally(() => {
                isSwitchingCameraRef.current = false;
            });
    };

    return (
        <div className={classes.videoContainer}>
            {permissionDenied && (
                <PermissionDeniedMessage
                    onRequestPermission={requestPermission}
                    onClose={handleClose}
                />
            )}

            <video className={classes.video} ref={videoRef}></video>

            <QrScannerControls
                isFlashAvailable={isFlashAvailable}
                isFlashOn={isFlashOn}
                isSoundOn={props.isSoundOn}
                cameraList={cameraList}
                onFlashToggle={handleFlashToggle}
                onSoundToggle={props.onSoundToggle}
                onCameraSelect={handleCameraSelection}
                onClose={handleClose}
            />

            <div className={`${classes.scannerOverlay} ${isScanSucceeded ? classes.success : ""} ${isScanFailed ? classes.failure : ""} ${isCheckingIn ? classes.checkingIn : ""}`}/>
        </div>
    );
};
