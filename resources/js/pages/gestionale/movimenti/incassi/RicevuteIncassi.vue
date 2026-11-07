<script setup lang="ts">
/**
 * La pagina delle ricevute (1.12.0-beta.2, decisioni 200 e 201): il lavoro di tutti i mesi in un clic. Si apre sulle ricevute mai emesse dei
 * versamenti dal primo del mese scorso; i filtri, la scelta delle righe o di «tutte le N dei filtri», la stampa e l'invio in blocco con
 * l'anteprima, e dopo l'invio il passo successivo (le ricevute da stampare per chi non ha un indirizzo). Per chi manda le ricevute dal suo
 * programma di posta, «Scarica un PDF per persona»: un archivio .zip con un PDF per persona e l'elenco degli indirizzi (decisione 207.2).
 */
import { computed, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import RicevutaDialog from '@/components/gestionale/movimenti/incassi/RicevutaDialog.vue';
import SceltaBollo from '@/components/gestionale/movimenti/incassi/SceltaBollo.vue';
import EstremiBollo from '@/components/gestionale/movimenti/incassi/EstremiBollo.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import MultiSelect from '@/components/ui/multi-select/MultiSelect.vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { AlertTriangle, Archive, Eye, FileText, Loader2, Mail, Printer, X } from 'lucide-vue-next';
import {
    AVVISO_PEC, FRASE_BOLLO, NOTA_CONTRASSEGNO_EMAIL, NOTA_INTERESSI, NOTA_MODO_UNICO, RIGA_ORIGINALE, TIPI_INDIRIZZO, alterna, apriPdfInUnaScheda, bolliRichiesti,
    estremiDaMandare, etichettaParteDelPdf, fraseDaErroreConFile, frasePerErrore, nomeDalContentDisposition, payloadSelezione, pronteDaEmettere, quanteSelezionate,
    riepilogoDelleScelte, scaricaFile, selezionaPagina, selezioneVuota, soloPersoneDaMandare, statoConModo, testoAltriIndirizzi, testoAnteprima, testoCausali,
    testoConfermaInviaDiNuovo, testoConfermaNonServe, testoConfermaRiprova, testoDelPdf, testoEmissione,
    testoLimiteRiprova, testoEsitoInvio, testoRegistratiDiRecente, testoRestantiBlocco, testoRestantiRiprova, testoRiepilogo, testoTetto, tutteDeiFiltri, unisciEsiti, verificaIlFile,
    type Emissione, type PersonaAnteprima, type Selezione,
} from '@/lib/gestionale/incassi/ricevute';
import type { Flash } from '@/types/flash';

interface Riga {
    id: number; numero: string; data: string | null; importo: number; sopra_soglia: boolean; ha_email: boolean;
    posizione: { id: number; nome: string } | null; versato_da: { id: number; nome: string } | null;
    ultima: Emissione | null; ultima_email_id: number | null;
    // L'ultima email, quella che «Invia di nuovo» rimanda: con il suo bollo e l'indirizzo a cui va adesso.
    ultima_email: (Emissione & { id: number; manda_a: string | null }) | null;
}
interface Filtri { data_da: string | null; data_a: string | null; gestione_id: number | null; cassa_id: number | null; persone: number[]; email: string | null; soglia: string | null; ricevuta: string }

const props = defineProps<{
    condominio: { id: number; nome: string }; condomini: any; esercizio: any; esercizi: any;
    incassi: { data: Riga[]; meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null } };
    totale: number;
    filters: Filtri;
    persone: Array<{ id: number; nome: string }>;
    gestioni: Array<{ id: number; nome: string }>;
    casse: Array<{ id: number; nome: string }>;
    scelta_ricordata: {
        bollo_sopra: string | null; bollo_sotto: string | null; modo_bollo: string | null; estremi_bollo: string | null;
        stampa_causale: boolean | null; indirizzi: string[] | null;
    };
    registrati_di_recente: { numero: number; dal: string } | null;
    tetto: number;
    senza_server_posta: boolean;
    in_coda_da_tempo: number;
    non_partite: number;
    annullate_da_comunicare: Array<{ id: number; numero: string; data: string | null }>;
}>();

const { generateRoute, generatePath } = usePermission();
const { euro } = useCurrencyFormatter();
// Il messaggio del server dopo un rimando: per esempio la scheda di un PDF riaperta con un GET (`riapriPdf` del controller).
const page = usePage<{ flash?: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash?.message);
const giorno = (d: string | null | undefined) => (d ? d.split('-').reverse().join('/') : '');

// ── I filtri ────────────────────────────────────────────────────────────
const f = ref({
    data_da: props.filters.data_da ?? '',
    data_a: props.filters.data_a ?? '',
    gestione_id: props.filters.gestione_id ? String(props.filters.gestione_id) : '',
    cassa_id: props.filters.cassa_id ? String(props.filters.cassa_id) : '',
    persone: (props.filters.persone ?? []).map(String),
    email: props.filters.email ?? '',
    soglia: props.filters.soglia ?? '',
    ricevuta: props.filters.ricevuta ?? 'mai',
});
const opzioniPersone = computed(() => props.persone.map((p) => ({ text: p.nome, value: String(p.id) })));
const parametri = () => ({
    condominio: props.condominio.id,
    ricevuta: f.value.ricevuta,
    data_da: f.value.data_da || undefined,
    data_a: f.value.data_a || undefined,
    gestione_id: f.value.gestione_id || undefined,
    cassa_id: f.value.cassa_id || undefined,
    persone: f.value.persone.length ? f.value.persone : undefined,
    email: f.value.email || undefined,
    soglia: f.value.soglia || undefined,
    per_page: props.incassi.meta.per_page,
});
const filtriPerIlServer = () => {
    const { condominio: _c, per_page: _p, ...resto } = parametri();
    return resto;
};
function applica(paginaN?: number) {
    router.get(route(generateRoute('gestionale.ricevute.index'), { ...parametri(), page: paginaN }), {}, { preserveState: true, preserveScroll: true, replace: true });
}
watch(() => ({ ...f.value, persone: [...f.value.persone] }), () => { selezione.value = selezioneVuota(); applica(); }, { deep: true });
const soloQuestaPersona = (id: number) => { f.value.persone = [String(id)]; };

// ── La selezione ────────────────────────────────────────────────────────
const selezione = ref<Selezione>(selezioneVuota());
const idsPagina = computed(() => props.incassi.data.map((r) => r.id));
const quante = computed(() => quanteSelezionate(selezione.value, props.totale));
const spuntata = (id: number) => selezione.value.tutte || selezione.value.ids.includes(id);
const paginaSpuntata = computed(() => idsPagina.value.length > 0 && idsPagina.value.every(spuntata));

// ── La finestra di una ricevuta ─────────────────────────────────────────
const incassoAperto = ref<number | null>(null);
const finestraAperta = ref(false);
const apri = (id: number) => { incassoAperto.value = id; finestraAperta.value = true; };
const ricarica = () => router.reload({ only: ['incassi', 'totale', 'non_partite', 'in_coda_da_tempo', 'annullate_da_comunicare', 'scelta_ricordata', 'registrati_di_recente'] });
// Le mai emesse di incassi registrati da poco con un versamento più vecchio: l'amministratore le include con un clic.
const includiLeRecenti = () => { if (props.registrati_di_recente) f.value.data_da = props.registrati_di_recente.dal; };

// ── Il blocco: anteprima, stampa, invio ─────────────────────────────────
// `persone`: «Scarica un PDF per persona», con le stesse scelte della stampa (decisione 207.2).
const blocco = ref<{ aperto: boolean; modo: 'stampa' | 'invio' | 'persone' }>({ aperto: false, modo: 'invio' });
const anteprima = ref<any>(null);
const caricamento = ref(false);
// L'anteprima si sta rifacendo (un'altra scelta, un altro ordine): i pulsanti che la leggono sono fermi, e la finestra lo dice.
const anteprimaInCorso = ref(false);
// Quale richiesta dell'anteprima è l'ultima: una risposta più vecchia che arriva dopo non la sostituisce.
let anteprimaChiesta = 0;
const errore = ref<string | null>(null);
const esito = ref<any>(null);
const prova = ref<string | null>(null);
// La riga in testa alla pagina: dove si legge l'esito di una richiesta quando la finestra che l'ha chiesta non c'è più, e quello di «Invia di
// nuovo», «Riprova» e «Non serve comunicarla», che non sono della finestra.
const messaggio = ref<string | null>(null);
// Ogni apertura della finestra del blocco ha il suo numero. Una richiesta partita in un'apertura, quando risponde, trova la finestra ancora
// quella (l'esito si scrive lì), chiusa, o riaperta da capo: allora la finestra è un'altra, con un'altra anteprima, e l'esito non è suo. Si
// legge in testa alla pagina.
let aperturaBlocco = 0;
const stessaApertura = (apertura: number) => blocco.value.aperto && apertura === aperturaBlocco;
// Una richiesta del blocco che scrive nel registro o manda email (l'archivio, l'invio) è in corso: finché non risponde un'altra uguale non parte,
// neanche da una finestra riaperta nel frattempo.
const richiestaInCorso = ref(false);
// L'esito di una richiesta partita da un'apertura precedente, arrivato a una finestra riaperta da capo: la finestra non l'ha vista partire, e la
// riga in testa alla pagina sta sotto il suo sfondo scuro. Si legge in una riga della finestra, finché non si riapre o non parte un'altra richiesta.
const esitoDiPrima = ref<{ frase: string; riuscito: boolean } | null>(null);
const scelte = ref({
    bollo_sopra: statoConModo(props.scelta_ricordata.bollo_sopra, props.scelta_ricordata.modo_bollo),
    bollo_sotto: statoConModo(props.scelta_ricordata.bollo_sotto, props.scelta_ricordata.modo_bollo),
    modo_bollo: props.scelta_ricordata.modo_bollo,
    // Il server ripropone solo l'autorizzazione del modo virtuale: il numero di un contrassegno è di una ricevuta sola.
    estremi_bollo: props.scelta_ricordata.estremi_bollo ?? '',
    // La nota è di queste ricevute: vale solo se è scritta in questa finestra, e parte vuota ogni volta.
    nota: '',
    stampa_causale: !!props.scelta_ricordata.stampa_causale,
});
// Un pulsante del bollo di un gruppo scrive lo stato di quel gruppo e, se è una delle due «Assolta», il modo: che è uno solo per tutte le ricevute,
// e quindi anche per l'altro gruppo. «Non dovuta» e «Da assolvere» lasciano il modo e l'autorizzazione di prima dove sono.
function scegliBollo(gruppo: 'bollo_sopra' | 'bollo_sotto', scelta: { stato: string; modo: string | null }) {
    scelte.value[gruppo] = scelta.stato;
    if (scelta.modo) scelte.value.modo_bollo = scelta.modo;
}
const indirizzi = ref<string[]>(props.scelta_ricordata.indirizzi ?? ['email', 'email_secondaria']);
const ancheChiHaVersato = ref(false);
const escludiGiaInviate = ref(true);
const ordine = ref<'persona' | 'unita' | 'data'>('persona');
// Chi riceve che cosa: le persone che l'amministratore toglie dall'invio, e la parte del PDF che si stampa quando le ricevute sono più del tetto.
const escluse = ref<number[]>([]);
const mostraLePersone = ref(false);
const mostraLeCausali = ref(false);
const partePdf = ref(0);
const personeAnteprima = computed<PersonaAnteprima[]>(() => anteprima.value?.persone ?? []);
const soloPersone = computed(() => soloPersoneDaMandare(personeAnteprima.value, escluse.value));
const riepilogoInvio = computed(() => riepilogoDelleScelte(personeAnteprima.value, escluse.value));
const alternaPersona = (id: number, dentro: boolean) => { escluse.value = dentro ? escluse.value.filter((x) => x !== id) : [...escluse.value, id]; };
const partiPdf = computed<number[][]>(() => anteprima.value?.parti_pdf ?? []);
const richiesti = computed(() => (anteprima.value ? bolliRichiesti(anteprima.value) : { sopra: false, sotto: false }));
const pronta = computed(() => !!anteprima.value && pronteDaEmettere(scelte.value, richiesti.value));
// Il modo e l'autorizzazione servono se un gruppo che si vede ha una delle due «Assolta» accesa.
const conBolloAssolto = computed(() => (richiesti.value.sopra && scelte.value.bollo_sopra === 'assolta') || (richiesti.value.sotto && scelte.value.bollo_sotto === 'assolta'));
const dueGruppiAssolti = computed(() => richiesti.value.sopra && richiesti.value.sotto && scelte.value.bollo_sopra === 'assolta' && scelte.value.bollo_sotto === 'assolta');
const sceltePerIlServer = () => ({
    bollo_sopra: scelte.value.bollo_sopra,
    bollo_sotto: scelte.value.bollo_sotto,
    ...estremiDaMandare([scelte.value.bollo_sopra, scelte.value.bollo_sotto], scelte.value.modo_bollo, scelte.value.estremi_bollo, true),
    nota: scelte.value.nota || null,
    stampa_causale: scelte.value.stampa_causale ? 1 : 0,
});
const corpo = () => ({
    ...payloadSelezione(selezione.value, filtriPerIlServer()),
    ...sceltePerIlServer(),
    indirizzi: indirizzi.value,
    anche_chi_ha_versato: ancheChiHaVersato.value ? 1 : 0,
});

async function caricaAnteprima() {
    const questa = ++anteprimaChiesta;
    caricamento.value = true;
    anteprimaInCorso.value = true;
    errore.value = null;
    try {
        const risposta = (await axios.post(route(generateRoute('gestionale.ricevute.anteprima'), { condominio: props.condominio.id }), {
            ...corpo(),
            // Nell'invio l'anteprima conta dopo l'esclusione delle già inviate, come farà l'invio.
            escludi_gia_inviate: blocco.value.modo === 'invio' && escludiGiaInviate.value ? 1 : 0,
            // Le parti del PDF seguono l'ordine scelto per la stampa, dalla prima all'ultima.
            ordine: ordine.value,
        })).data;
        if (questa === anteprimaChiesta) anteprima.value = risposta;
    } catch (e: any) {
        if (questa === anteprimaChiesta) errore.value = frasePerErrore(e, 'Non è stato possibile preparare l\'anteprima. Riprova.');
    } finally {
        if (questa === anteprimaChiesta) {
            caricamento.value = false;
            anteprimaInCorso.value = false;
        }
    }
}
// La risposta di una richiesta del blocco arriva e la finestra non è più quella che l'ha chiesta. Se è chiusa, l'esito si legge in testa alla
// pagina. Se è stata riaperta da capo, la frase sta anche nella finestra; e se la richiesta ha scritto qualcosa (`rifai`: la stampa, l'archivio,
// l'invio), la finestra rilegge il registro con un'anteprima nuova, perché quella a video è di prima: finché non arriva i pulsanti che la leggono
// restano fermi (`caricamento`). Un errore non cambia niente: l'anteprima di prima è ancora buona, come nella stessa finestra.
function inUnAltraApertura(frase: string | null, riuscito: boolean, rifai = false) {
    if (frase) messaggio.value = frase;
    if (!blocco.value.aperto) return;
    if (frase) esitoDiPrima.value = { frase, riuscito };
    if (riuscito && rifai) caricaAnteprima();
}
function apriBlocco(modo: 'stampa' | 'invio' | 'persone') {
    aperturaBlocco++;
    esitoDiPrima.value = null;
    blocco.value = { aperto: true, modo };
    anteprima.value = null;
    esito.value = null;
    prova.value = null;
    scaricato.value = null;
    pdfArrivato.value = null;
    scelte.value.nota = '';
    escluse.value = [];
    mostraLePersone.value = false;
    mostraLeCausali.value = false;
    partePdf.value = 0;
    senzaIndirizzoPrima.value = null;
    caricaAnteprima();
}
watch([indirizzi, ancheChiHaVersato, escludiGiaInviate], () => { if (blocco.value.aperto) caricaAnteprima(); }, { deep: true });
// Con più ricevute del tetto di un PDF l'ordine cambia le parti: si rifà l'anteprima e si riparte dalla prima. A metà (una parte già stampata) l'ordine non si cambia.
watch(ordine, () => { if (blocco.value.aperto && anteprima.value?.oltre_tetto) { partePdf.value = 0; caricaAnteprima(); } });

// La stampa e l'anteprima del PDF: la scheda nuova si apre nel clic, il PDF arriva con una richiesta in sottofondo (`apriPdfInUnaScheda`).
// Niente `await` prima della chiamata: la scheda aperta dopo un'attesa il browser la blocca. Mentre un PDF si prepara gli altri pulsanti del
// PDF sono fermi, e un secondo clic non ne chiede un altro (per la stampa sarebbe una seconda riga nel registro). Dopo la stampa il registro è
// scritto: la pagina si ricarica quando la risposta arriva. L'anteprima non scrive niente e non ricarica niente. Mentre aspetta, la riga
// dice dove andrà il PDF: nella scheda, o fra i file scaricati se la scheda non si è aperta.
const pdfInArrivo = ref(false);
const pdfSenzaScheda = ref(false);
const pdfArrivato = ref<string | null>(null);
async function apriIlPdf(stampa: boolean, campi: Record<string, unknown>): Promise<boolean> {
    if (pdfInArrivo.value) return false;
    const apertura = aperturaBlocco;
    pdfInArrivo.value = true;
    errore.value = null;
    pdfArrivato.value = null;
    esitoDiPrima.value = null;
    try {
        const pdf = await apriPdfInUnaScheda(
            () => axios.post(route(generateRoute(stampa ? 'gestionale.ricevute.stampa' : 'gestionale.ricevute.pdf-anteprima'), { condominio: props.condominio.id }),
                campi, { responseType: 'blob' }),
            stampa ? 'ricevute.pdf' : 'anteprima-ricevute.pdf',
            undefined,
            (aperta) => { pdfSenzaScheda.value = !aperta; },
            // L'anteprima non scrive niente: se chi lavora ha chiuso la scheda non la voleva più, e il file non si scarica.
            { scaricaSeChiusa: stampa },
        );
        const frase = testoDelPdf(pdf, { stampa, una: false });
        if (stessaApertura(apertura)) pdfArrivato.value = frase;
        else inUnAltraApertura(frase, true, stampa);
        if (stampa) ricarica();

        return true;
    } catch (e: any) {
        const frase = await fraseDaErroreConFile(e, stampa ? 'Non è stato possibile stampare le ricevute. Riprova.' : 'Non è stato possibile aprire l\'anteprima. Riprova.');
        if (stessaApertura(apertura)) errore.value = frase;
        else inUnAltraApertura(frase, false);

        return false;
    } finally {
        pdfInArrivo.value = false;
    }
}

function stampaInBlocco(soloIncassi?: number[]): Promise<boolean> {
    return apriIlPdf(true, { ...(soloIncassi ? { incassi: soloIncassi, ...sceltePerIlServer() } : corpo()), ordine: ordine.value });
}

// Più ricevute del tetto di un PDF: una parte per volta, ogni parte con le persone intere. Si passa alla parte dopo quando questa è stampata:
// se il server la rifiuta, resta da stampare.
async function stampaLaParte() {
    const apertura = aperturaBlocco;
    const indice = partePdf.value;
    const parte = partiPdf.value[indice];
    if (!parte || pdfInArrivo.value) return;
    // La parte è di questa apertura: una finestra riaperta nel frattempo riparte dalla prima.
    if (await stampaInBlocco(parte) && stessaApertura(apertura)) partePdf.value = indice < partiPdf.value.length - 1 ? indice + 1 : partiPdf.value.length;
}

// «Scarica un PDF per persona»: l'archivio .zip arriva come file e si salva; ogni ricevuta risulta stampata.
const scaricato = ref<string | null>(null);
async function scaricaPerPersona() {
    if (richiestaInCorso.value) return;
    const apertura = aperturaBlocco;
    richiestaInCorso.value = true;
    errore.value = null;
    scaricato.value = null;
    esitoDiPrima.value = null;
    try {
        const risposta = await axios.post(route(generateRoute('gestionale.ricevute.pdf-per-persona'), { condominio: props.condominio.id }), corpo(), { responseType: 'blob' });
        await verificaIlFile(risposta, 'zip');
        const nome = nomeDalContentDisposition(risposta.headers?.['content-disposition'], 'ricevute-per-persona.zip');
        scaricaFile(risposta.data as Blob, nome);
        const frase = `Archivio scaricato: ${nome}. Le ricevute risultano stampate. Aprilo, e manda a ogni persona il suo PDF dal tuo programma di posta; gli indirizzi sono in «Indirizzi email.txt».`;
        if (stessaApertura(apertura)) scaricato.value = frase;
        else inUnAltraApertura(frase, true, true);
        ricarica();
    } catch (e: any) {
        const frase = await fraseDaErroreConFile(e, 'Non è stato possibile preparare l\'archivio. Riprova.');
        if (stessaApertura(apertura)) errore.value = frase;
        else inUnAltraApertura(frase, false);
    } finally {
        richiestaInCorso.value = false;
    }
}

// Guarda il PDF senza emetterlo: non scrive il registro e non fa uscire come «Copia» quello che va alla persona.
function guardaLeRicevute() {
    const parte = partiPdf.value[Math.min(partePdf.value, Math.max(partiPdf.value.length - 1, 0))];
    apriIlPdf(false, { ...(parte ? { incassi: parte, ...sceltePerIlServer() } : corpo()), ordine: ordine.value });
}

// Chi era senza indirizzo nel primo invio: «Invia le altre» ha un esito che conosce solo le persone rimaste, e non lo deve far sparire.
const senzaIndirizzoPrima = ref<{ senza_indirizzo: number; senza_indirizzo_incassi: number[]; senza_indirizzo_persone: string[] } | null>(null);

async function inviaInBlocco(restanti?: { persone: number[]; incassi: number[] }) {
    if (richiestaInCorso.value) return;
    const apertura = aperturaBlocco;
    richiestaInCorso.value = true;
    errore.value = null;
    esitoDiPrima.value = null;
    try {
        // «Invia le altre»: dopo il tetto dei messaggi, le persone rimaste con le loro ricevute, e niente di più.
        const richiesta = restanti
            ? { ...corpo(), tutte: undefined, filtri: undefined, incassi: restanti.incassi, solo_persone: restanti.persone, escludi_gia_inviate: 0 }
            : { ...corpo(), escludi_gia_inviate: escludiGiaInviate.value ? 1 : 0, solo_persone: soloPersone.value };
        const nuovo = (await axios.post(route(generateRoute('gestionale.ricevute.invia'), { condominio: props.condominio.id }), richiesta)).data;
        if (stessaApertura(apertura)) {
            if (!restanti) senzaIndirizzoPrima.value = null;
            esito.value = restanti ? unisciEsiti(senzaIndirizzoPrima.value, nuovo) : nuovo;
            senzaIndirizzoPrima.value = esito.value && esito.value.senza_indirizzo > 0
                ? { senza_indirizzo: esito.value.senza_indirizzo, senza_indirizzo_incassi: esito.value.senza_indirizzo_incassi, senza_indirizzo_persone: esito.value.senza_indirizzo_persone }
                : senzaIndirizzoPrima.value;
            selezione.value = selezioneVuota();
        } else {
            // La finestra è chiusa, o è un'altra: l'esito si legge in testa alla pagina, e nella finestra se è aperta. La scelta delle righe si
            // toglie solo se nessuna finestra è aperta; una finestra riaperta nel frattempo è fatta su quella scelta, e la tiene.
            inUnAltraApertura(testoEsitoInvio(nuovo), true, true);
            if (!blocco.value.aperto) selezione.value = selezioneVuota();
        }
        ricarica();
    } catch (e: any) {
        const frase = frasePerErrore(e, 'Invio non riuscito. Riprova.');
        if (stessaApertura(apertura)) errore.value = frase;
        else inUnAltraApertura(frase, false);
    } finally {
        richiestaInCorso.value = false;
    }
}

async function mandaneUnaAMe() {
    const apertura = aperturaBlocco;
    prova.value = null;
    errore.value = null;
    esitoDiPrima.value = null;
    try {
        const r = (await axios.post(route(generateRoute('gestionale.ricevute.prova'), { condominio: props.condominio.id }), { ...corpo(), solo_persone: soloPersone.value })).data;
        const frase = `Mandato a ${r.indirizzo} il messaggio di ${r.persona}, con ${r.ricevute === 1 ? '1 ricevuta' : `${r.ricevute} ricevute`}.`;
        if (stessaApertura(apertura)) prova.value = frase;
        else inUnAltraApertura(frase, true);
    } catch (e: any) {
        const frase = frasePerErrore(e, 'La prova non è partita.');
        if (stessaApertura(apertura)) errore.value = frase;
        else inUnAltraApertura(frase, false);
    }
}

// «Invia di nuovo» e «Riprova» mandano email con un clic: prima una conferma breve, con l'indirizzo e lo stato del bollo. «Non serve comunicarla»
// scrive una riga che resta: anche lei chiede prima, con il numero dell'incasso.
const conferma = ref<{ aperta: boolean; titolo: string; testo: string; pulsante: string; azione: () => void }>({ aperta: false, titolo: '', testo: '', pulsante: '', azione: () => {} });
const chiedi = (testo: string, azione: () => void, titolo = 'Mandare di nuovo?', pulsante = 'Manda') => { conferma.value = { aperta: true, titolo, testo, pulsante, azione }; };
const confermaNonServe = (a: { id: number; numero: string }) => chiedi(testoConfermaNonServe(a.numero), () => nonServeComunicarla(a.id), 'Non serve comunicarla?', 'Segna');
const confermaRiprova = () => chiedi(testoConfermaRiprova(props.non_partite), riprovaNonPartite);
// La conferma legge l'ultima email (non l'ultima emissione di qualunque canale): è la riga che «Invia di nuovo» rimanda.
const confermaInviaDiNuovo = (r: Riga) => { const e = r.ultima_email; if (e) chiedi(testoConfermaInviaDiNuovo(e), () => inviaDiNuovo(e.id)); };

async function riprovaNonPartite() {
    try {
        const r = (await axios.post(route(generateRoute('gestionale.ricevute.riprova'), { condominio: props.condominio.id }))).data;
        messaggio.value = [testoRiepilogo(r), testoRestantiRiprova(r), testoLimiteRiprova(r)].filter(Boolean).join(' ');
    } catch (e: any) {
        messaggio.value = frasePerErrore(e, 'Invio non riuscito. Riprova.');
    }
    ricarica();
}
async function inviaDiNuovo(id: number) {
    try {
        messaggio.value = testoRiepilogo((await axios.post(route(generateRoute('gestionale.ricevute.invia-di-nuovo'), { condominio: props.condominio.id, invio: id }))).data);
    } catch (e: any) {
        messaggio.value = frasePerErrore(e, 'Invio non riuscito. Riprova.');
    }
    ricarica();
}

// «Non serve comunicarla»: della ricevuta di un incasso annullato che era uscita, l'amministratore dice che alla persona non serve dirlo (decisione 205).
async function nonServeComunicarla(id: number) {
    try {
        await axios.post(route(generateRoute('gestionale.ricevute.non-serve-comunicarla'), { condominio: props.condominio.id, scrittura: id }));
        messaggio.value = 'Segnata: alla persona non serve comunicare l\'annullamento. La ricevuta resta fra le emissioni dell\'incasso.';
    } catch (e: any) {
        messaggio.value = frasePerErrore(e, 'Non è stato possibile segnarla. Riprova.');
    }
    ricarica();
}

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Incassi Rate', href: route(generateRoute('gestionale.movimenti-rate.index'), { condominio: props.condominio.id }) },
    { title: 'Ricevute' },
]);
</script>

<template>
    <Head title="Ricevute degli incassi" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-4">
            <PageHeaderGuide
                page-title="Ricevute degli incassi"
                page-subtitle="Stampa o invia per email le ricevute dei versamenti, una o tante. La pagina si apre sulle ricevute mai emesse dal primo del mese scorso."
                :guides="[]"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                :esercizio="props.esercizio"
                :esercizi="props.esercizi"
            />

            <div v-if="flashMessage">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <!-- Gli avvisi: la coda ferma, le non partite, le annullate da comunicare -->
            <p v-if="in_coda_da_tempo > 0" class="flex items-start gap-2 rounded-lg border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/40 p-3 text-sm text-amber-800 dark:text-amber-200">
                <AlertTriangle class="w-4 h-4 mt-0.5 shrink-0" />
                <span>
                    {{ in_coda_da_tempo === 1 ? '1 ricevuta è in coda' : `${in_coda_da_tempo} ricevute sono in coda` }} da più di un'ora. Di solito vuol dire che sul
                    server non gira il servizio che manda le email in coda: chi gestisce l'installazione controlla che sia attivo (SCHEDULE_QUEUE_WORKER nel
                    file .env, o un processo queue:work).
                </span>
            </p>
            <div v-if="non_partite > 0" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-rose-200 dark:border-rose-900 bg-rose-50 dark:bg-rose-950/40 p-3 text-sm text-rose-800 dark:text-rose-200">
                <span>{{ non_partite === 1 ? '1 invio non è partito' : `${non_partite} invii non sono partiti` }}: l'errore del server è accanto a ogni ricevuta.</span>
                <Button size="sm" variant="outline" @click="confermaRiprova"><Mail class="w-4 h-4 mr-2" /> Riprova le non partite</Button>
            </div>
            <div v-if="annullate_da_comunicare.length" class="rounded-lg border border-sky-200 dark:border-sky-900 bg-sky-50 dark:bg-sky-950/40 p-3 text-sm text-sky-900 dark:text-sky-100 space-y-1">
                <p class="font-medium">Annullate dopo l'uscita della ricevuta: la persona l'ha avuta, poi l'incasso è stato annullato. La ricevuta si ristampa o, se era partita per email, si rimanda: esce con «INCASSO ANNULLATO». Se e come dirlo alla persona lo decidi tu. Se non serve, segnalalo: l'avviso si toglie.</p>
                <p v-for="a in annullate_da_comunicare" :key="a.id" class="flex flex-wrap items-center gap-2">
                    N. {{ a.numero }} del {{ giorno(a.data) }}
                    <Button size="sm" variant="ghost" class="h-7 text-xs" @click="apri(a.id)"><Printer class="w-3.5 h-3.5 mr-1" /> Stampa o invia</Button>
                    <Button size="sm" variant="ghost" class="h-7 text-xs" @click="confermaNonServe(a)">Non serve comunicarla</Button>
                </p>
            </div>
            <div v-if="registrati_di_recente" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 p-3 text-sm text-slate-700 dark:text-slate-200">
                <span>{{ testoRegistratiDiRecente(registrati_di_recente) }}</span>
                <Button size="sm" variant="outline" @click="includiLeRecenti">Includile</Button>
            </div>
            <p v-if="messaggio" data-testid="messaggio-pagina" class="rounded-lg border bg-slate-50 dark:bg-slate-800/60 p-2 text-sm">{{ messaggio }}</p>

            <!-- I filtri -->
            <div class="flex flex-wrap items-end gap-3 rounded-xl border bg-white dark:bg-slate-900 p-3 text-xs">
                <label class="space-y-1">
                    <span class="block text-slate-500 dark:text-slate-400">Ricevuta</span>
                    <select v-model="f.ricevuta" class="h-8 rounded-md border px-2 bg-transparent">
                        <option value="mai">Mai emessa</option>
                        <option value="stampata">Già stampata</option>
                        <option value="inviata">Già inviata o in coda</option>
                        <option value="tutte">Tutte</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="block text-slate-500 dark:text-slate-400">Versamento dal</span>
                    <Input v-model="f.data_da" type="date" class="h-8 w-[140px] text-xs" />
                </label>
                <label class="space-y-1">
                    <span class="block text-slate-500 dark:text-slate-400">al</span>
                    <Input v-model="f.data_a" type="date" class="h-8 w-[140px] text-xs" />
                </label>
                <label class="space-y-1">
                    <span class="block text-slate-500 dark:text-slate-400">Gestione</span>
                    <select v-model="f.gestione_id" class="h-8 rounded-md border px-2 bg-transparent">
                        <option value="">Tutte</option>
                        <option v-for="g in gestioni" :key="g.id" :value="String(g.id)">{{ g.nome }}</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="block text-slate-500 dark:text-slate-400">Cassa</span>
                    <select v-model="f.cassa_id" class="h-8 rounded-md border px-2 bg-transparent">
                        <option value="">Tutte</option>
                        <option v-for="c in casse" :key="c.id" :value="String(c.id)">{{ c.nome }}</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="block text-slate-500 dark:text-slate-400">Email</span>
                    <select v-model="f.email" class="h-8 rounded-md border px-2 bg-transparent">
                        <option value="">Con o senza</option>
                        <option value="con">Con email</option>
                        <option value="senza">Senza email</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="block text-slate-500 dark:text-slate-400">Importo</span>
                    <select v-model="f.soglia" class="h-8 rounded-md border px-2 bg-transparent">
                        <option value="">Tutti</option>
                        <option value="sopra">Sopra € 77,47</option>
                        <option value="fino">Fino a € 77,47</option>
                    </select>
                </label>
                <div class="space-y-1 min-w-[220px]">
                    <span class="block text-slate-500 dark:text-slate-400">Persone (vuoto: tutte)</span>
                    <MultiSelect v-model="f.persone" :options="opzioniPersone" text="text" value="value" placeholder="Cerca per nome" />
                </div>
            </div>

            <!-- La scelta e le azioni -->
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <!-- Sul telefono l'intestazione della tabella non c'è: la casella che spunta la pagina sta qui. -->
                    <label v-if="incassi.data.length" data-testid="seleziona-pagina-telefono" class="flex items-center gap-2 text-xs md:hidden">
                        <Checkbox :model-value="paginaSpuntata" :disabled="selezione.tutte" @update:model-value="selezione = selezionaPagina(selezione, idsPagina)" /> Seleziona questa pagina
                    </label>
                    <span>{{ totale === 1 ? '1 ricevuta' : `${totale} ricevute` }} con questi filtri.</span>
                    <span v-if="quante > 0" class="font-medium">Selezionate: {{ quante }}.</span>
                    <Button v-if="!selezione.tutte && totale > 0" size="sm" variant="ghost" class="h-7 text-xs" @click="selezione = tutteDeiFiltri(selezione)">
                        Seleziona tutte le {{ totale }} dei filtri
                    </Button>
                    <Button v-if="quante > 0" size="sm" variant="ghost" class="h-7 text-xs" @click="selezione = selezioneVuota()">
                        <X class="w-3.5 h-3.5 mr-1" /> Togli la scelta
                    </Button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" :disabled="quante === 0" @click="apriBlocco('stampa')"><Printer class="w-4 h-4 mr-2" /> Stampa le selezionate</Button>
                    <!-- Per chi le manda dal suo programma di posta: un PDF per persona in un archivio, con gli indirizzi. -->
                    <Button size="sm" variant="outline" :disabled="quante === 0" @click="apriBlocco('persone')"><Archive class="w-4 h-4 mr-2" /> Scarica un PDF per persona</Button>
                    <Button size="sm" :disabled="quante === 0" @click="apriBlocco('invio')"><Mail class="w-4 h-4 mr-2" /> Invia per email le selezionate</Button>
                </div>
            </div>

            <!-- L'elenco: una tabella da tablet in su; sul telefono ogni riga è una scheda, così «Ultima emissione» e «Apri» restano dentro il riquadro. -->
            <div class="overflow-x-auto rounded-xl border bg-white dark:bg-slate-900" data-testid="elenco-ricevute">
                <table class="block w-full text-sm md:table">
                    <thead class="hidden bg-slate-50 dark:bg-slate-800 text-xs text-slate-500 dark:text-slate-400 md:table-header-group">
                        <tr>
                            <th class="p-2 w-8"><Checkbox :model-value="paginaSpuntata" :disabled="selezione.tutte" @update:model-value="selezione = selezionaPagina(selezione, idsPagina)" /></th>
                            <th class="p-2 text-left">Numero e data</th>
                            <th class="p-2 text-left">Persona</th>
                            <th class="p-2 text-right">Importo</th>
                            <th class="p-2 text-left">Email</th>
                            <th class="p-2 text-left">Ultima emissione</th>
                            <th class="p-2"></th>
                        </tr>
                    </thead>
                    <tbody class="block md:table-row-group">
                        <tr v-if="incassi.data.length === 0" class="block md:table-row"><td colspan="7" class="block p-6 text-center text-slate-500 dark:text-slate-400 md:table-cell">Nessuna ricevuta con questi filtri.</td></tr>
                        <tr v-for="r in incassi.data" :key="r.id" class="grid grid-cols-[auto_1fr_auto] gap-x-3 gap-y-1 border-t border-slate-100 dark:border-slate-800 p-3 align-top md:table-row md:p-0">
                            <td class="md:table-cell md:p-2"><Checkbox :model-value="spuntata(r.id)" :disabled="selezione.tutte" @update:model-value="selezione = alterna(selezione, r.id)" /></td>
                            <td class="whitespace-nowrap md:table-cell md:p-2"><span class="font-semibold">{{ r.numero }}</span><br><span class="text-xs text-slate-500 dark:text-slate-400">{{ giorno(r.data) }}</span></td>
                            <td class="col-start-2 col-span-2 row-start-2 md:table-cell md:p-2">
                                <span class="font-medium">{{ r.posizione?.nome ?? '—' }}</span>
                                <button v-if="r.posizione" type="button" class="ml-2 text-xs text-sky-700 dark:text-sky-400 hover:underline" @click="soloQuestaPersona(r.posizione.id)">solo questa persona</button>
                                <span v-if="r.versato_da" class="block text-xs text-sky-700 dark:text-sky-400">Versato da {{ r.versato_da.nome }}</span>
                            </td>
                            <td class="col-start-3 row-start-1 text-right whitespace-nowrap md:table-cell md:p-2">{{ euro(r.importo) }}<span v-if="r.sopra_soglia" class="block text-[11px] text-amber-700 dark:text-amber-300">sopra € 77,47</span></td>
                            <td class="col-start-2 col-span-2 text-xs md:table-cell md:p-2"><span class="text-slate-500 dark:text-slate-400 md:hidden">Email: </span>{{ r.ha_email ? 'Sì' : 'No: stampala per la posta' }}</td>
                            <td class="col-start-2 col-span-2 text-xs md:table-cell md:p-2" :class="r.ultima?.stato === 'non_partita' ? 'text-rose-700 dark:text-rose-300' : 'text-slate-600 dark:text-slate-300'">
                                {{ testoEmissione(r.ultima) }}
                                <span v-if="r.ultima?.errore_completo" class="block break-words text-[11px] text-slate-500 dark:text-slate-400">Dal server: {{ r.ultima.errore_completo }}</span>
                                <button v-if="r.ultima_email?.manda_a" type="button" class="block text-sky-700 dark:text-sky-400 hover:underline" @click="confermaInviaDiNuovo(r)">invia di nuovo</button>
                            </td>
                            <td class="col-start-2 col-span-2 md:table-cell md:p-2"><Button size="sm" variant="ghost" class="h-7 -ml-2 text-xs md:ml-0" @click="apri(r.id)"><FileText class="w-3.5 h-3.5 mr-1" /> Apri</Button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="incassi.meta.last_page > 1" class="flex items-center justify-end gap-2 text-sm">
                <Button size="sm" variant="outline" :disabled="incassi.meta.current_page <= 1" @click="applica(incassi.meta.current_page - 1)">Precedente</Button>
                <span>Pagina {{ incassi.meta.current_page }} di {{ incassi.meta.last_page }}</span>
                <Button size="sm" variant="outline" :disabled="incassi.meta.current_page >= incassi.meta.last_page" @click="applica(incassi.meta.current_page + 1)">Successiva</Button>
            </div>
        </div>

        <RicevutaDialog v-model:open="finestraAperta" :condominio-id="condominio.id" :incasso-id="incassoAperto" @emessa="ricarica" @avviso="(frase: string) => (messaggio = frase)" />
        <ConfirmDialog v-model="conferma.aperta" :title="conferma.titolo" :description="conferma.testo" :confirm-text="conferma.pulsante" @confirm="conferma.azione()" />

        <!-- La finestra del blocco -->
        <Dialog v-model:open="blocco.aperto">
            <DialogContent class="sm:max-w-[640px] max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ blocco.modo === 'stampa' ? 'Stampa le ricevute selezionate' : blocco.modo === 'persone' ? 'Scarica un PDF per persona' : 'Invia per email le ricevute selezionate' }}</DialogTitle>
                    <DialogDescription v-if="anteprima">
                        {{ anteprima.ricevute === 1 ? '1 ricevuta' : `${anteprima.ricevute} ricevute` }}<template v-if="anteprima.annullati">, {{ anteprima.annullati }} di incassi annullati escluse</template><template v-if="anteprima.senza_versamento">, {{ anteprima.senza_versamento }} senza denaro escluse (le rate coperte solo dal credito)</template><template v-if="anteprima.piu_persone">, {{ anteprima.piu_persone }} con le rate di più persone escluse (si leggono nell'estratto conto di ciascuna)</template>.
                    </DialogDescription>
                </DialogHeader>

                <div v-if="caricamento && !anteprima" class="flex items-center gap-2 py-6 text-sm text-muted-foreground"><Loader2 class="w-4 h-4 animate-spin" /> Preparo l'anteprima…</div>
                <!-- L'anteprima si rifà con un'altra scelta: quella di prima resta a video, ma i pulsanti che la leggono sono fermi finché arriva la nuova. -->
                <p v-if="anteprimaInCorso && anteprima" class="flex items-center gap-2 text-xs text-muted-foreground"><Loader2 class="w-3.5 h-3.5 animate-spin" /> Aggiorno l'anteprima…</p>

                <div v-if="anteprima && !esito" class="space-y-4 text-sm">
                    <p v-if="anteprima.piu_vecchie > 0" class="flex items-start gap-2 rounded-md border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/40 p-2 text-xs text-amber-800 dark:text-amber-200">
                        <AlertTriangle class="w-4 h-4 shrink-0" /> {{ anteprima.piu_vecchie === 1 ? '1 ricevuta è' : `${anteprima.piu_vecchie} ricevute sono` }} di versamenti di più di 60 giorni fa. Controlla il periodo.
                    </p>
                    <p v-if="anteprima.copie > 0" class="text-xs text-muted-foreground">
                        {{ anteprima.copie === 1 ? '1 è già stata emessa: esce' : `${anteprima.copie} sono già state emesse: escono` }} come «Copia», con le cifre dell'originale.
                    </p>
                    <!-- La prima emissione è l'originale: lo si dice prima, perché chi guarda le ricevute non lo bruci (c'è «Anteprima»). -->
                    <p v-if="anteprima.ricevute - anteprima.copie > 0" class="text-xs text-muted-foreground">{{ RIGA_ORIGINALE }}</p>
                    <p v-if="blocco.modo === 'stampa' && anteprima.oltre_tetto" class="rounded-md border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/40 p-2 text-xs text-amber-800 dark:text-amber-200">
                        In un PDF stanno al massimo {{ anteprima.tetto }} ricevute: queste si stampano in {{ partiPdf.length }} parti, una per volta, senza dividere una persona (salvo una che da sola ha più di {{ anteprima.tetto }} ricevute).
                    </p>
                    <template v-if="blocco.modo === 'persone'">
                        <p class="rounded-md border bg-muted/40 p-2 text-xs">
                            Un PDF per persona, con le sue ricevute, in un archivio .zip; dentro c'è anche «Indirizzi email.txt», con gli indirizzi di ognuna.
                            Lo mandi dal tuo programma di posta. Ogni ricevuta risulta stampata.
                        </p>
                        <p v-if="anteprima.oltre_tetto" class="rounded-md border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/40 p-2 text-xs text-amber-800 dark:text-amber-200">
                            In un archivio stanno al massimo {{ anteprima.tetto }} ricevute: restringi i filtri, per esempio il periodo, e scaricale in più volte.
                        </p>
                    </template>

                    <!-- Il bollo: la stessa scelta, una volta per le ricevute sopra la soglia e una per le altre. La frase sta sotto il titolo del primo gruppo che si vede. -->
                    <div v-if="richiesti.sopra || richiesti.sotto" class="space-y-4">
                        <SceltaBollo
                            v-if="richiesti.sopra"
                            :stato="scelte.bollo_sopra"
                            :modo="scelte.modo_bollo"
                            :nota="`Ricevute sopra € 77,47 (${anteprima.sopra_soglia})`"
                            :nome="`Imposta di bollo, ricevute sopra € 77,47 (${anteprima.sopra_soglia})`"
                            :frase="FRASE_BOLLO"
                            @scegli="(scelta) => scegliBollo('bollo_sopra', scelta)"
                        />
                        <SceltaBollo
                            v-if="richiesti.sotto"
                            :stato="scelte.bollo_sotto"
                            :modo="scelte.modo_bollo"
                            :nota="`Ricevute fino a € 77,47 (${anteprima.sotto_soglia})`"
                            :nome="`Imposta di bollo, ricevute fino a € 77,47 (${anteprima.sotto_soglia})`"
                            :frase="richiesti.sopra ? null : FRASE_BOLLO"
                            @scegli="(scelta) => scegliBollo('bollo_sotto', scelta)"
                        />
                        <!-- Il modo e l'autorizzazione sono uno solo per tutte le ricevute: l'autorizzazione del modo virtuale vale per tutte, il numero di un contrassegno no. -->
                        <div v-if="conBolloAssolto && scelte.modo_bollo" class="space-y-2">
                            <p v-if="dueGruppiAssolti" data-testid="nota-modo-unico" class="text-xs text-muted-foreground">{{ NOTA_MODO_UNICO }}</p>
                            <EstremiBollo v-if="scelte.modo_bollo === 'virtuale'" id="estremi-bollo-blocco" v-model="scelte.estremi_bollo" modo="virtuale" />
                            <p v-if="scelte.modo_bollo === 'contrassegno'" class="text-xs text-muted-foreground">
                                Il numero del contrassegno è diverso per ogni ricevuta: in blocco non si scrive. Scrivilo sulle ricevute stampate, o emetti ogni ricevuta dalla sua finestra.
                            </p>
                            <!-- Su una ricevuta per email la marca non viaggia: il contrassegno si può scegliere, e come assolvere il bollo lo decide l'amministratore. -->
                            <p v-if="scelte.modo_bollo === 'contrassegno' && blocco.modo !== 'stampa'" data-testid="nota-contrassegno-email" class="text-xs text-muted-foreground">{{ NOTA_CONTRASSEGNO_EMAIL }}</p>
                        </div>
                    </div>
                    <div class="space-y-1">
                        <label class="font-medium" for="nota-blocco">Nota, facoltativa, su tutte</label>
                        <Textarea id="nota-blocco" v-model="scelte.nota" maxlength="500" rows="2" />
                        <p class="text-xs text-muted-foreground">{{ NOTA_INTERESSI }}</p>
                    </div>
                    <!-- La causale è un testo libero e può portare note interne: si stampa solo se lo scegli (decisione 202). -->
                    <label class="flex items-start gap-2 cursor-pointer">
                        <Checkbox :model-value="scelte.stampa_causale" @update:model-value="(v: any) => (scelte.stampa_causale = !!v)" class="mt-0.5" />
                        <span>Stampa la causale<span class="block text-xs text-muted-foreground">Il testo scritto nell'incasso, per esempio «Bonifico CRO …»; può portare note interne.</span></span>
                    </label>
                    <div v-if="testoCausali(anteprima)" class="-mt-2 pl-6 text-xs text-muted-foreground">
                        {{ testoCausali(anteprima) }}
                        <button type="button" class="ml-1 text-sky-700 dark:text-sky-400 hover:underline" @click="mostraLeCausali = !mostraLeCausali">{{ mostraLeCausali ? 'nascondi' : 'mostra' }}</button>
                        <ul v-if="mostraLeCausali" class="mt-1 space-y-0.5">
                            <li v-for="c in anteprima.causali" :key="c.testo">«{{ c.testo }}» — {{ c.quante }}</li>
                        </ul>
                    </div>

                    <template v-if="blocco.modo === 'stampa'">
                        <label class="space-y-1 block">
                            <span class="font-medium">Ordine</span>
                            <select v-model="ordine" :disabled="partePdf > 0 || anteprimaInCorso || pdfInArrivo" class="h-8 rounded-md border px-2 bg-transparent block disabled:opacity-60">
                                <option value="persona">Per persona</option>
                                <option value="unita">Per unità</option>
                                <option value="data">Per data del versamento</option>
                            </select>
                            <span class="text-xs text-muted-foreground">Le ricevute della stessa persona restano insieme, con l'indirizzo sulla prima: una busta per persona.</span>
                            <span v-if="anteprima.oltre_tetto" class="block text-xs text-muted-foreground">
                                {{ partePdf > 0 ? 'Una parte è già stampata: l\'ordine non si cambia a metà.' : 'L\'ordine vale per tutte le parti, dalla prima all\'ultima.' }}
                            </span>
                        </label>
                        <p v-if="anteprima.senza_indirizzo_postale.length" class="text-xs text-amber-700 dark:text-amber-300">
                            Senza indirizzo postale, il riquadro della busta resta vuoto: {{ anteprima.senza_indirizzo_postale.join(', ') }}.
                        </p>
                    </template>

                    <template v-else-if="blocco.modo === 'persone'">
                        <p v-if="scaricato" class="rounded-md border border-emerald-200 dark:border-emerald-900 bg-emerald-50 dark:bg-emerald-950/40 p-2 text-xs text-emerald-800 dark:text-emerald-200">{{ scaricato }}</p>
                    </template>

                    <p v-else-if="anteprima.senza_server_posta" class="rounded-md border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/40 p-2 text-xs text-amber-800 dark:text-amber-200">
                        Nessun server di posta configurato: le ricevute non possono partire per email. Configuralo nelle impostazioni della posta; intanto puoi stamparle.
                    </p>

                    <template v-else>
                        <div class="space-y-1">
                            <p class="font-medium">A quali indirizzi</p>
                            <label v-for="(etichetta, tipo) in { email: 'Primaria', email_secondaria: 'Secondaria', pec: 'PEC' }" :key="tipo" class="flex items-start gap-2 cursor-pointer">
                                <Checkbox :model-value="indirizzi.includes(tipo)" @update:model-value="(v: any) => (indirizzi = v ? [...indirizzi, tipo] : indirizzi.filter((t) => t !== tipo))" class="mt-0.5" />
                                <span>{{ etichetta }}<span v-if="tipo === 'pec'" class="block text-xs text-muted-foreground">{{ AVVISO_PEC }}</span></span>
                            </label>
                        </div>
                        <label v-if="anteprima.chi_ha_versato > 0" class="flex items-start gap-2 cursor-pointer">
                            <Checkbox :model-value="ancheChiHaVersato" @update:model-value="(v: any) => (ancheChiHaVersato = !!v)" class="mt-0.5" />
                            <span>Manda anche a chi ha versato per conto di altri ({{ anteprima.chi_ha_versato }})<span class="block text-xs text-muted-foreground">La ricevuta porta le rate e il residuo della posizione.</span></span>
                        </label>
                        <label v-if="anteprima.gia_inviate.ricevute > 0" class="flex items-start gap-2 cursor-pointer">
                            <Checkbox :model-value="escludiGiaInviate" @update:model-value="(v: any) => (escludiGiaInviate = !!v)" class="mt-0.5" />
                            <span>Escludi le {{ anteprima.gia_inviate.ricevute }} già inviate o in coda</span>
                        </label>
                        <p class="rounded-md border bg-muted/40 p-2">Partiranno: {{ testoAnteprima(riepilogoInvio) }}.</p>
                        <p v-if="testoTetto(anteprima) && escluse.length === 0" class="text-xs text-amber-800 dark:text-amber-200">{{ testoTetto(anteprima) }}</p>

                        <!-- Chi riceve che cosa: per persona, con gli indirizzi e le ricevute; si può togliere una persona dall'invio. -->
                        <div v-if="personeAnteprima.length" class="space-y-1">
                            <button type="button" class="text-xs font-medium text-sky-700 dark:text-sky-400 hover:underline" @click="mostraLePersone = !mostraLePersone">
                                {{ mostraLePersone ? 'Nascondi chi riceve che cosa' : 'Mostra chi riceve che cosa' }}
                                <template v-if="escluse.length">({{ escluse.length === 1 ? '1 persona tolta' : `${escluse.length} persone tolte` }})</template>
                            </button>
                            <ul v-if="mostraLePersone" class="max-h-56 space-y-1 overflow-y-auto rounded-md border p-2 text-xs">
                                <li v-for="p in personeAnteprima" :key="p.id" class="flex items-start gap-2">
                                    <Checkbox :model-value="!escluse.includes(p.id)" @update:model-value="(v: any) => alternaPersona(p.id, !!v)" class="mt-0.5" />
                                    <span>
                                        <span class="font-medium">{{ p.nome }}</span>
                                        — {{ p.indirizzi.map((i) => `${TIPI_INDIRIZZO[i.tipo] ?? i.tipo}: ${i.indirizzo}`).join('; ') }}
                                        <span class="block text-muted-foreground">
                                            {{ p.ricevute.map((r) => (r.per_conto_di ? `n. ${r.numero} (per conto di ${r.per_conto_di})` : `n. ${r.numero}`)).join(', ') }}
                                            — {{ p.messaggi === 1 ? '1 messaggio' : `${p.messaggi} messaggi` }}
                                        </span>
                                    </span>
                                </li>
                            </ul>
                        </div>
                        <div v-if="anteprima.senza_indirizzo.ricevute > 0" class="flex flex-wrap items-center justify-between gap-2 text-xs text-amber-800 dark:text-amber-200">
                            <span>
                                Senza un indirizzo fra quelli scelti, da stampare per la posta: {{ anteprima.senza_indirizzo.persone.join(', ') }}.
                                <template v-if="testoAltriIndirizzi(anteprima.altri_indirizzi)">{{ testoAltriIndirizzi(anteprima.altri_indirizzi) }}</template>
                            </span>
                            <Button size="sm" variant="outline" class="h-7 text-xs" :disabled="!pronta || pdfInArrivo || anteprimaInCorso" @click="stampaInBlocco(anteprima.senza_indirizzo.incassi)">Stampa le {{ anteprima.senza_indirizzo.ricevute }} senza indirizzo</Button>
                        </div>
                        <p v-if="prova" class="rounded-md border border-emerald-200 dark:border-emerald-900 bg-emerald-50 dark:bg-emerald-950/40 p-2 text-xs text-emerald-800 dark:text-emerald-200">{{ prova }}</p>
                    </template>
                </div>

                <div v-if="esito" class="space-y-3 text-sm">
                    <p class="rounded-md border border-emerald-200 dark:border-emerald-900 bg-emerald-50 dark:bg-emerald-950/40 p-3 text-emerald-800 dark:text-emerald-200">{{ testoRiepilogo(esito) }}</p>
                    <p v-if="esito.errori?.length" class="text-xs text-rose-700 dark:text-rose-300">{{ esito.errori.join(' ') }}</p>
                    <div v-if="esito.restanti" class="flex flex-wrap items-center gap-2 rounded-md border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/40 p-3 text-xs text-amber-800 dark:text-amber-200">
                        <span>{{ testoRestantiBlocco(esito) }}</span>
                        <Button size="sm" :disabled="caricamento || richiestaInCorso" @click="inviaInBlocco(esito.restanti)"><Mail class="w-4 h-4 mr-2" /> Invia le altre</Button>
                    </div>
                    <div v-if="esito.senza_indirizzo > 0" class="flex flex-wrap items-center gap-2">
                        <span>{{ esito.senza_indirizzo_persone.join(', ') }}: senza indirizzo.</span>
                        <Button size="sm" variant="outline" :disabled="pdfInArrivo" @click="stampaInBlocco(esito.senza_indirizzo_incassi)"><Printer class="w-4 h-4 mr-2" /> Stampa le {{ esito.senza_indirizzo }} senza indirizzo</Button>
                    </div>
                </div>

                <!-- L'esito di una richiesta partita prima che la finestra si riaprisse: la stessa frase che sta in testa alla pagina, dietro lo sfondo scuro. -->
                <p
                    v-if="esitoDiPrima"
                    data-testid="esito-di-prima"
                    class="rounded-md border p-2 text-sm"
                    :class="esitoDiPrima.riuscito
                        ? 'border-emerald-200 dark:border-emerald-900 bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-200'
                        : 'border-rose-200 dark:border-rose-900 bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-200'"
                >{{ esitoDiPrima.frase }}</p>
                <p v-if="pdfInArrivo" role="status" class="flex items-center gap-2 text-xs text-muted-foreground"><Loader2 class="w-3.5 h-3.5 animate-spin" /> {{ pdfSenzaScheda ? 'Sto preparando il PDF: la scheda nuova non si è aperta, quindi si scarica.' : 'Sto preparando il PDF: si apre nella scheda nuova.' }}</p>
                <p v-if="pdfArrivato" class="rounded-md border border-emerald-200 dark:border-emerald-900 bg-emerald-50 dark:bg-emerald-950/40 p-2 text-sm text-emerald-800 dark:text-emerald-200">{{ pdfArrivato }}</p>
                <p v-if="errore" class="rounded-md border border-rose-200 dark:border-rose-900 bg-rose-50 dark:bg-rose-950/40 p-2 text-sm text-rose-800 dark:text-rose-200">{{ errore }}</p>

                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="blocco.aperto = false">Chiudi</Button>
                    <template v-if="anteprima && !esito">
                        <template v-if="blocco.modo === 'stampa'">
                            <!-- Guarda le ricevute senza emetterle: non scrive il registro. -->
                            <Button variant="outline" :disabled="anteprima.ricevute === 0 || anteprimaInCorso || pdfInArrivo" @click="guardaLeRicevute"><Eye class="w-4 h-4 mr-2" /> Anteprima</Button>
                            <!-- Fermo mentre l'anteprima si rifà: le parti a video sono quelle dell'ordine di prima. -->
                            <Button v-if="anteprima.oltre_tetto && partiPdf.length" :disabled="!pronta || caricamento || pdfInArrivo || partePdf >= partiPdf.length" @click="stampaLaParte">
                                <Printer class="w-4 h-4 mr-2" /> {{ partePdf >= partiPdf.length ? 'Tutte stampate' : etichettaParteDelPdf(partiPdf, partePdf) }}
                            </Button>
                            <Button v-else :disabled="!pronta || caricamento || pdfInArrivo || anteprima.oltre_tetto" @click="stampaInBlocco()"><Printer class="w-4 h-4 mr-2" /> Stampa</Button>
                        </template>
                        <template v-else-if="blocco.modo === 'persone'">
                            <Button variant="outline" :disabled="anteprima.ricevute === 0 || anteprima.oltre_tetto || anteprimaInCorso || pdfInArrivo" @click="guardaLeRicevute"><Eye class="w-4 h-4 mr-2" /> Anteprima</Button>
                            <Button :disabled="!pronta || caricamento || richiestaInCorso || anteprima.oltre_tetto || anteprima.ricevute === 0" @click="scaricaPerPersona">
                                <Loader2 v-if="caricamento || richiestaInCorso" class="w-4 h-4 mr-2 animate-spin" /><Archive v-else class="w-4 h-4 mr-2" /> Scarica lo .zip
                            </Button>
                        </template>
                        <template v-else>
                            <Button variant="outline" :disabled="!pronta || anteprima.senza_server_posta || riepilogoInvio.persone === 0" @click="mandaneUnaAMe">Mandane una a me</Button>
                            <Button :disabled="!pronta || caricamento || richiestaInCorso || anteprima.senza_server_posta || riepilogoInvio.persone === 0 || indirizzi.length === 0" @click="inviaInBlocco()">
                                <Loader2 v-if="caricamento || richiestaInCorso" class="w-4 h-4 mr-2 animate-spin" /><Mail v-else class="w-4 h-4 mr-2" /> Invia
                            </Button>
                        </template>
                    </template>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </GestionaleLayout>
</template>
