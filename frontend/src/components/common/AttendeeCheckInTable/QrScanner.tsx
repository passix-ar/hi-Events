import {useEffect, useRef, useState} from 'react';
import QrScanner from 'qr-scanner';
import classes from './QrScanner.module.scss';
import {showError} from "../../../utilites/notifications.tsx";
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
    isSoundOn?: boolean;
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

    const [isScanFailed, setIsScanFailed] = useState(false);
    const [isScanSucceeded, setIsScanSucceeded] = useState(false);

    const feedbackTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const scanSuccessAudioRef = useRef<HTMLAudioElement | null>(null);
    const scanErrorAudioRef = useRef<HTMLAudioElement | null>(null);
    const scanInProgressAudioRef = useRef<HTMLAudioElement | null>(null);

    const [isSoundOn, setIsSoundOn] = useState(() => {
        // Use the prop value if provided, otherwise fallback to unified storage
        if (props.isSoundOn !== undefined) {
            return props.isSoundOn;
        }
        const storedIsSoundOn = localStorage.getItem("scannerSoundOn");
        return storedIsSoundOn === null ? true : JSON.parse(storedIsSoundOn);
    });

    // Sync with prop changes
    useEffect(() => {
        if (props.isSoundOn !== undefined) {
            setIsSoundOn(props.isSoundOn);
        }
    }, [props.isSoundOn]);

    useEffect(() => {
        // Only save to localStorage if not controlled by props
        if (props.isSoundOn === undefined) {
            localStorage.setItem("scannerSoundOn", JSON.stringify(isSoundOn));
        }
    }, [isSoundOn, props.isSoundOn]);

    const isSoundOnRef = useRef(isSoundOn);
    isSoundOnRef.current = isSoundOn;

    const playAudio = (audio: HTMLAudioElement | null) => {
        if (isSoundOnRef.current && audio) {
            audio.play().catch(() => {
                // Ignore audio play errors (e.g. the browser blocked autoplay)
            });
        }
    };

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
        playAudio(scanInProgressAudioRef.current);

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
                qrScannerRef.current.start();
            }
        } catch (error) {
            setPermissionDenied(true);
            console.error(error);
        }
    };

    const showScanFeedback = (succeeded: boolean) => {
        setIsScanSucceeded(succeeded);
        setIsScanFailed(!succeeded);

        playAudio(succeeded ? scanSuccessAudioRef.current : scanErrorAudioRef.current);

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

    const handleSoundToggle = () => {
        setIsSoundOn(!isSoundOn);
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
                isSoundOn={isSoundOn}
                cameraList={cameraList}
                onFlashToggle={handleFlashToggle}
                onSoundToggle={handleSoundToggle}
                onCameraSelect={handleCameraSelection}
                onClose={handleClose}
            />

            <audio ref={scanSuccessAudioRef} src="/sounds/scan-success.wav"/>
            <audio ref={scanErrorAudioRef} src="/sounds/scan-error.wav"/>
            <audio ref={scanInProgressAudioRef} src="/sounds/scan-in-progress.wav"/>
            
            <div className={`${classes.scannerOverlay} ${isScanSucceeded ? classes.success : ""} ${isScanFailed ? classes.failure : ""} ${isCheckingIn ? classes.checkingIn : ""}`}/>
        </div>
    );
};
