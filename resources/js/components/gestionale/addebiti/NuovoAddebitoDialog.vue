<script setup lang="ts">
/**
 * La finestra di un addebito a una persona sola, dalle tre porte: il riquadro delle quote non assegnate del piano (`piano`: le quote di un'unità, una
 * riga per voce, ognuna da tenere o togliere e con il suo importo), l'estratto conto della persona (`persona`: un contributo, o una spesa di una sola
 * unità già fatturata), la scheda «Conguaglio» sulle spese non attribuite (`conguaglio`: quello che resta da addebitare). Il server propone la persona
 * (la proposta per prima, con il perché), l'importo, la causale, la gestione e l'esercizio; l'amministratore sceglie e registra. Gli importi si scrivono
 * in euro: il server li porta in centesimi.
 *
 * Una risposta arrivata dopo un'altra lettura (cambiata l'unità, riaperta la finestra) si scarta.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import vSelect from 'vue-select';
import { frasePerErrore } from '@/lib/gestionale/incassi/ricevute';
import { euroTesto } from '@/lib/gestionale/debiti/rateScadute';
import { FRASE_ADDEBITO, FRASE_CHI, centesimiInEuro, chiaveDove, daChiaveDove, dataDiSerie } from '@/lib/gestionale/addebiti/addebiti';
import { FRASE_BASE, avvisiDi, type Scelta } from '@/lib/gestionale/addebiti/accessori';

export type OrigineAddebito =
    | { tipo: 'piano'; pianoRateId: number; chiavi: string[] }
    | { tipo: 'persona'; anagraficaId: number }
    | { tipo: 'conguaglio'; gestioneId: number; esercizioId: number; importo: number };

interface Persona { anagrafica_id: number; nome: string; perche: string }
interface Unita { id: number; nome: string }
interface Dove { gestione_id: number; esercizio_id: number; nome: string; inizio?: string; fine?: string }
interface VoceDelPiano { chiave: string; piano_origine_id: number; conto_id: number | null; importo_cents: number; addebitato_cents: number; resta_cents: number; causale: string }
interface Spesa { riga_fattura_id: number; immobile_id: number; unita: string | null; descrizione: string; fattura: string; resta_cents: number; dove: Dove | null }
interface Proposta {
    persone?: Persona[]; unita?: Unita | Unita[]; persona?: { id: number; nome: string }; voci?: VoceDelPiano[]; spese?: Spesa[]; immobile_id?: number | null;
    causale?: string; origine?: { gestione_id: number; esercizio_id: number }; dove: { proposta: Dove | null; scelte: Dove[] }; scadenza: string; oggi: string;
    tipi?: Scelta[]; basi?: Scelta[]; avvisi?: Record<string, Record<string, string[]>>;
}

const props = defineProps<{ open: boolean; condominioId: number; origine: OrigineAddebito | null }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'fatto'): void }>();

const { generateRoute } = usePermission();
const rotta = (nome: string) => route(generateRoute(`gestionale.addebiti.${nome}`), { condominio: props.condominioId });

const proposta = ref<Proposta | null>(null);
const errore = ref<string | null>(null);
const esito = ref<string | null>(null);
const inCorso = ref(false);
let numeroLettura = 0;

const modulo = ref({
    persona: null as number | null,
    unita: null as number | null,
    dove: '',
    // La data dell'addebito, che è la data della sua scrittura: dentro l'esercizio scelto.
    data: '',
    scadenza: '',
    // Dal piano: una riga per quota, da tenere o togliere.
    voci: [] as Array<VoceDelPiano & { scelta: boolean; importo: string; testo: string }>,
    // Dall'estratto conto e dal conguaglio.
    modo: 'contributo' as 'contributo' | 'spesa',
    importo: '',
    causale: '',
    spesa: null as number | null,
    natura: 'contributo' as 'contributo' | 'accessorio',
    // Una spesa per il ritardo: che cos'è e su che cosa si fonda (facoltativi con la fattura).
    tipo: '',
    base: '',
    riferimento: '',
});

async function leggi(immobileId: number | null = null) {
    const o = props.origine;
    if (!o) {
        return;
    }
    const numero = ++numeroLettura;
    errore.value = null;
    const params: Record<string, unknown> = o.tipo === 'piano' ? { piano_rate_id: o.pianoRateId, chiavi: o.chiavi }
        : o.tipo === 'persona' ? { anagrafica_id: o.anagraficaId } : { gestione_id: o.gestioneId, esercizio_id: o.esercizioId, immobile_id: immobileId ?? undefined };
    try {
        const r = (await axios.get(rotta('proposta'), { params })).data as Proposta;
        if (numero !== numeroLettura) {
            return;
        }
        proposta.value = r;
        modulo.value.persona = o.tipo === 'persona' ? (r.persona?.id ?? null) : (r.persone?.[0]?.anagrafica_id ?? null);
        if (immobileId === null) {
            modulo.value.dove = r.dove.proposta ? chiaveDove(r.dove.proposta) : '';
            modulo.value.data = dataDiSerie(r.oggi, r.dove.proposta?.inizio, r.dove.proposta?.fine);
            modulo.value.scadenza = r.scadenza;
            modulo.value.voci = (r.voci ?? []).map((v) => ({ ...v, scelta: v.resta_cents > 0, importo: centesimiInEuro(v.resta_cents), testo: v.causale }));
            modulo.value.unita = o.tipo === 'piano' ? ((r.unita as Unita | undefined)?.id ?? null)
                : o.tipo === 'persona' ? (((r.unita as Unita[] | undefined) ?? [])[0]?.id ?? null) : (r.immobile_id ?? null);
            if (o.tipo === 'conguaglio') {
                modulo.value.importo = centesimiInEuro(o.importo);
                modulo.value.causale = r.causale ?? '';
            }
        }
    } catch (e) {
        if (numero === numeroLettura) {
            errore.value = frasePerErrore(e, 'La proposta non si è caricata. Riprova.');
        }
    }
}

watch(() => props.open, (aperta) => {
    if (!aperta) {
        return;
    }
    proposta.value = null;
    esito.value = null;
    modulo.value = { persona: null, unita: null, dove: '', data: '', scadenza: '', voci: [], modo: 'contributo', importo: '', causale: '', spesa: null, natura: 'contributo',
        tipo: '', base: '', riferimento: '' };
    leggi();
}, { immediate: true });

/** Nella scheda «Conguaglio» le persone dipendono dall'unità: si rileggono quando cambia. */
watch(() => modulo.value.unita, (unita, prima) => {
    if (props.open && props.origine?.tipo === 'conguaglio' && unita !== null && unita !== prima && unita !== (proposta.value?.immobile_id ?? null)) {
        leggi(unita);
    }
});

/** La spesa scelta dall'estratto conto propone il suo importo e la sua causale, e si scrive nella gestione e nell'esercizio della sua fattura. */
watch(() => modulo.value.spesa, (id) => {
    const s = (proposta.value?.spese ?? []).find((x) => x.riga_fattura_id === id);
    if (s) {
        modulo.value.importo = centesimiInEuro(s.resta_cents);
        modulo.value.causale = `${s.descrizione || 'Spesa'}, ${s.unita ?? ''}`.replace(/, $/, '');
        modulo.value.unita = s.immobile_id;
        if (s.dove) {
            modulo.value.dove = chiaveDove(s.dove);
        }
    }
});

/** Cambiato l'esercizio, la data di serie è la sua. */
watch(() => modulo.value.dove, (chiave, prima) => {
    const d = (proposta.value?.dove.scelte ?? []).find((x) => chiaveDove(x) === chiave);
    if (proposta.value && d && chiave !== prima) {
        modulo.value.data = dataDiSerie(proposta.value.oggi, d.inizio, d.fine);
    }
});

/** La spesa già fatturata scelta: il posto è quello della fattura, e non si cambia. */
const spesaScelta = computed<Spesa | null>(() => (props.origine?.tipo === 'persona' && modulo.value.modo === 'spesa'
    ? (proposta.value?.spese ?? []).find((x) => x.riga_fattura_id === modulo.value.spesa) ?? null : null));
const doveDellaSpesa = computed(() => spesaScelta.value?.dove ?? null);
const doveScelto = computed(() => (proposta.value?.dove.scelte ?? []).find((x) => chiaveDove(x) === modulo.value.dove) ?? null);

const unitaDellaPersona = computed<Unita[]>(() => (Array.isArray(proposta.value?.unita) ? proposta.value!.unita as Unita[] : []));
// Le voci dei menu: la riga della fattura detta per intero, e le gestioni con la loro chiave.
const speseDelMenu = computed(() => (proposta.value?.spese ?? []).map((s) => ({ id: s.riga_fattura_id,
    etichetta: `${s.unita} · ${s.descrizione || 'senza descrizione'} · fattura ${s.fattura} · da chiedere ${euroTesto(s.resta_cents)}` })));
const doveDelMenu = computed(() => (proposta.value?.dove.scelte ?? []).map((d) => ({ chiave: chiaveDove(d), nome: d.nome })));
const titolo = computed(() => (props.origine?.tipo === 'persona' && proposta.value?.persona ? `Nuovo addebito a ${proposta.value.persona.nome}` : 'Addebita a una persona'));

function vociDaMandare(): Array<Record<string, unknown>> {
    const o = props.origine;
    const m = modulo.value;
    const base = { anagrafica_id: m.persona, scadenza: m.scadenza };
    if (o?.tipo === 'piano') {
        return m.voci.filter((v) => v.scelta).map((v) => ({ ...base, immobile_id: m.unita, importo: v.importo, causale: v.testo, chiave: v.chiave,
            piano_origine_id: v.piano_origine_id, conto_id: v.conto_id }));
    }
    if (o?.tipo === 'conguaglio') {
        return [{ ...base, immobile_id: m.unita, importo: m.importo, causale: m.causale, gestione_origine_id: o.gestioneId, esercizio_origine_id: o.esercizioId }];
    }
    if (m.modo === 'spesa') {
        return [{ ...base, immobile_id: m.unita, importo: m.importo, causale: m.causale, riga_fattura_id: m.spesa, natura: m.natura,
            // Il tipo, la base e il riferimento di una spesa per il ritardo, solo quelli scelti: con la fattura sono facoltativi.
            ...(m.natura === 'accessorio' ? Object.fromEntries(Object.entries({ tipo_accessorio: m.tipo, base: m.base, riferimento: m.riferimento }).filter(([, v]) => v !== '')) : {}) }];
    }

    return [{ ...base, immobile_id: m.unita, importo: m.importo, causale: m.causale }];
}

const pronto = computed(() => {
    const m = modulo.value;
    const dove = daChiaveDove(m.dove);
    if (!dove || m.persona === null || m.unita === null || !m.scadenza || !m.data) {
        return false;
    }
    if (props.origine?.tipo === 'piano') {
        return m.voci.some((v) => v.scelta && v.importo.trim() !== '' && v.testo.trim() !== '');
    }
    if (props.origine?.tipo === 'persona' && m.modo === 'spesa' && m.spesa === null) {
        return false;
    }

    return m.importo.trim() !== '' && m.causale.trim() !== '';
});

async function registra() {
    const dove = daChiaveDove(modulo.value.dove);
    if (!dove) {
        return;
    }
    errore.value = null;
    esito.value = null;
    inCorso.value = true;
    try {
        const r = (await axios.post(rotta('store'), { ...dove, data: modulo.value.data, voci: vociDaMandare() })).data;
        esito.value = r.messaggio;
        emit('fatto');
        if (props.origine?.tipo === 'persona') {
            // Le spese da chiedere sono cambiate: si rileggono, con la stessa persona.
            modulo.value = { ...modulo.value, importo: '', causale: '', spesa: null };
        }
        await leggi();
    } catch (e) {
        errore.value = frasePerErrore(e, 'L\'addebito non è stato registrato. Riprova.');
    } finally {
        inCorso.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <!-- All'apertura il cursore non va in un menu, che prendendolo si aprirebbe da sé. -->
        <DialogContent class="sm:max-w-2xl max-h-[90vh] overflow-y-auto" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>{{ titolo }}</DialogTitle>
                <DialogDescription>{{ FRASE_ADDEBITO }}</DialogDescription>
            </DialogHeader>

            <div v-if="proposta" class="space-y-4 text-sm" data-testid="modulo-addebito">
                <!-- Dal piano: le quote non assegnate di un'unità, una riga per voce -->
                <div v-if="origine?.tipo === 'piano'" class="space-y-2" data-testid="voci">
                    <p class="text-xs text-slate-500">Unità: <strong>{{ (proposta.unita as Unita).nome }}</strong></p>
                    <div v-for="v in modulo.voci" :key="v.chiave" class="rounded-lg border p-2 space-y-1 text-xs" data-testid="voce">
                        <div class="flex flex-wrap items-center gap-2">
                            <Checkbox :model-value="v.scelta" :disabled="v.resta_cents === 0" @update:model-value="(x: any) => (v.scelta = !!x)" />
                            <span class="flex-1">{{ v.causale }}</span>
                            <Input v-model="v.importo" inputmode="decimal" :disabled="!v.scelta" class="h-7 w-[100px] text-xs" aria-label="Importo da addebitare" />
                        </div>
                        <p class="text-slate-500 pl-6" data-testid="resta">
                            Quota non assegnata {{ euroTesto(v.importo_cents) }}<template v-if="v.addebitato_cents">, già addebitati {{ euroTesto(v.addebitato_cents) }}</template>: si addebitano al più {{ euroTesto(v.resta_cents) }}.
                        </p>
                        <label class="block pl-6"><span class="block text-slate-500">Causale</span>
                            <Input v-model="v.testo" :disabled="!v.scelta" class="h-7 text-xs" /></label>
                    </div>
                </div>

                <!-- Dall'estratto conto: un contributo, o una spesa di una sola unità già fatturata -->
                <div v-else-if="origine?.tipo === 'persona'" class="space-y-2 text-xs">
                    <div class="flex flex-wrap gap-3">
                        <label class="flex items-center gap-1" data-testid="modo-contributo"><input v-model="modulo.modo" type="radio" value="contributo" /> Un contributo</label>
                        <label class="flex items-center gap-1" data-testid="modo-spesa"><input v-model="modulo.modo" type="radio" value="spesa" :disabled="!(proposta.spese ?? []).length" /> Una spesa di una sola unità già fatturata</label>
                    </div>
                    <template v-if="modulo.modo === 'spesa'">
                        <div class="menu-campo" data-testid="spesa">
                            <v-select :model-value="modulo.spesa" :options="speseDelMenu" label="etichetta" :reduce="(s: { id: number }) => s.id" :searchable="false"
                                :clearable="false" placeholder="Scegli la riga della fattura" class="w-full" @update:model-value="(v: number) => (modulo.spesa = v)" />
                        </div>
                        <p class="text-slate-500">La spesa esce da quelle anticipate per l'unità: il conguaglio la conta chiesta, una volta sola.</p>
                        <div class="flex flex-wrap gap-3">
                            <label class="flex items-center gap-1"><input v-model="modulo.natura" type="radio" value="contributo" /> Una spesa dell'unità</label>
                            <label class="flex items-center gap-1" data-testid="natura-accessorio"><input v-model="modulo.natura" type="radio" value="accessorio" /> Una spesa per il ritardo (sollecito, legale)</label>
                        </div>
                        <div v-if="modulo.natura === 'accessorio'" class="space-y-1 rounded-lg border p-2" data-testid="base-della-spesa">
                            <div class="flex flex-wrap gap-3">
                                <span class="text-slate-500">Che cos'è</span>
                                <label v-for="t in proposta.tipi ?? []" :key="t.valore" class="flex items-center gap-1"><input v-model="modulo.tipo" type="radio" :value="t.valore" /> {{ t.nome }}</label>
                            </div>
                            <div class="flex flex-wrap gap-3">
                                <span class="text-slate-500">Su che cosa si fonda</span>
                                <label v-for="b in proposta.basi ?? []" :key="b.valore" class="flex items-center gap-1"><input v-model="modulo.base" type="radio" :value="b.valore" /> {{ b.nome }}</label>
                            </div>
                            <Input v-model="modulo.riferimento" placeholder="Il riferimento: l'articolo, la data e il verbale, il provvedimento" class="h-7 text-xs" data-testid="riferimento" />
                            <p class="text-slate-500">{{ FRASE_BASE }}</p>
                            <p v-for="a in avvisiDi(proposta.avvisi, modulo.tipo, modulo.base)" :key="a" class="text-amber-800 dark:text-amber-300" data-testid="avviso">{{ a }}</p>
                        </div>
                    </template>
                    <div v-else class="block space-y-1 menu-campo" data-testid="unita"><span class="block text-slate-500">Unità</span>
                        <v-select :model-value="modulo.unita" :options="unitaDellaPersona" label="nome" :reduce="(u: Unita) => u.id" :searchable="false"
                            :clearable="false" class="w-full" @update:model-value="(v: number) => (modulo.unita = v)" />
                    </div>
                    <p v-if="!unitaDellaPersona.length" class="text-amber-700 dark:text-amber-400" data-testid="senza-unita">{{ proposta.persona?.nome }} non è e non è stato titolare di un'unità di questo condominio: un addebito va a chi ha un'unità.</p>
                </div>

                <!-- Dal conguaglio: quello che resta delle spese non attribuite -->
                <div v-else class="space-y-1 text-xs">
                    <div class="block space-y-1 menu-campo" data-testid="unita"><span class="block text-slate-500">Unità</span>
                        <v-select :model-value="modulo.unita" :options="(proposta.unita as Unita[])" label="nome" :reduce="(u: Unita) => u.id" :searchable="false"
                            :clearable="false" placeholder="Scegli l'unità" class="w-full" @update:model-value="(v: number) => (modulo.unita = v)" />
                    </div>
                </div>

                <!-- Chi lo paga: la proposta per prima, con il perché -->
                <fieldset v-if="origine?.tipo !== 'persona'" class="space-y-1 text-xs" data-testid="persone">
                    <legend class="text-slate-500">Chi lo paga</legend>
                    <p v-if="!(proposta.persone ?? []).length" data-testid="nessuna-persona">L'unità non ha e non ha avuto un proprietario, un nudo proprietario o un usufruttuario: aggiungi il titolare all'unità.</p>
                    <label v-for="(p, i) in proposta.persone" :key="p.anagrafica_id" class="flex items-start gap-2" data-testid="persona">
                        <input v-model="modulo.persona" type="radio" :value="p.anagrafica_id" class="mt-0.5" />
                        <span><strong>{{ p.nome }}</strong> — {{ p.perche }}<template v-if="i === 0"> (la proposta)</template></span>
                    </label>
                    <p class="text-slate-500">{{ FRASE_CHI }}</p>
                </fieldset>

                <div v-if="origine?.tipo !== 'piano'" class="flex flex-wrap gap-3 text-xs">
                    <label class="space-y-1"><span class="block text-slate-500">Importo (€)</span>
                        <Input v-model="modulo.importo" inputmode="decimal" class="h-8 w-[120px] text-xs" data-testid="importo" /></label>
                    <label class="flex-1 space-y-1 min-w-[220px]"><span class="block text-slate-500">Causale</span>
                        <Input v-model="modulo.causale" class="h-8 text-xs" data-testid="causale" /></label>
                </div>

                <div class="flex flex-wrap gap-3 text-xs">
                    <!-- Tutta la riga: il nome della gestione con il suo esercizio si legge per intero. -->
                    <div class="basis-full space-y-1 menu-campo" data-testid="dove"><span class="block text-slate-500">Gestione</span>
                        <v-select :model-value="modulo.dove" :options="doveDelMenu" label="nome" :reduce="(d: { chiave: string }) => d.chiave" :searchable="false"
                            :clearable="false" :disabled="!!doveDellaSpesa" class="w-full" @update:model-value="(v: string) => (modulo.dove = v)" />
                    </div>
                    <label class="space-y-1"><span class="block text-slate-500">Data dell'addebito</span>
                        <Input v-model="modulo.data" type="date" :min="doveScelto?.inizio" :max="doveScelto?.fine" class="h-8 w-[150px] text-xs" data-testid="data" /></label>
                    <label class="space-y-1"><span class="block text-slate-500">Scadenza</span>
                        <Input v-model="modulo.scadenza" type="date" class="h-8 w-[150px] text-xs" data-testid="scadenza" /></label>
                </div>
                <p v-if="doveDellaSpesa" class="text-xs text-slate-500" data-testid="dove-della-spesa">La spesa è della fattura {{ spesaScelta?.fattura }}: l'addebito si scrive in {{ doveDellaSpesa.nome }}, l'esercizio della fattura.</p>
                <p class="text-xs text-slate-500">La data dell'addebito è quella della sua scrittura, dentro l'esercizio scelto; la scadenza è il giorno entro cui la persona lo versa.</p>
                <p v-if="!proposta.dove.scelte.length" class="text-xs text-amber-700 dark:text-amber-400" data-testid="senza-esercizio">Non c'è un esercizio aperto: l'addebito si registra in un esercizio aperto.</p>

                <Button size="sm" :disabled="inCorso || !pronto" data-testid="registra-addebito" @click="registra">Registra l'addebito</Button>
            </div>
            <p v-if="esito" class="text-xs text-emerald-800 dark:text-emerald-300" data-testid="esito">{{ esito }}</p>
            <p v-if="errore" class="text-xs text-red-700 dark:text-red-400" data-testid="errore">{{ errore }}</p>
        </DialogContent>
    </Dialog>
</template>
