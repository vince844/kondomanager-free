<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Proponi gli interessi alla data …», dall'elenco «Rate scadute», per le persone scelte (una lista, o «tutte le N dei filtri», che il server rilegge).
 * Il server propone riga per riga (la rata, la scadenza, i tratti con il capitale, i giorni, il tasso e il divisore, gli interessi della rata arrotondati
 * al centesimo); l'amministratore toglie righe o persone, sceglie la voce di entrata che riceve gli interessi quando sono versati, e registra. Il server
 * rifà la proposta e scrive solo se le cifre sono quelle viste. Niente si registra senza il suo clic.
 *
 * Decisioni 489, 493 e 494 (4): la riga del debito ereditato con il badge «ereditato» e la sua frase; la riga del defunto ferma al decesso con la sua (D4);
 * «Cambia» su ogni riga, solo in meno, da 1 centesimo alla cifra proposta, con la frase delle conseguenze sotto la riga cambiata e «Perché cambi le cifre»,
 * obbligatorio se una cifra cambia; il pulsante dice la cifra che si registra. Al server vanno le cifre scritte, in euro, e il perché: i centesimi li fa lui.
 *
 * Decisione 511: accanto alla cifra di ogni riga il tasso usato («€ 0,49 · 1,60 %», «1,60 % → 2,00 %» se nel periodo cambia). Decisione 512: «Cambia» con
 * «Cifra» o «Tasso»; con il tasso la finestra rifà la riga sugli stessi giorni e tratti e mostra la cifra, solo in meno; al server va il tasso scritto, e la
 * cifra la rifà lui. L'etichetta «ereditato» è un badge `rounded-md` (decisione 399).
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Info } from 'lucide-vue-next';
import vSelect from 'vue-select';
import { frasePerErrore } from '@/lib/gestionale/incassi/ricevute';
import { euroTesto, giorno } from '@/lib/gestionale/debiti/rateScadute';
import {
    FRASE_CALCOLO, FRASE_PROPOSTA_INTERESSI, euroEsatti, testoAccanto, testoCausa, testoFinoAllAccordo, testoGiaRegistrati, testoTassi, testoTratto, totaleTenuto,
    type PersonaInteressi, type RigaInteressi,
} from '@/lib/gestionale/addebiti/accessori';
import {
    centesimiScritti, fraseDelTasso, fraseDelleConseguenze, interessiAlTasso, perchéDelMotivo, perchéNonVa, perchéNonVaIlTasso, tassoScritto,
} from '@/lib/gestionale/addebiti/interessiDegliEredi';
import { Textarea } from '@/components/ui/textarea';

interface GestioneDellaProposta {
    gestione_id: number; gestione: string; scrive: { gestione_id: number; esercizio_id: number; nome: string; piano_conto_id?: number | null } | null; perche: string | null;
    voci: Array<{ id: number; nome: string }>; voce_proposta: number | null;
}
interface Proposta {
    alla: string; persone: PersonaInteressi[]; totale: number; gestioni: GestioneDellaProposta[];
    regole: { righe: unknown[]; di_oggi: string | null; avviso: string | null }; frase_domanda_giudiziale: string;
    /** I tassi legali scritti a mano dallo studio che la proposta usa, con chi, quando e la fonte. */
    tassi_a_mano?: string[];
}

const props = defineProps<{
    open: boolean; condominioId: number; oggi: string;
    /** Le persone scelte nell'elenco; o tutte quelle dei filtri, con i filtri come li legge il server. */
    persone: number[] | null; filtri?: Record<string, unknown>;
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'fatto'): void }>();

const { generateRoute } = usePermission();
const alla = ref(props.oggi);
const proposta = ref<Proposta | null>(null);
const tenute = ref<Set<string>>(new Set());
const voci = ref<Record<number, number | null>>({});
const aperte = ref<Set<string>>(new Set());
const errore = ref<string | null>(null);
const esito = ref<string | null>(null);
const inCorso = ref(false);
let numeroLettura = 0;

async function leggi() {
    const numero = ++numeroLettura;
    errore.value = null;
    esito.value = null;
    const params: Record<string, unknown> = props.persone !== null ? { persone: props.persone, alla: alla.value } : { tutte: 1, filtri: props.filtri ?? {}, alla: alla.value };
    try {
        const r = (await axios.get(route(generateRoute('gestionale.addebiti.interessi.proposta'), { condominio: props.condominioId }), { params })).data as Proposta;
        if (numero !== numeroLettura) {
            return;
        }
        proposta.value = r;
        cambiate.value = {};
        modi.value = {};
        tassiScritti.value = {};
        motivo.value = '';
        tenute.value = new Set(r.persone.flatMap((p) => p.righe.filter((x) => x.fermo === null && x.interessi > 0).map((x) => x.chiave)));
        voci.value = Object.fromEntries(r.gestioni.map((g) => [g.gestione_id, g.voce_proposta]));
    } catch (e) {
        if (numero === numeroLettura) {
            errore.value = frasePerErrore(e, 'La proposta non si è caricata. Riprova.');
        }
    }
}

watch(() => props.open, (aperta) => {
    if (aperta) {
        alla.value = props.oggi;
        proposta.value = null;
        leggi();
    }
}, { immediate: true });

const alterna = (chiave: string) => {
    const s = new Set(tenute.value);
    s.has(chiave) ? s.delete(chiave) : s.add(chiave);
    tenute.value = s;
};
const togliPersona = (p: PersonaInteressi) => {
    const s = new Set(tenute.value);
    p.righe.forEach((r) => s.delete(r.chiave));
    tenute.value = s;
};
/** Le righe di una persona che si possono registrare: con gli interessi e non ferme. */
const registrabili = (p: PersonaInteressi) => p.righe.filter((r) => r.fermo === null && r.interessi > 0);
/** Una persona tolta dalla registrazione (Coda 678, 4): ha righe da registrare e nessuna è tenuta. La proposta la mostra spenta, con il modo di rimetterla. */
const esclusa = (p: PersonaInteressi) => registrabili(p).length > 0 && !registrabili(p).some((r) => tenute.value.has(r.chiave));
const rimettiPersona = (p: PersonaInteressi) => {
    const s = new Set(tenute.value);
    registrabili(p).forEach((r) => s.add(r.chiave));
    tenute.value = s;
};
const apriChiudi = (chiave: string) => {
    const s = new Set(aperte.value);
    s.has(chiave) ? s.delete(chiave) : s.add(chiave);
    aperte.value = s;
};

const totale = computed(() => totaleTenuto(proposta.value?.persone ?? [], tenute.value));

// Decisione 493 (10): «Cambia» su una riga: la cifra scritta, in euro, di serie quella proposta; solo in meno, con il perché. Decisione 512: o il tasso, di
// serie quello delle regole (il più basso dei tratti), e la cifra la rifà il conto della riga sugli stessi giorni.
const cambiate = ref<Record<string, string>>({});
const modi = ref<Record<string, 'cifra' | 'tasso'>>({});
const tassiScritti = ref<Record<string, string>>({});
const motivo = ref('');
const euroDaScrivere = (c: number) => (c / 100).toFixed(2).replace('.', ',');
const tassoDaScrivere = (e4: number) => (e4 / 10000).toFixed(e4 % 100 === 0 ? 2 : 4).replace('.', ',');
const apriCambia = (r: RigaInteressi) => {
    cambiate.value = { ...cambiate.value, [r.chiave]: euroDaScrivere(r.interessi) };
    modi.value = { ...modi.value, [r.chiave]: 'cifra' };
    // Di serie il tasso delle regole, se è uno solo nei giorni della riga: niente è cambiato finché non lo cambi. Con più tassi il campo è vuoto.
    const tassi = [...new Set(r.tratti.map((t) => t.tasso_e4 ?? 0))];
    tassiScritti.value = { ...tassiScritti.value, [r.chiave]: tassi.length === 1 && tassi[0] > 0 ? tassoDaScrivere(tassi[0]) : '' };
};
const lasciaLaCifra = (r: RigaInteressi) => {
    for (const mappa of [cambiate, modi, tassiScritti]) {
        const c = { ...mappa.value } as Record<string, unknown>;
        delete c[r.chiave];
        (mappa as { value: Record<string, unknown> }).value = c;
    }
};
const scegliModo = (r: RigaInteressi, modo: 'cifra' | 'tasso') => {
    modi.value = { ...modi.value, [r.chiave]: modo };
};
const conTasso = (chiave: string) => modi.value[chiave] === 'tasso';
/** La «i» accanto a «Cifra» e «Tasso», con un caso. */
const ESEMPIO_CAMBIA = 'Per esempio: la riga propone € 0,49 all\'1,60 %. Con «Cifra» scrivi quanto registrare, per esempio 0,40; con «Tasso» scrivi 1,00 e il '
    + 'programma rifà il conto sugli stessi giorni: € 0,31. Sempre solo in meno, con il perché.';
const rigaDi = (chiave: string): RigaInteressi | undefined => (proposta.value?.persone ?? []).flatMap((p) => p.righe).find((r) => r.chiave === chiave);
/** La cifra che il tasso scritto dà sugli stessi giorni e tratti; null se il tasso non va. */
const cifraDalTasso = (r: RigaInteressi): number | null => {
    const testo = tassiScritti.value[r.chiave] ?? '';
    const e4 = tassoScritto(testo);

    return e4 !== null && perchéNonVaIlTasso(testo, r.tratti) === null ? interessiAlTasso(r.tratti, e4) : null;
};
/** Le righe tenute con una cifra davvero cambiata (valida e più bassa della proposta), con la cifra o con il tasso: chiave => [proposti, registrati]. */
const cifreCambiate = computed(() => {
    const esito: Record<string, [number, number]> = {};
    for (const [chiave, testo] of Object.entries(cambiate.value)) {
        const r = rigaDi(chiave);
        if (!r || !tenute.value.has(chiave)) {
            continue;
        }
        const c = conTasso(chiave) ? cifraDalTasso(r) : (perchéNonVa(testo, r.interessi) === null ? centesimiScritti(testo) : null);
        if (c !== null && c < r.interessi) {
            esito[chiave] = [r.interessi, c];
        }
    }

    return esito;
});
const erroriDelleCifre = computed(() => Object.entries(cambiate.value)
    .filter(([chiave]) => tenute.value.has(chiave))
    .map(([chiave, testo]) => [chiave, conTasso(chiave)
        // Il campo del tasso vuoto: niente è cambiato, e niente da dire.
        ? ((tassiScritti.value[chiave] ?? '').trim() === '' ? null : perchéNonVaIlTasso(tassiScritti.value[chiave] ?? '', rigaDi(chiave)?.tratti ?? []))
        : perchéNonVa(testo, rigaDi(chiave)?.interessi ?? 0)] as const)
    .filter(([, e]) => e !== null));
const erroreDellaCifra = (chiave: string) => erroriDelleCifre.value.find(([k]) => k === chiave)?.[1] ?? null;
const erroreDelMotivo = computed(() => perchéDelMotivo(motivo.value, Object.keys(cifreCambiate.value).length));
/** La cifra che si registra: le cifre cambiate al posto di quelle proposte. */
const totaleDaRegistrare = computed(() => totale.value - Object.values(cifreCambiate.value).reduce((s, [p, c]) => s + (p - c), 0));
/** Le gestioni delle righe tenute: per ognuna serve la voce. */
const gestioniTenute = computed(() => {
    const ids = new Set((proposta.value?.persone ?? []).flatMap((p) => p.righe.filter((r) => tenute.value.has(r.chiave)).map((r) => r.gestione_id)));

    return (proposta.value?.gestioni ?? []).filter((g) => ids.has(g.gestione_id));
});
/**
 * Le gestioni delle righe tenute che non hanno una voce di entrata che riceva gli interessi (Coda 678, 3): senza, «Registra» non si può premere. Lo si dice in testa
 * alla proposta e accanto al pulsante, con il posto dove la voce si crea: il piano dei conti dell'esercizio della gestione.
 */
const senzaVoce = computed(() => gestioniTenute.value.filter((g) => g.scrive !== null && !g.voci.length));
// Il piano dei conti della gestione dove si scrive, non l'elenco dei piani; senza il piano, l'elenco.
const indirizzoPianoDeiConti = (g: GestioneDellaProposta) => g.scrive?.piano_conto_id
    ? route(generateRoute('gestionale.esercizi.piani-conti.show'), { condominio: props.condominioId, esercizio: g.scrive.esercizio_id, pianoConto: g.scrive.piano_conto_id })
    : route(generateRoute('gestionale.esercizi.piani-conti.index'), { condominio: props.condominioId, esercizio: g.scrive?.esercizio_id });
const pronto = computed(() => totale.value > 0 && gestioniTenute.value.every((g) => g.scrive !== null && voci.value[g.gestione_id])
    && erroriDelleCifre.value.length === 0 && erroreDelMotivo.value === null);
const indirizzoRegole = computed(() => route(generateRoute('gestionale.recupero-crediti.index'), { condominio: props.condominioId }));

async function registra() {
    if (!pronto.value || !proposta.value) {
        return;
    }
    errore.value = null;
    inCorso.value = true;
    try {
        const r = (await axios.post(route(generateRoute('gestionale.addebiti.interessi.store'), { condominio: props.condominioId }), {
            alla: proposta.value.alla, righe: [...tenute.value], atteso: totale.value,
            voci: Object.fromEntries(gestioniTenute.value.map((g) => [g.gestione_id, voci.value[g.gestione_id]])),
            ...(Object.keys(cifreCambiate.value).length ? {
                importi: Object.fromEntries(Object.keys(cifreCambiate.value).filter((k) => !conTasso(k)).map((k) => [k, cambiate.value[k]])),
                tassi: Object.fromEntries(Object.keys(cifreCambiate.value).filter((k) => conTasso(k)).map((k) => [k, tassiScritti.value[k]])),
                motivo: motivo.value.trim(),
            } : {}),
        })).data;
        esito.value = r.messaggio;
        emit('fatto');
        await leggi();
        esito.value = r.messaggio;
    } catch (e) {
        errore.value = frasePerErrore(e, 'Gli interessi non sono stati registrati. Riprova.');
    } finally {
        inCorso.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <!-- All'apertura il cursore non va in un menu, che prendendolo si aprirebbe da sé. -->
        <DialogContent class="sm:max-w-4xl max-h-[90vh] overflow-y-auto" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>Proponi gli interessi di mora</DialogTitle>
                <DialogDescription>{{ FRASE_PROPOSTA_INTERESSI }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-3 text-sm">
                <div class="flex flex-wrap items-end gap-3 text-xs">
                    <label class="space-y-1"><span class="block text-slate-500">Alla data</span>
                        <Input v-model="alla" type="date" :max="oggi" class="h-8 w-[150px] text-xs" data-testid="alla" /></label>
                    <Button size="sm" variant="outline" class="h-8" data-testid="rileggi" @click="leggi">Rifai la proposta</Button>
                    <Link :href="indirizzoRegole" class="underline text-slate-600 dark:text-slate-300" data-testid="regole">Le regole di questo condominio</Link>
                </div>

                <template v-if="proposta">
                    <p class="text-xs text-slate-600 dark:text-slate-300" data-testid="domanda-giudiziale">{{ proposta.frase_domanda_giudiziale }}</p>
                    <p v-if="proposta.regole.di_oggi" class="text-xs text-slate-500">{{ proposta.regole.di_oggi }}</p>
                    <p v-if="proposta.regole.avviso" class="text-xs text-amber-800 dark:text-amber-300" data-testid="avviso-fonte">{{ proposta.regole.avviso }}</p>
                    <p v-for="f in proposta.tassi_a_mano ?? []" :key="f" class="text-xs text-slate-600 dark:text-slate-300" data-testid="tasso-a-mano">{{ f }}</p>
                    <div v-for="g in senzaVoce" :key="g.gestione_id" class="rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200" data-testid="manca-la-voce">
                        <p>Per «{{ g.scrive?.nome }}» non c'è una voce di entrata che riceva gli interessi: senza non si possono registrare. Creala nel piano dei conti, come voce di entrata con il preventivo a zero, e poi rifai la proposta.</p>
                        <Link :href="indirizzoPianoDeiConti(g)" class="mt-1 inline-block underline" data-testid="vai-al-piano-dei-conti">Apri il piano dei conti di «{{ g.scrive?.nome }}»</Link>
                    </div>
                    <p v-if="!proposta.persone.length" class="text-xs text-slate-500" data-testid="nessuna-proposta">Alla data non ci sono interessi da proporre per queste persone.</p>

                    <div v-for="p in proposta.persone" :key="p.anagrafica_id" class="rounded-lg border p-2 space-y-1 text-xs" data-testid="persona-interessi">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <strong>{{ p.nome }}</strong>
                            <span class="flex items-center gap-2">
                                <span v-if="esclusa(p)" class="rounded-md bg-slate-100 px-1.5 py-0.5 text-slate-600 dark:bg-slate-800 dark:text-slate-300" data-testid="persona-esclusa">Esclusa dalla registrazione</span>
                                <span class="tabular-nums" :class="{ 'line-through text-slate-500': esclusa(p) }">{{ euroTesto(p.totale) }}</span>
                                <Button v-if="p.righe.some((r) => tenute.has(r.chiave))" size="sm" variant="ghost" class="h-6 text-xs" data-testid="togli-persona" @click="togliPersona(p)">Togli la persona</Button>
                                <Button v-else-if="esclusa(p)" size="sm" variant="outline" class="h-6 text-xs" data-testid="rimetti-persona" @click="rimettiPersona(p)">Rimetti la persona</Button>
                            </span>
                        </div>
                        <p v-for="f in testoAccanto(p)" :key="f" class="text-slate-500" data-testid="accanto">{{ f }}</p>
                        <p v-if="p.dopo_il_decesso" class="text-slate-600 dark:text-slate-300" data-testid="dopo-il-decesso">{{ p.dopo_il_decesso }}</p>
                        <div v-for="r in p.righe" :key="r.chiave" class="pl-2 border-l space-y-0.5" :class="{ 'opacity-60': esclusa(p) }" data-testid="riga-interessi">
                            <div class="flex flex-wrap items-center gap-2">
                                <!-- Una riga a zero porta solo la frase del riallineo: si legge, non si registra. -->
                                <Checkbox v-if="r.interessi > 0 || r.fermo !== null" :model-value="tenute.has(r.chiave)" :disabled="r.fermo !== null" @update:model-value="alterna(r.chiave)" />
                                <span v-else class="w-4 shrink-0" aria-hidden="true" />
                                <span class="flex-1 min-w-0">
                                    <Badge v-if="r.ereditata" variant="outline" class="mr-1 h-5 border-violet-200 bg-violet-50 px-1.5 text-xs font-medium text-violet-800 dark:border-violet-800 dark:bg-violet-950 dark:text-violet-200" data-testid="badge-ereditata">ereditato</Badge>
                                    {{ r.rata }}<template v-if="r.unita"> · {{ r.unita }}</template> · scaduta il {{ giorno(r.scadenza) }}</span>
                                <span class="tabular-nums font-medium whitespace-nowrap" :class="{ 'line-through text-slate-500': cifreCambiate[r.chiave] }" data-testid="cifra-riga">{{ r.fermo ? '—' : euroTesto(r.interessi) }}<template v-if="!r.fermo && testoTassi(r)"> · <span data-testid="tasso-riga">{{ testoTassi(r) }}</span></template></span>
                                <template v-if="r.interessi > 0 && r.fermo === null && tenute.has(r.chiave)">
                                    <span v-if="cambiate[r.chiave] !== undefined" class="flex flex-wrap items-center gap-1">
                                        <!-- Decisione 512: «Cifra» o «Tasso». -->
                                        <span class="inline-flex overflow-hidden rounded-md border border-slate-200 dark:border-slate-700" role="group" :aria-label="`Che cosa cambi per ${r.rata}`">
                                            <button type="button" class="px-2 py-0.5" :class="!conTasso(r.chiave) ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-slate-600 dark:text-slate-300'"
                                                :aria-pressed="!conTasso(r.chiave)" data-testid="modo-cifra" @click="scegliModo(r, 'cifra')">Cifra</button>
                                            <button type="button" class="px-2 py-0.5" :class="conTasso(r.chiave) ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-slate-600 dark:text-slate-300'"
                                                :aria-pressed="conTasso(r.chiave)" data-testid="modo-tasso" @click="scegliModo(r, 'tasso')">Tasso</button>
                                        </span>
                                        <TooltipProvider :delay-duration="200">
                                            <Tooltip>
                                                <TooltipTrigger as-child>
                                                    <span tabindex="0" role="img" :aria-label="ESEMPIO_CAMBIA" data-testid="info-cambia"
                                                        class="inline-flex items-center text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300 cursor-help rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                                                        <Info class="w-3.5 h-3.5" />
                                                    </span>
                                                </TooltipTrigger>
                                                <TooltipContent class="max-w-80 text-xs leading-snug"><p>{{ ESEMPIO_CAMBIA }}</p></TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                        <Input v-if="!conTasso(r.chiave)" v-model="cambiate[r.chiave]" inputmode="decimal" class="h-6 w-[80px] text-xs" :aria-label="`La cifra da registrare per ${r.rata}`" data-testid="cifra-cambiata" />
                                        <span v-else class="flex items-center gap-1">
                                            <Input v-model="tassiScritti[r.chiave]" inputmode="decimal" class="h-6 w-[70px] text-xs" :aria-label="`Il tasso da usare per ${r.rata}`" data-testid="tasso-cambiato" />
                                            <span class="text-slate-500">%</span>
                                        </span>
                                        <button type="button" class="underline text-slate-500" data-testid="lascia-la-cifra" @click="lasciaLaCifra(r)">Lascia</button>
                                    </span>
                                    <button v-else type="button" class="underline text-slate-500" data-testid="cambia" @click="apriCambia(r)">Cambia</button>
                                </template>
                                <button v-if="r.tratti.length" type="button" class="underline text-slate-500" data-testid="apri-tratti" @click="apriChiudi(r.chiave)">{{ aperte.has(r.chiave) ? 'Nascondi i tratti' : 'I tratti' }}</button>
                            </div>
                            <p v-if="r.fermo" class="text-amber-800 dark:text-amber-300" data-testid="fermo">{{ r.fermo }}</p>
                            <p v-if="r.frase_ereditata" class="text-slate-600 dark:text-slate-300" data-testid="frase-ereditata">{{ r.frase_ereditata }}</p>
                            <p v-if="r.fino_al_decesso" class="text-slate-500" data-testid="fino-al-decesso">{{ r.fino_al_decesso }}</p>
                            <p v-if="erroreDellaCifra(r.chiave)" class="text-red-700 dark:text-red-400" data-testid="errore-cifra">{{ erroreDellaCifra(r.chiave) }}</p>
                            <template v-else-if="cifreCambiate[r.chiave]">
                                <p v-if="conTasso(r.chiave)" class="text-slate-700 dark:text-slate-200" data-testid="cifra-dal-tasso">{{ fraseDelTasso(tassoScritto(tassiScritti[r.chiave] ?? '') ?? 0, cifreCambiate[r.chiave][1]) }}</p>
                                <p class="text-amber-800 dark:text-amber-300" data-testid="conseguenze">{{ fraseDelleConseguenze(r.al ?? proposta.alla, cifreCambiate[r.chiave][0], cifreCambiate[r.chiave][1]) }}</p>
                            </template>
                            <p v-if="testoGiaRegistrati(r.gia_registrati_al)" class="text-slate-500">{{ testoGiaRegistrati(r.gia_registrati_al) }}</p>
                            <p v-if="testoFinoAllAccordo(r)" class="text-slate-500" data-testid="fino-all-accordo">{{ testoFinoAllAccordo(r) }}</p>
                            <p v-if="r.riallineo" class="text-amber-800 dark:text-amber-300" data-testid="riallineo">{{ r.riallineo }}</p>
                            <ul v-if="aperte.has(r.chiave)" class="pl-6 list-disc text-slate-600 dark:text-slate-300" data-testid="tratti">
                                <li v-for="(t, i) in r.tratti" :key="i">{{ testoTratto(t) }}: {{ euroEsatti(t.esatti) }} <span class="text-slate-500">({{ testoCausa(t.causa) }})</span></li>
                            </ul>
                        </div>
                    </div>

                    <div v-if="gestioniTenute.length" class="space-y-1 text-xs" data-testid="voci">
                        <div v-for="g in gestioniTenute" :key="g.gestione_id" class="flex flex-wrap items-center gap-2">
                            <span class="text-slate-500">{{ g.scrive ? `Si scrivono in ${g.scrive.nome}, sulla voce` : g.perche }}</span>
                            <div v-if="g.scrive" class="menu-campo w-[240px]" :data-testid="`voce-${g.gestione_id}`">
                                <v-select :model-value="voci[g.gestione_id] ?? null" :options="g.voci" label="nome" :reduce="(v: { id: number }) => v.id" :searchable="false"
                                    :clearable="false" placeholder="Scegli la voce di entrata" class="w-full" @update:model-value="(v: number) => (voci[g.gestione_id] = v)">
                                    <template #no-options>Nessuna voce di entrata.</template>
                                </v-select>
                            </div>
                        </div>
                    </div>
                    <div v-if="Object.keys(cambiate).length" class="space-y-1 text-xs" data-testid="perche-cambi">
                        <label for="perche-cambi-le-cifre" class="block font-medium text-slate-700 dark:text-slate-300">Perché cambi le cifre</label>
                        <Textarea id="perche-cambi-le-cifre" v-model="motivo" rows="2" maxlength="255" class="text-xs" placeholder="Per esempio: accordo con l'erede del 05/01/2027" />
                        <p v-if="erroreDelMotivo" class="text-red-700 dark:text-red-400" data-testid="errore-motivo">{{ erroreDelMotivo }}</p>
                        <p v-else class="text-slate-500">Resta scritto nell'addebito, con le due cifre, il tasso se l'hai cambiato, chi l'ha registrato e quando.</p>
                    </div>
                    <p class="text-[11px] text-slate-500">{{ FRASE_CALCOLO }}</p>

                    <div class="flex flex-wrap items-center gap-3">
                        <Button size="sm" :disabled="inCorso || !pronto" data-testid="registra-interessi" @click="registra">Registra {{ euroTesto(totaleDaRegistrare) }}</Button>
                        <span v-if="senzaVoce.length && totale > 0" class="text-xs text-amber-800 dark:text-amber-300" data-testid="perche-spento">Il pulsante è spento: manca una voce di entrata per {{ senzaVoce.map((g) => `«${g.scrive?.nome}»`).join(', ') }}. Creala nel piano dei conti.</span>
                        <span v-else class="text-xs text-slate-500">Una scadenza per persona e unità, «Interessi di mora al {{ giorno(proposta.alla) }}»; con un tasso scritto, anche il tasso e il suo riferimento.</span>
                    </div>
                </template>
                <p v-if="esito" class="text-xs text-emerald-800 dark:text-emerald-300" data-testid="esito">{{ esito }}</p>
                <p v-if="errore" class="text-xs text-red-700 dark:text-red-400" data-testid="errore">{{ errore }}</p>
            </div>
        </DialogContent>
    </Dialog>
</template>
