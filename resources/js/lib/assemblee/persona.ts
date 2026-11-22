// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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
    favorevole_ai_punti: number[];
    altri_voti: { punto: number; voto: string; testo: string | null; dichiarazioni: string[] }[];
    puo_impugnare_fino_al: string | null;
    impugnare_dopo_la_consegna: boolean;
    sulle_sue_cose: { frase: string; url: string | null }[];
}

/** Il mezzo con cui si convoca la persona (art. 66 disp. att.), a parole. */
const PAROLE_DEI_MEZZI: Record<string, string> = { pec: 'PEC', raccomandata: 'Raccomandata', a_mano: 'A mano', fax: 'Fax' };
export const parolaDelMezzo = (mezzo: string | null | undefined): string | null => PAROLE_DEI_MEZZI[mezzo ?? ''] ?? null;
