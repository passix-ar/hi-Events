import {useCallback, useEffect, useRef, useState} from "react";
import {isSsr} from "../utilites/helpers";

const STORAGE_KEY = "scannerSoundOn";

/**
 * The door's sounds, owned in one place.
 *
 * They used to live in two: the check-in page had its own pair of `<audio>` elements and the camera
 * scanner had another three. Both fired for the same scan — the page when it resolved the check-in,
 * the scanner when the promise came back to it — so every person walking in triggered the same wav
 * twice, milliseconds apart. Whoever renders `audioElements` is now the only emitter; the scanner
 * asks for a sound through a callback instead of keeping its own copies.
 */
export const useScanSounds = () => {
    const [isSoundOn, setIsSoundOn] = useState<boolean>(() => {
        if (isSsr()) return true;
        try {
            const stored = localStorage.getItem(STORAGE_KEY);
            return stored === null ? true : JSON.parse(stored);
        } catch {
            // Storage blocked or holding something unparseable: sound on is the safer default.
            return true;
        }
    });

    useEffect(() => {
        if (isSsr()) return;
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(isSoundOn));
        } catch {
            // Storage full or blocked: the preference just does not survive a reload.
        }
    }, [isSoundOn]);

    // The scanner's decode callback is registered once, so it reads the preference from a ref
    // rather than from the closure it was created in.
    const isSoundOnRef = useRef(isSoundOn);
    isSoundOnRef.current = isSoundOn;

    const successRef = useRef<HTMLAudioElement | null>(null);
    const errorRef = useRef<HTMLAudioElement | null>(null);
    const inProgressRef = useRef<HTMLAudioElement | null>(null);

    const play = useCallback((audio: HTMLAudioElement | null) => {
        if (!isSoundOnRef.current || !audio) return;

        // Rewound first: at a door two people go through within a second of each other, and calling
        // play() on an element that is already playing is a no-op — the second scan stayed silent.
        audio.currentTime = 0;
        audio.play().catch(() => {
            // Ignore: the browser blocks autoplay until the page has been interacted with.
        });
    }, []);

    const playSuccess = useCallback(() => play(successRef.current), [play]);
    const playError = useCallback(() => play(errorRef.current), [play]);
    const playInProgress = useCallback(() => play(inProgressRef.current), [play]);

    const audioElements = (
        <>
            <audio ref={successRef} src="/sounds/scan-success.wav"/>
            <audio ref={errorRef} src="/sounds/scan-error.wav"/>
            <audio ref={inProgressRef} src="/sounds/scan-in-progress.wav"/>
        </>
    );

    return {isSoundOn, setIsSoundOn, playSuccess, playError, playInProgress, audioElements};
};
