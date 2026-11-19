// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

export const LATO_MASSIMO = 1600;
export const QUALITA = 0.82;

export interface ImmagineLetta {
    larghezza: number;
    altezza: number;
    disegna(larghezza: number, altezza: number, tipo: string, qualita: number): Promise<Blob | null>;
    chiudi(): void;
}

export interface Codec {
    leggi(file: File): Promise<ImmagineLetta>;
}

export interface OpzioniPreparaImmagine {
    codec?: Codec;
    scattata?: boolean;
    adesso?: Date;
}

export type Trattamento = 'ricodifica' | 'se_grande' | 'niente';

export function misureRidotte(
    larghezza: number,
    altezza: number,
    latoMassimo: number = LATO_MASSIMO,
): { larghezza: number; altezza: number } {
    const latoLungo = Math.max(larghezza, altezza);
    if (latoLungo <= latoMassimo) {
        return {
            larghezza: Math.max(1, Math.round(larghezza)),
            altezza: Math.max(1, Math.round(altezza)),
        };
    }

    const scala = latoMassimo / latoLungo;
    return {
        larghezza: Math.max(1, Math.round(larghezza * scala)),
        altezza: Math.max(1, Math.round(altezza * scala)),
    };
}

export function trattamento(file: File): Trattamento {
    const tipo = (file.type || '').toLowerCase();
    const estensione = file.name.includes('.')
        ? file.name.slice(file.name.lastIndexOf('.')).toLowerCase()
        : '';

    if (tipo === 'image/jpeg' || estensione === '.jpg' || estensione === '.jpeg') {
        return 'ricodifica';
    }

    if (tipo === 'image/png' || estensione === '.png' || tipo === 'image/webp' || estensione === '.webp') {
        return 'se_grande';
    }

    return 'niente';
}

export function nomeFoto(adesso: Date = new Date()): string {
    const aaaa = adesso.getFullYear();
    const mm = String(adesso.getMonth() + 1).padStart(2, '0');
    const gg = String(adesso.getDate()).padStart(2, '0');
    const hh = String(adesso.getHours()).padStart(2, '0');
    const min = String(adesso.getMinutes()).padStart(2, '0');
    const ss = String(adesso.getSeconds()).padStart(2, '0');

    return `foto-${aaaa}${mm}${gg}-${hh}${min}${ss}.jpg`;
}

export const codecBrowser: Codec = {
    async leggi(file: File): Promise<ImmagineLetta> {
        if (
            typeof window === 'undefined' ||
            typeof document === 'undefined' ||
            typeof URL === 'undefined' ||
            typeof URL.createObjectURL !== 'function' ||
            typeof Image === 'undefined'
        ) {
            throw new Error('Ambiente browser non disponibile');
        }

        const url = URL.createObjectURL(file);
        try {
            const img = new Image();
            img.src = url;

            let timer: ReturnType<typeof setTimeout> | undefined;
            try {
                const timeout = new Promise<never>((_, reject) => {
                    timer = setTimeout(() => reject(new Error('Timeout decodifica')), 20000);
                });
                const decodePromise = typeof img.decode === 'function'
                    ? img.decode()
                    : new Promise<void>((resolve, reject) => {
                        img.onload = () => resolve();
                        img.onerror = () => reject(new Error('Errore decodifica'));
                    });
                await Promise.race([decodePromise, timeout]);
            } finally {
                if (timer) {
                    clearTimeout(timer);
                }
            }

            const larghezza = img.naturalWidth || img.width;
            const altezza = img.naturalHeight || img.height;

            return {
                larghezza,
                altezza,
                disegna: (w: number, h: number, tipo: string, qualita: number) => {
                    return new Promise<Blob | null>((resolve) => {
                        try {
                            const canvas = document.createElement('canvas');
                            canvas.width = w;
                            canvas.height = h;
                            const ctx = canvas.getContext('2d');
                            if (!ctx) {
                                resolve(null);
                                return;
                            }
                            ctx.drawImage(img, 0, 0, w, h);
                            if (typeof canvas.toBlob !== 'function') {
                                resolve(null);
                                return;
                            }
                            canvas.toBlob((blob) => resolve(blob), tipo, qualita);
                        } catch {
                            resolve(null);
                        }
                    });
                },
                chiudi: () => {
                    try {
                        URL.revokeObjectURL(url);
                    } catch {
                        // ignora
                    }
                },
            };
        } catch (e) {
            try {
                URL.revokeObjectURL(url);
            } catch {
                // ignora
            }
            throw e;
        }
    },
};

export async function preparaImmagine(
    file: File,
    opzioni: OpzioniPreparaImmagine = {},
): Promise<File> {
    const modo = trattamento(file);
    if (modo === 'niente') {
        return file;
    }

    const codec = opzioni.codec ?? codecBrowser;
    let immagine: ImmagineLetta | undefined;
    try {
        immagine = await codec.leggi(file);
        const latoLungo = Math.max(immagine.larghezza, immagine.altezza);
        if (modo === 'se_grande' && latoLungo <= LATO_MASSIMO) {
            return file;
        }

        const { larghezza, altezza } = misureRidotte(
            immagine.larghezza,
            immagine.altezza,
            LATO_MASSIMO,
        );

        let tipoTarget = 'image/jpeg';
        if (modo === 'se_grande') {
            if (file.type === 'image/png' || file.type === 'image/webp') {
                tipoTarget = file.type;
            } else {
                const estensione = file.name.slice(file.name.lastIndexOf('.')).toLowerCase();
                tipoTarget = estensione === '.webp' ? 'image/webp' : 'image/png';
            }
        }

        const blob = await immagine.disegna(larghezza, altezza, tipoTarget, QUALITA);
        if (!blob || blob.type !== tipoTarget) {
            return file;
        }

        let nome = file.name;
        if (modo === 'ricodifica' && opzioni.scattata) {
            nome = nomeFoto(opzioni.adesso);
        }

        return new File([blob], nome, { type: tipoTarget });
    } catch {
        return file;
    } finally {
        if (immagine) {
            try {
                immagine.chiudi();
            } catch {
                // ignora
            }
        }
    }
}
