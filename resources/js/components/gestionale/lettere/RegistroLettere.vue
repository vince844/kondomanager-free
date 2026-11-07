<script setup lang="ts">
/**
 * «Lettere e comunicazioni» di una persona, nella pagina del suo estratto conto: per ogni lettera il tipo, quando, chi l'ha emessa, il totale
 * di allora e il residuo di oggi delle stesse rate, le righe (una per indirizzo, se è andata per email) con il loro stato, il PDF conservato
 * con l'impronta verificata, la spedizione e le prove. Da qui si registra la spedizione e la si corregge (quella di prima resta fra gli eventi),
 * si allega o si toglie una prova, si rimanda per email (la lettera si rifà con le cifre di oggi). Mai «consegnata», «ricevuta», «notificata»
 * detto della persona.
 */
import { computed, ref } from 'vue';
import axios from 'axios';
import { router, usePage } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { frasePerErrore } from '@/lib/gestionale/incassi/ricevute';
import { giorno } from '@/lib/gestionale/debiti/rateScadute';
import { CANALI_SPEDIZIONE, NOMI_TIPI, testoContiCambiati, testoRigaDelRegistro, testoTotaleEResiduo, type TipoLettera } from '@/lib/gestionale/lettere/lettere';

export interface LetteraDelRegistro {
    lettera: string; tipo: string; emessa_il: string; ora: string; emesso_da: string | null; totale: number; residuo_oggi: number; alla: string | null;
    termine: number | null; stato: string;
    righe: Array<{ id: number; canale: string; formato: string | null; destinatario: string | null; stato: string; quando: string; errore: string | null;
        spedizione: Record<string, string> | null; spedibile: boolean; rimandabile: boolean }>;
    pdf: { invio_id: number; nome: string; impronta: string | null; verificato: boolean } | null;
    prove: Array<{ id: number; invio_id: number; nome: string; aggiunta_il: string | null; rimovibile: boolean }>;
    eventi: Array<{ evento: string; il: string; giorno?: string; da_nome?: string | null; nome_file?: string }>;
}

const props = defineProps<{ condominioId: number; lettere: LetteraDelRegistro[]; oggi: string }>();

const { generateRoute } = usePermission();
const rotta = (nome: string, altro: Record<string, unknown> = {}) => route(generateRoute(`gestionale.lettere.${nome}`), { condominio: props.condominioId, ...altro });
const messaggio = ref<string | null>(null);
const errore = ref<string | null>(null);
const spedizioneAperta = ref<number | null>(null);
const spedizione = ref({ canale: '', data: '', numero: '', nota: '' });
/** La riga di cui si corregge la spedizione registrata: il modulo parte dai dati di oggi. */
const correzioneAperta = ref<number | null>(null);

const ricarica = () => router.reload({ only: ['lettere'] });
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
        <h3 class="text-sm font-semibold">Lettere</h3>
        <p v-if="!lettere.length" class="text-xs text-slate-500 dark:text-slate-400">Nessuna lettera a questa persona in questo condominio.</p>
        <p v-if="messaggio" class="text-xs text-emerald-800 dark:text-emerald-300">{{ messaggio }}</p>
        <p v-if="errore" class="text-xs text-red-700 dark:text-red-400">{{ errore }}</p>
        <p v-for="e in erroriDellaPagina" :key="e" class="text-xs text-red-700 dark:text-red-400" data-testid="errore-prova">{{ e }}</p>
        <article v-for="l in lettere" :key="l.lettera" class="rounded-lg border p-2 text-xs space-y-1" data-testid="lettera">
            <p class="font-medium">{{ nomeTipo(l.tipo) }} del {{ giorno(l.emessa_il) }} alle {{ l.ora }}<span v-if="l.emesso_da">, da {{ l.emesso_da }}</span></p>
            <p>{{ testoTotaleEResiduo(l) }}</p>
            <ul class="space-y-1">
                <li v-for="r in l.righe" :key="r.id" class="flex flex-wrap items-center gap-2">
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
            <p v-if="l.pdf">
                <a :href="rotta('pdf', { invio: l.pdf.invio_id })" class="underline">Il PDF conservato</a>
                <span :class="l.pdf.verificato ? 'text-emerald-800 dark:text-emerald-300' : 'text-red-700 dark:text-red-400'">
                    — {{ l.pdf.verificato ? 'il file corrisponde all\'impronta della lettera' : 'il file non corrisponde all\'impronta della lettera' }}
                </span>
            </p>
            <ul v-if="l.prove.length" class="space-y-0.5">
                <li v-for="p in l.prove" :key="p.id" class="flex flex-wrap items-center gap-2">
                    <a :href="rotta('prove.scarica', { invio: p.invio_id, documento: p.id })" class="underline">{{ p.nome }}</a>
                    <span v-if="p.aggiunta_il" class="text-slate-500">allegata il {{ giorno(p.aggiunta_il) }}</span>
                    <Button v-if="p.rimovibile" size="sm" variant="ghost" class="h-6 text-[11px]" @click="togli(p.invio_id, p.id)">Togli</Button>
                </li>
            </ul>
            <p v-if="l.eventi.length" class="text-[11px] text-slate-500 dark:text-slate-400">{{ l.eventi.map(eventoTesto).join('; ') }}</p>
        </article>
    </section>
</template>
