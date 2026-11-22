<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La pagina «Rate scadute» del condominio: una riga per persona con lo scaduto (lordo: il credito accanto, mai tolto), le fasce, il ritardo
 * più vecchio in parole, la data più vicina dei sei mesi con la dispensa se c'è; aperta, la riga mostra le rate con le due date. La vista
 * «In scadenza nei prossimi N giorni» è la stessa pagina. I filtri stanno nell'indirizzo e non si ricordano, come nelle altre pagine; la
 * scelta delle righe è una lista o «tutte le N dei filtri», che il server rilegge per la stampa.
 *
 * «Da portare in assemblea» (Coda 736), per chi gestisce le assemblee: in alto il memo della dispensa con i sei mesi dell'esercizio che il server indica
 * (la data più vicina, o quella del filtro «I sei mesi del …»), lo stesso punto che il programma propone; nel menu «⋯» della riga lo stesso memo con i
 * sei mesi della data più vicina di quella persona. Il punto resta senza nomi: l'avviso di convocazione stampa il testo del punto.
 */
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { GUIDE_RATE_SCADUTE } from '@/lib/gestionale/debiti/guideRateScadute';
import Alert from '@/components/Alert.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TooltipProvider } from '@/components/ui/tooltip';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import SpiegazioneDelFiltro from '@/components/gestionale/debiti/SpiegazioneDelFiltro.vue';
import RateScaduteGuide from '@/components/guides/RateScaduteGuide.vue';
import FinestraDelMemo from '@/components/assemblee/FinestraDelMemo.vue';
import PulsanteDaPortareInAssemblea from '@/components/assemblee/PulsanteDaPortareInAssemblea.vue';
import { useToast } from '@/components/ui/toast';
import { usePermission } from '@/composables/permissions';
import { gestisceLeAssemblee } from '@/lib/assemblee/permessi';
import { AlertTriangle, CalendarPlus, ChevronDown, ChevronRight, Coins, Handshake, History, Lightbulb, ListChecks, Mail, MoreHorizontal, Printer, Scale, Send, X } from 'lucide-vue-next';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import axios from 'axios';
import LettereDialog from '@/components/gestionale/lettere/LettereDialog.vue';
import AccordoRientroDialog from '@/components/gestionale/accordi/AccordoRientroDialog.vue';
import ProponiInteressiDialog from '@/components/gestionale/addebiti/ProponiInteressiDialog.vue';
import { ereditatiDellaPersona, testoEreditatoInElenco, type EreditatoInElenco } from '@/lib/gestionale/addebiti/interessiDegliEredi';
import AddebitaSpesaDialog from '@/components/gestionale/addebiti/AddebitaSpesaDialog.vue';
import { testoParteInAccordo, testoSegnaleBreve, testoSegnoAccordo, type AccordoRientro, type ParteInAccordo } from '@/lib/gestionale/debiti/accordiRientro';
import { CANALI_SPEDIZIONE, testoContiCambiati, testoPecDaRimandare, testoRiprova, testoUltimaLettera, type UltimaLettera } from '@/lib/gestionale/lettere/lettere';
import { apriPdfInUnaScheda, fraseDaErroreConFile, frasePerErrore } from '@/lib/gestionale/incassi/ricevute';
import { alterna, quanteSelezionate, selezionaPagina, selezioneVuota, tutteDeiFiltri, type Selezione } from '@/lib/gestionale/incassi/ricevute';
import {
    FRASE_DATA_PASSATA, FRASE_RISERVATEZZA, NOTA_PREGRESSO_NELLA_RATA, PERSONE_NEL_MENU, RIGA_TESORERIA, SPIEGAZIONI_DEI_FILTRI, euroTesto, giorno, personeDelMenu,
    testoData, testoDispensa, testoGiorniDellaRata,
    testoNonEmesse, testoSeiMesiDellaPersona, testoTotale, titoloVista, testoSeiMesiDel, testoDiCui, type NonEmesse, type SeiMesiDellaRata,
} from '@/lib/gestionale/debiti/rateScadute';
import type { Flash } from '@/types/flash';

interface Rata {
    rata_id: number; numero_rata: number; piano: string; unita: string | null; scadenza: string; residuo: number; giorni_ritardo: number | null;
    pregresso: { origine: string; etichetta: string } | null; pregresso_nella_rata: boolean; sei_mesi: SeiMesiDellaRata | null;
    accordo?: ParteInAccordo | null;
    /** Con il filtro «I sei mesi del …»: la rata è di quella data. */
    del_sei_mesi?: boolean;
    /** Decisioni 494 (3) e 516: il debito ereditato finché i suoi interessi corrono; la rata è quella del defunto. */
    ereditato?: EreditatoInElenco | null;
}
interface Riga {
    anagrafica_id: number; nome: string; unita: string[]; importo: number; credito: number | null; rate: Rata[];
    fasce?: { '1_30': number; '31_90': number; oltre_90: number }; ritardo_testo?: string; prima_scadenza?: string;
    sei_mesi?: { data: string | null; dispensa: any; mancano: number }; con_pregresso?: boolean; origine_non_dichiarata?: boolean;
    ultima_lettera?: UltimaLettera | null; lettera_in_sospeso?: UltimaLettera | null; accordi?: AccordoRientro[];
    /** Con il filtro «I sei mesi del …»: quanto dello scaduto è di quella data. */
    di_cui_sei_mesi?: number;
}
interface Filtri {
    alla: string; vista: string; giorni: number; cerca: string | null; anagrafica_id: number | null; fascia: string | null; minimo: number | null;
    gestione_id: number | null; sei_mesi_entro: number | null; con_pregresso: boolean; ordine: string;
    senza_lettere_da?: number | null; mai_scritto?: boolean; da_spedire?: boolean; con_accordo?: boolean; sei_mesi_del?: string | null;
}

const props = defineProps<{
    condominio: { id: number; nome: string }; condomini: any; esercizio: any;
    righe: { data: Riga[]; meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null } };
    totale: number; persone_totali: number; filtri: Filtri; oggi: string; non_emesse: NonEmesse | null;
    gestioni: Array<{ id: number; nome: string }>; persone?: Array<{ id: number; nome: string }>; persona_della_porta: string | null; testo_norma: string; frase_festivo?: string; frase_sei_mesi?: string;
    lettere_da_riprovare?: number; lettere_in_coda_da_tempo?: number;
    /** Le lettere per PEC non partite, o con l'avviso di mancata consegna: si rimandano in blocco o si stampano per la posta. */
    pec_da_rimandare?: number;
    /** I suggerimenti che chiedono le regole del recupero: suggerimenti, mai azioni. */
    suggerimenti?: Array<{ chiave: string; frase: string; persone: number[]; porta: 'seleziona' | 'proponi_interessi' }>;
    /** Il tasso degli interessi di mora di oggi, con la sua fonte. */
    regole_del_recupero?: string | null;
    /** «I sei mesi del …», dal riquadro dei sei mesi: la data, l'esercizio da cui viene, quante persone e la cifra di quell'esercizio. */
    sei_mesi_del?: { data: string; esercizio: string | null; persone: number; cifra: number } | null;
    /** «Da portare in assemblea» (Coda 736): l'esercizio dei sei mesi da cui nasce il memo della dispensa. */
    dispensa_in_assemblea?: { esercizio_id: number; data: string } | null;
}>();

const permessi = usePermission();
const { generateRoute, generatePath } = permessi;
const conLeAssemblee = computed(() => gestisceLeAssemblee(permessi));
const page = usePage<{ flash?: { message?: Flash }; paginazione?: { consentite?: number[] } }>();
const flashMessage = computed(() => page.props.flash?.message);

const inScadenza = computed(() => props.filtri.vista === 'in_scadenza');
const allaDiOggi = computed(() => props.filtri.alla === props.oggi);
const titolo = computed(() => titoloVista(props.filtri.vista, props.filtri.giorni));

// ── I filtri ────────────────────────────────────────────────────────────
const f = ref({
    alla: props.filtri.alla,
    giorni: String(props.filtri.giorni),
    anagrafica_id: props.filtri.anagrafica_id ?? null,
    fascia: props.filtri.fascia ?? '',
    minimo: props.filtri.minimo ? String(props.filtri.minimo / 100).replace('.', ',') : '',
    gestione_id: props.filtri.gestione_id ? String(props.filtri.gestione_id) : '',
    sei_mesi_entro: props.filtri.sei_mesi_entro !== null ? String(props.filtri.sei_mesi_entro) : '',
    con_pregresso: props.filtri.con_pregresso,
    ordine: props.filtri.ordine,
    senza_lettere_da: props.filtri.senza_lettere_da ? String(props.filtri.senza_lettere_da) : '',
    mai_scritto: !!props.filtri.mai_scritto,
    da_spedire: !!props.filtri.da_spedire,
    con_accordo: !!props.filtri.con_accordo,
});
// La ricerca per una parte del nome non ha più un campo, ma un indirizzo vecchio (dalla cronologia del browser) può averla: la pagina la mostra
// sopra l'elenco con la × e la porta alla stampa e alle lettere, così l'elenco, la stampa e le lettere hanno le stesse persone.
const cercaDallIndirizzo = ref(props.filtri.cerca ?? '');
const togliCerca = () => { cercaDallIndirizzo.value = ''; selezione.value = selezioneVuota(); applica(); };
// «I sei mesi del …» arriva dal riquadro dei sei mesi («Tutte le rate di questo esercizio →»): tiene le persone con rate di quella data, e si toglie con la ×.
const seiMesiDel = ref(props.filtri.sei_mesi_del ?? '');
const togliSeiMesiDel = () => { seiMesiDel.value = ''; selezione.value = selezioneVuota(); applica(); };
const parametri = (vista = props.filtri.vista) => ({
    condominio: props.condominio.id,
    cerca: cercaDallIndirizzo.value || undefined,
    vista,
    alla: f.value.alla && f.value.alla !== props.oggi ? f.value.alla : undefined,
    giorni: vista === 'in_scadenza' ? f.value.giorni : undefined,
    anagrafica_id: f.value.anagrafica_id ?? undefined,
    fascia: f.value.fascia || undefined,
    minimo: f.value.minimo || undefined,
    gestione_id: f.value.gestione_id || undefined,
    sei_mesi_entro: f.value.sei_mesi_entro || undefined,
    con_pregresso: f.value.con_pregresso ? 1 : undefined,
    ordine: f.value.ordine !== 'scaduto' ? f.value.ordine : undefined,
    senza_lettere_da: f.value.senza_lettere_da || undefined,
    mai_scritto: f.value.mai_scritto ? 1 : undefined,
    da_spedire: f.value.da_spedire ? 1 : undefined,
    con_accordo: f.value.con_accordo ? 1 : undefined,
    sei_mesi_del: seiMesiDel.value || undefined,
    per_page: props.righe.meta.per_page,
});
function applica(paginaN?: number, vista?: string) {
    router.get(route(generateRoute('gestionale.rate-scadute.index'), { ...parametri(vista), page: paginaN }), {}, { preserveState: true, preserveScroll: true, replace: true });
}
// Le righe per pagina, come nelle altre tabelle del programma: i valori del server, con dentro quello in uso; la scelta torna alla pagina 1 e il
// server la ricorda per chi lavora (`PaginaElenco`).
const righePerPagina = computed<number[]>(() => {
    const consentite = page.props.paginazione?.consentite ?? [10, 15, 20, 30, 40, 50, 100];
    const inUso = props.righe.meta.per_page;

    return consentite.includes(inUso) ? consentite : [...consentite, inUso].sort((a, b) => a - b);
});
function cambiaRighePerPagina(valore: unknown) {
    const n = Number(valore);
    if (!Number.isInteger(n) || n === props.righe.meta.per_page) {
        return;
    }
    router.get(route(generateRoute('gestionale.rate-scadute.index'), { ...parametri(), per_page: n }), {}, { preserveState: true, preserveScroll: true, replace: true });
}
let attesa: ReturnType<typeof setTimeout> | null = null;
watch(f, () => {
    selezione.value = selezioneVuota();
    if (attesa) clearTimeout(attesa);
    attesa = setTimeout(() => applica(), 350);
}, { deep: true });
const cambiaVista = (vista: string) => { selezione.value = selezioneVuota(); applica(undefined, vista); };

// La guida che si apre di lato dal pulsante «Guida» dell'intestazione, come nelle altre pagine.
const mostraGuida = ref(false);

// ── Le scelte dei filtri ────────────────────────────────────────────────
// La persona si sceglie fra quelle del condominio; con un condominio grande il menu ne mostra al massimo PERSONE_NEL_MENU, e le altre si trovano
// scrivendo una parte del nome. Se la persona arrivata dall'indirizzo non è nell'elenco, il suo nome viene dal server.
const testoPersona = ref('');
const elencoPersone = computed(() => {
    const tutte = props.persone ?? [];
    const id = f.value.anagrafica_id;

    return id !== null && props.persona_della_porta && !tutte.some((p) => p.id === id) ? [{ id, nome: props.persona_della_porta }, ...tutte] : tutte;
});
const personeTrovate = computed(() => personeDelMenu(elencoPersone.value, testoPersona.value));
// Il menu filtra da sé (`:filterable="false"`): `v-select` filtra solo quando c'è un testo, e con il testo vuoto mostrerebbe tutte le persone.
// La persona scelta resta fra le opzioni anche fuori dalle prime PERSONE_NEL_MENU, perché il campo possa mostrarne il nome.
const opzioniPersone = computed(() => {
    const mostrate = personeTrovate.value.mostrate;
    const id = f.value.anagrafica_id;
    const scelta = id === null ? undefined : elencoPersone.value.find((p) => p.id === id);

    return scelta && !mostrate.some((p) => p.id === id) ? [...mostrate, scelta] : mostrate;
});
const FASCE = [
    { valore: '1_30', etichetta: 'Ha qualcosa a 1–30 giorni' },
    { valore: '31_90', etichetta: 'Ha qualcosa a 31–90 giorni' },
    { valore: 'oltre_90', etichetta: 'Ha qualcosa oltre 90 giorni' },
];
const ORDINI = [
    { valore: 'scaduto', etichetta: 'Per scaduto, dal più alto' },
    { valore: 'sei_mesi', etichetta: 'Per data dei sei mesi, dalla più vicina' },
];
const opzioniGestioni = computed(() => props.gestioni.map((g) => ({ valore: String(g.id), etichetta: g.nome })));

// ── La selezione e la stampa ────────────────────────────────────────────
const selezione = ref<Selezione>(selezioneVuota());
const idsPagina = computed(() => props.righe.data.map((r) => r.anagrafica_id));
const quante = computed(() => quanteSelezionate(selezione.value, props.persone_totali));
const spuntata = (id: number) => selezione.value.tutte || selezione.value.ids.includes(id);
const paginaSpuntata = computed(() => idsPagina.value.length > 0 && idsPagina.value.every(spuntata));
function indirizzoStampa(): string {
    const { per_page: _p, ...resto } = parametri();
    const scelta = quante.value > 0 && !selezione.value.tutte ? { persone: selezione.value.ids } : { tutte: 1 };

    return route(generateRoute('gestionale.rate-scadute.stampa'), { ...resto, ...scelta });
}
const stampa = () => window.open(indirizzoStampa(), '_blank', 'noopener');

// ── Le lettere ──────────────────────────────────────────────────────────
const finestraLettere = ref(false);
const personaDellaLettera = ref<{ id: number; nome: string } | null>(null);
const selezioneDelleLettere = ref<Selezione | null>(null);
/** I filtri come li legge il server per «tutte le N dei filtri». */
const filtriDelServer = () => {
    const { condominio: _c, per_page: _p, ...resto } = parametri();

    return resto;
};
const preparaLettere = () => {
    personaDellaLettera.value = null;
    selezioneDelleLettere.value = quante.value > 0 && !selezione.value.tutte ? { ...selezione.value } : { tutte: true, ids: [] };
    finestraLettere.value = true;
};
const scriviA = (r: Riga) => {
    personaDellaLettera.value = { id: r.anagrafica_id, nome: r.nome };
    selezioneDelleLettere.value = null;
    finestraLettere.value = true;
};
const ricaricaDopoLeLettere = () => router.reload({ only: ['righe', 'lettere_da_riprovare', 'lettere_in_coda_da_tempo', 'pec_da_rimandare'] });

// ── Gli interessi e le spese per il ritardo ─────────────────────────────
// Per le persone scelte, o per tutte quelle dei filtri, che il server rilegge.
const finestraInteressi = ref(false);
const finestraSpesa = ref(false);
const personeDelleAzioni = ref<number[] | null>(null);
const sceltePerLeAzioni = (): number[] | null => (quante.value > 0 && !selezione.value.tutte ? [...selezione.value.ids] : null);
const proponiInteressi = (persone: number[] | null) => { personeDelleAzioni.value = persone; finestraInteressi.value = true; };
const addebitaSpesa = () => { personeDelleAzioni.value = sceltePerLeAzioni(); finestraSpesa.value = true; };
// Un suggerimento porta alle sue persone: le sceglie nell'elenco, o apre la proposta degli interessi per loro. Niente si scrive.
function seguiSuggerimento(s: { persone: number[]; porta: string }) {
    if (s.porta === 'proponi_interessi') {
        proponiInteressi(s.persone);

        return;
    }
    selezione.value = { tutte: false, ids: [...s.persone] };
}
const indirizzoRegole = computed(() => route(generateRoute('gestionale.recupero-crediti.index'), { condominio: props.condominio.id }));
const ricaricaDopoGliAddebiti = () => router.reload({ only: ['righe', 'totale', 'persone_totali', 'suggerimenti'] });

// ── L'accordo di rientro, dalla riga ────────────────────────────────────
const finestraAccordo = ref(false);
const personaDellAccordo = ref<{ id: number; nome: string } | null>(null);
const apriAccordo = (r: Riga) => {
    personaDellAccordo.value = { id: r.anagrafica_id, nome: r.nome };
    finestraAccordo.value = true;
};
const ricaricaDopoLAccordo = () => router.reload({ only: ['righe', 'totale', 'persone_totali'] });

// ── «Da portare in assemblea», dalla riga: la dispensa con i sei mesi della persona ──
const { toast } = useToast();
const finestraMemo = ref(false);
const esercizioDelMemo = ref<number | null>(null);
/** L'esercizio della data dei sei mesi più vicina della persona (quella della riga), dalle sue rate; null senza la data. */
const esercizioDeiSeiMesi = (r: Riga): number | null => (r.sei_mesi?.data
    ? r.rate.find((x) => x.sei_mesi?.piu_vicina === r.sei_mesi?.data)?.sei_mesi?.piu_vicina_esercizio?.id ?? null : null);
const memoDi = (r: Riga) => {
    esercizioDelMemo.value = esercizioDeiSeiMesi(r);
    finestraMemo.value = true;
};
const memoSalvato = (frase: string) => toast({ title: 'Da portare in assemblea', description: frase });
const esitoRiprova = ref<string | null>(null);
async function riprovaLettere() {
    try {
        const r = (await axios.post(route(generateRoute('gestionale.lettere.riprova'), { condominio: props.condominio.id }))).data;
        esitoRiprova.value = testoRiprova(r);
        ricaricaDopoLeLettere();
    } catch (e) {
        esitoRiprova.value = frasePerErrore(e, 'Le lettere non sono state rifatte. Riprova.');
    }
}

// ── Le lettere per PEC non partite, o con l'avviso di mancata consegna ──
// Si rimandano in blocco dalla casella dello studio, con le cifre di oggi; oppure si stampano per la posta (poi si registra la spedizione).
const esitoPec = ref<string | null>(null);
async function rimandaLePec() {
    esitoPec.value = null;
    try {
        const r = (await axios.post(route(generateRoute('gestionale.lettere.pec-non-partite'), { condominio: props.condominio.id }))).data;
        const conti = testoContiCambiati(r.conti_cambiati ?? []);
        esitoPec.value = `${r.frase}${conti ? ` ${conti}` : ''}`;
        ricaricaDopoLeLettere();
    } catch (e) {
        esitoPec.value = frasePerErrore(e, 'Le PEC non sono partite. Prima di rimandarle guarda la corrispondenza delle persone.');
    }
}
function stampaLePec() {
    esitoPec.value = null;
    // La scheda si apre nel clic, prima della richiesta: niente `await` prima di questa chiamata.
    apriPdfInUnaScheda(() => axios.post(route(generateRoute('gestionale.lettere.pec-non-partite.stampa'), { condominio: props.condominio.id }), {},
        { responseType: 'blob' }), 'lettere.pdf').then(() => {
        esitoPec.value = 'Le lettere rifatte con le cifre di oggi sono nel PDF: stampale, poi registra la spedizione con il filtro «Stampate, spedizione da registrare».';
        ricaricaDopoLeLettere();
    }).catch(async (e) => {
        esitoPec.value = await fraseDaErroreConFile(e, 'Le lettere non sono state stampate. Riprova.');
    });
}

// ── La spedizione in blocco, il giorno dopo la stampa ───────────────────
// Con il filtro «Stampate, spedizione da registrare»: le lettere da spedire delle persone scelte in questa pagina, con lo stesso canale e la
// stessa data e il numero di ognuna (la rotta è quella del registro). Le persone delle altre pagine si vedono con più righe per pagina.
const pannelloSpedizione = ref(false);
const spedizione = ref({ canale: '', data: props.oggi, nota: '', numeri: {} as Record<number, string> });
/** I canali della spedizione per il menu (decisione 392): il valore e il nome. */
const canaliDiSpedizione = Object.entries(CANALI_SPEDIZIONE).map(([valore, nome]) => ({ valore, nome }));
const esitoSpedizione = ref<string | null>(null);
const daSpedire = computed(() => props.righe.data.filter((r) => spuntata(r.anagrafica_id))
    .flatMap((r) => ((r.ultima_lettera as (UltimaLettera & { invii?: number[] }) | null | undefined)?.invii ?? []).map((invio) => ({ invio, nome: r.nome }))));
const altrePagine = computed(() => (selezione.value.tutte && props.persone_totali > props.righe.data.length ? props.persone_totali - props.righe.data.length : 0));
const apriSpedizione = () => {
    spedizione.value = { canale: '', data: props.oggi, nota: '', numeri: {} };
    esitoSpedizione.value = null;
    pannelloSpedizione.value = true;
};
async function registraSpedizione() {
    try {
        const r = (await axios.post(route(generateRoute('gestionale.lettere.spedizione'), { condominio: props.condominio.id }), {
            invii: daSpedire.value.map((d) => d.invio), canale: spedizione.value.canale, data: spedizione.value.data, nota: spedizione.value.nota || null,
            numeri: spedizione.value.numeri,
        })).data;
        esitoSpedizione.value = r.spedite === 1 ? 'Spedizione registrata per 1 lettera.' : `Spedizione registrata per ${r.spedite} lettere.`;
        pannelloSpedizione.value = false;
        selezione.value = selezioneVuota();
        ricaricaDopoLeLettere();
    } catch (e) {
        esitoSpedizione.value = frasePerErrore(e, 'La spedizione non è stata registrata. Riprova.');
    }
}

// ── Le righe aperte ─────────────────────────────────────────────────────
const aperte = ref<Set<number>>(new Set());
const apriChiudi = (id: number) => {
    const s = new Set(aperte.value);
    s.has(id) ? s.delete(id) : s.add(id);
    aperte.value = s;
};

const estrattoConto = (id: number) => route(generateRoute('gestionale.anagrafiche.estratto-conto'), { condominio: props.condominio.id, anagrafica: id });
const indirizzoEsercizi = computed(() => route(generateRoute('gestionale.esercizi.index'), { condominio: props.condominio.id }));
// «Dichiarala dai saldi»: la pagina dei saldi apre «Dichiara l'origine» con i saldi di quella persona già scelti.
const indirizzoSaldi = (persona: number) => route(generateRoute('gestionale.saldi.index'), { condominio: props.condominio.id, origine_di: persona });
const indirizzoDispense = computed(() => route(generateRoute('gestionale.dispense.index'), { condominio: props.condominio.id }));
const indirizzoPiani = computed(() => props.esercizio
    ? route(generateRoute('gestionale.esercizi.piani-rate.index'), { condominio: props.condominio.id, esercizio: props.esercizio.id })
    : indirizzoEsercizi.value);
const frasNonEmesse = computed(() => testoNonEmesse(props.non_emesse));

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti', href: route(generateRoute('gestionale.movimenti-rate.index'), { condominio: props.condominio.id }) },
    { title: titolo.value },
]);
// I menu leggono il valore con una funzione con il nome: lo schema scritto dentro l'attributo il controllo dei tipi non lo legge.
const perId = (o: { id: number }) => o.id;
const perValore = (o: { valore: string }) => o.valore;
</script>

<template>
    <Head :title="titolo" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-4">
            <PageHeaderGuide
                :page-title="titolo"
                page-subtitle="Chi deve ancora pagare, persona per persona: lo scaduto, le fasce, il ritardo e le date dei sei mesi."
                :guides="GUIDE_RATE_SCADUTE"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                has-text-guide
                text-guide-title="Guida"
                @open-text-guide="mostraGuida = true"
            >
                <!-- Le dispense dei sei mesi sono un'altra pagina: un collegamento accanto alla guida, con l'aspetto dei pulsanti dell'intestazione. -->
                <template #actions>
                    <Link v-if="!inScadenza" :href="indirizzoDispense" data-testid="dispense-sei-mesi"
                        class="inline-flex h-8 items-center justify-center gap-2 px-3 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"><Scale class="w-3.5 h-3.5" />Dispense dei sei mesi</Link>
                    <!-- Coda 736: la dispensa in assemblea, con i sei mesi dell'esercizio della data più vicina (o del filtro): lo stesso punto del programma. -->
                    <PulsanteDaPortareInAssemblea v-if="conLeAssemblee && dispensa_in_assemblea" :condominio-id="condominio.id" origine-tipo="sei_mesi"
                        :origine-id="dispensa_in_assemblea.esercizio_id" forma="riga" />
                </template>
            </PageHeaderGuide>
            <MovimentiLayout>
                <div class="space-y-3">
                    <p data-testid="riga-tesoreria" class="text-xs text-slate-500 dark:text-slate-400">{{ RIGA_TESORERIA }}</p>

                    <div v-if="flashMessage">
                        <Alert :message="flashMessage.message" :type="flashMessage.type" />
                    </div>

                    <!-- La vista -->
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <Button size="sm" :variant="inScadenza ? 'outline' : 'default'" data-testid="vista-scadute" @click="cambiaVista('scadute')">Rate scadute</Button>
                        <Button size="sm" :variant="inScadenza ? 'default' : 'outline'" data-testid="vista-in-scadenza" @click="cambiaVista('in_scadenza')">
                            In scadenza nei prossimi {{ filtri.giorni }} giorni
                        </Button>
                        <label v-if="inScadenza" class="flex items-center gap-1 text-xs">
                            giorni <Input v-model="f.giorni" type="number" min="1" max="366" class="h-8 w-20 text-xs" />
                        </label>
                    </div>

                    <!-- I filtri: in alto le scelte, sotto le caselle; accanto a ogni nome l'icona che dice a che cosa serve -->
                    <TooltipProvider :delay-duration="200">
                        <div class="filtri-rate-scadute rounded-xl border bg-white dark:bg-slate-900 p-3 text-xs space-y-3" data-testid="filtri">
                            <div class="flex flex-wrap items-end gap-3">
                                <label class="flex flex-col gap-1 w-full sm:w-auto">
                                    <span class="flex items-center gap-1 text-slate-500 dark:text-slate-400">Elenco al <SpiegazioneDelFiltro :testo="SPIEGAZIONI_DEI_FILTRI.alla" /></span>
                                    <Input v-model="f.alla" type="date" :max="oggi" class="h-8 w-full sm:w-[150px] text-xs" data-testid="filtro-alla" />
                                </label>
                                <div class="flex flex-col gap-1 w-full sm:w-auto" data-testid="filtro-persona">
                                    <span class="flex items-center gap-1 text-slate-500 dark:text-slate-400">Persona <SpiegazioneDelFiltro :testo="SPIEGAZIONI_DEI_FILTRI.persona" /></span>
                                    <v-select
                                        v-model="f.anagrafica_id"
                                        :options="opzioniPersone"
                                        label="nome"
                                        :reduce="perId"
                                        :filterable="false"
                                        placeholder="Tutte le persone"
                                        class="w-full sm:w-[240px]"
                                        @search="(t: string) => (testoPersona = t)"
                                    >
                                        <template #no-options>Nessuna persona con questo nome.</template>
                                        <template #list-footer>
                                            <li v-if="personeTrovate.altre > 0" class="px-3 py-1.5 text-xs text-slate-500 border-t" data-testid="persone-altre">
                                                Ne mostro {{ PERSONE_NEL_MENU }}: scrivi una parte del nome per trovare le altre {{ personeTrovate.altre }}.
                                            </li>
                                        </template>
                                    </v-select>
                                </div>
                                <div v-if="!inScadenza" class="flex flex-col gap-1 w-full sm:w-auto" data-testid="filtro-fascia">
                                    <span class="flex items-center gap-1 text-slate-500 dark:text-slate-400">Fascia <SpiegazioneDelFiltro :testo="SPIEGAZIONI_DEI_FILTRI.fascia" /></span>
                                    <v-select
                                        :model-value="f.fascia || null"
                                        :options="FASCE"
                                        label="etichetta"
                                        :reduce="perValore"
                                        :searchable="false"
                                        placeholder="Tutte"
                                        class="w-full sm:w-[220px]"
                                        @update:model-value="(v: string | null) => (f.fascia = v ?? '')"
                                    />
                                </div>
                                <label class="flex flex-col gap-1 w-full sm:w-auto">
                                    <span class="flex items-center gap-1 text-slate-500 dark:text-slate-400">Importo minimo (€) <SpiegazioneDelFiltro :testo="SPIEGAZIONI_DEI_FILTRI.minimo" /></span>
                                    <Input v-model="f.minimo" inputmode="decimal" placeholder="0,00" class="h-8 w-0 min-w-full text-xs" />
                                </label>
                                <div class="flex flex-col gap-1 w-full sm:w-auto" data-testid="filtro-gestione">
                                    <span class="flex items-center gap-1 text-slate-500 dark:text-slate-400">Gestione <SpiegazioneDelFiltro :testo="SPIEGAZIONI_DEI_FILTRI.gestione" /></span>
                                    <v-select
                                        :model-value="f.gestione_id || null"
                                        :options="opzioniGestioni"
                                        label="etichetta"
                                        :reduce="perValore"
                                        placeholder="Tutte"
                                        class="w-full sm:w-[220px]"
                                        @update:model-value="(v: string | null) => (f.gestione_id = v ?? '')"
                                    >
                                        <template #no-options>Nessuna gestione con questo nome.</template>
                                    </v-select>
                                </div>
                                <label v-if="!inScadenza" class="flex flex-col gap-1 w-full sm:w-auto">
                                    <span class="flex items-center gap-1 text-slate-500 dark:text-slate-400">Data dei sei mesi entro (giorni) <SpiegazioneDelFiltro :testo="SPIEGAZIONI_DEI_FILTRI.sei_mesi_entro" /></span>
                                    <Input v-model="f.sei_mesi_entro" type="number" min="0" class="h-8 w-0 min-w-full text-xs" data-testid="filtro-sei-mesi" />
                                </label>
                                <label class="flex flex-col gap-1 w-full sm:w-auto">
                                    <span class="flex items-center gap-1 text-slate-500 dark:text-slate-400">Nessuna lettera negli ultimi (giorni) <SpiegazioneDelFiltro :testo="SPIEGAZIONI_DEI_FILTRI.senza_lettere_da" /></span>
                                    <Input v-model="f.senza_lettere_da" type="number" min="1" class="h-8 w-0 min-w-full text-xs" data-testid="filtro-senza-lettere" />
                                </label>
                                <div v-if="!inScadenza" class="flex flex-col gap-1 w-full sm:w-auto" data-testid="filtro-ordine">
                                    <span class="flex items-center gap-1 text-slate-500 dark:text-slate-400">Ordine <SpiegazioneDelFiltro :testo="SPIEGAZIONI_DEI_FILTRI.ordine" /></span>
                                    <v-select
                                        :model-value="f.ordine"
                                        :options="ORDINI"
                                        label="etichetta"
                                        :reduce="perValore"
                                        :searchable="false"
                                        :clearable="false"
                                        class="w-full sm:w-[260px]"
                                        @update:model-value="(v: string) => (f.ordine = v)"
                                    />
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t pt-3">
                                <label v-if="!inScadenza" class="flex items-center gap-2">
                                    <Checkbox :model-value="f.con_pregresso" @update:model-value="(v: any) => (f.con_pregresso = !!v)" />
                                    <span>Con pregresso <SpiegazioneDelFiltro class="align-middle" :testo="SPIEGAZIONI_DEI_FILTRI.con_pregresso" /></span>
                                </label>
                                <label class="flex items-center gap-2" data-testid="filtro-mai-scritto">
                                    <Checkbox :model-value="f.mai_scritto" @update:model-value="(v: any) => (f.mai_scritto = !!v)" />
                                    <span>Mai scritto (contano le lettere partite o spedite) <SpiegazioneDelFiltro class="align-middle" :testo="SPIEGAZIONI_DEI_FILTRI.mai_scritto" /></span>
                                </label>
                                <label class="flex items-center gap-2" data-testid="filtro-da-spedire">
                                    <Checkbox :model-value="f.da_spedire" @update:model-value="(v: any) => (f.da_spedire = !!v)" />
                                    <span>Stampate o scaricate, spedizione da registrare <SpiegazioneDelFiltro class="align-middle" :testo="SPIEGAZIONI_DEI_FILTRI.da_spedire" /></span>
                                </label>
                                <label class="flex items-center gap-2" data-testid="filtro-con-accordo">
                                    <Checkbox :model-value="f.con_accordo" @update:model-value="(v: any) => (f.con_accordo = !!v)" />
                                    <span>Con accordo di rientro <SpiegazioneDelFiltro class="align-middle" :testo="SPIEGAZIONI_DEI_FILTRI.con_accordo" /></span>
                                </label>
                            </div>
                        </div>
                    </TooltipProvider>

                    <p v-if="(lettere_da_riprovare ?? 0) > 0" data-testid="lettere-da-riprovare" class="flex flex-wrap items-center gap-2 rounded-lg border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/40 p-2 text-xs text-amber-900 dark:text-amber-100">
                        {{ lettere_da_riprovare === 1 ? '1 lettera per email non è partita.' : `${lettere_da_riprovare} lettere per email non sono partite.` }}
                        <Button size="sm" variant="outline" class="h-7 text-xs" @click="riprovaLettere">{{ lettere_da_riprovare === 1 ? 'Rifalla con le cifre di oggi' : 'Rifalle con le cifre di oggi' }}</Button>
                    </p>
                    <p v-if="(lettere_in_coda_da_tempo ?? 0) > 0" data-testid="lettere-in-coda" class="text-xs text-amber-900 dark:text-amber-100">
                        {{ lettere_in_coda_da_tempo === 1 ? '1 lettera per email è in coda da più di un\'ora: non è ancora partita.' : `${lettere_in_coda_da_tempo} lettere per email sono in coda da più di un'ora: non sono ancora partite.` }}
                        Di solito vuol dire che sul server non gira il servizio che manda le email in coda: chi gestisce l'installazione controlla che sia attivo
                        (SCHEDULE_QUEUE_WORKER nel file .env, o un processo queue:work).
                    </p>
                    <p v-if="testoPecDaRimandare(pec_da_rimandare ?? 0)" data-testid="pec-da-rimandare" class="flex flex-wrap items-center gap-2 rounded-lg border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/40 p-2 text-xs text-amber-900 dark:text-amber-100">
                        {{ testoPecDaRimandare(pec_da_rimandare ?? 0) }}
                        <Button size="sm" variant="outline" class="h-7 text-xs" data-testid="rimanda-le-pec" @click="rimandaLePec">Rimanda le PEC non partite</Button>
                        <Button size="sm" variant="outline" class="h-7 text-xs" data-testid="stampa-le-pec" @click="stampaLePec">Stampale per la posta</Button>
                    </p>
                    <p v-if="esitoPec" class="text-xs" data-testid="esito-pec">{{ esitoPec }}</p>
                    <p v-if="esitoRiprova" class="text-xs">{{ esitoRiprova }}</p>
                    <p v-if="esitoSpedizione" class="text-xs" data-testid="esito-spedizione">{{ esitoSpedizione }}</p>
                    <p v-if="!allaDiOggi" data-testid="frase-data-passata" class="text-xs text-slate-600 dark:text-slate-300">{{ FRASE_DATA_PASSATA }}</p>
                    <p v-if="frasNonEmesse" data-testid="riga-non-emesse" class="flex flex-wrap items-start gap-2 rounded-lg border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/40 p-2 text-xs text-amber-900 dark:text-amber-100">
                        <AlertTriangle class="w-4 h-4 shrink-0" />
                        <span>{{ frasNonEmesse }} <Link :href="indirizzoPiani" class="underline">Vai ai piani rate</Link></span>
                    </p>

                    <!-- I suggerimenti che chiedono le regole del recupero: dicono quante persone e quali; scegliere o proporre resta tuo. -->
                    <div v-if="(suggerimenti ?? []).length" class="space-y-1 rounded-lg border border-sky-200 dark:border-sky-900 bg-sky-50 dark:bg-sky-950/40 p-2 text-xs text-sky-900 dark:text-sky-100" data-testid="suggerimenti">
                        <p v-for="sg in suggerimenti" :key="sg.chiave" class="flex flex-wrap items-center gap-2" :data-testid="`suggerimento-${sg.chiave}`">
                            <Lightbulb class="w-4 h-4 shrink-0" /> <span>{{ sg.frase }}</span>
                            <Button size="sm" variant="outline" class="h-7 text-xs" @click="seguiSuggerimento(sg)">{{ sg.porta === 'proponi_interessi' ? 'Proponi gli interessi' : 'Scegli queste persone' }}</Button>
                        </p>
                        <p class="text-xs">Dalle <Link :href="indirizzoRegole" class="underline">regole del recupero</Link> del condominio<template v-if="regole_del_recupero"> (tasso di oggi: {{ regole_del_recupero }})</template>.</p>
                    </div>

                    <p v-if="cercaDallIndirizzo" class="flex flex-wrap items-center gap-2 text-xs" data-testid="filtro-cerca">
                        Solo i nomi con «{{ cercaDallIndirizzo }}».
                        <Button size="sm" variant="ghost" class="h-7 text-xs" data-testid="togli-cerca" @click="togliCerca"><X class="w-3.5 h-3.5 mr-1" /> Tutti i nomi</Button>
                    </p>

                    <p v-if="sei_mesi_del && seiMesiDel" class="flex flex-wrap items-center gap-2 text-xs" data-testid="filtro-sei-mesi-del">
                        {{ testoSeiMesiDel(sei_mesi_del) }}
                        <Button size="sm" variant="ghost" class="h-7 text-xs" data-testid="togli-sei-mesi-del" @click="togliSeiMesiDel"><X class="w-3.5 h-3.5 mr-1" /> Tutte le date</Button>
                    </p>

                    <!-- Il totale, la scelta e la stampa -->
                    <div class="space-y-2 text-sm">
                        <div class="flex flex-wrap items-center gap-2">
                            <span data-testid="totale">{{ testoTotale({ persone: persone_totali, totale, vista: filtri.vista }) }}</span>
                            <span v-if="quante > 0" class="font-medium" data-testid="selezionate">Selezionate: {{ quante }}.</span>
                        </div>
                        <!-- Prima la scelta delle righe, poi le azioni, divise da una linea: senza righe scelte le azioni valgono già per tutte. -->
                        <div class="flex flex-wrap items-center gap-2">
                            <Button v-if="!selezione.tutte && persone_totali > 1" size="sm" variant="outline" data-testid="seleziona-tutte" @click="selezione = tutteDeiFiltri(selezione)">
                                <ListChecks class="w-4 h-4 mr-2" /> Seleziona tutte le {{ persone_totali }} dei filtri
                            </Button>
                            <Button v-if="quante > 0" size="sm" variant="outline" data-testid="togli-scelta" @click="selezione = selezioneVuota()"><X class="w-4 h-4 mr-2" /> Togli la scelta</Button>
                            <span v-if="(!selezione.tutte && persone_totali > 1) || quante > 0" class="hidden sm:block h-6 w-px bg-slate-200 dark:bg-slate-700 mx-1" aria-hidden="true" />
                            <Button size="sm" :disabled="persone_totali === 0" data-testid="prepara-lettere" @click="preparaLettere">
                                <Mail class="w-4 h-4 mr-2" /> {{ quante > 0 && !selezione.tutte ? (quante === 1 ? 'Prepara la lettera alla selezionata' : 'Prepara le lettere alle selezionate')
                                    : (persone_totali === 1 ? 'Prepara la lettera' : `Prepara le lettere a tutte le ${persone_totali}`) }}
                            </Button>
                            <Button size="sm" variant="outline" :disabled="persone_totali === 0" data-testid="stampa" @click="stampa">
                                <Printer class="w-4 h-4 mr-2" /> {{ quante > 0 && !selezione.tutte ? 'Stampa le selezionate' : 'Stampa l\'elenco' }}
                            </Button>
                            <Button v-if="filtri.da_spedire" size="sm" variant="outline" :disabled="daSpedire.length === 0" data-testid="spedizione-in-blocco" @click="apriSpedizione">
                                <Send class="w-4 h-4 mr-2" /> Registra la spedizione
                            </Button>
                            <!-- Gli interessi si propongono dal suggerimento, che dice per chi: un pulsante solo. -->
                            <Button v-if="!inScadenza" size="sm" variant="outline" :disabled="persone_totali === 0" data-testid="spese-del-ritardo" @click="addebitaSpesa">
                                <Coins class="w-4 h-4 mr-2" /> {{ quante > 0 && !selezione.tutte ? 'Spese del ritardo alle selezionate' : 'Spese del ritardo' }}
                            </Button>
                        </div>
                    </div>

                    <div v-if="pannelloSpedizione" class="space-y-2 rounded-lg border p-2 text-xs" data-testid="pannello-spedizione">
                        <p class="font-medium">Registra la spedizione {{ daSpedire.length === 1 ? 'della lettera' : `delle ${daSpedire.length} lettere` }}: lo stesso canale e la stessa data, il numero di ognuna</p>
                        <p v-if="altrePagine > 0">Sono {{ persone_totali }} persone: in questa pagina ne vedi {{ righe.data.length }}. Per registrarle tutte insieme scegli più righe per pagina; o registra queste, e poi le altre.</p>
                        <div class="flex flex-wrap gap-2">
                            <!-- Decisione 392: i menu con vSelect, mai il menu del browser. -->
                            <v-select
                                :model-value="spedizione.canale || null"
                                :options="canaliDiSpedizione"
                                label="nome"
                                :reduce="perValore"
                                :clearable="false"
                                :searchable="false"
                                placeholder="Come"
                                class="w-full sm:w-[220px] text-xs"
                                data-testid="canale-spedizione"
                                @update:model-value="(v: string | null) => (spedizione.canale = v ?? '')"
                            />
                            <Input v-model="spedizione.data" type="date" :max="oggi" class="h-8 w-[150px] text-xs" />
                            <Input v-model="spedizione.nota" placeholder="Nota" class="h-8 w-[200px] text-xs" />
                        </div>
                        <div v-for="d in daSpedire" :key="d.invio" class="flex items-center gap-2">
                            <span class="w-40 truncate">{{ d.nome }}</span>
                            <Input v-model="spedizione.numeri[d.invio]" placeholder="Numero o estremi" class="h-7 w-[200px] text-xs" :data-testid="`numero-${d.invio}`" />
                        </div>
                        <div class="flex gap-2">
                            <Button size="sm" variant="outline" :disabled="!spedizione.canale || daSpedire.length === 0" data-testid="registra-spedizione-blocco" @click="registraSpedizione">Registra la spedizione</Button>
                            <Button size="sm" variant="ghost" @click="pannelloSpedizione = false">Annulla</Button>
                        </div>
                    </div>

                    <!-- La tabella -->
                    <div class="overflow-x-auto rounded-xl border bg-white dark:bg-slate-900">
                        <table class="w-full text-xs">
                            <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-500 dark:text-slate-400">
                                <tr>
                                    <th class="p-2 w-8"><Checkbox :model-value="paginaSpuntata" :disabled="selezione.tutte" @update:model-value="selezione = selezionaPagina(selezione, idsPagina)" /></th>
                                    <th class="p-2 w-6"></th>
                                    <th class="p-2 text-left">Persona</th>
                                    <th class="p-2 text-left">Unità</th>
                                    <th class="p-2 text-right">{{ inScadenza ? 'In scadenza' : 'Scaduto' }}</th>
                                    <template v-if="!inScadenza">
                                        <th class="p-2 text-right">1–30 giorni</th>
                                        <th class="p-2 text-right">31–90 giorni</th>
                                        <th class="p-2 text-right">oltre 90 giorni</th>
                                        <th class="p-2 text-left">Ritardo</th>
                                        <th class="p-2 text-left">Data dei sei mesi più vicina</th>
                                    </template>
                                    <th v-else class="p-2 text-left">Prima scadenza</th>
                                    <th v-if="allaDiOggi" class="p-2 text-right">Credito</th>
                                    <th class="p-2 text-left">Ultima lettera</th>
                                    <th class="p-2 w-10"><span class="sr-only">Azioni</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="r in righe.data" :key="r.anagrafica_id">
                                    <tr class="border-t" data-testid="riga-persona">
                                        <td class="p-2"><Checkbox :model-value="spuntata(r.anagrafica_id)" @update:model-value="selezione = alterna(selezione, r.anagrafica_id)" /></td>
                                        <td class="p-2">
                                            <button type="button" :aria-label="aperte.has(r.anagrafica_id) ? 'Chiudi le rate' : 'Apri le rate'" @click="apriChiudi(r.anagrafica_id)">
                                                <ChevronDown v-if="aperte.has(r.anagrafica_id)" class="w-4 h-4" /><ChevronRight v-else class="w-4 h-4" />
                                            </button>
                                        </td>
                                        <td class="p-2">
                                            <Link :href="estrattoConto(r.anagrafica_id)" class="font-medium hover:underline">{{ r.nome }}</Link>
                                            <span v-if="r.origine_non_dichiarata" class="block text-xs text-amber-700 dark:text-amber-400">
                                                pregresso con origine non dichiarata — <Link :href="indirizzoSaldi(r.anagrafica_id)" class="underline">dichiarala dai saldi</Link>
                                            </span>
                                            <!-- La prova a video della beta.12: il debito ereditato si vede anche con la riga chiusa, non solo aprendo le rate. -->
                                            <span v-for="e in ereditatiDellaPersona(r.rate)" :key="`ered-${e.subentro_id}`" class="mt-0.5 flex flex-wrap items-center gap-1 text-xs text-slate-600 dark:text-slate-300" data-testid="ereditato-in-testa">
                                                <Badge variant="outline" class="h-5 border-violet-200 bg-violet-50 px-1.5 text-xs font-medium text-violet-800 dark:border-violet-800 dark:bg-violet-950 dark:text-violet-200">ereditato</Badge>
                                                {{ e.testo }}
                                            </span>
                                            <span v-for="a in r.accordi ?? []" :key="a.id" class="block text-xs text-sky-800 dark:text-sky-300" data-testid="segno-accordo">
                                                {{ testoSegnoAccordo(a) }}
                                                <span v-if="testoSegnaleBreve(a)" class="block text-amber-800 dark:text-amber-300" data-testid="segnale-accordo">{{ testoSegnaleBreve(a) }}</span>
                                            </span>
                                        </td>
                                        <td class="p-2">{{ r.unita.join(', ') }}</td>
                                        <td class="p-2 text-right font-semibold whitespace-nowrap">
                                            {{ euroTesto(r.importo) }}
                                            <span v-if="r.di_cui_sei_mesi && sei_mesi_del" class="block text-xs font-normal text-slate-500 dark:text-slate-400" data-testid="di-cui-sei-mesi">{{ testoDiCui(sei_mesi_del.data, r.di_cui_sei_mesi) }}</span>
                                        </td>
                                        <template v-if="!inScadenza && r.fasce">
                                            <td class="p-2 text-right whitespace-nowrap">{{ r.fasce['1_30'] ? euroTesto(r.fasce['1_30']) : '' }}</td>
                                            <td class="p-2 text-right whitespace-nowrap">{{ r.fasce['31_90'] ? euroTesto(r.fasce['31_90']) : '' }}</td>
                                            <td class="p-2 text-right whitespace-nowrap">{{ r.fasce.oltre_90 ? euroTesto(r.fasce.oltre_90) : '' }}</td>
                                            <td class="p-2">{{ r.ritardo_testo }}</td>
                                            <td class="p-2">{{ r.sei_mesi ? testoSeiMesiDellaPersona(r.sei_mesi) : '' }}</td>
                                        </template>
                                        <td v-else class="p-2">{{ giorno(r.prima_scadenza) }}</td>
                                        <td v-if="allaDiOggi" class="p-2 text-right whitespace-nowrap text-sky-700 dark:text-sky-400">{{ (r.credito ?? 0) > 0 ? euroTesto(r.credito ?? 0) : '' }}</td>
                                        <td class="p-2" data-testid="ultima-lettera">
                                            {{ testoUltimaLettera(r.ultima_lettera) }}
                                            <span v-if="r.lettera_in_sospeso" class="block text-xs text-amber-700 dark:text-amber-400">poi: {{ testoUltimaLettera(r.lettera_in_sospeso) }}</span>
                                        </td>
                                        <td class="p-2 w-10 text-right" data-testid="azioni-riga">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger as-child>
                                                    <Button variant="ghost" class="w-8 h-8 p-0" :aria-label="`Apri il menu delle azioni di ${r.nome}`" :data-testid="`menu-${r.anagrafica_id}`">
                                                        <MoreHorizontal class="w-4 h-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuLabel>Azioni</DropdownMenuLabel>
                                                    <DropdownMenuItem :data-testid="`scrivi-${r.anagrafica_id}`" @click="scriviA(r)">
                                                        <Mail class="w-4 h-4" /> Prepara una lettera
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem v-if="!inScadenza" :data-testid="`accordo-${r.anagrafica_id}`" @click="apriAccordo(r)">
                                                        <Handshake class="w-4 h-4" /> {{ (r.accordi ?? []).length ? 'Apri l\'accordo di rientro' : 'Registra un accordo di rientro' }}
                                                    </DropdownMenuItem>
                                                    <!-- Lo storico della persona, nella scheda «Corrispondenza» del suo estratto conto. -->
                                                    <DropdownMenuItem v-if="r.ultima_lettera || r.lettera_in_sospeso" as-child>
                                                        <Link :href="`${estrattoConto(r.anagrafica_id)}?scheda=corrispondenza`" :data-testid="`corrispondenza-${r.anagrafica_id}`">
                                                            <History class="w-4 h-4" /> Vedi la corrispondenza
                                                        </Link>
                                                    </DropdownMenuItem>
                                                    <!-- Coda 736: la dispensa con i sei mesi della persona, senza il nome nel punto. -->
                                                    <DropdownMenuItem v-if="conLeAssemblee && esercizioDeiSeiMesi(r) !== null" :data-testid="`memo-${r.anagrafica_id}`" @click="memoDi(r)">
                                                        <CalendarPlus class="w-4 h-4" /> Da portare in assemblea
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </td>
                                    </tr>
                                    <tr v-if="aperte.has(r.anagrafica_id)" class="bg-slate-50/60 dark:bg-slate-800/30">
                                        <td></td>
                                        <td :colspan="allaDiOggi ? 13 : 12" class="p-2">
                                            <table class="w-full text-xs">
                                                <thead class="text-slate-500">
                                                    <tr>
                                                        <th class="p-1 text-left">Unità</th><th class="p-1 text-left">Piano</th><th class="p-1 text-left">Rata</th>
                                                        <th class="p-1 text-left">Scadenza</th><th class="p-1 text-right">Residuo</th><th class="p-1 text-left">Ritardo</th>
                                                        <template v-if="!inScadenza"><th class="p-1 text-left">Dalla scadenza</th><th class="p-1 text-left">Dalla competenza</th></template>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr v-for="rata in r.rate" :key="`${rata.rata_id}-${rata.unita}-${rata.ereditato?.subentro_id ?? ''}`" class="border-t align-top"
                                                        :class="{ 'bg-amber-50/70 dark:bg-amber-900/20': rata.del_sei_mesi }" :data-sei-mesi="rata.del_sei_mesi ? 'si' : undefined">
                                                        <td class="p-1">{{ rata.unita }}</td>
                                                        <td class="p-1">{{ rata.piano }}</td>
                                                        <td class="p-1">
                                                            <Badge v-if="rata.ereditato" variant="outline" class="mr-1 h-5 border-violet-200 bg-violet-50 px-1.5 text-xs font-medium text-violet-800 dark:border-violet-800 dark:bg-violet-950 dark:text-violet-200" data-testid="badge-ereditato">ereditato</Badge>
                                                            {{ rata.numero_rata === 0 ? 'Rata 0 (pregresso)' : `Rata ${rata.numero_rata}` }}
                                                            <span v-if="rata.pregresso_nella_rata" class="block text-slate-500">{{ NOTA_PREGRESSO_NELLA_RATA }}</span>
                                                            <!-- Decisione 494 (3): da chi e con quale successione; dal debito ereditato si apre la proposta degli interessi dell'erede. -->
                                                            <span v-if="rata.ereditato" class="block text-slate-600 dark:text-slate-300" data-testid="ereditato-da">{{ testoEreditatoInElenco(rata.ereditato) }}
                                                                <button type="button" class="ml-1 underline" data-testid="interessi-dell-erede" @click="proponiInteressi([r.anagrafica_id])">Proponi gli interessi</button></span>
                                                        </td>
                                                        <td class="p-1">{{ giorno(rata.scadenza) }}</td>
                                                        <td class="p-1 text-right whitespace-nowrap">
                                                            {{ euroTesto(rata.residuo) }}
                                                            <span v-if="rata.accordo" class="block text-left whitespace-normal text-sky-800 dark:text-sky-300" data-testid="parte-in-accordo">{{ testoParteInAccordo(rata.accordo) }}</span>
                                                        </td>
                                                        <td class="p-1">{{ inScadenza ? '' : testoGiorniDellaRata(rata) }}</td>
                                                        <template v-if="!inScadenza && rata.sei_mesi">
                                                            <td class="p-1" :class="{ 'font-semibold': rata.sei_mesi.piu_vicina && rata.sei_mesi.piu_vicina === rata.sei_mesi.dalla_scadenza.data }">
                                                                {{ testoData(rata.sei_mesi.dalla_scadenza, rata.scadenza) }}
                                                            </td>
                                                            <td class="p-1" :class="{ 'font-semibold': !rata.sei_mesi.coincidono && rata.sei_mesi.piu_vicina && rata.sei_mesi.piu_vicina === rata.sei_mesi.dalla_competenza.data && !rata.sei_mesi.dalla_competenza.prima_della_scadenza }">
                                                                {{ rata.sei_mesi.coincidono ? 'la stessa' : testoData(rata.sei_mesi.dalla_competenza, rata.scadenza, rata.sei_mesi.dalla_scadenza) }}
                                                            </td>
                                                        </template>
                                                    </tr>
                                                </tbody>
                                            </table>
                                            <p v-if="r.sei_mesi?.dispensa" class="mt-1 text-xs">{{ testoDispensa(r.sei_mesi.dispensa) }}</p>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="!righe.data.length">
                                    <td colspan="14" class="p-6 text-center text-slate-500">{{ inScadenza ? 'Nessuna rata in scadenza con questi filtri.' : 'Nessuna persona con rate scadute con questi filtri.' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="righe.data.length" class="flex flex-wrap items-center justify-end gap-2 text-sm">
                        <span class="text-xs text-slate-500 dark:text-slate-400">Righe per pagina</span>
                        <Select :model-value="righe.meta.per_page" @update:model-value="cambiaRighePerPagina">
                            <SelectTrigger class="h-8 w-[70px]">
                                <SelectValue :placeholder="`${righe.meta.per_page}`" />
                            </SelectTrigger>
                            <SelectContent side="top">
                                <SelectItem v-for="n in righePerPagina" :key="n" :value="n">{{ n }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <template v-if="righe.meta.last_page > 1">
                            <Button size="sm" variant="outline" :disabled="righe.meta.current_page <= 1" @click="applica(righe.meta.current_page - 1)">Precedente</Button>
                            <span>Pagina {{ righe.meta.current_page }} di {{ righe.meta.last_page }}</span>
                            <Button size="sm" variant="outline" :disabled="righe.meta.current_page >= righe.meta.last_page" @click="applica(righe.meta.current_page + 1)">Successiva</Button>
                        </template>
                    </div>

                    <details v-if="!inScadenza" class="text-xs text-slate-500 dark:text-slate-400">
                        <summary class="cursor-pointer">Le due date dei sei mesi</summary>
                        <p class="mt-1">
                            Per ogni rata scaduta: dalla fine dell'esercizio che contiene la sua scadenza, e dalla fine dell'esercizio di competenza del piano
                            (per una gestione straordinaria, quello della delibera), più sei mesi. La più vicina senza una dispensa è in evidenza; una data che viene prima della
                            scadenza della rata non lo è. {{ frase_sei_mesi }} {{ frase_festivo }}
                        </p>
                        <p class="mt-1 italic">{{ testo_norma }}</p>
                    </details>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ FRASE_RISERVATEZZA }}</p>
                </div>
                <LettereDialog v-model:open="finestraLettere" :condominio-id="condominio.id" :persona="personaDellaLettera" :selezione="selezioneDelleLettere"
                    :filtri="filtriDelServer()" :vista="filtri.vista" :giorni="filtri.giorni" :oggi="oggi" @fatto="ricaricaDopoLeLettere" />
                <RateScaduteGuide v-model:open="mostraGuida" :testo-norma="testo_norma" />
                <AccordoRientroDialog v-model:open="finestraAccordo" :condominio-id="condominio.id" :persona="personaDellAccordo" :oggi="oggi" @fatto="ricaricaDopoLAccordo" />
                <ProponiInteressiDialog v-model:open="finestraInteressi" :condominio-id="condominio.id" :oggi="oggi" :persone="personeDelleAzioni" :filtri="filtriDelServer()"
                    @fatto="ricaricaDopoGliAddebiti" />
                <AddebitaSpesaDialog v-model:open="finestraSpesa" :condominio-id="condominio.id" :persone="personeDelleAzioni" :filtri="filtriDelServer()" @fatto="ricaricaDopoGliAddebiti" />
                <FinestraDelMemo v-if="conLeAssemblee && finestraMemo" v-model:open="finestraMemo" :condominio-id="condominio.id" origine-tipo="sei_mesi"
                    :origine-id="esercizioDelMemo" @salvato="memoSalvato" />
            </MovimentiLayout>
        </div>
    </GestionaleLayout>
</template>

<style>
/* Le scelte dei filtri delle rate scadute: alte come i campi accanto, con il testo piccolo della barra, e leggibili nel tema scuro. Solo qui:
   gli altri menu del programma restano come sono. */
.filtri-rate-scadute .v-select {
    --vs-font-size: 0.75rem;
    --vs-line-height: 1.25;
    --vs-border-radius: 0.375rem;
    --vs-border-color: var(--input);
    background: transparent;
}
.filtri-rate-scadute .v-select {
    --vs-controls-color: rgb(100 116 139);
}
.filtri-rate-scadute .vs__dropdown-toggle {
    min-height: 2rem;
    padding: 0;
    align-items: center;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
}
.filtri-rate-scadute .vs__selected-options {
    align-items: center;
    padding-left: 6px;
}
/* Il testo al centro del campo, come negli altri campi della barra: niente margine in alto né lo spazio che `custom.css` dà alla ricerca. */
.filtri-rate-scadute .vs__selected,
.filtri-rate-scadute .vs__search {
    margin: 0 !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
}
.filtri-rate-scadute .vs__search,
.filtri-rate-scadute .vs__search::placeholder {
    font-size: 0.75rem !important;
}
.filtri-rate-scadute .vs__actions {
    padding: 0 8px 0 4px;
}
/* La freccia più piccola, come quella degli altri menu del programma; aperta si gira come prima. */
.filtri-rate-scadute .vs__open-indicator {
    transform: scale(0.75);
}
.filtri-rate-scadute .vs--open .vs__open-indicator {
    transform: rotate(180deg) scale(0.75);
}
.filtri-rate-scadute .vs__dropdown-menu {
    font-size: 0.75rem;
    max-height: 18rem;
}
.dark .filtri-rate-scadute .v-select {
    --vs-dropdown-bg: rgb(15 23 42);
    --vs-dropdown-color: rgb(226 232 240);
    --vs-dropdown-option-color: rgb(226 232 240);
    --vs-selected-color: rgb(226 232 240);
    --vs-search-input-color: rgb(226 232 240);
    --vs-controls-color: rgb(148 163 184);
    --vs-border-color: var(--input);
}
/* Nel tema scuro lo sfondo dei campi di testo del programma (`dark:bg-input/30`), perché i menu non sembrino un'altra cosa. */
.dark .filtri-rate-scadute .vs__dropdown-toggle {
    background: color-mix(in oklab, var(--input) 30%, transparent);
}
/* `custom.css` evidenzia l'opzione con un grigio chiarissimo e il testo nero: nel tema scuro diventava una barra bianca. */
.dark .filtri-rate-scadute .vs__dropdown-option--highlight {
    background: rgb(30 41 59) !important;
    color: rgb(241 245 249) !important;
}
</style>
