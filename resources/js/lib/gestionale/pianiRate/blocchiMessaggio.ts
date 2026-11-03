/**
 * Come la modale della pagina del piano mostra un messaggio del server (verifica a video della 1.11.0-beta.42): i rifiuti
 * dell'emissione e del suo annullamento dicevano la cosa giusta in un blocco unico, centrato, difficile da seguire.
 *
 * Il server scrive testo semplice con tre convenzioni (`EmissioneRateController::elencoPuntato`, `passiNumerati`):
 *
 * - una riga vuota separa i capoversi;
 * - una riga che comincia con «• » è una voce di un elenco puntato (i passaggi, le ragioni);
 * - una riga che comincia con «1. », «2. », … è un passo numerato (che cosa fare, in ordine): solo «1.» apre una serie, e
 *   solo il numero dopo la continua, così una frase che comincia con un anno («2026. …») resta testo, e i numeri che
 *   l'elenco mostra sono sempre quelli scritti.
 *
 * Le altre righe sono testo. Un messaggio senza nessuna di queste forme resta un capoverso solo, come prima.
 */
export type BloccoMessaggio =
    | { tipo: 'testo'; testo: string }
    | { tipo: 'punti'; voci: string[] }
    | { tipo: 'passi'; voci: string[] };

const PUNTO = /^•\s+/;
const PASSO = /^(\d+)\.\s+/;

/** I capoversi del messaggio, ognuno come una fila di blocchi (testo, elenco puntato, passi numerati). */
export function blocchiMessaggio(messaggio: string | null | undefined): BloccoMessaggio[][] {
    if (!messaggio) return [];

    return messaggio
        .split(/\n\s*\n/)
        .map((capoverso) => {
            const blocchi: BloccoMessaggio[] = [];
            for (const riga of capoverso.split('\n').map((r) => r.trim()).filter((r) => r !== '')) {
                const ultimo = blocchi[blocchi.length - 1];
                if (PUNTO.test(riga)) {
                    const voce = riga.replace(PUNTO, '');
                    if (ultimo?.tipo === 'punti') ultimo.voci.push(voce);
                    else blocchi.push({ tipo: 'punti', voci: [voce] });
                } else if (seguePasso(riga, ultimo)) {
                    (ultimo as { voci: string[] }).voci.push(riga.replace(PASSO, ''));
                } else if (Number(riga.match(PASSO)?.[1]) === 1) {
                    blocchi.push({ tipo: 'passi', voci: [riga.replace(PASSO, '')] });
                } else if (ultimo?.tipo === 'testo') {
                    ultimo.testo += ' ' + riga;
                } else {
                    blocchi.push({ tipo: 'testo', testo: riga });
                }
            }

            return blocchi;
        })
        .filter((capoverso) => capoverso.length > 0);
}

/** «n.» continua la serie solo se viene subito dopo il passo n − 1 dello stesso blocco; «1.» ne apre una nuova. */
function seguePasso(riga: string, ultimo: BloccoMessaggio | undefined): boolean {
    const m = riga.match(PASSO);

    return m !== null && ultimo?.tipo === 'passi' && Number(m[1]) === ultimo.voci.length + 1;
}

/** Un messaggio da leggere a sinistra e in una modale più larga: più di un capoverso, o un elenco. */
export function messaggioArticolato(capoversi: BloccoMessaggio[][]): boolean {
    return capoversi.length > 1 || capoversi.some((c) => c.some((b) => b.tipo !== 'testo'));
}
