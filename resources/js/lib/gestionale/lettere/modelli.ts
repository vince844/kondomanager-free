/**
 * Le parole dei modelli delle lettere: la finestra della lettera (il menu, «Salva come modello…», «Aggiorna») e la pagina «Modelli delle
 * lettere». Le regole stanno nel server (`ModelliLettere`): l'ordine, il modello ricordato, il nome unico, il permesso. Qui c'è quello che serve a
 * mostrarle. Le date arrivano dal server come `Y-m-d`: qui si formattano e basta.
 */
import { giorno } from '@/lib/gestionale/debiti/rateScadute';
import { NOMI_TIPI, type TipoLettera } from '@/lib/gestionale/lettere/lettere';

/** I tipi che hanno i modelli: le lettere e, dalla 1.12.0-beta.9, l'email dei fogli del conguaglio. */
export type TipoModello = TipoLettera | 'foglio_conguaglio';

/** Il nome di ogni tipo, come lo dice la pagina dei modelli. */
export const NOMI_MODELLI: Record<TipoModello, string> = { ...NOMI_TIPI, foglio_conguaglio: 'Email del foglio del conguaglio' };

/** Un modello come lo manda la finestra: per tutti i condomini, o per quello della finestra. */
export interface ModelloDellaFinestra { id: number; tipo: TipoModello; nome: string; testo: string; per_tutti: boolean }

/** L'ultimo modello di un tipo nel condominio; `mancante` se non c'è più o non vale più qui. */
export interface ModelloRicordato { id: number | null; nome: string; mancante: boolean }

/** Un modello come lo mostra la pagina. */
export interface ModelloDellaPagina {
    id: number; tipo: TipoModello; nome: string; testo: string; condominio: { id: number; nome: string } | null; cambiato_da: string | null; cambiato_il: string | null;
}

/** I valori con cui la pagina mostra l'anteprima di un testo, scritti dal server come li scrive la lettera. */
export interface Esempio { nome: string; totale: string; termine: string; condominio: string; esercizio?: string }

/** A chi non può salvare i modelli, nella finestra: chi li salva, e che qui il testo cambia solo per queste lettere. */
export const FRASE_MODELLI_SENZA_PERMESSO = 'I modelli li salva chi gestisce le impostazioni di stampa: qui cambi il testo solo per queste lettere.';

/** A chi non può cambiare i modelli, nella pagina. */
export const FRASE_PAGINA_SENZA_PERMESSO = 'I modelli li crea e li cambia chi gestisce le impostazioni di stampa: qui li vedi, e nella finestra delle lettere li usi.';

/** La conferma dell'eliminazione. */
export const FRASE_ELIMINA = 'Le lettere già scritte con questo modello non cambiano: il testo di ognuna è nella lettera.';

/** Il testo del programma, nella pagina. */
export const FRASE_PROGRAMMA = 'Il testo da cui parte la finestra quando non scegli un modello. Non si modifica: duplicalo per farne un modello tuo.';

/** In testa alla pagina: che cosa è un modello, e che le lettere ne copiano il testo. */
export const FRASE_PAGINA = 'Nella finestra della lettera scegli il modello, che riempie il testo; il testo resta modificabile per quell\'invio. '
    + 'La lettera copia il testo: cambiare o eliminare un modello dopo non cambia le lettere già scritte.';

/** Il modello scelto l'ultima volta per un tipo non c'è più, o non vale più per questo condominio: la finestra parte dal testo del programma. */
export function testoModelloMancante(nome: string): string {
    const quale = nome.trim() ? `Il modello «${nome.trim()}», scelto l'ultima volta per questo tipo di lettera,` : 'Il modello scelto l\'ultima volta per questo tipo di lettera';

    return `${quale} non c'è più o non vale più per questo condominio: la finestra parte dal testo del programma.`;
}

/** I modelli di un tipo, come il menu li mostra: prima quelli del condominio, poi quelli per tutti, ognuno nell'ordine del server. */
export function modelliDelTipo(modelli: ModelloDellaFinestra[], tipo: TipoModello): { delCondominio: ModelloDellaFinestra[]; perTutti: ModelloDellaFinestra[] } {
    const delTipo = modelli.filter((m) => m.tipo === tipo);

    return { delCondominio: delTipo.filter((m) => !m.per_tutti), perTutti: delTipo.filter((m) => m.per_tutti) };
}

/** Dopo «Salva come modello…». */
export function testoSalvato(nome: string, perTutti: boolean): string {
    return `Salvato il modello «${nome}», ${perTutti ? 'per tutti i condomini' : 'per questo condominio'}.`;
}

/** Il pulsante «Aggiorna»: un modello che vale per tutti i condomini lo dice, perché il testo cambia per tutti. */
export function testoAggiorna(nome: string, perTutti: boolean): string {
    return perTutti ? `Aggiorna «${nome}» per tutti i condomini` : `Aggiorna «${nome}»`;
}

/** Dopo «Aggiorna»: un modello che vale per tutti i condomini lo dice. */
export function testoAggiornato(nome: string, perTutti = false): string {
    return perTutti
        ? `Aggiornato il modello «${nome}», che vale per tutti i condomini: le lettere già scritte non cambiano.`
        : `Aggiornato il modello «${nome}»: le lettere già scritte non cambiano.`;
}

/** Scelto un modello sul testo cambiato a mano: il testo di prima si rimette. */
export const FRASE_TESTO_SOSTITUITO = 'Il testo che avevi scritto è stato sostituito dal modello.';

/** «Vale per tutti i condomini» o «Vale per Le Rose». */
export function testoValePer(condominio: { nome: string } | null): string {
    return condominio ? `Vale per ${condominio.nome}` : 'Vale per tutti i condomini';
}

/** «Cambiato da Marta Neri il 20/03/2026»; senza il nome (l'utente non c'è più) «Cambiato il 20/03/2026». */
export function testoCambiato(m: { cambiato_da: string | null; cambiato_il: string | null }): string {
    if (!m.cambiato_il) {
        return '';
    }

    return m.cambiato_da ? `Cambiato da ${m.cambiato_da} il ${giorno(m.cambiato_il)}` : `Cambiato il ${giorno(m.cambiato_il)}`;
}

/** Il nome proposto per la copia: «Sollecito cortese (copia)»; dal testo del programma, il nome del tipo. */
export function nomeDellaCopia(nome: string | null, tipo: TipoModello): string {
    return `${nome ?? NOMI_MODELLI[tipo]} (copia)`;
}

/**
 * L'anteprima di un testo con i valori d'esempio: i campi riempiti come li riempie la lettera (`TestiLettere::riempi()` nel server, la stessa
 * regola per un nome che comincia già con «Condominio»), e [esercizio] come lo riempie l'email dei fogli. È solo per la pagina: la lettera la
 * riempie il server.
 */
export function riempiEsempio(testo: string, esempio: Esempio, campiDelTipo?: Record<string, string>): string {
    const conLaParola = /^\s*(super)?condominio(?![\p{L}\p{N}])/iu.test(esempio.condominio);
    const base = conLaParola ? testo.replace(/(?<![\p{L}\p{N}])condominio\s+\[condominio\]/giu, '[condominio]') : testo;
    const campi: Record<string, string> = { '[nome]': esempio.nome, '[totale]': esempio.totale, '[termine]': esempio.termine, '[condominio]': esempio.condominio };
    if (esempio.esercizio !== undefined) {
        campi['[esercizio]'] = esempio.esercizio;
    }

    // Con i campi del tipo, solo quelli si riempiono: gli altri restano come sono scritti, come li lascia (o li rifiuta) il server.
    return base.replace(/\[(nome|totale|termine|condominio|esercizio)\]/g, (c) => (campiDelTipo && !(c in campiDelTipo) ? c : (campi[c] ?? c)));
}
