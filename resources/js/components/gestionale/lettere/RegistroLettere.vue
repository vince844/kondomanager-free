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
 * si allega o si toglie una prova, si rimanda per email (la lettera si rifà con le cifre di oggi). Mai «consegnata», «ricevuta», «notificata»
 * detto della persona.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { router, usePage } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { frasePerErrore } from '@/lib/gestionale/incassi/ricevute';
import { giorno } from '@/lib/gestionale/debiti/rateScadute';
import { CANALI_SPEDIZIONE, NOMI_TIPI, testoContiCambiati, testoRigaDelRegistro, testoTotaleEResiduo, type TipoLettera } from '@/lib/gestionale/lettere/lettere';
import {
    FRASE_NIENTE, TIPI_DEI_FILTRI, filtraCorrispondenza, fraseDelVuoto, paginaDi, testoCifreDelDocumento, testoDocumento, testoRigaDelDocumento,
    vociDellaCorrispondenza, type DocumentoInviato,
} from '@/lib/gestionale/lettere/corrispondenza';

export interface LetteraDelRegistro {
    lettera: string; tipo: string; emessa_il: string; ora: string; emesso_da: string | null; totale: number; residuo_oggi: number; alla: string | null;
    termine: number | null; stato: string;
    righe: Array<{ id: number; canale: string; formato: string | null; destinatario: string | null; stato: string; quando: string; errore: string | null;
        spedizione: Record<string, string> | null; spedibile: boolean; rimandabile: boolean }>;
    pdf: { invio_id: number; nome: string; impronta: string | null; verificato: boolean } | null;
    prove: Array<{ id: number; invio_id: number; nome: string; aggiunta_il: string | null; rimovibile: boolean }>;
    eventi: Array<{ evento: string; il: string; giorno?: string; da_nome?: string | null; nome_file?: string }>;
}

const props = withDefaults(defineProps<{
    condominioId: number; lettere: LetteraDelRegistro[]; oggi: string;
    /** Coda 533: le ricevute, gli avvisi di compensazione e «non serve comunicarla» della persona, dal server. */
    documenti?: DocumentoInviato[];
    /** La persona: porta alla pagina delle ricevute filtrata su di lei. */
    personaId?: number | null;
}>(), { documenti: () => [], personaId: null });

const { generateRoute } = usePermission();
const rotta = (nome: string, altro: Record<string, unknown> = {}) => route(generateRoute(`gestionale.lettere.${nome}`), { condominio: props.condominioId, ...altro });
const messaggio = ref<string | null>(null);
const errore = ref<string | null>(null);
const spedizioneAperta = ref<number | null>(null);
const spedizione = ref({ canale: '', data: '', numero: '', nota: '' });
/** La riga di cui si corregge la spedizione registrata: il modulo parte dai dati di oggi. */
const correzioneAperta = ref<number | null>(null);

const ricarica = () => router.reload({ only: ['lettere', 'ultima_lettera'] });

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
                <select v-model="filtri.tipo" class="h-8 rounded-md border px-2 bg-transparent dark:bg-slate-900" data-testid="filtro-tipo">
                    <option v-for="t in TIPI_DEI_FILTRI" :key="t.valore" :value="t.valore">{{ t.nome }}</option>
                </select>
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
            <a v-if="ricevuteDellaPersona" :href="ricevuteDellaPersona" class="ml-auto underline text-[11px]" data-testid="apri-ricevute">Apri nelle ricevute degli incassi</a>
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
            <ul class="space-y-1">
                <li v-for="r in v.lettera.righe" :key="r.id" class="flex flex-wrap items-center gap-2">
                    <span>{{ testoRigaDelRegistro(r) }}</span>
                    <Button v-if="r.spedibile" size="sm" variant="outline" class="h-6 text-[11px]" @click="apriSpedizione(r.id)">Registra la spedizione</Button>
                    <Button v-if="r.canale === 'stampa' && r.stato === 'spedita'" size="sm" variant="ghost" class="h-6 text-[11px]" data-testid="correggi-spedizione"
                        @click="apriCorrezione(r)">Correggi la spedizione</Button>
                    <Button v-if="r.rimandabile" size="sm" variant="ghost" class="h-6 text-[11px]" data-testid="invia-di-nuovo" @click="inviaDiNuovo(r.id)">Invia di nuovo, con le cifre di oggi</Button>
                    <label v-if="r.stato !== 'in_coda' && r.stato !== 'non_partita'" class="cursor-pointer underline text-[11px]">
                        Allega la prova
                        <input type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.eml,.p7m" @change="allega(r.id, $event)" />
                    </label>
                    <div v-if="spedizioneAperta === r.id" class="flex flex-wrap items-center gap-2 w-full">
                        <select v-model="spedizione.canale" class="h-7 rounded-md border px-1 bg-transparent">
                            <option value="" disabled>Come</option>
                            <option v-for="(nome, c) in CANALI_SPEDIZIONE" :key="c" :value="c">{{ nome }}</option>
                        </select>
                        <Input v-model="spedizione.data" type="date" :max="oggi" class="h-7 w-[140px] text-xs" />
                        <Input v-model="spedizione.numero" placeholder="Numero o estremi" class="h-7 w-[160px] text-xs" />
                        <Input v-model="spedizione.nota" placeholder="Nota" class="h-7 w-[160px] text-xs" />
                        <Button size="sm" class="h-7 text-[11px]" :disabled="!spedizione.canale" @click="registraSpedizione(r.id)">Registra</Button>
                    </div>
                    <div v-if="correzioneAperta === r.id" class="flex flex-wrap items-center gap-2 w-full" data-testid="modulo-correzione">
                        <select v-model="spedizione.canale" class="h-7 rounded-md border px-1 bg-transparent">
                            <option value="" disabled>Come</option>
                            <option v-for="(nome, c) in CANALI_SPEDIZIONE" :key="c" :value="c">{{ nome }}</option>
                        </select>
                        <Input v-model="spedizione.data" type="date" :max="oggi" class="h-7 w-[140px] text-xs" data-testid="spedizione-data" />
                        <Input v-model="spedizione.numero" placeholder="Numero o estremi" class="h-7 w-[160px] text-xs" data-testid="spedizione-numero" />
                        <Input v-model="spedizione.nota" placeholder="Perché la correggi" class="h-7 w-[160px] text-xs" />
                        <Button size="sm" class="h-7 text-[11px]" :disabled="!spedizione.canale" data-testid="registra-correzione" @click="correggiSpedizione(r.id)">Registra la correzione</Button>
                        <span class="w-full text-[11px] text-slate-500 dark:text-slate-400">La spedizione di prima resta nel registro, con la correzione.</span>
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
                    <span v-if="p.aggiunta_il" class="text-slate-500">allegata il {{ giorno(p.aggiunta_il) }}</span>
                    <Button v-if="p.rimovibile" size="sm" variant="ghost" class="h-6 text-[11px]" @click="togli(p.invio_id, p.id)">Togli</Button>
                </li>
            </ul>
            <p v-if="v.lettera.eventi.length" class="text-[11px] text-slate-500 dark:text-slate-400">{{ v.lettera.eventi.map(eventoTesto).join('; ') }}</p>
        </article>
        <article v-else class="rounded-lg border p-2 text-xs space-y-1" data-testid="documento">
            <p class="font-medium">{{ testoDocumento(v.documento) }}</p>
            <p v-if="testoCifreDelDocumento(v.documento)">{{ testoCifreDelDocumento(v.documento) }}</p>
            <ul class="space-y-1">
                <li v-for="r in v.documento.righe" :key="r.id">{{ testoRigaDelDocumento(r) }}</li>
            </ul>
            <a v-if="v.documento.incasso_id" :href="incassoDi(v.documento.incasso_id)" class="underline text-[11px]" data-testid="apri-incasso">Apri l'incasso{{ v.documento.numero ? ` n. ${v.documento.numero}` : '' }}</a>
        </article>
        </template>
        <nav v-if="vociDellaPagina.pagine > 1" class="flex items-center gap-2 text-xs" data-testid="pagine-corrispondenza">
            <Button size="sm" variant="outline" class="h-7 text-[11px]" :disabled="vociDellaPagina.pagina <= 1" data-testid="pagina-precedente"
                @click="paginaChiesta = vociDellaPagina.pagina - 1">Precedente</Button>
            <span>Pagina {{ vociDellaPagina.pagina }} di {{ vociDellaPagina.pagine }}</span>
            <Button size="sm" variant="outline" class="h-7 text-[11px]" :disabled="vociDellaPagina.pagina >= vociDellaPagina.pagine" data-testid="pagina-successiva"
                @click="paginaChiesta = vociDellaPagina.pagina + 1">Successiva</Button>
        </nav>
    </section>
</template>
