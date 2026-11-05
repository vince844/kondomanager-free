<script setup lang="ts">
/**
 * La finestra della ricevuta di un incasso (1.12.0-beta.2): una sola per «Stampa ricevuta» e «Invia ricevuta». Il bollo (quattro pulsanti, nessuno
 * acceso la prima volta; poi l'ultima scelta in questo condominio) e gli estremi di quello assolto, la nota (vuota ogni volta: è di questa ricevuta),
 * «Stampa la causale», gli indirizzi a cui mandarla, un altro indirizzo. Mostra a quali quote sarà imputato il versamento. «Anteprima» apre il
 * PDF senza emetterlo; si stampa e si invia senza riaprirla. I destinatari e gli indirizzi li propone il server. Se la ricevuta è già stata
 * emessa la prossima è una copia, con le cifre dell'originale; se dopo la situazione delle rate è cambiata, la finestra lo dice e rimanda
 * all'estratto conto. Di un incasso annullato la ricevuta si stampa, e si manda per email agli indirizzi a cui era già andata; se era uscita,
 * «Non serve comunicarla» lo dice, con una conferma (decisione 206.2). Le quote stanno in una tabella compatta, un gruppo per piano (206.1).
 * Senza un server di posta, o dopo un invio non partito, «Prepara l'email» fa il PDF (una stampa nel registro) e lo affida al programma di posta
 * di chi lavora: con la condivisione del browser, o scaricandolo e aprendo l'email (decisione 207.1). Mentre il PDF si prepara la finestra non si
 * chiude e gli indirizzi non si toccano: il PDF arriva a chi lavora, nella finestra della sua ricevuta, con gli indirizzi che ha scelto.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import SceltaBollo from '@/components/gestionale/movimenti/incassi/SceltaBollo.vue';
import EstremiBollo from '@/components/gestionale/movimenti/incassi/EstremiBollo.vue';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Eye, Loader2, Mail, Printer, Share2 } from 'lucide-vue-next';
import {
    AVVISO_PEC, FRASE_BOLLO, NOTA_CONTRASSEGNO_EMAIL, NOTA_INTERESSI, RIGA_ORIGINALE, TESTO_COPIA_CAMBIATA, TESTO_IMPUTAZIONE, TIPI_INDIRIZZO, apriPdfInUnaScheda,
    chiaveIndirizzo, destinatariScelti, estremiDaMandare, fraseDaErroreConFile, frasePerErrore, gruppiDelleQuote, indirizziIniziali, indirizzoMailto,
    nomeDalContentDisposition, preparaEmail, scaricaFile, statoConModo, testoConfermaNonServe, testoDelPdf, testoDellaBozza, testoEmissione, testoParteInPiu, testoRiepilogo,
    testoSogliaCredito, totaliDelleQuote, type BozzaEmail, type Destinatario, type Emissione, type QuotaDellaRicevuta,
} from '@/lib/gestionale/incassi/ricevute';

interface Opzioni {
    id: number;
    numero: string;
    data_versamento: string | null;
    importo: number;
    causale: string | null;
    compensazioni_escluse: Array<{ persona: string; importo: number }>;
    quote: QuotaDellaRicevuta[];
    parte_in_piu: Array<{ importo: number; persona: string | null; rata: string | null; riduce: number; a_credito: number }>;
    credito_usato: number;
    sopra_soglia: boolean;
    annullato: boolean;
    inviabile: boolean;
    emettibile: boolean;
    motivo: string | null;
    persone_dell_incasso: Array<{ id: number; nome: string }>;
    senza_server_posta: boolean;
    bozza_email?: BozzaEmail;
    copia: { del: string; cambiata: boolean } | null;
    storno: { protocollo: string; data: string } | null;
    posizione: { id: number; nome: string } | null;
    versato_da: { id: number; nome: string } | null;
    destinatari: Destinatario[];
    emissioni: Emissione[];
    scelta_ricordata: {
        bollo_sopra: string | null; bollo_sotto: string | null; modo_bollo: string | null; estremi_bollo: string | null;
        stampa_causale: boolean | null;
    };
}

const props = defineProps<{ condominioId: number; incassoId: number | null }>();
const aperta = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ (e: 'emessa'): void }>();

const { generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();

const opzioni = ref<Opzioni | null>(null);
const errore = ref<string | null>(null);
const esito = ref<string | null>(null);
const inviando = ref(false);
const bollo = ref<string | null>(null);
const modo = ref<string | null>(null);
const estremi = ref('');
const nota = ref('');
const stampaCausale = ref(false);
const caselle = ref<Record<string, boolean>>({});
const altroIndirizzo = ref('');

const giorno = (d: string | null | undefined) => (d ? d.split('-').reverse().join('/') : '');
// La finestra è una sola per tutte le righe della pagina delle ricevute: una richiesta partita per un incasso, quando risponde, trova la finestra
// ancora aperta su quell'incasso, o chiusa, o passata a un altro.
const stessaFinestra = (incassoId: number) => aperta.value && opzioni.value?.id === incassoId;

async function carica() {
    if (!props.incassoId) return;
    errore.value = null;
    try {
        const risposta = await axios.get(route(generateRoute('gestionale.ricevute.opzioni'), { condominio: props.condominioId, scrittura: props.incassoId }));
        const o = risposta.data as Opzioni;
        const primaVolta = opzioni.value?.id !== o.id;
        opzioni.value = o;
        if (primaVolta) {
            modo.value = o.scelta_ricordata.modo_bollo ?? null;
            bollo.value = statoConModo(o.sopra_soglia ? o.scelta_ricordata.bollo_sopra : o.scelta_ricordata.bollo_sotto, modo.value);
            // Il numero di un contrassegno è di una ricevuta sola: il server ripropone solo l'autorizzazione del modo virtuale.
            estremi.value = o.scelta_ricordata.modo_bollo === 'virtuale' ? (o.scelta_ricordata.estremi_bollo ?? '') : '';
            // La nota è di questa ricevuta: parte vuota ogni volta, perché una scritta per un'altra persona non finisca su questa.
            nota.value = '';
            stampaCausale.value = !!o.scelta_ricordata.stampa_causale;
            caselle.value = indirizziIniziali(o.destinatari);
            altroIndirizzo.value = '';
            esito.value = null;
            chiediSegna.value = false;
            preparata.value = null;
        }
    } catch {
        errore.value = 'Non è stato possibile leggere i dati della ricevuta. Riprova.';
    }
}

watch(aperta, (v) => { if (v) { opzioni.value = null; carica(); } });

const scelti = computed(() => (opzioni.value ? destinatariScelti(opzioni.value.destinatari, caselle.value) : []));
// La copia c'è solo se una ricevuta è già uscita (stampata o partita): il server lo dice con `copia`.
const haOriginale = computed(() => !!opzioni.value?.copia);
const giaInviata = computed(() => opzioni.value?.emissioni.find((e) => e.canale === 'email' && e.stato === 'inviata') ?? null);
const scelte = () => ({
    bollo: bollo.value,
    ...estremiDaMandare([bollo.value], modo.value, estremi.value, false),
    nota: nota.value || null,
    stampa_causale: stampaCausale.value ? 1 : 0,
});
// Un pulsante del bollo scrive lo stato e, se è una delle due «Assolta», il modo. «Non dovuta» e «Da assolvere» lasciano il modo e gli estremi di
// prima dove sono: tornando su «Assolta» ci sono ancora, e la richiesta li porta solo con «Assolta».
function scegliBollo(scelta: { stato: string; modo: string | null }) {
    bollo.value = scelta.stato;
    if (scelta.modo) modo.value = scelta.modo;
}
// Il PDF che si sta preparando, dell'anteprima o della stampa: intanto un altro clic non ne chiede un secondo (per la stampa sarebbe una
// seconda riga nel registro).
const pdfInArrivo = ref<'anteprima' | 'stampa' | null>(null);
const puoStampare = computed(() => !!opzioni.value && opzioni.value.emettibile && !!bollo.value && !pdfInArrivo.value);
// L'anteprima non emette niente: non chiede il bollo.
const puoVedere = computed(() => !!opzioni.value && opzioni.value.emettibile && !pdfInArrivo.value);
const puoInviare = computed(() => !!opzioni.value && opzioni.value.emettibile && opzioni.value.inviabile && !opzioni.value.senza_server_posta && !!bollo.value
    && (scelti.value.length > 0 || altroIndirizzo.value.trim() !== '') && !inviando.value);
// La tabella delle quote: un gruppo per piano e gestione, il totale in fondo (decisione 206.1).
const gruppiQuote = computed(() => gruppiDelleQuote(opzioni.value?.quote ?? []));
const totaliQuote = computed(() => totaliDelleQuote(opzioni.value?.quote ?? []));
const estrattoContoDi = (anagrafica: number) => route(generateRoute('gestionale.anagrafiche.estratto-conto'), { condominio: props.condominioId, anagrafica });
const estrattoConto = computed(() => (opzioni.value?.posizione ? estrattoContoDi(opzioni.value.posizione.id) : null));
const creditoNellaSoglia = computed(() => testoSogliaCredito(opzioni.value?.credito_usato ?? 0));

// «Anteprima» e «Stampa»: la scheda nuova si apre nel clic, il PDF arriva con una richiesta in sottofondo (`apriPdfInUnaScheda`). Niente
// `await` prima della chiamata: la scheda aperta dopo un'attesa il browser la blocca. La stampa ha scritto il registro quando la risposta
// arriva: allora la finestra si rilegge e la pagina lo sa; l'anteprima non scrive niente e non ricarica niente.
async function apriIlPdf(quale: 'anteprima' | 'stampa') {
    const o = opzioni.value;
    if (!o || pdfInArrivo.value || !(quale === 'stampa' ? puoStampare.value : puoVedere.value)) return;
    const stampa = quale === 'stampa';
    pdfInArrivo.value = quale;
    errore.value = null;
    esito.value = null;
    try {
        const pdf = await apriPdfInUnaScheda(
            () => axios.post(route(generateRoute(stampa ? 'gestionale.ricevute.stampa' : 'gestionale.ricevute.pdf-anteprima'), { condominio: props.condominioId }),
                { incassi: [o.id], ...scelte() }, { responseType: 'blob' }),
            `${stampa ? 'ricevuta' : 'anteprima-ricevuta'}-${o.numero}.pdf`,
        );
        // La finestra è una sola per tutte le righe della pagina: l'esito si legge nella finestra di questa ricevuta.
        if (stessaFinestra(o.id)) {
            esito.value = testoDelPdf(pdf, { stampa, una: true });
            if (stampa) await carica();
        }
        if (stampa) emit('emessa');
    } catch (e: any) {
        const frase = await fraseDaErroreConFile(e, stampa ? 'Non è stato possibile stampare la ricevuta. Riprova.' : 'Non è stato possibile aprire l\'anteprima. Riprova.');
        if (stessaFinestra(o.id)) errore.value = frase;
    } finally {
        pdfInArrivo.value = null;
    }
}
const anteprima = () => apriIlPdf('anteprima');
const stampa = () => apriIlPdf('stampa');

// ── «Non serve comunicarla» (decisione 206.2): di un incasso annullato la cui ricevuta era uscita, con una conferma breve ──
const chiediSegna = ref(false);
const segnando = ref(false);
const puoSegnare = computed(() => !!opzioni.value?.annullato && haOriginale.value && !opzioni.value.emissioni.some((e) => e.stato === 'non_da_comunicare'));
async function segnaNonServe() {
    if (!opzioni.value || segnando.value) return;
    const id = opzioni.value.id;
    segnando.value = true;
    errore.value = null;
    try {
        await axios.post(route(generateRoute('gestionale.ricevute.non-serve-comunicarla'), { condominio: props.condominioId, scrittura: id }));
        // La finestra è una sola per tutte le righe della pagina: se intanto è stata chiusa o è passata a un altro incasso, la scelta è
        // scritta sul suo incasso, la pagina lo sa, e il messaggio non va a chi guarda un'altra ricevuta.
        if (stessaFinestra(id)) {
            chiediSegna.value = false;
            esito.value = 'Segnata: alla persona non serve comunicare l\'annullamento. La ricevuta resta fra le emissioni dell\'incasso.';
            await carica();
        }
        emit('emessa');
    } catch (e: any) {
        if (stessaFinestra(id)) errore.value = frasePerErrore(e, 'Non è stato possibile segnarla. Riprova.');
    } finally {
        segnando.value = false;
    }
}

// ── «Prepara l'email» (decisione 207.1): senza un server di posta, o dopo un invio non partito ──
// L'ultima email (le emissioni arrivano dalla più recente) non è partita: il server di posta c'è ma non ha funzionato.
const ultimaEmailNonPartita = computed(() => opzioni.value?.emissioni.find((e) => e.canale === 'email')?.stato === 'non_partita');
const preparaDisponibile = computed(() => !!opzioni.value && opzioni.value.emettibile && opzioni.value.inviabile && !!opzioni.value.bozza_email
    && (opzioni.value.senza_server_posta || ultimaEmailNonPartita.value));
const preparando = ref(false);
const preparata = ref<{ modo: 'condivisa' | 'scaricata' | 'annullata'; mailto: string; a: string[]; pdf: Blob; nome: string } | null>(null);
const puoPreparare = computed(() => preparaDisponibile.value && !!bollo.value && !preparando.value);
// Gli indirizzi dell'email: quelli spuntati, e l'altro indirizzo se è scritto.
const indirizziDellEmail = () => {
    const o = opzioni.value;
    if (!o) return [];
    const spuntati = o.destinatari.flatMap((d) => d.indirizzi.filter((i) => caselle.value[`${d.anagrafica_id}:${chiaveIndirizzo(i)}`]).map((i) => i.indirizzo));
    const altro = altroIndirizzo.value.trim();

    return [...new Set([...spuntati, ...(altro && !o.annullato ? [altro] : [])])];
};
async function preparaLEmail() {
    const o = opzioni.value;
    if (!o || !o.bozza_email || !puoPreparare.value) return;
    // Gli indirizzi, l'oggetto e il saluto sono quelli di adesso, di questa ricevuta: si leggono prima dell'attesa del PDF, non dopo, quando la
    // finestra potrebbe avere altre caselle o un altro incasso.
    const a = indirizziDellEmail();
    // Il saluto della persona a cui va: la prima spuntata, altrimenti la posizione.
    const persona = o.destinatari.find((d) => d.indirizzi.some((i) => caselle.value[`${d.anagrafica_id}:${chiaveIndirizzo(i)}`]))?.anagrafica_id ?? o.posizione?.id ?? null;
    const email = { a, oggetto: o.bozza_email.oggetto, testo: testoDellaBozza(o.bozza_email, persona) };
    preparando.value = true;
    errore.value = null;
    esito.value = null;
    preparata.value = null;
    try {
        // Il PDF è un'emissione, come la stampa: la riga del registro con le scelte della finestra. Va per email: senza l'indirizzo per la busta.
        const risposta = await axios.post(route(generateRoute('gestionale.ricevute.stampa'), { condominio: props.condominioId }), { incassi: [o.id], per_email: 1, ...scelte() }, { responseType: 'blob' });
        // La ricevuta è uscita (una riga nel registro): la pagina lo sa. Ma se la finestra è stata chiusa o è passata a un altro incasso, il PDF
        // e l'email di questa ricevuta non si danno a chi guarda un'altra: niente scaricamento, niente email, niente riquadro.
        if (!stessaFinestra(o.id)) {
            emit('emessa');

            return;
        }
        const nome = nomeDalContentDisposition(risposta.headers?.['content-disposition'], `ricevuta-${o.numero}.pdf`);
        const modo = await preparaEmail(risposta.data as Blob, nome, email);
        // Anche la condivisione si aspetta: la finestra può essere cambiata mentre chi lavora sceglieva dove mandarla.
        if (stessaFinestra(o.id)) {
            preparata.value = { modo, mailto: indirizzoMailto(email.a, email.oggetto, email.testo), a, pdf: risposta.data as Blob, nome };
            await carica();
        }
        emit('emessa');
    } catch (e: any) {
        const frase = await fraseDaErroreConFile(e, 'Non è stato possibile preparare il PDF. Riprova.');
        if (stessaFinestra(o.id)) errore.value = frase;
    } finally {
        preparando.value = false;
    }
}
const scaricaDiNuovo = () => { if (preparata.value) scaricaFile(preparata.value.pdf, preparata.value.nome); };
// Mentre il PDF si prepara la finestra non si chiude (Chiudi, la X, Esc, un clic fuori): la ricevuta è già uscita, e il PDF deve arrivare a chi
// lavora, nella finestra di questa ricevuta, con gli indirizzi che ha scelto.
const cambiaApertura = (v: boolean) => { if (!v && preparando.value) return; aperta.value = v; };

async function invia() {
    if (!opzioni.value || !puoInviare.value) return;
    const id = opzioni.value.id;
    inviando.value = true;
    esito.value = null;
    errore.value = null;
    try {
        const risposta = await axios.post(route(generateRoute('gestionale.ricevute.invia-una'), { condominio: props.condominioId, scrittura: id }), {
            destinatari: scelti.value,
            altro_indirizzo: altroIndirizzo.value.trim() || null,
            ...scelte(),
        });
        // La finestra è una sola per tutte le righe della pagina: l'esito di questa ricevuta si legge nella sua finestra. Se intanto è stata
        // chiusa o è passata a un altro incasso, la pagina lo sa (l'invio c'è nel registro) e la finestra dell'altro non dice niente.
        if (stessaFinestra(id)) {
            const r = risposta.data;
            esito.value = testoRiepilogo(r) + (r.errori?.length ? ` ${r.errori.join(' ')}` : '');
            await carica();
        }
        emit('emessa');
    } catch (e: any) {
        // Un 404 o un 500 arrivano con il messaggio vuoto, e un 429 con quello del framework: la finestra dice comunque una frase in italiano.
        if (stessaFinestra(id)) errore.value = frasePerErrore(e, 'Invio non riuscito. Riprova.');
    } finally {
        inviando.value = false;
    }
}
</script>

<template>
    <Dialog :open="aperta" @update:open="cambiaApertura">
        <DialogContent class="sm:max-w-[620px] max-h-[90vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Ricevuta di pagamento</DialogTitle>
                <DialogDescription v-if="opzioni">
                    N. {{ opzioni.numero }}, versamento di {{ euro(opzioni.importo) }} del {{ giorno(opzioni.data_versamento) }}
                    <template v-if="opzioni.posizione">, {{ opzioni.versato_da ? `per conto di ${opzioni.posizione.nome}` : opzioni.posizione.nome }}</template>.
                </DialogDescription>
            </DialogHeader>

            <div v-if="!opzioni && !errore" class="flex items-center gap-2 text-sm text-muted-foreground py-6">
                <Loader2 class="w-4 h-4 animate-spin" /> Caricamento…
            </div>

            <div v-if="opzioni && !opzioni.emettibile" class="text-sm space-y-2">
                <p class="rounded-md border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 p-3 text-slate-700 dark:text-slate-200">{{ opzioni.motivo }}</p>
                <ul v-if="opzioni.persone_dell_incasso.length" class="list-disc pl-5 text-xs">
                    <li v-for="p in opzioni.persone_dell_incasso" :key="p.id"><a :href="estrattoContoDi(p.id)" class="underline">Estratto conto di {{ p.nome }}</a></li>
                </ul>
            </div>

            <div v-if="opzioni && opzioni.emettibile" class="space-y-5 text-sm">
                <p v-if="opzioni.annullato" class="rounded-md border border-rose-200 dark:border-rose-900 bg-rose-50 dark:bg-rose-950/40 p-3 text-rose-800 dark:text-rose-200">
                    Incasso annullato<template v-if="opzioni.storno"> il {{ giorno(opzioni.storno.data) }} (storno n. {{ opzioni.storno.protocollo }})</template>.
                    La ricevuta si stampa con «INCASSO ANNULLATO».
                    <template v-if="opzioni.inviabile">Era partita per email: puoi mandarla di nuovo agli stessi indirizzi.</template>
                    <template v-else>Se e come dirlo alla persona lo decidi tu.</template>
                    <template v-if="puoSegnare"> Se non serve, segnalalo: l'avviso si toglie.</template>
                </p>
                <!-- «Non serve comunicarla» (decisione 206.2): scrive una riga che resta, quindi chiede prima una conferma con il numero dell'incasso. -->
                <div v-if="puoSegnare" class="-mt-3 space-y-2">
                    <Button v-if="!chiediSegna" size="sm" variant="outline" class="h-7 text-xs" @click="chiediSegna = true">Non serve comunicarla</Button>
                    <div v-else role="group" class="rounded-md border p-3 text-xs space-y-2">
                        <p>{{ testoConfermaNonServe(opzioni.numero) }}</p>
                        <div class="flex flex-wrap gap-2">
                            <Button size="sm" class="h-7 text-xs" :disabled="segnando" @click="segnaNonServe"><Loader2 v-if="segnando" class="w-3.5 h-3.5 mr-1 animate-spin" /> Sì, segna</Button>
                            <Button size="sm" variant="ghost" class="h-7 text-xs" @click="chiediSegna = false">No</Button>
                        </div>
                    </div>
                </div>

                <!-- La prima emissione è l'originale: lo si dice prima, perché chi guarda la ricevuta non lo bruci (c'è «Anteprima»). -->
                <p v-if="!haOriginale && !opzioni.annullato" class="rounded-md border bg-muted/40 p-3 text-xs text-muted-foreground">{{ RIGA_ORIGINALE }}</p>

                <div v-if="opzioni.emissioni.length" class="rounded-md border bg-muted/40 p-3 space-y-1">
                    <p class="font-medium">
                        {{ haOriginale && opzioni.copia ? `Già emessa il ${giorno(opzioni.copia.del)}: la prossima esce come «Copia», con le cifre dell'originale.` : 'Emissioni di prima' }}
                    </p>
                    <div v-for="e in opzioni.emissioni.slice(0, 3)" :key="e.id" class="text-xs text-muted-foreground">
                        {{ testoEmissione(e) }}
                        <span v-if="e.errore_completo" class="block break-words text-[11px]">Dal server: {{ e.errore_completo }}</span>
                    </div>
                    <p v-if="giaInviata" class="text-xs text-amber-700 dark:text-amber-300">L'ultimo invio è partito: rimandarla la manda di nuovo.</p>
                    <p v-if="opzioni.copia?.cambiata" class="text-xs text-amber-800 dark:text-amber-200">
                        {{ TESTO_COPIA_CAMBIATA }} La situazione di oggi è nell'<a v-if="estrattoConto" :href="estrattoConto" class="underline">estratto conto di {{ opzioni.posizione?.nome }}</a><template v-else>estratto conto</template>.
                    </p>
                </div>

                <!-- A che cosa va il versamento: la ricevuta lo scrive com'è registrato nell'incasso (non lo sceglie). Una tabella compatta (decisione
                     206.1): il piano e la gestione una volta, in testa al gruppo, e sotto i titoli delle colonne, così si leggono come colonne di quel
                     piano; una riga per quota; il totale in fondo. Una tabella sola, perché le colonne dei gruppi restino allineate. Quattro colonne
                     strette: sul telefono l'unità e la rata vanno a capo, le cifre no. -->
                <div class="space-y-1">
                    <p class="font-medium">A che cosa va il versamento</p>
                    <table v-if="opzioni.quote.length" class="w-full text-xs" data-testid="quote-del-versamento">
                        <tbody v-for="(g, gi) in gruppiQuote" :key="`gruppo-${gi}`">
                            <tr v-if="g.titolo"><th colspan="4" scope="colgroup" class="pb-1 text-left font-medium" :class="{ 'pt-3': gi > 0 }">{{ g.titolo }}</th></tr>
                            <tr class="text-muted-foreground" data-titoli-colonne>
                                <th scope="col" class="py-1 pr-2 text-left font-normal">Unità</th>
                                <th scope="col" class="py-1 pr-2 text-left font-normal">Rata e scadenza</th>
                                <th scope="col" class="py-1 pr-2 text-right font-normal">Imputato</th>
                                <th scope="col" class="py-1 text-right font-normal">Resta</th>
                            </tr>
                            <tr v-for="(q, i) in g.quote" :key="`quota-${gi}-${i}`" class="border-t align-top">
                                <td class="py-1 pr-2 break-words">{{ q.unita ?? '—' }}</td>
                                <td class="py-1 pr-2 break-words">{{ q.rata }}<span v-if="q.scadenza" class="block text-muted-foreground">{{ giorno(q.scadenza) }}</span></td>
                                <td class="py-1 pr-2 text-right whitespace-nowrap">{{ euro(q.imputato) }}<span v-if="q.dal_credito > 0" class="block whitespace-normal text-muted-foreground">di cui dal credito {{ euro(q.dal_credito) }}</span></td>
                                <td class="py-1 text-right whitespace-nowrap">{{ euro(q.dopo) }}</td>
                            </tr>
                        </tbody>
                        <tfoot v-if="opzioni.quote.length > 1">
                            <tr class="border-t font-medium">
                                <td colspan="2" class="py-1 pr-2">Totale</td>
                                <td class="py-1 pr-2 text-right whitespace-nowrap">{{ euro(totaliQuote.imputato) }}</td>
                                <td class="py-1 text-right whitespace-nowrap">{{ euro(totaliQuote.dopo) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                    <ul v-if="opzioni.parte_in_piu.length || !opzioni.quote.length" class="space-y-0.5 text-xs">
                        <li v-for="(e, i) in opzioni.parte_in_piu" :key="`piu-${i}`">Parte in più: {{ testoParteInPiu(e) }}</li>
                        <li v-if="opzioni.quote.length === 0 && opzioni.parte_in_piu.length === 0">Il versamento non è imputato a nessuna quota.</li>
                    </ul>
                    <p class="text-xs text-muted-foreground">{{ TESTO_IMPUTAZIONE }}</p>
                </div>

                <p v-for="(c, i) in opzioni.compensazioni_escluse" :key="`comp-${i}`" class="rounded-md border bg-muted/40 p-3 text-xs text-muted-foreground">
                    Nello stesso incasso il credito di {{ c.persona }} ha coperto {{ euro(c.importo) }} delle sue rate: è una compensazione, non un versamento,
                    e questa ricevuta non la porta. Si legge nel suo estratto conto.
                </p>

                <!-- Il bollo: sceglie l'amministratore. Quattro pulsanti, uno solo acceso; gli estremi solo con una delle due «Assolta». -->
                <div class="space-y-2">
                    <SceltaBollo
                        :stato="bollo"
                        :modo="modo"
                        :nota="opzioni.sopra_soglia ? 'Importo sopra € 77,47' : null"
                        :frase="creditoNellaSoglia ? `${FRASE_BOLLO} ${creditoNellaSoglia}` : FRASE_BOLLO"
                        @scegli="scegliBollo"
                    />
                    <EstremiBollo v-if="bollo === 'assolta' && modo" id="estremi-bollo-ricevuta" v-model="estremi" :modo="modo">
                        <!-- Su una ricevuta per email la marca non viaggia: il contrassegno si può scegliere, e come assolvere il bollo lo decide l'amministratore. -->
                        <p v-if="modo === 'contrassegno' && opzioni.inviabile && (!opzioni.senza_server_posta || preparaDisponibile)" data-testid="nota-contrassegno-email" class="text-xs text-muted-foreground">{{ NOTA_CONTRASSEGNO_EMAIL }}</p>
                    </EstremiBollo>
                </div>

                <div class="space-y-1">
                    <label class="font-medium" for="nota-ricevuta">Nota, facoltativa</label>
                    <Textarea id="nota-ricevuta" v-model="nota" maxlength="500" rows="2" placeholder="Stampata sulla ricevuta" />
                    <p class="text-xs text-muted-foreground">{{ NOTA_INTERESSI }}</p>
                </div>

                <!-- La causale è un testo libero e può portare note interne: si stampa solo se lo scegli (decisione 202). -->
                <label v-if="opzioni.causale" class="flex items-start gap-2 cursor-pointer">
                    <Checkbox :model-value="stampaCausale" @update:model-value="(v: any) => (stampaCausale = !!v)" class="mt-0.5" />
                    <span>Stampa la causale<span class="block text-xs text-muted-foreground">«{{ opzioni.causale }}»</span></span>
                </label>

                <!-- Gli indirizzi: quelli spuntati ricevono la ricevuta, un messaggio per indirizzo. -->
                <p v-if="opzioni.inviabile && opzioni.senza_server_posta" class="rounded-md border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/40 p-3 text-xs text-amber-800 dark:text-amber-200">
                    Nessun server di posta configurato: il programma non manda la ricevuta per email. Configuralo nelle impostazioni della posta; intanto
                    puoi stamparla, o mandarla dal tuo programma di posta con «Prepara l'email».
                </p>
                <p v-else-if="preparaDisponibile" class="rounded-md border bg-muted/40 p-3 text-xs text-muted-foreground">
                    L'ultimo invio non è partito. Puoi riprovare con «Invia», o mandarla dal tuo programma di posta con «Prepara l'email».
                </p>
                <div v-if="opzioni.inviabile && (!opzioni.senza_server_posta || preparaDisponibile)" class="space-y-3">
                    <p class="font-medium">A chi mandarla<span v-if="opzioni.annullato" class="font-normal text-xs text-muted-foreground"> — gli indirizzi a cui era già andata</span></p>
                    <div v-for="d in opzioni.destinatari" :key="d.anagrafica_id" class="rounded-md border p-3 space-y-2">
                        <p class="font-medium">
                            {{ d.nome }}
                            <span class="text-xs font-normal text-muted-foreground">{{ d.ruolo === 'posizione' ? '— la posizione' : '— ha versato per conto della posizione' }}</span>
                        </p>
                        <p v-if="d.ruolo === 'versato_da'" class="text-xs text-muted-foreground">La ricevuta porta le rate e il residuo della posizione: spunta solo se va mandata anche a chi ha versato.</p>
                        <p v-if="d.indirizzi.length === 0" class="text-xs text-amber-700 dark:text-amber-300">Nessun indirizzo email: stampala per la posta.</p>
                        <label v-for="i in d.indirizzi" :key="chiaveIndirizzo(i)" class="flex items-start gap-2 cursor-pointer">
                            <Checkbox :model-value="caselle[`${d.anagrafica_id}:${chiaveIndirizzo(i)}`]" :disabled="preparando" @update:model-value="(v: any) => (caselle[`${d.anagrafica_id}:${chiaveIndirizzo(i)}`] = !!v)" class="mt-0.5" />
                            <span>
                                {{ TIPI_INDIRIZZO[i.tipo] }}: {{ i.indirizzo }}
                                <span v-if="i.tipo === 'pec'" class="block text-xs text-muted-foreground">{{ AVVISO_PEC }}</span>
                            </span>
                        </label>
                    </div>
                    <div v-if="!opzioni.annullato" class="space-y-1">
                        <label class="text-xs text-muted-foreground" for="altro-indirizzo">Un altro indirizzo, se la persona chiede di mandarla altrove</label>
                        <Input id="altro-indirizzo" v-model="altroIndirizzo" type="email" placeholder="nome@esempio.it" class="h-8 text-xs" :disabled="preparando" />
                    </div>
                </div>

                <p v-if="preparando" role="status" class="rounded-md border bg-muted/40 p-3 text-xs text-muted-foreground">
                    Sto preparando il PDF: la finestra si può chiudere appena è pronto.
                </p>

                <p v-if="esito" class="rounded-md border border-emerald-200 dark:border-emerald-900 bg-emerald-50 dark:bg-emerald-950/40 p-2 text-emerald-800 dark:text-emerald-200">{{ esito }}</p>

                <!-- Dopo «Prepara l'email»: la ricevuta è uscita (una stampa nel registro); che cosa fare adesso, e i collegamenti se l'email non si è aperta. -->
                <div v-if="preparata" class="rounded-md border border-emerald-200 dark:border-emerald-900 bg-emerald-50 dark:bg-emerald-950/40 p-3 text-xs text-emerald-800 dark:text-emerald-200 space-y-1">
                    <p v-if="preparata.modo === 'condivisa'">
                        Il PDF è pronto e la ricevuta risulta stampata. Nel programma di posta che hai scelto scrivi l'indirizzo<template v-if="preparata.a.length">: {{ preparata.a.join(', ') }}</template>.
                    </p>
                    <p v-else-if="preparata.modo === 'annullata'">
                        Il PDF è pronto e la ricevuta risulta stampata. Hai chiuso la condivisione: puoi aprire l'email o scaricare il PDF da qui.
                    </p>
                    <p v-else>
                        Il PDF è pronto e la ricevuta risulta stampata. Allega il PDF scaricato ({{ preparata.nome }}) all'email che si è aperta, poi mandala.
                    </p>
                    <p class="flex flex-wrap gap-x-3">
                        <a :href="preparata.mailto" class="underline">Apri l'email</a>
                        <button type="button" class="underline" @click="scaricaDiNuovo">Scarica di nuovo il PDF</button>
                    </p>
                </div>
            </div>

            <p v-if="errore" class="rounded-md border border-rose-200 dark:border-rose-900 bg-rose-50 dark:bg-rose-950/40 p-2 text-sm text-rose-800 dark:text-rose-200">{{ errore }}</p>

            <DialogFooter class="gap-2">
                <Button variant="outline" :disabled="preparando" @click="aperta = false">Chiudi</Button>
                <!-- Guarda la ricevuta senza emetterla: non scrive il registro e non fa uscire come «Copia» quella che va alla persona. -->
                <Button variant="outline" :disabled="!puoVedere" @click="anteprima">
                    <Loader2 v-if="pdfInArrivo === 'anteprima'" class="w-4 h-4 mr-2 animate-spin" />
                    <Eye v-else class="w-4 h-4 mr-2" /> Anteprima
                </Button>
                <Button variant="outline" :disabled="!puoStampare" @click="stampa">
                    <Loader2 v-if="pdfInArrivo === 'stampa'" class="w-4 h-4 mr-2 animate-spin" />
                    <Printer v-else class="w-4 h-4 mr-2" /> Stampa
                </Button>
                <!-- Per chi non ha la posta del programma, o se l'invio non è partito: il PDF al programma di posta di chi lavora. -->
                <Button v-if="preparaDisponibile" variant="outline" :disabled="!puoPreparare" @click="preparaLEmail">
                    <Loader2 v-if="preparando" class="w-4 h-4 mr-2 animate-spin" />
                    <Share2 v-else class="w-4 h-4 mr-2" /> Prepara l'email
                </Button>
                <Button v-if="opzioni && opzioni.emettibile && opzioni.inviabile" :disabled="!puoInviare" @click="invia">
                    <Loader2 v-if="inviando" class="w-4 h-4 mr-2 animate-spin" />
                    <Mail v-else class="w-4 h-4 mr-2" /> Invia
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
