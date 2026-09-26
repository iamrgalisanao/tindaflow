import { useEffect, useMemo, useRef, useState } from 'react';
import { SHOTS } from './shots';

/**
 * A real screenshot with an animated "what to do" layer on top.
 *
 * Each mark points at a named box on the picture and has a `type`:
 *   click  - a cursor glides there and presses it (green). The clicks play in the order they are listed.
 *   type   - like click, then `text` is typed into the field (green).
 *   avoid  - "don't touch this" (red, dashed, always shown).
 *   look   - "read this, it is a result rather than an action" (blue, always shown).
 *
 * The numbered list under the picture repeats every mark in words, so nothing depends on the animation being
 * seen; under prefers-reduced-motion the cursor never moves and every mark is simply drawn in place.
 */

const ACTIONS = new Set(['click', 'type']);

const TONES = {
    click: { ring: 'border-emerald-400', dim: 'border-emerald-400/45', chip: 'bg-emerald-400 text-slate-950', badge: 'bg-emerald-400 text-slate-950' },
    type: { ring: 'border-emerald-400', dim: 'border-emerald-400/45', chip: 'bg-emerald-400 text-slate-950', badge: 'bg-emerald-400 text-slate-950' },
    avoid: { ring: 'border-red-400', dim: 'border-red-400', chip: 'bg-red-500 text-white', badge: 'bg-red-500 text-white' },
    look: { ring: 'border-sky-400', dim: 'border-sky-400', chip: 'bg-sky-400 text-slate-950', badge: 'bg-sky-400 text-slate-950' },
};

const LEGEND = [
    { type: 'click', label: 'Click or tap here', dot: 'bg-emerald-400' },
    { type: 'avoid', label: "Don't click", dot: 'bg-red-500' },
    { type: 'look', label: 'Just look', dot: 'bg-sky-400' },
];

function useReducedMotion() {
    const [reduced, setReduced] = useState(() => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false);
    useEffect(() => {
        const query = window.matchMedia?.('(prefers-reduced-motion: reduce)');
        if (!query) {
            return undefined;
        }
        const onChange = () => setReduced(query.matches);
        query.addEventListener('change', onChange);
        return () => query.removeEventListener('change', onChange);
    }, []);
    return reduced;
}

function useOnScreen(ref) {
    const [visible, setVisible] = useState(false);
    useEffect(() => {
        const node = ref.current;
        if (!node || typeof IntersectionObserver === 'undefined') {
            setVisible(true);
            return undefined;
        }
        const observer = new IntersectionObserver(([entry]) => setVisible(entry.isIntersecting), { threshold: 0.35 });
        observer.observe(node);
        return () => observer.disconnect();
    }, [ref]);
    return visible;
}

/** The picture's on-screen width, so a narrow phone can drop the floating labels and leave the explanation to the numbered list. */
function useWidth(ref) {
    const [width, setWidth] = useState(0);
    useEffect(() => {
        const node = ref.current;
        if (!node || typeof ResizeObserver === 'undefined') {
            return undefined;
        }
        const observer = new ResizeObserver(([entry]) => setWidth(entry.contentRect.width));
        observer.observe(node);
        return () => observer.disconnect();
    }, [ref]);
    return width;
}

function Cursor({ x, y, pressed }) {
    return (
        <svg
            viewBox="0 0 24 24"
            width="26"
            height="26"
            aria-hidden="true"
            className="pointer-events-none absolute z-30 drop-shadow-[0_2px_3px_rgba(0,0,0,0.6)] transition-[left,top,transform] duration-700 ease-in-out"
            style={{ left: `${x}%`, top: `${y}%`, transform: `translate(-6px, -3px) scale(${pressed ? 0.86 : 1})` }}
        >
            <path d="M5 3l14 8-6.2 1.6L9.8 19 5 3z" fill="#fff" stroke="#020617" strokeWidth="1.4" strokeLinejoin="round" />
        </svg>
    );
}

function TypedText({ rect, text, state }) {
    const [shown, setShown] = useState(0);

    useEffect(() => {
        if (state !== 'typing') {
            setShown(0);
            return undefined;
        }
        let count = 0;
        const timer = setInterval(() => {
            count += 1;
            setShown(count);
            if (count >= text.length) {
                clearInterval(timer);
            }
        }, 85);
        return () => clearInterval(timer);
    }, [state, text]);

    if (state === 'hidden') {
        return null;
    }

    const visibleText = state === 'full' ? text : text.slice(0, shown);

    return (
        <div
            className="absolute z-20 flex items-center overflow-hidden rounded-md bg-slate-950 px-[1.2%] font-mono text-[clamp(9px,1.25vw,14px)] text-slate-100"
            style={{ left: `calc(${rect[0]}% + 5px)`, top: `calc(${rect[1]}% + 5px)`, width: `calc(${rect[2]}% - 10px)`, height: `calc(${rect[3]}% - 10px)` }}
            aria-hidden="true"
        >
            <span className="truncate">{visibleText}</span>
            <span className="ml-px inline-block h-[1.1em] w-[2px] shrink-0 animate-help-caret bg-emerald-300" />
        </div>
    );
}

function MarkLabel({ mark, rect, tone, top }) {
    // Centre the chip on the box, but keep it inside the picture; sit it above the box unless there is no room.
    const centre = Math.min(84, Math.max(16, rect[0] + rect[2] / 2));
    const above = rect[1] > 11 && !top;

    return (
        <span
            className={`pointer-events-none absolute z-30 -translate-x-1/2 whitespace-nowrap rounded px-2 py-0.5 text-[clamp(10px,1.3vw,13px)] font-semibold shadow-lg ${tone.chip}`}
            style={above ? { left: `${centre}%`, top: `${rect[1]}%`, transform: 'translate(-50%, calc(-100% - 6px))' } : { left: `${centre}%`, top: `${rect[1] + rect[3]}%`, transform: 'translate(-50%, 6px)' }}
        >
            {mark.label}
        </span>
    );
}

function Figure({ shot, marks, alt, zoom, onZoom }) {
    const data = SHOTS[shot];
    const ref = useRef(null);
    const reduced = useReducedMotion();
    const visible = useOnScreen(ref);
    const compact = useWidth(ref) < 520;
    const [playing, setPlaying] = useState(true);
    const [active, setActive] = useState(-1); // index into `actions`; -1 = cursor waiting at the corner
    const [pressed, setPressed] = useState(false);
    const [clickTick, setClickTick] = useState(0);
    const [typing, setTyping] = useState(false);

    const drawn = useMemo(() => marks.map((mark, index) => ({ ...mark, index, rect: data?.t?.[mark.target] })).filter((mark) => mark.rect), [marks, data]);
    const actions = useMemo(() => drawn.filter((mark) => ACTIONS.has(mark.type)), [drawn]);
    const actionCount = actions.length;

    useEffect(() => {
        if (reduced || !playing || !visible || actionCount === 0) {
            return undefined;
        }
        let cancelled = false;
        const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

        (async () => {
            while (!cancelled) {
                for (let step = 0; step < actionCount; step += 1) {
                    if (cancelled) {
                        return;
                    }
                    setTyping(false);
                    setActive(step);
                    await wait(step === 0 ? 1000 : 850);
                    if (cancelled) {
                        return;
                    }
                    setPressed(true);
                    setClickTick((tick) => tick + 1);
                    await wait(160);
                    setPressed(false);
                    const isType = actions[step].type === 'type';
                    setTyping(isType);
                    await wait(isType ? 600 + (actions[step].text?.length ?? 0) * 85 + 900 : 1300);
                }
                setTyping(false);
                setActive(-1);
                await wait(1400);
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [reduced, playing, visible, actionCount, actions]);

    if (!data) {
        return (
            <div className="flex aspect-[16/10] items-center justify-center rounded-lg border border-dashed border-slate-700 bg-slate-900 text-xs text-slate-500">
                Screenshot &ldquo;{shot}&rdquo; has not been captured yet.
            </div>
        );
    }

    const cursorTarget = active >= 0 ? actions[active].rect : null;
    const cursor = cursorTarget ? { x: cursorTarget[0] + cursorTarget[2] * 0.62, y: cursorTarget[1] + cursorTarget[3] * 0.6 } : { x: 96, y: 96 };
    const activeMark = active >= 0 ? actions[active] : null;
    const showAllStatic = reduced;

    // A field keeps what was typed into it while the cursor moves on to the next one, and empties when the loop restarts.
    function typedState(position) {
        if (reduced || position < active) {
            return 'full';
        }
        if (position === active) {
            return !playing ? 'full' : typing ? 'typing' : 'hidden';
        }
        return 'hidden';
    }

    function jumpTo(mark) {
        const position = actions.indexOf(mark);
        if (position < 0) {
            return;
        }
        setPlaying(false);
        setActive(position);
        setTyping(false);
        setClickTick((tick) => tick + 1);
    }

    return (
        <figure className="mx-auto w-full space-y-2" style={{ maxWidth: `${Math.max(data.w, 420)}px` }}>
            <div
                ref={ref}
                className="relative w-full select-none overflow-hidden rounded-lg border border-slate-700 bg-slate-900 shadow-[0_4px_12px_rgba(0,0,0,0.45)]"
                style={{ aspectRatio: `${data.w} / ${data.h}` }}
            >
                <img src={data.url} width={data.w} height={data.h} alt={alt} loading="lazy" decoding="async" className="absolute inset-0 h-full w-full" draggable="false" />

                {drawn.map((mark) => {
                    const tone = TONES[mark.type] ?? TONES.click;
                    const isAction = ACTIONS.has(mark.type);
                    const isActive = isAction && activeMark === mark;
                    const position = isAction ? actions.indexOf(mark) : -1;
                    const boxClass = isAction
                        ? isActive
                            ? `${tone.ring} animate-help-pulse motion-reduce:animate-none bg-emerald-400/10`
                            : `${tone.dim} border-dashed`
                        : mark.type === 'avoid'
                          ? `${tone.ring} border-dashed bg-red-500/10 animate-help-wobble motion-reduce:animate-none`
                          : `${tone.ring} bg-sky-400/10`;

                    return (
                        <div key={mark.index}>
                            <div
                                className={`pointer-events-none absolute z-10 rounded-md border-2 transition-colors ${boxClass}`}
                                style={{ left: `${mark.rect[0]}%`, top: `${mark.rect[1]}%`, width: `${mark.rect[2]}%`, height: `${mark.rect[3]}%` }}
                            />
                            <span
                                className={`pointer-events-none absolute z-20 flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-bold shadow ${tone.badge}`}
                                style={{ left: `${mark.rect[0]}%`, top: `${mark.rect[1]}%`, transform: 'translate(-45%, -45%)' }}
                                aria-hidden="true"
                            >
                                {mark.type === 'avoid' ? '×' : mark.type === 'look' ? '?' : position + 1}
                            </span>
                            {(isActive || (!compact && (showAllStatic || !isAction))) && <MarkLabel mark={mark} rect={mark.rect} tone={tone} />}
                            {mark.type === 'type' && mark.text && (
                                <TypedText rect={mark.rect} text={mark.text} state={typedState(position)} />
                            )}
                        </div>
                    );
                })}

                {!reduced && actionCount > 0 && (
                    <>
                        <Cursor x={cursor.x} y={cursor.y} pressed={pressed} />
                        {activeMark && clickTick > 0 && (
                            <span
                                key={clickTick}
                                className="pointer-events-none absolute z-20 h-12 w-12 animate-help-ripple rounded-full border-2 border-emerald-300 bg-emerald-300/30"
                                style={{ left: `${cursor.x}%`, top: `${cursor.y}%` }}
                                aria-hidden="true"
                            />
                        )}
                    </>
                )}

                <div className="absolute right-2 top-2 z-40 flex gap-1.5">
                    {!reduced && actionCount > 0 && (
                        <button
                            type="button"
                            onClick={() => setPlaying((value) => !value)}
                            aria-label={playing ? 'Pause the animation' : 'Play the animation'}
                            className="flex h-8 items-center gap-1 rounded bg-slate-950/80 px-2 text-[11px] font-semibold text-slate-200 backdrop-blur hover:bg-slate-800"
                        >
                            {playing ? (
                                <svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true">
                                    <rect x="6" y="5" width="4" height="14" />
                                    <rect x="14" y="5" width="4" height="14" />
                                </svg>
                            ) : (
                                <svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true">
                                    <path d="M7 4l13 8-13 8V4z" />
                                </svg>
                            )}
                            {playing ? 'Pause' : 'Play'}
                        </button>
                    )}
                    {!zoom && (
                        <button
                            type="button"
                            onClick={onZoom}
                            aria-label="Enlarge this picture"
                            className="flex h-8 items-center gap-1 rounded bg-slate-950/80 px-2 text-[11px] font-semibold text-slate-200 backdrop-blur hover:bg-slate-800"
                        >
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" aria-hidden="true">
                                <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7" />
                            </svg>
                            Enlarge
                        </button>
                    )}
                </div>
            </div>

            {drawn.length > 0 && (
                <figcaption className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <ol className="min-w-0 flex-1 space-y-1">
                        {drawn.map((mark) => {
                            const tone = TONES[mark.type] ?? TONES.click;
                            const isAction = ACTIONS.has(mark.type);
                            const position = isAction ? actions.indexOf(mark) : -1;
                            const content = (
                                <>
                                    <span className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold ${tone.badge}`} aria-hidden="true">
                                        {mark.type === 'avoid' ? '×' : mark.type === 'look' ? '?' : position + 1}
                                    </span>
                                    <span className="text-left text-sm text-slate-300">
                                        {mark.type === 'avoid' && <span className="font-semibold text-red-300">Don&apos;t: </span>}
                                        {mark.type === 'look' && <span className="font-semibold text-sky-300">Look: </span>}
                                        {mark.detail ?? mark.label}
                                        {mark.type === 'type' && mark.text && (
                                            <>
                                                {' '}
                                                <code className="rounded bg-slate-800 px-1.5 py-0.5 font-mono text-[12px] text-sky-300">{mark.text}</code>
                                            </>
                                        )}
                                    </span>
                                </>
                            );
                            return (
                                <li key={mark.index}>
                                    {isAction ? (
                                        <button type="button" onClick={() => jumpTo(mark)} className="flex w-full items-start gap-2 rounded px-1 py-0.5 hover:bg-slate-800/70">
                                            {content}
                                        </button>
                                    ) : (
                                        <div className="flex items-start gap-2 px-1 py-0.5">{content}</div>
                                    )}
                                </li>
                            );
                        })}
                    </ol>
                    <ul className="flex shrink-0 flex-wrap gap-x-3 gap-y-1 text-[11px] text-slate-500 sm:flex-col sm:gap-y-1" aria-label="What the colours mean">
                        {LEGEND.map((item) => (
                            <li key={item.type} className="flex items-center gap-1.5">
                                <span className={`h-2.5 w-2.5 rounded-full ${item.dot}`} aria-hidden="true" />
                                {item.label}
                            </li>
                        ))}
                    </ul>
                </figcaption>
            )}
        </figure>
    );
}

export default function StepFigure(props) {
    const [zoomed, setZoomed] = useState(false);

    useEffect(() => {
        if (!zoomed) {
            return undefined;
        }
        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                setZoomed(false);
            }
        };
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.body.style.overflow = previousOverflow;
        };
    }, [zoomed]);

    return (
        <>
            <Figure {...props} onZoom={() => setZoomed(true)} />
            {zoomed && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-6" role="dialog" aria-modal="true" aria-label="Enlarged picture">
                    <div className="absolute inset-0 bg-slate-950/90 backdrop-blur-sm" onClick={() => setZoomed(false)} aria-hidden="true" />
                    <div className="relative max-h-full w-full max-w-6xl overflow-y-auto rounded-xl border border-slate-700 bg-slate-950 p-3 sm:p-4">
                        <button
                            type="button"
                            onClick={() => setZoomed(false)}
                            aria-label="Close the enlarged picture"
                            className="mb-2 ml-auto flex min-h-11 items-center rounded border border-slate-700 px-3 text-sm text-slate-300 hover:bg-slate-800"
                        >
                            Close
                        </button>
                        <Figure {...props} zoom />
                    </div>
                </div>
            )}
        </>
    );
}
