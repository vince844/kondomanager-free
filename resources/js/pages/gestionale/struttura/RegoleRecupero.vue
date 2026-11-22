<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Le regole del recupero dei crediti del condominio, nella sua scheda: la regola di oggi, la storia delle regole, una regola nuova dal giorno in cui
 * comincia, l'eliminazione di una regola che nessun interesse registrato ha usato. Una regola non si riscrive: per cambiarla se ne scrive una nuova, e
 * la vecchia resta per i giorni in cui valeva. Senza nessuna regola vale la legge.
 *
 * La regola nuova: la data nel formato del programma, il tasso come due schede da scegliere (nessuna già scelta), i tre giorni con la spiegazione sotto
 * la casella, il pulsante in fondo che dice che cosa manca finché è spento. Un tasso scritto vuole la fonte e il riferimento, con il suggerimento
 * nell'icona accanto al campo: il riferimento si stampa accanto al tasso. Una regola scritta prima senza riferimento resta, e la storia la segna. La
 * tabella del tasso legale dice l'anno che manca, il tasso scritto a mano dallo studio con chi, quando e la fonte, e la cifra del programma che vince su
 * quella scritta a mano; il tasso di un anno che manca lo scrive, lo corregge e lo toglie chi gestisce le impostazioni generali
 * (`TassoLegaleAManoDialog.vue`). Il pulsante «Guida» dell'intestazione apre la guida della pagina nel cassetto laterale.
 *
 * Decisioni 493 e 494 (1): la sezione «Gli interessi degli eredi», le otto scelte del condominio per il debito ereditato con la successione, ognuna con la
 * riga «Adesso: …» e la «i» con il caso; le salva chi modifica il condominio, gli altri le leggono. Valgono per ogni successione, salvo quella che le cambia.
 *
 * Coda 736: «Da portare in assemblea» nella card «Oggi», per chi gestisce le assemblee: il memo nasce dalla regola di oggi (il punto dei criteri per gli
 * interessi e le spese, e dopo il voto «Scrivi la regola»); senza regole, con lo stesso testo scritto.
 */
import { computed, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import TassoLegaleAManoDialog from '@/components/gestionale/addebiti/TassoLegaleAManoDialog.vue';
import RegoleRecuperoGuide from '@/components/guides/RegoleRecuperoGuide.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { usePermission } from '@/composables/permissions';
import { AlertTriangle, CalendarClock, History, Info, Percent } from 'lucide-vue-next';
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import { giorno } from '@/lib/gestionale/debiti/rateScadute';
import { SUGGERIMENTO_RIFERIMENTO, cheCosaMancaAllaRegola, testoDellaRegola, testoScrittoAMano, type TassoLegaleAMano } from '@/lib/gestionale/addebiti/accessori';
import InteressiDegliEredi from '@/components/gestionale/addebiti/InteressiDegliEredi.vue';
import { scelteDalleVoci, scelteDiverse, type SceltaDegliEredi, type VoceInteressiEredi } from '@/lib/gestionale/addebiti/interessiDegliEredi';
import type { Building } from '@/types/buildings';
import type { Flash } from '@/types/flash';
import ScegliLaDelibera from '@/components/assemblee/ScegliLaDelibera.vue';
import PulsanteDaPortareInAssemblea from '@/components/assemblee/PulsanteDaPortareInAssemblea.vue';
import EsitiDellaScheda from '@/components/assemblee/seguito/EsitiDellaScheda.vue';
import { gestisceLeAssemblee } from '@/lib/assemblee/permessi';
import type { DeliberaDelMenu } from '@/types/assemblee';

interface Regola {
    id: number; dal: string; tasso_tipo: 'legale' | 'scritto'; tasso: string; fonte: string | null; riferimento: string | null; giorni_decorrenza: number;
    giorni_tolleranza: number | null; termine_lettere_giorni: number | null; avviso: string | null; creata_da: string | null; usata: string | null;
}

const props = defineProps<{
    condominio: Building; condomini: Building[]; esercizio: any;
    regole: Regola[]; di_oggi: number | null; tasso_di_oggi: string | null;
    /** Gli anni del programma, dal più recente; `a_mano_diverso` quando lo studio aveva scritto a mano un'altra cifra per lo stesso anno. */
    tabella_legale: Array<{ anno: number; tasso: string; a_mano_diverso: string | null }>;
    /** I tassi scritti a mano dallo studio per gli anni dopo quelli del programma, dal più recente. */
    legali_a_mano: TassoLegaleAMano[];
    /** Gli anni che mancano, dal più recente, con la frase. */
    anni_mancanti: Array<{ anno: number; frase: string }>;
    anni_da_scrivere: number[]; puo_scrivere_tasso_legale: boolean;
    fonti: Array<{ valore: string; nome: string }>; frase_senza_regole: string; avvisi_fonti: Record<string, string>; oggi: string;
    /** I caratteri del riferimento di un tasso scritto che il server accetta. */
    riferimento_massimo?: number;
    /** Decisione 494 (1): le otto scelte sugli interessi degli eredi del condominio, e chi le può cambiare. */
    interessi_eredi?: { descrizione: string; voci: VoceInteressiEredi[] };
    puo_modificare_eredi?: boolean;
}>();

// Le scelte degli eredi nel modulo: quelle del condominio finché l'amministratore non le cambia; «Salva» si accende solo con una scelta diversa.
const scelteEredi = ref<Record<SceltaDegliEredi, boolean>>(scelteDalleVoci(props.interessi_eredi?.voci ?? []));
watch(() => props.interessi_eredi?.voci, (v) => { scelteEredi.value = scelteDalleVoci(v ?? []); });
const erediCambiate = computed(() => scelteDiverse(scelteEredi.value, scelteDalleVoci(props.interessi_eredi?.voci ?? [])));
function salvaEredi() {
    router.post(route(generateRoute('gestionale.recupero-crediti.interessi-eredi'), { condominio: props.condominio.id }), { scelte: scelteEredi.value },
        { preserveScroll: true, preserveState: true, only: ['interessi_eredi', 'flash', 'errors'] });
}

const permessi = usePermission();
const { generateRoute, generatePath } = permessi;
// Un tasso dalla delibera dell'assemblea: con «Gestisce le assemblee» si sceglie la delibera, e il riferimento è la sua frase (§1.4 del progetto della tappa 2).
const conLaDelibera = computed(() => gestisceLeAssemblee(permessi));
const delibera = ref<DeliberaDelMenu | null>(null);
/** Il punto dei criteri del recupero, lo stesso della scheda della regola (`PropostaDelPunto`): per «Scegli la delibera» e per il memo senza regole. */
const CRITERI = 'Criteri per l\'addebito degli interessi e delle spese di recupero dei crediti';
watch(delibera, (d) => { nuova.value.riferimento = d ? d.frase : ''; });
const page = usePage<{ flash?: { message?: Flash }; errors?: Record<string, unknown> }>();
const flashMessage = computed(() => page.props.flash?.message);
/** Gli errori della regola nuova; quelli del tasso legale arrivano nel loro gruppo, `tassoLegale`. */
const errori = computed(() => Object.fromEntries(Object.entries(page.props.errors ?? {}).filter(([, m]) => typeof m === 'string')) as Record<string, string>);
const erroriTasso = computed(() => Object.values((page.props.errors?.tassoLegale ?? {}) as Record<string, string>));

const vuota = () => ({ dal: '' as string | null, tasso_tipo: '' as '' | 'legale' | 'scritto', tasso_annuo: '', fonte: '', riferimento: '', giorni_decorrenza: '0', giorni_tolleranza: '', termine_lettere_giorni: '' });
const nuova = ref(vuota());
const manca = computed(() => cheCosaMancaAllaRegola(nuova.value));

const SCHEDE_TASSO = [
    { valore: 'legale' as const, titolo: 'Il tasso legale dell\'anno', riga: 'Cambia ogni 1° gennaio. Senza una regola vale questo.' },
    { valore: 'scritto' as const, titolo: 'Un tasso scritto, con la sua fonte', riga: 'Dal regolamento contrattuale o da un voto all\'unanimità.' },
];
const CASELLE_GIORNI = [
    { campo: 'giorni_decorrenza' as const, etichetta: 'Interessi dopo la scadenza', spiegazione: '0: dal giorno dopo la scadenza', min: 0, testid: 'decorrenza' },
    { campo: 'giorni_tolleranza' as const, etichetta: 'Tolleranza prima del sollecito', spiegazione: 'Vuoto: nessun suggerimento', min: 0, testid: 'tolleranza' },
    { campo: 'termine_lettere_giorni' as const, etichetta: 'Termine scritto nelle lettere', spiegazione: 'Vuoto: quello che scegli nella finestra', min: 1, testid: 'termine' },
];
const schedaScelta = 'border-primary bg-primary/5 ring-1 ring-primary';
const schedaLibera = 'border-slate-200 bg-white hover:border-slate-300 dark:border-slate-700 dark:bg-slate-900 dark:hover:border-slate-600';

/** La data scritta e confermata con Invio o Tab: il calendario aperto sotto il campo si chiude. */
const dpDal = ref<any>(null);
function chiudiCalendario() {
    setTimeout(() => dpDal.value?.closeMenu?.(), 60);
}

function registra() {
    if (manca.value !== null) {
        return;
    }
    const n = nuova.value;
    router.post(route(generateRoute('gestionale.recupero-crediti.store'), { condominio: props.condominio.id }), {
        dal: n.dal, tasso_tipo: n.tasso_tipo, tasso_annuo: n.tasso_tipo === 'scritto' ? n.tasso_annuo : null, fonte: n.tasso_tipo === 'scritto' ? n.fonte : null,
        riferimento: n.riferimento || null, giorni_decorrenza: n.giorni_decorrenza === '' ? 0 : Number(n.giorni_decorrenza),
        giorni_tolleranza: n.giorni_tolleranza === '' ? null : Number(n.giorni_tolleranza), termine_lettere_giorni: n.termine_lettere_giorni === '' ? null : Number(n.termine_lettere_giorni),
        ...(conDelibera.value ? { delibera_id: delibera.value!.id } : {}),
    }, { preserveScroll: true, onSuccess: () => { nuova.value = vuota(); delibera.value = null; } });
}
/** La delibera parte solo per un tasso scritto con la fonte «delibera dell'assemblea». */
const conDelibera = computed(() => conLaDelibera.value && nuova.value.tasso_tipo === 'scritto' && nuova.value.fonte === 'delibera' && delibera.value !== null);
function elimina(r: Regola) {
    router.delete(route(generateRoute('gestionale.recupero-crediti.destroy'), { condominio: props.condominio.id, regola: r.id }), { preserveScroll: true });
}

/** Una regola con un tasso scritto registrata senza riferimento, quando non era obbligatorio: resta, e la storia lo dice. */
const senzaRiferimento = (r: Regola) => r.tasso_tipo === 'scritto' && !(r.riferimento ?? '').trim();

// La guida che si apre di lato dal pulsante «Guida» dell'intestazione, come nelle altre pagine.
const mostraGuida = ref(false);

const finestraTasso = ref(false);
const annoTasso = ref<number | null>(null);
function apriTasso(anno: number) {
    annoTasso.value = anno;
    finestraTasso.value = true;
}
function togliTasso(anno: number) {
    router.delete(route(generateRoute('gestionale.recupero-crediti.tasso-legale.destroy'), { condominio: props.condominio.id }),
        { data: { anno }, errorBag: 'tassoLegale', preserveScroll: true });
}


const pageGuides = [
    { title: 'A che cosa servono', description: 'Il tasso degli interessi di mora, i giorni dopo la scadenza da cui decorrono, i giorni di tolleranza prima di sollecitare e il termine di serie delle lettere, come li dice il regolamento del condominio. Li legge la proposta degli interessi, e i suggerimenti dell\'elenco «Rate scadute».', icon: Percent, colorVariant: 'blue' as const },
    { title: 'Senza una regola', description: 'Vale la legge: il tasso legale dell\'anno (artt. 1224, 1282 e 1284 c.c.), dal giorno dopo la scadenza. Un tasso diverso si scrive con la sua fonte: il regolamento, una delibera, altro.', icon: CalendarClock, colorVariant: 'amber' as const },
    { title: 'La storia', description: 'Una regola vale dal suo giorno al giorno prima della regola dopo, e non si riscrive: per cambiarla ne scrivi una nuova. Gli interessi già registrati non cambiano; una regola che nessun interesse ha usato si elimina.', icon: History, colorVariant: 'emerald' as const },
];

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Struttura', href: generatePath('gestionale/:condominio/struttura', { condominio: props.condominio.id }) },
    { title: 'Recupero dei crediti' },
]);
</script>

<template>
    <Head title="Recupero dei crediti" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-4">
            <PageHeaderGuide
                :storia="{ tipo: 'regole_recupero', id: props.condominio.id }"
                page-title="Recupero dei crediti"
                page-subtitle="Le regole del condominio per gli interessi di mora e per i solleciti, con la loro storia."
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :video-url="null"
                :condominio="props.condominio"
                :condomini="props.condomini"
                has-text-guide
                text-guide-title="Guida"
                @open-text-guide="mostraGuida = true"
            />

            <div class="w-full">
                <StrutturaLayout>
                    <div class="container mx-auto p-0 mt-4 space-y-6">
                        <div v-if="flashMessage"><Alert :message="flashMessage.message" :type="flashMessage.type" /></div>

                        <!-- La regola di oggi -->
                        <div class="bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800/80 rounded-xl shadow-sm p-6 lg:p-8" data-testid="di-oggi">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-base font-bold text-slate-900 dark:text-white">Oggi</h3>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">La regola che vale il {{ giorno(oggi) }}.</p>
                                </div>
                                <!-- Coda 736: i criteri del recupero in assemblea, dalla regola di oggi o, senza regole, con il testo scritto. -->
                                <PulsanteDaPortareInAssemblea v-if="conLaDelibera" :condominio-id="condominio.id" :origine-tipo="di_oggi !== null ? 'regola_recupero' : null"
                                    :origine-id="di_oggi" :testo="di_oggi !== null ? null : CRITERI" forma="riga" />
                            </div>
                            <p class="text-[13px] mt-4">
                                <span class="font-bold text-slate-900 dark:text-white">Tasso degli interessi di mora:</span>
                                <span class="text-slate-700 dark:text-slate-300 ml-1">{{ tasso_di_oggi ?? 'il tasso legale dell\'anno non è ancora nel programma' }}</span>
                            </p>
                            <p v-if="di_oggi === null" class="text-[13px] text-slate-600 dark:text-slate-400 mt-1" data-testid="senza-regole">{{ frase_senza_regole }}</p>
                        </div>

                        <!-- La storia -->
                        <div class="bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800/80 rounded-xl shadow-sm overflow-hidden">
                            <div class="p-6 lg:p-8 pb-3">
                                <h3 class="text-base font-bold text-slate-900 dark:text-white">Le regole del condominio</h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Dalla più recente. Ognuna vale fino al giorno prima di quella sopra.</p>
                            </div>
                            <div class="divide-y divide-slate-100 dark:divide-slate-800/60">
                                <div v-for="r in regole" :key="r.id" class="px-6 lg:px-8 py-3 text-[13px] space-y-1" data-testid="regola">
                                    <!-- Gli esiti delle delibere sulla regola: i segni della delibera che è la sua fonte; con «Apri l'assemblea». -->
                                    <EsitiDellaScheda :condominio-id="props.condominio.id" tipo="regola_recupero" :id="r.id" />
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span><span class="font-bold text-slate-900 dark:text-white">Dal {{ giorno(r.dal) }}</span><span v-if="r.id === di_oggi" class="ml-2 text-xs text-emerald-700 dark:text-emerald-400">vale oggi</span></span>
                                        <Button v-if="!r.usata" size="sm" variant="outline" class="h-7 text-xs" data-testid="elimina-regola" @click="elimina(r)">Elimina</Button>
                                        <span v-else class="text-xs text-slate-500">usata per gli interessi registrati al {{ giorno(r.usata) }}: resta</span>
                                    </div>
                                    <p class="text-slate-700 dark:text-slate-300">{{ r.tasso }}<span v-if="senzaRiferimento(r)" class="ml-2 text-xs text-amber-800 dark:text-amber-300" data-testid="senza-riferimento">senza riferimento</span></p>
                                    <p class="text-xs text-slate-500">{{ testoDellaRegola(r) }}<template v-if="r.creata_da"> Scritta da {{ r.creata_da }}.</template></p>
                                    <p v-if="r.avviso" class="text-xs text-amber-800 dark:text-amber-300" data-testid="avviso">{{ r.avviso }}</p>
                                </div>
                                <p v-if="!regole.length" class="px-6 lg:px-8 py-4 text-sm text-slate-500 italic" data-testid="nessuna-regola">Nessuna regola scritta: {{ frase_senza_regole.charAt(0).toLowerCase() + frase_senza_regole.slice(1) }}</p>
                            </div>
                        </div>

                        <!-- Una regola nuova -->
                        <div class="bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800/80 rounded-xl shadow-sm p-6 lg:p-8 text-sm" data-testid="regola-nuova">
                            <div>
                                <h3 class="text-base font-bold text-slate-900 dark:text-white">Scrivi una regola</h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Vale dal giorno che scrivi; le regole di prima restano per i loro giorni.</p>
                            </div>

                            <div class="mt-6 space-y-6">
                                <div class="space-y-1.5 max-w-[220px]">
                                    <Label for="dp-input-regola-dal" class="text-xs font-semibold text-slate-700 dark:text-slate-300" data-testid="etichetta-dal">Da quando vale</Label>
                                    <VueDatePicker ref="dpDal" v-model="nuova.dal" uid="regola-dal" model-type="yyyy-MM-dd" text-input :text-input-options="{ format: 'dd/MM/yyyy' }"
                                        format="dd/MM/yyyy" locale="it" :enable-time-picker="false" auto-apply placeholder="Scegli la data" class="w-full"
                                        @text-submit="chiudiCalendario" />
                                </div>

                                <div class="space-y-2">
                                    <p id="regola-tasso" class="text-xs font-semibold text-slate-700 dark:text-slate-300">Il tasso degli interessi di mora</p>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" role="radiogroup" aria-labelledby="regola-tasso">
                                        <button v-for="s in SCHEDE_TASSO" :key="s.valore" type="button" role="radio" :aria-checked="nuova.tasso_tipo === s.valore"
                                            :data-testid="`scheda-${s.valore}`" class="rounded-lg border px-4 py-3 text-left transition-colors"
                                            :class="nuova.tasso_tipo === s.valore ? schedaScelta : schedaLibera" @click="nuova.tasso_tipo = s.valore">
                                            <span class="block text-sm font-semibold text-slate-900 dark:text-white" data-testid="titolo">{{ s.titolo }}</span>
                                            <span class="block mt-0.5 text-xs leading-snug text-slate-500 dark:text-slate-400" data-testid="riga">{{ s.riga }}</span>
                                        </button>
                                    </div>

                                    <div v-if="nuova.tasso_tipo === 'scritto'" class="rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/40 p-4 space-y-4"
                                        data-testid="tasso-scritto">
                                        <div class="grid grid-cols-1 sm:grid-cols-[160px_1fr] gap-4">
                                            <div class="space-y-1.5">
                                                <Label for="regola-tasso-annuo" class="text-xs font-semibold text-slate-700 dark:text-slate-300">Tasso annuo (%)</Label>
                                                <Input id="regola-tasso-annuo" v-model="nuova.tasso_annuo" inputmode="decimal" class="h-9 w-full text-sm bg-white dark:bg-slate-950" data-testid="tasso" />
                                            </div>
                                            <div class="space-y-1.5">
                                                <p id="regola-fonte" class="text-xs font-semibold text-slate-700 dark:text-slate-300">Da dove viene</p>
                                                <div class="flex flex-wrap gap-2" role="radiogroup" aria-labelledby="regola-fonte">
                                                    <button v-for="f in fonti" :key="f.valore" type="button" role="radio" :aria-checked="nuova.fonte === f.valore" :data-testid="`fonte-${f.valore}`"
                                                        class="h-9 rounded-md border px-3 text-xs font-medium text-slate-700 dark:text-slate-200 transition-colors"
                                                        :class="nuova.fonte === f.valore ? schedaScelta : schedaLibera" @click="nuova.fonte = f.valore">{{ f.nome }}</button>
                                                </div>
                                            </div>
                                        </div>
                                        <div v-if="conLaDelibera && nuova.fonte === 'delibera'" class="space-y-1.5" data-testid="fonte-dalla-delibera">
                                            <ScegliLaDelibera v-model="delibera" :condominio-id="condominio.id" :condominio-nome="condominio.nome" ruolo="fonte"
                                                per-questo="questa regola" :testo-proposto="CRITERI" />
                                            <p class="text-[11px] leading-snug text-slate-500 dark:text-slate-400">Il riferimento è la frase della delibera: si stampa accanto al tasso.</p>
                                        </div>
                                        <div v-else class="space-y-1.5">
                                            <div class="flex items-center gap-1.5">
                                                <Label for="regola-riferimento" class="text-xs font-semibold text-slate-700 dark:text-slate-300">Il riferimento</Label>
                                                <TooltipProvider :delay-duration="200">
                                                    <Tooltip>
                                                        <TooltipTrigger as-child>
                                                            <span tabindex="0" role="img" :aria-label="SUGGERIMENTO_RIFERIMENTO" data-testid="suggerimento-riferimento"
                                                                class="inline-flex items-center text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300 cursor-help rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                                                                <Info class="w-3.5 h-3.5" />
                                                            </span>
                                                        </TooltipTrigger>
                                                        <TooltipContent class="max-w-80 text-xs leading-snug"><p>{{ SUGGERIMENTO_RIFERIMENTO }}</p></TooltipContent>
                                                    </Tooltip>
                                                </TooltipProvider>
                                            </div>
                                            <Input id="regola-riferimento" v-model="nuova.riferimento" :maxlength="riferimento_massimo ?? 150" class="h-9 w-full text-sm bg-white dark:bg-slate-950" data-testid="riferimento" />
                                            <p class="text-[11px] leading-snug text-slate-500 dark:text-slate-400">L'articolo, la data e il verbale: per esempio art. 12 del regolamento.</p>
                                        </div>
                                        <p v-if="avvisi_fonti[nuova.fonte]" class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-snug text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200"
                                            data-testid="avviso-fonte">
                                            <AlertTriangle class="w-4 h-4 shrink-0 text-amber-600" /><span>{{ avvisi_fonti[nuova.fonte] }}</span>
                                        </p>
                                    </div>
                                </div>

                                <div class="space-y-2">
                                    <p class="text-xs font-semibold text-slate-700 dark:text-slate-300">I giorni</p>
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4" data-testid="giorni">
                                        <div v-for="c in CASELLE_GIORNI" :key="c.campo" class="space-y-1.5" data-testid="casella-giorni">
                                            <Label :for="`regola-${c.campo}`" class="text-xs font-semibold text-slate-700 dark:text-slate-300">{{ c.etichetta }}</Label>
                                            <Input :id="`regola-${c.campo}`" v-model="nuova[c.campo]" type="number" :min="c.min" max="365" class="h-9 w-full text-sm" :data-testid="c.testid" />
                                            <p class="text-[11px] leading-snug text-slate-500 dark:text-slate-400" data-testid="spiegazione">{{ c.spiegazione }}</p>
                                        </div>
                                    </div>
                                </div>

                                <ul v-if="Object.keys(errori).length" class="text-xs text-rose-700 dark:text-rose-300 list-disc pl-4" data-testid="errori">
                                    <li v-for="(m, k) in errori" :key="k">{{ m }}</li>
                                </ul>

                                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-slate-100 dark:border-slate-800 pt-5" data-testid="piede">
                                    <span v-if="manca" class="text-xs text-slate-500 dark:text-slate-400" data-testid="cosa-manca">{{ manca }}</span>
                                    <Button :disabled="manca !== null" data-testid="registra-regola" @click="registra">Registra la regola</Button>
                                </div>
                            </div>
                        </div>

                        <!-- Decisione 494 (1): gli interessi degli eredi -->
                        <div v-if="interessi_eredi" class="bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800/80 rounded-xl shadow-sm p-6 lg:p-8" data-testid="sezione-eredi">
                            <h3 class="text-base font-bold text-slate-900 dark:text-white">Gli interessi degli eredi</h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-3xl">{{ interessi_eredi.descrizione }}</p>
                            <div class="mt-3">
                                <InteressiDegliEredi v-model="scelteEredi" :voci="interessi_eredi.voci" :modificabile="puo_modificare_eredi" prefisso="condominio-eredi" />
                            </div>
                            <div v-if="puo_modificare_eredi" class="flex flex-wrap items-center justify-end gap-3 border-t border-slate-100 dark:border-slate-800 pt-4">
                                <span v-if="!erediCambiate" class="text-xs text-slate-500 dark:text-slate-400" data-testid="eredi-niente-da-salvare">Cambia una scelta per salvarla.</span>
                                <Button :disabled="!erediCambiate" data-testid="salva-eredi" @click="salvaEredi">Salva le scelte degli eredi</Button>
                            </div>
                            <p v-else class="mt-3 text-xs text-slate-500 dark:text-slate-400" data-testid="eredi-sola-lettura">Le cambia chi può modificare il condominio.</p>
                        </div>

                        <!-- Il tasso legale -->
                        <div class="bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800/80 rounded-xl shadow-sm p-6 lg:p-8" data-testid="tabella-legale">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <h3 class="text-base font-bold text-slate-900 dark:text-white">Il tasso legale</h3>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-3xl">
                                        Dal 1° gennaio di ogni anno (art. 1284 c.c.), lo stesso per tutti i condomini. Il tasso di un anno nuovo entra nel programma con un
                                        aggiornamento; intanto chi gestisce le impostazioni generali lo può scrivere a mano, con il decreto e la Gazzetta Ufficiale. Se poi il
                                        programma ha una cifra diversa, vale quella del programma. Per un anno senza tasso la proposta degli interessi si ferma.
                                    </p>
                                </div>
                                <Button v-if="puo_scrivere_tasso_legale && anni_mancanti.length" size="sm" variant="outline" data-testid="scrivi-tasso-legale"
                                    @click="apriTasso(anni_mancanti[anni_mancanti.length - 1].anno)">Scrivi il tasso di un anno che manca</Button>
                            </div>

                            <div class="mt-4 divide-y divide-slate-100 dark:divide-slate-800/60 text-[13px]">
                                <div v-for="m in anni_mancanti" :key="`manca-${m.anno}`" class="flex flex-wrap items-baseline gap-x-3 py-2" data-testid="anno-mancante">
                                    <span class="w-12 font-bold text-slate-900 dark:text-white">{{ m.anno }}</span> <span class="italic text-slate-500 dark:text-slate-400">{{ m.frase }}</span>
                                </div>
                                <div v-for="t in legali_a_mano" :key="`mano-${t.anno}`" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2" data-testid="legale-a-mano">
                                    <span class="w-12 font-bold text-slate-900 dark:text-white">{{ t.anno }}</span> <span class="text-slate-700 dark:text-slate-300">{{ t.tasso }}</span> <span
                                        class="min-w-[220px] flex-1 text-xs text-slate-500 dark:text-slate-400">{{ testoScrittoAMano(t) }} · fonte: {{ t.fonte }}</span>
                                    <span v-if="puo_scrivere_tasso_legale" class="flex gap-2">
                                        <Button size="sm" variant="outline" class="h-7 text-xs" data-testid="correggi-tasso-legale" @click="apriTasso(t.anno)">Correggi</Button>
                                        <Button size="sm" variant="outline" class="h-7 text-xs" data-testid="togli-tasso-legale" @click="togliTasso(t.anno)">Togli</Button>
                                    </span>
                                </div>
                                <div class="py-3">
                                    <div class="flex flex-wrap gap-x-6 gap-y-1">
                                        <span v-for="t in tabella_legale" :key="t.anno"><span class="font-bold text-slate-900 dark:text-white">{{ t.anno }}</span> <span class="text-slate-700 dark:text-slate-300">{{ t.tasso }}</span></span>
                                    </div>
                                    <template v-for="t in tabella_legale" :key="`diverso-${t.anno}`">
                                        <p v-if="t.a_mano_diverso" class="mt-2 text-xs text-amber-800 dark:text-amber-300" data-testid="a-mano-diverso">{{ t.a_mano_diverso }}</p>
                                    </template>
                                </div>
                            </div>
                            <ul v-if="erroriTasso.length && !finestraTasso" class="mt-2 text-xs text-rose-700 dark:text-rose-300 list-disc pl-4" data-testid="errori-tasso-legale">
                                <li v-for="m in erroriTasso" :key="m">{{ m }}</li>
                            </ul>
                        </div>
                    </div>
                </StrutturaLayout>
            </div>
        </div>
        <TassoLegaleAManoDialog v-model:open="finestraTasso" :condominio-id="condominio.id" :anno="annoTasso" :anni="anni_da_scrivere" :a-mano="legali_a_mano" />
        <RegoleRecuperoGuide v-model:open="mostraGuida" />
    </GestionaleLayout>
</template>
