<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La scheda «Corrispondenza» di una persona, nella pagina del suo estratto conto (Coda 533): le lettere e gli altri documenti emessi per lei in quel
 * condominio (ricevute, avvisi di compensazione, «non serve comunicarla»), dalla più recente, con i filtri per tipo e per periodo (non si
 * ricordano: decisione 223) e dieci per pagina; l'ordine, i filtri e le pagine stanno in `@/lib/gestionale/lettere/corrispondenza`. Le ricevute e
 * gli avvisi qui si leggono: ognuno porta alla pagina del suo incasso, dove si ristampa, si rimanda e si comunica un annullamento (l'elenco delle
 * ricevute non ha gli incassi annullati né gli avvisi). «Apri nelle ricevute degli incassi» porta all'elenco delle ricevute di questa persona.
 *
 * Per ogni lettera il tipo, quando, chi l'ha emessa, il totale
 * di allora e il residuo di oggi delle stesse rate, le righe (una per indirizzo, se è andata per email) con il loro stato, il PDF conservato
 * con l'impronta verificata, la spedizione e le prove. Da qui si registra la spedizione e la si corregge (quella di prima resta fra gli eventi),
 * si allega o si toglie una prova, si rimanda per email (la lettera si rifà con le cifre di oggi). «Consegnata» si dice solo con la consegna della PEC
 * provata dalla ricevuta del gestore (o scritta a mano con la prova), e la frase la scrive il server: per l'email e la carta il programma non lo sa.
 *
 * La lettera per PEC dalla casella dello studio (decisioni 569, 577 e 588): lo stato in un badge e in parole; la zona dove si trascina la ricevuta di
 * consegna (al telefono, e per chi non trascina, «Carica la ricevuta»), che va al server con una visita Inertia; se il file non si legge, il giorno e l'ora
 * dell'invio già scritti, da confermare o correggere, e la consegna scritta a mano con il file come prova; la ricevuta fra le prove, che non si toglie; il
 * termine contato dalla consegna; «Manda di nuovo per PEC, con le cifre di oggi» su una PEC non partita, con l'avviso di mancata consegna o ferma in partenza,
 * e «Stampala per la posta» (la lettera rifatta con le cifre di oggi, in un PDF). L'avviso di mancata consegna si trascina come la ricevuta: il server lo
 * registra, e la lettera non conta come arrivata. La riga della PEC non ha «Allega la prova»: la sua prova è la ricevuta, nella sua zona.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { router, usePage } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { apriPdfInUnaScheda, fraseDaErroreConFile, frasePerErrore } from '@/lib/gestionale/incassi/ricevute';
import { giorno } from '@/lib/gestionale/debiti/rateScadute';
import {
    CANALI_SPEDIZIONE, NOMI_TIPI, testoContiCambiati, testoRigaDelRegistro, testoTermineDallaConsegna, testoTotaleEResiduo, type TermineDallaConsegna, type TipoLettera,
} from '@/lib/gestionale/lettere/lettere';
import {
    FRASE_NIENTE, TIPI_DEI_FILTRI, filtraCorrispondenza, fraseDelVuoto, paginaDi, testoCifreDelDocumento, testoDocumento, testoRigaDelDocumento,
    vociDellaCorrispondenza, type DocumentoInviato,
} from '@/lib/gestionale/lettere/corrispondenza';

export interface LetteraDelRegistro {
    lettera: string; tipo: string; emessa_il: string; ora: string; emesso_da: string | null; totale: number; residuo_oggi: number; alla: string | null;
    termine: number | null; stato: string;
    /** Con la consegna della PEC provata, il termine contato da lì. */
    termine_dalla_consegna?: TermineDallaConsegna | null;
    righe: Array<RigaDelRegistro>;
    pdf: { invio_id: number; nome: string; impronta: string | null; verificato: boolean } | null;
    prove: Array<{ id: number; invio_id: number; nome: string; aggiunta_il: string | null; rimovibile: boolean; della_consegna?: boolean; consegna_a_mano?: boolean;
        mancata_consegna?: boolean }>;
    eventi: Array<{ evento: string; il: string; giorno?: string; da_nome?: string | null; nome_file?: string; parole?: string }>;
}

/** Lo stato di una riga per PEC, dal server: il badge e la frase, se prende la ricevuta, se si manda di nuovo, il giorno e l'ora dell'invio. */
export interface StatoDellaPec {
    badge: string; frase: string; provata: boolean; ricevibile: boolean; ripetibile: boolean; invio: { giorno: string; ora: string };
    /** L'avviso di mancata consegna del gestore è sulla riga. */
    mancata_consegna?: boolean;
    /** Si stampa per la posta, rifatta con le cifre di oggi: una PEC non partita o non consegnata, senza la copia su carta. */
    stampabile?: boolean;
}

export interface RigaDelRegistro {
    id: number; canale: string; formato: string | null; destinatario: string | null; stato: string; quando: string; errore: string | null;
    spedizione: Record<string, string> | null; spedibile: boolean; rimandabile: boolean; pec?: StatoDellaPec | null;
}

const props = withDefaults(defineProps<{
    condominioId: number; lettere: LetteraDelRegistro[]; oggi: string;
    /** Coda 533: le ricevute, gli avvisi di compensazione e «non serve comunicarla» della persona, dal server. */
    documenti?: DocumentoInviato[];
    /** La persona: porta alla pagina delle ricevute filtrata su di lei. */
    personaId?: number | null;
}>(), { documenti: () => [], personaId: null });

const { generateRoute, hasPermission } = usePermission();
// La ricevuta e la prova sono file nel gestionale: chiedono «Crea documenti archivio», come «Allega» della fattura. Senza, la zona e il pulsante non ci sono.
const puoCaricare = hasPermission([Permission.CREATE_ARCHIVE_DOCUMENTS]);
const rotta = (nome: string, altro: Record<string, unknown> = {}) => route(generateRoute(`gestionale.lettere.${nome}`), { condominio: props.condominioId, ...altro });
const messaggio = ref<string | null>(null);
const errore = ref<string | null>(null);
const spedizioneAperta = ref<number | null>(null);
const spedizione = ref({ canale: '', data: '', numero: '', nota: '' });
/** La riga di cui si corregge la spedizione registrata: il modulo parte dai dati di oggi. */
const correzioneAperta = ref<number | null>(null);

const ricarica = () => router.reload({ only: ['lettere', 'ultima_lettera'] });

/**
 * La ricevuta di una riga per PEC: il file scelto, gli errori del server e, quando il file non si legge, il giorno e l'ora da confermare (quelli dell'invio,
 * già scritti). Il file resta qui finché la consegna non è registrata: con il giorno e l'ora va di nuovo al server, come prova.
 */
interface RicevutaInCorso { file: File; errori: Record<string, string>; aMano: boolean; giorno: string; ora: string }
const ricevute = ref<Record<number, RicevutaInCorso>>({});
/** Il perché di una ricevuta nuova su una consegna già registrata (una correzione). */
const perche = ref<Record<number, string>>({});
const sopra = ref<number | null>(null);

function mandaLaRicevuta(r: RigaDelRegistro, file: File, piu: Record<string, string> = {}) {
    const motivo = (perche.value[r.id] ?? '').trim();
    router.post(rotta('ricevuta', { invio: r.id }), { ricevuta: file, ...(motivo ? { perche: motivo } : {}), ...piu } as never, {
        forceFormData: true,
        preserveScroll: true,
        only: ['lettere', 'ultima_lettera', 'flash', 'errors'],
        onSuccess: () => {
            const { [r.id]: _fatta, ...altre } = ricevute.value;
            ricevute.value = altre;
        },
        onError: (e: Record<string, string>) => {
            const prima = ricevute.value[r.id];
            ricevute.value = { ...ricevute.value, [r.id]: { file, errori: e, aMano: !!e.ricevuta_a_mano || !!prima?.aMano,
                giorno: prima?.giorno ?? r.pec?.invio.giorno ?? props.oggi, ora: prima?.ora ?? r.pec?.invio.ora ?? '' } };
        },
    });
}
function sceltoIlFile(r: RigaDelRegistro, file: File | null | undefined) {
    sopra.value = null;
    if (file) {
        mandaLaRicevuta(r, file);
    }
}
function registraAMano(r: RigaDelRegistro) {
    const c = ricevute.value[r.id];
    if (c) {
        mandaLaRicevuta(r, c.file, { giorno: c.giorno, ...(c.ora ? { ora: c.ora } : {}) });
    }
}
const erroriDellaRicevuta = (id: number) => Object.entries(ricevute.value[id]?.errori ?? {}).filter(([k]) => k !== 'ricevuta_a_mano').map(([, v]) => v);
async function pecDiNuovo(invio: number) {
    errore.value = null;
    try {
        const r = (await axios.post(rotta('pec-di-nuovo', { invio }))).data;
        const conti = testoContiCambiati(r.conti_cambiati ?? []);
        messaggio.value = `${r.frase}${conti ? ` ${conti}` : ''}`;
        ricarica();
    } catch (e) {
        errore.value = frasePerErrore(e, 'La lettera non è stata mandata di nuovo. Riprova.');
    }
}
/** «Stampala per la posta»: la lettera rifatta con le cifre di oggi, in un PDF che si apre in un'altra scheda; poi si registra la spedizione dalla sua riga. */
function stampaPerLaPosta(invio: number) {
    errore.value = null;
    // La scheda si apre nel clic, prima della richiesta: niente `await` prima di questa chiamata.
    apriPdfInUnaScheda(() => axios.post(rotta('pec-non-partite.stampa'), { invii: [invio] }, { responseType: 'blob' }), 'lettera.pdf').then(() => {
        messaggio.value = 'Lettera rifatta con le cifre di oggi, da stampare: poi registra la spedizione dalla sua riga.';
        ricarica();
    }).catch(async (e) => {
        errore.value = await fraseDaErroreConFile(e, 'La lettera non è stata stampata. Riprova.');
    });
}
const vociDeiFiltri = TIPI_DEI_FILTRI.map((t) => ({ valore: t.valore, nome: t.nome }));
const vociDellaSpedizione = Object.entries(CANALI_SPEDIZIONE).map(([valore, nome]) => ({ valore, nome }));

// Le voci della scheda, i filtri (non si ricordano) e la pagina. Un filtro cambiato torna alla prima pagina; dopo un ricaricamento la pagina resta,
// e se non c'è più diventa l'ultima.
const filtri = ref({ tipo: '', dal: '', al: '' });
const paginaChiesta = ref(1);
const voci = computed(() => vociDellaCorrispondenza(props.lettere, props.documenti));
const filtrate = computed(() => filtraCorrispondenza(voci.value, filtri.value));
const vociDellaPagina = computed(() => paginaDi(filtrate.value, paginaChiesta.value));
const conFiltri = computed(() => filtri.value.tipo !== '' || filtri.value.dal !== '' || filtri.value.al !== '');
watch(filtri, () => { paginaChiesta.value = 1; }, { deep: true });
const togliFiltri = () => { filtri.value = { tipo: '', dal: '', al: '' }; };
const incassoDi = (id: number) => route(generateRoute('gestionale.movimenti-rate.show'), { condominio: props.condominioId, scrittura: id });
const ricevuteDellaPersona = computed(() => (props.personaId
    ? `${route(generateRoute('gestionale.ricevute.index'), { condominio: props.condominioId })}?ricevuta=tutte&persone[]=${props.personaId}`
    : null));
// I rifiuti di «Allega la prova» e «Togli» tornano come errori della pagina.
const pagina = usePage<{ errors?: Record<string, string> }>();
const erroriDellaPagina = computed(() => [pagina.props.errors?.file, pagina.props.errors?.prova].filter(Boolean) as string[]);

function apriSpedizione(invio: number) {
    spedizioneAperta.value = invio;
    spedizione.value = { canale: '', data: props.oggi, numero: '', nota: '' };
}
async function registraSpedizione(invio: number) {
    errore.value = null;
    try {
        await axios.post(rotta('spedizione'), { invii: [invio], canale: spedizione.value.canale, data: spedizione.value.data,
            numeri: { [invio]: spedizione.value.numero }, nota: spedizione.value.nota || null });
        spedizioneAperta.value = null;
        messaggio.value = 'Spedizione registrata.';
        ricarica();
    } catch (e) {
        errore.value = frasePerErrore(e, 'La spedizione non è stata registrata. Riprova.');
    }
}
function apriCorrezione(r: LetteraDelRegistro['righe'][number]) {
    spedizioneAperta.value = null;
    correzioneAperta.value = r.id;
    spedizione.value = { canale: r.spedizione?.canale ?? '', data: r.spedizione?.data ?? props.oggi, numero: r.spedizione?.numero ?? '', nota: r.spedizione?.nota ?? '' };
}
async function correggiSpedizione(invio: number) {
    errore.value = null;
    try {
        await axios.post(rotta('spedizione.correggi', { invio }), { canale: spedizione.value.canale, data: spedizione.value.data,
            numero: spedizione.value.numero || null, nota: spedizione.value.nota || null });
        correzioneAperta.value = null;
        messaggio.value = 'Spedizione corretta.';
        ricarica();
    } catch (e) {
        errore.value = frasePerErrore(e, 'La spedizione non è stata corretta. Riprova.');
    }
}
function allega(invio: number, evento: Event) {
    const file = (evento.target as HTMLInputElement).files?.[0];
    if (!file) {
        return;
    }
    router.post(rotta('prove.store', { invio }), { file }, { forceFormData: true, preserveScroll: true, only: ['lettere', 'flash', 'errors'] });
}
function togli(invio: number, documento: number) {
    router.delete(rotta('prove.togli', { invio, documento }), { preserveScroll: true, only: ['lettere', 'flash', 'errors'] });
}
async function inviaDiNuovo(invio: number) {
    errore.value = null;
    try {
        const r = (await axios.post(rotta('invia-di-nuovo', { invio }))).data;
        const conti = testoContiCambiati(r.conti_cambiati ?? []);
        messaggio.value = (r.non_partite > 0 ? 'La lettera rifatta non è partita: leggi la riga.' : 'Lettera rifatta con le cifre di oggi e rimandata.') + (conti ? ` ${conti}` : '');
        ricarica();
    } catch (e) {
        errore.value = frasePerErrore(e, 'La lettera non è stata rimandata. Riprova.');
    }
}
const nomeTipo = (t: string) => NOMI_TIPI[t as TipoLettera] ?? t;
const eventoTesto = (e: LetteraDelRegistro['eventi'][number]) => {
    // Il giorno lo scrive il server, nel calendario dell'amministratore; l'istante è in UTC (alle 00:30 di Roma è ancora il giorno prima).
    const quando = giorno(e.giorno ?? e.il?.slice(0, 10));
    const chi = e.da_nome ? `, da ${e.da_nome}` : '';
    switch (e.evento) {
        case 'spedizione':
            return `spedizione registrata il ${quando}${chi}`;
        case 'spedizione_corretta':
            return `spedizione corretta il ${quando}${chi}`;
        case 'prova_allegata':
            return `prova allegata il ${quando}${chi}${e.nome_file ? ` (${e.nome_file})` : ''}`;
        case 'prova_tolta':
            return `prova tolta il ${quando}${chi}${e.nome_file ? ` (${e.nome_file})` : ''}`;
        // Le parole della consegna le scrive il server, dalla ricevuta del gestore o dalla consegna scritta a mano con la prova.
        case 'ricevuta':
            return `ricevuta caricata il ${quando}${chi}${e.parole ? ` (${e.parole})` : ''}`;
        case 'consegna_a_mano':
            return `consegna scritta a mano il ${quando}${chi}${e.parole ? ` (${e.parole})` : ''}`;
        case 'mancata_consegna':
            return `avviso di mancata consegna caricato il ${quando}${chi}${e.parole ? ` (${e.parole})` : ''}`;
        default:
            return e.evento;
    }
};
</script>

<template>
    <section class="space-y-2" data-testid="registro-lettere">
        <div v-if="voci.length" class="flex flex-wrap items-end gap-2 text-xs" data-testid="filtri-corrispondenza">
            <label class="flex flex-col gap-0.5">
                <span class="text-slate-500 dark:text-slate-400">Tipo</span>
                <v-select v-model="filtri.tipo" :options="vociDeiFiltri" label="nome" :reduce="(t: { valore: string }) => t.valore" :clearable="false" :searchable="false"
                    class="min-w-[12rem] text-xs" data-testid="filtro-tipo" />
            </label>
            <label class="flex flex-col gap-0.5">
                <span class="text-slate-500 dark:text-slate-400">Dal</span>
                <Input v-model="filtri.dal" type="date" class="h-8 w-[150px] text-xs" data-testid="filtro-dal" />
            </label>
            <label class="flex flex-col gap-0.5">
                <span class="text-slate-500 dark:text-slate-400">Al</span>
                <Input v-model="filtri.al" type="date" class="h-8 w-[150px] text-xs" data-testid="filtro-al" />
            </label>
            <Button v-if="conFiltri" size="sm" variant="ghost" class="h-8 text-xs" data-testid="togli-filtri" @click="togliFiltri">Togli i filtri</Button>
            <a v-if="ricevuteDellaPersona" :href="ricevuteDellaPersona" class="ml-auto underline text-xs" data-testid="apri-ricevute">Apri nelle ricevute degli incassi</a>
        </div>
        <p v-if="!voci.length" class="text-xs text-slate-500 dark:text-slate-400" data-testid="niente">{{ FRASE_NIENTE }}</p>
        <p v-else-if="!filtrate.length" class="text-xs text-slate-500 dark:text-slate-400" data-testid="niente-coi-filtri">{{ fraseDelVuoto(filtri) }}</p>
        <p v-if="messaggio" class="text-xs text-emerald-800 dark:text-emerald-300">{{ messaggio }}</p>
        <p v-if="errore" class="text-xs text-red-700 dark:text-red-400">{{ errore }}</p>
        <p v-for="e in erroriDellaPagina" :key="e" class="text-xs text-red-700 dark:text-red-400" data-testid="errore-prova">{{ e }}</p>
        <template v-for="v in vociDellaPagina.voci" :key="v.chiave">
        <article v-if="v.genere === 'lettera'" class="rounded-lg border p-2 text-xs space-y-1" data-testid="lettera">
            <p class="font-medium">{{ nomeTipo(v.lettera.tipo) }} del {{ giorno(v.lettera.emessa_il) }} alle {{ v.lettera.ora }}<span v-if="v.lettera.emesso_da">, da {{ v.lettera.emesso_da }}</span></p>
            <p>{{ testoTotaleEResiduo(v.lettera) }}</p>
            <p v-if="testoTermineDallaConsegna(v.lettera.termine_dalla_consegna)" class="text-slate-700 dark:text-slate-200" data-testid="termine-dalla-consegna">
                {{ testoTermineDallaConsegna(v.lettera.termine_dalla_consegna) }}
            </p>
            <ul class="space-y-1">
                <li v-for="r in v.lettera.righe" :key="r.id" class="flex flex-wrap items-center gap-2">
                    <span v-if="r.pec" class="inline-flex rounded-md border px-1.5 py-0.5 text-xs font-medium" :data-testid="`badge-pec-${r.id}`"
                        :class="r.pec.provata ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                            : r.stato === 'non_partita' || r.pec.mancata_consegna ? 'border-red-300 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300'
                                : 'border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-300'">{{ r.pec.badge }}</span>
                    <span>{{ testoRigaDelRegistro(r) }}</span>
                    <Button v-if="r.pec?.ripetibile" size="sm" variant="outline" class="h-7 text-xs" :data-testid="`pec-di-nuovo-${r.id}`" @click="pecDiNuovo(r.id)">
                        Manda di nuovo per PEC, con le cifre di oggi
                    </Button>
                    <Button v-if="r.pec?.stampabile" size="sm" variant="outline" class="h-7 text-xs" :data-testid="`stampa-per-la-posta-${r.id}`" @click="stampaPerLaPosta(r.id)">Stampala per la posta</Button>
                    <!-- La ricevuta di consegna: si trascina sulla zona, o si sceglie con «Carica la ricevuta» (al telefono, e per chi non trascina). -->
                    <div v-if="r.pec?.ricevibile && puoCaricare" class="w-full space-y-1 rounded-lg border border-dashed p-2 text-xs"
                        :class="sopra === r.id ? 'border-sky-500 bg-sky-50 dark:bg-sky-950/40' : 'border-slate-300 dark:border-slate-600'" :data-testid="`zona-ricevuta-${r.id}`"
                        @dragover.prevent="sopra = r.id" @dragleave="sopra = null" @drop.prevent="sceltoIlFile(r, ($event as DragEvent).dataTransfer?.files?.[0])">
                        <p class="font-medium">{{ r.pec.provata ? 'Un\'altra ricevuta di consegna? Trascinala qui (.eml)' : 'Trascina qui la ricevuta di consegna (.eml)' }}</p>
                        <p class="text-slate-500 dark:text-slate-400">Il file che salvi dalla casella PEC: il programma ne legge il giorno e l'ora.</p>
                        <p class="text-slate-500 dark:text-slate-400">Anche l'avviso di mancata consegna: il programma lo registra, e la lettera non conta come arrivata.</p>
                        <label v-if="r.pec.provata" class="block space-y-1">
                            <span class="block text-slate-500 dark:text-slate-400">La consegna è già registrata: scrivi perché la correggi</span>
                            <Input v-model="perche[r.id]" class="h-8 text-xs" :data-testid="`perche-ricevuta-${r.id}`" />
                        </label>
                        <label class="inline-flex cursor-pointer items-center rounded-md border px-2 py-1 font-medium hover:bg-slate-50 dark:hover:bg-slate-800">
                            Carica la ricevuta
                            <input type="file" class="sr-only" accept=".eml,.pdf,.p7m,.jpg,.jpeg,.png" :data-testid="`file-ricevuta-${r.id}`"
                                @change="sceltoIlFile(r, ($event.target as HTMLInputElement).files?.[0]); ($event.target as HTMLInputElement).value = ''" />
                        </label>
                        <p v-for="e in erroriDellaRicevuta(r.id)" :key="e" class="text-red-700 dark:text-red-400" :data-testid="`errore-ricevuta-${r.id}`">{{ e }}</p>
                        <!-- Il file non si legge: il giorno e l'ora dell'invio, già scritti, da confermare o correggere; il file resta come prova. -->
                        <div v-if="ricevute[r.id]?.aMano" class="space-y-2 rounded-md bg-slate-50 p-2 dark:bg-slate-900" :data-testid="`consegna-a-mano-${r.id}`">
                            <p>Il giorno e l'ora sono quelli dell'invio: confermali o correggili. Il file resta come prova.</p>
                            <div class="flex flex-wrap items-end gap-2">
                                <label class="space-y-0.5">
                                    <span class="block text-slate-500 dark:text-slate-400">Il giorno della consegna</span>
                                    <Input v-model="ricevute[r.id].giorno" type="date" :max="oggi" class="h-8 w-[150px] text-xs" :data-testid="`giorno-consegna-${r.id}`" />
                                </label>
                                <label class="space-y-0.5">
                                    <span class="block text-slate-500 dark:text-slate-400">L'ora</span>
                                    <Input v-model="ricevute[r.id].ora" type="time" class="h-8 w-[110px] text-xs" :data-testid="`ora-consegna-${r.id}`" />
                                </label>
                                <Button size="sm" class="h-8 text-xs" :disabled="!ricevute[r.id].giorno" :data-testid="`registra-consegna-${r.id}`" @click="registraAMano(r)">
                                    Registra la consegna, con il file come prova
                                </Button>
                            </div>
                        </div>
                    </div>
                    <Button v-if="r.spedibile" size="sm" variant="outline" class="h-7 text-xs" @click="apriSpedizione(r.id)">Registra la spedizione</Button>
                    <Button v-if="r.canale === 'stampa' && r.stato === 'spedita'" size="sm" variant="ghost" class="h-7 text-xs" data-testid="correggi-spedizione"
                        @click="apriCorrezione(r)">Correggi la spedizione</Button>
                    <Button v-if="r.rimandabile" size="sm" variant="ghost" class="h-7 text-xs" data-testid="invia-di-nuovo" @click="inviaDiNuovo(r.id)">Invia di nuovo, con le cifre di oggi</Button>
                    <!-- La prova di una PEC è la ricevuta, nella sua zona: «Allega la prova» è delle lettere su carta e per email. -->
                    <label v-if="puoCaricare && r.canale !== 'pec' && r.stato !== 'in_coda' && r.stato !== 'non_partita'" class="cursor-pointer underline text-xs" :data-testid="`allega-prova-${r.id}`">
                        Allega la prova
                        <input type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.eml,.p7m" @change="allega(r.id, $event)" />
                    </label>
                    <div v-if="spedizioneAperta === r.id" class="flex flex-wrap items-center gap-2 w-full">
                        <v-select v-model="spedizione.canale" :options="vociDellaSpedizione" label="nome" :reduce="(c: { valore: string }) => c.valore" :clearable="false"
                            :searchable="false" placeholder="Come" class="min-w-[12rem] text-xs" data-testid="canale-spedizione" />
                        <Input v-model="spedizione.data" type="date" :max="oggi" class="h-7 w-[140px] text-xs" />
                        <Input v-model="spedizione.numero" placeholder="Numero o estremi" class="h-7 w-[160px] text-xs" />
                        <Input v-model="spedizione.nota" placeholder="Nota" class="h-7 w-[160px] text-xs" />
                        <Button size="sm" class="h-7 text-xs" :disabled="!spedizione.canale" @click="registraSpedizione(r.id)">Registra</Button>
                    </div>
                    <div v-if="correzioneAperta === r.id" class="flex flex-wrap items-center gap-2 w-full" data-testid="modulo-correzione">
                        <v-select v-model="spedizione.canale" :options="vociDellaSpedizione" label="nome" :reduce="(c: { valore: string }) => c.valore" :clearable="false"
                            :searchable="false" placeholder="Come" class="min-w-[12rem] text-xs" data-testid="canale-correzione" />
                        <Input v-model="spedizione.data" type="date" :max="oggi" class="h-7 w-[140px] text-xs" data-testid="spedizione-data" />
                        <Input v-model="spedizione.numero" placeholder="Numero o estremi" class="h-7 w-[160px] text-xs" data-testid="spedizione-numero" />
                        <Input v-model="spedizione.nota" placeholder="Perché la correggi" class="h-7 w-[160px] text-xs" />
                        <Button size="sm" class="h-7 text-xs" :disabled="!spedizione.canale" data-testid="registra-correzione" @click="correggiSpedizione(r.id)">Registra la correzione</Button>
                        <span class="w-full text-xs text-slate-500 dark:text-slate-400">La spedizione di prima resta nel registro, con la correzione.</span>
                    </div>
                </li>
            </ul>
            <p v-if="v.lettera.pdf">
                <a :href="rotta('pdf', { invio: v.lettera.pdf.invio_id })" class="underline">Il PDF conservato</a>
                <span :class="v.lettera.pdf.verificato ? 'text-emerald-800 dark:text-emerald-300' : 'text-red-700 dark:text-red-400'">
                    — {{ v.lettera.pdf.verificato ? 'il file corrisponde all\'impronta della lettera' : 'il file non corrisponde all\'impronta della lettera' }}
                </span>
            </p>
            <ul v-if="v.lettera.prove.length" class="space-y-0.5">
                <li v-for="p in v.lettera.prove" :key="p.id" class="flex flex-wrap items-center gap-2">
                    <a :href="rotta('prove.scarica', { invio: p.invio_id, documento: p.id })" class="underline">{{ p.nome }}</a>
                    <span v-if="p.aggiunta_il" class="text-slate-500 dark:text-slate-400">allegata il {{ giorno(p.aggiunta_il) }}</span>
                    <span v-if="p.della_consegna && p.consegna_a_mano" class="text-slate-500 dark:text-slate-400">prova della consegna · non si toglie</span>
                    <span v-else-if="p.della_consegna && p.mancata_consegna" class="text-slate-500 dark:text-slate-400">avviso di mancata consegna · non si toglie</span>
                    <span v-else-if="p.della_consegna" class="text-slate-500 dark:text-slate-400">ricevuta di consegna · non si toglie</span>
                    <Button v-if="p.rimovibile" size="sm" variant="ghost" class="h-7 text-xs" @click="togli(p.invio_id, p.id)">Togli</Button>
                </li>
            </ul>
            <p v-if="v.lettera.eventi.length" class="text-xs text-slate-500 dark:text-slate-400">{{ v.lettera.eventi.map(eventoTesto).join('; ') }}</p>
        </article>
        <article v-else class="rounded-lg border p-2 text-xs space-y-1" data-testid="documento">
            <p class="font-medium">{{ testoDocumento(v.documento) }}</p>
            <p v-if="testoCifreDelDocumento(v.documento)">{{ testoCifreDelDocumento(v.documento) }}</p>
            <ul class="space-y-1">
                <li v-for="r in v.documento.righe" :key="r.id">{{ testoRigaDelDocumento(r) }}</li>
            </ul>
            <a v-if="v.documento.incasso_id" :href="incassoDi(v.documento.incasso_id)" class="underline text-xs" data-testid="apri-incasso">Apri l'incasso{{ v.documento.numero ? ` n. ${v.documento.numero}` : '' }}</a>
        </article>
        </template>
        <nav v-if="vociDellaPagina.pagine > 1" class="flex items-center gap-2 text-xs" data-testid="pagine-corrispondenza">
            <Button size="sm" variant="outline" class="h-7 text-xs" :disabled="vociDellaPagina.pagina <= 1" data-testid="pagina-precedente"
                @click="paginaChiesta = vociDellaPagina.pagina - 1">Precedente</Button>
            <span>Pagina {{ vociDellaPagina.pagina }} di {{ vociDellaPagina.pagine }}</span>
            <Button size="sm" variant="outline" class="h-7 text-xs" :disabled="vociDellaPagina.pagina >= vociDellaPagina.pagine" data-testid="pagina-successiva"
                @click="paginaChiesta = vociDellaPagina.pagina + 1">Successiva</Button>
        </nav>
    </section>
</template>
