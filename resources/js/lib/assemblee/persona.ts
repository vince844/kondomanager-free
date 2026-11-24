// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/** Le due votazioni del punto del rendiconto, quando si votano a parte. */
export type OggettoDelVoto = 'rendiconto' | 'riparto';

/** «Le sue assemblee» nella linguetta «Assemblee» della scheda della persona (decisioni 622 (5) e 651): una persona in un'assemblea, come lo manda il server (`AssembleeDellaPersona`). */
export interface PersonaInAssemblea {
    id: number;
    condominio_id: number;
    condominio: string;
    data: string | null;
    anno: number | null;
    /** «Per lei», il badge della riga: niente se l'assemblea non si è ancora tenuta. */
    per_lei: { parola: string; tono: 'ambra' | 'verde' | 'grigio' } | null;
    nome: string;
    verbale: string | null;
    convocazione: string | null;
    in_sala: string | null;
    per_delega_da: string | null;
    /** I punti a favore, interi: per il punto votato due volte, a favore del rendiconto e del riparto. */
    favorevole_ai_punti: number[];
    /** Il rendiconto o il riparto a favore, quando l'altro voto dello stesso punto è diverso (Coda 750 B2): «e al riparto del punto 1». */
    favorevole_alle_parti?: { punto: number; oggetto: OggettoDelVoto }[];
    /** Una riga per voto che non è a favore; il punto votato due volte con due voti diversi ha due righe, con `oggetto`. */
    altri_voti: { punto: number; oggetto?: OggettoDelVoto | null; voto: string; testo: string | null; dichiarazioni: string[] }[];
    puo_impugnare_fino_al: string | null;
    impugnare_dopo_la_consegna: boolean;
    sulle_sue_cose: { frase: string; url: string | null }[];
}

/** Il mezzo con cui si convoca la persona (art. 66 disp. att.), a parole. */
const PAROLE_DEI_MEZZI: Record<string, string> = { pec: 'PEC', raccomandata: 'Raccomandata', a_mano: 'A mano', fax: 'Fax' };
export const parolaDelMezzo = (mezzo: string | null | undefined): string | null => PAROLE_DEI_MEZZI[mezzo ?? ''] ?? null;

/** «ai punti 2, 3 e 5», «al punto 4», con le parti: «ai punti 2 e 3 e al riparto del punto 1», «al riparto del punto 1». */
export function aiPunti(punti: number[], parti: { punto: number; oggetto: OggettoDelVoto }[] = []): string {
    const voci = [
        ...(punti.length === 0 ? [] : [punti.length === 1 ? `al punto ${punti[0]}` : `ai punti ${punti.slice(0, -1).join(', ')} e ${punti[punti.length - 1]}`]),
        ...parti.map((x) => `al ${x.oggetto} del punto ${x.punto}`),
    ];
    return voci.length <= 1 ? (voci[0] ?? '') : `${voci.slice(0, -1).join(', ')} e ${voci[voci.length - 1]}`;
}
