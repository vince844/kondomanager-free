<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import Alert from '@/components/Alert.vue';
import RegolazioneImmediataGuide from '@/components/guides/RegolazioneImmediataGuide.vue';
import type { Flash } from '@/types/flash';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import MoneyInput from '@/components/MoneyInput.vue';
import InputError from '@/components/InputError.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Zap, Landmark, AlertTriangle, ArrowRight, FileText, Info } from 'lucide-vue-next';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import type { Breadcrumb } from '@/components/PageHeaderGuide.vue';

interface Capitolo { id: number; nome: string; parent_nome: string | null; label: string }
interface Cassa { id: number; nome: string; tipo: string | null }
interface Gestione { id: number; nome: string; tipo: string; esercizio_ids: number[] }
interface Esercizio { id: number; nome: string }
interface FornitoreOpt { id: number; ragione_sociale: string; soggetto_ritenuta: boolean }

const props = defineProps<{
    condominio: any;
    condomini: any[];
    esercizi: Esercizio[];
    gestioni: Gestione[];
    capitoli: Capitolo[];
    casse: Cassa[];
    fornitori: FornitoreOpt[];
}>();

const { generateRoute, generatePath } = usePermission();
const { euro } = useCurrencyFormatter({ fromCents: false });

const breadcrumbs = computed<Breadcrumb[]>(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Fatture e Uscite', href: generatePath('gestionale/:condominio/fatture', { condominio: props.condominio.id }) },
    { title: 'Regolazione immediata' },
]);

const pageGuides = [
    {
        title: 'Quando usarla',
        description: 'Per i fatti che nascono e si estinguono nello stesso momento: imposte di bollo, commissioni bancarie, addebiti automatici, piccole spese. Una sola scrittura, nessun fornitore fittizio.',
        icon: Zap,
        colorVariant: 'amber' as const,
    },
    {
        title: 'Quando NON usarla',
        description: 'Se il fornitore è soggetto a ritenuta d\'acconto, se il debito va tracciato nel tempo o se serve lo scadenziario: in quei casi registra una fattura passiva.',
        icon: FileText,
        colorVariant: 'blue' as const,
    },
    {
        title: 'Effetto contabile',
        description: 'Genera una scrittura di prima nota: DARE sul capitolo di spesa, AVERE sulla cassa. Il capitolo resta agganciato, quindi budget e riparto funzionano come per una fattura.',
        icon: Landmark,
        colorVariant: 'emerald' as const,
    },
];

const oggi = new Date().toISOString().slice(0, 10);

// NB: il campo NON può chiamarsi `data` — useForm di Inertia espone già un metodo
// form.data(), la collisione impedisce al v-model di legarsi e il campo resta vuoto.
const form = useForm({
    esercizio_id: props.esercizi.length === 1 ? props.esercizi[0].id : null as number | null,
    gestione_id: null as number | null,
    conto_id: null as number | null,
    cassa_id: props.casse.length === 1 ? props.casse[0].id : null as number | null,
    fornitore_id: null as number | null,
    data_operazione: oggi,
    causale: '',
    importo: 0,
});

// Le gestioni disponibili dipendono dall'esercizio scelto (stessa regola del form fattura).
const gestioniDisponibili = computed(() =>
    form.esercizio_id
        ? props.gestioni.filter(g => g.esercizio_ids.includes(Number(form.esercizio_id)))
        : props.gestioni
);

// `immediate` è necessario: con un solo esercizio il valore è già impostato all'avvio,
// quindi senza esecuzione immediata la gestione unica non verrebbe mai preselezionata.
watch(() => form.esercizio_id, () => {
    if (form.gestione_id && !gestioniDisponibili.value.some(g => g.id === form.gestione_id)) {
        form.gestione_id = null;
    }
    if (!form.gestione_id && gestioniDisponibili.value.length === 1) {
        form.gestione_id = gestioniDisponibili.value[0].id;
    }
}, { immediate: true });

const capitoloSelezionato = computed(() => props.capitoli.find(c => c.id === form.conto_id) ?? null);
const cassaSelezionata = computed(() => props.casse.find(c => c.id === form.cassa_id) ?? null);
const fornitoreSelezionato = computed(() => props.fornitori.find(f => f.id === form.fornitore_id) ?? null);

// Guard rail §6 anticipato in UI: lo stesso controllo esiste nell'Action, ma
// scoprirlo dopo il submit sarebbe una perdita di tempo per l'amministratore.
const bloccoRitenuta = computed(() => fornitoreSelezionato.value?.soggetto_ritenuta === true);

const importoValido = computed(() => Number(form.importo) > 0);

const puoRegistrare = computed(() =>
    !bloccoRitenuta.value &&
    importoValido.value &&
    !!form.esercizio_id && !!form.gestione_id && !!form.conto_id && !!form.cassa_id &&
    form.causale.trim().length >= 3
);

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);
const showGuideCompleta = ref(false);

/**
 * creaAltro: dopo il salvataggio si torna qui, al modulo vuoto — per chi ha
 * una pila di scontrini e commissioni da registrare in fila.
 */
const submit = (creaAltro = false) => {
    if (!puoRegistrare.value) return;
    form.transform((data) => ({ ...data, crea_altro: creaAltro }))
        .post(route(generateRoute('gestionale.regolazioni-immediate.store'), { condominio: props.condominio.id }), {
            preserveScroll: !creaAltro,
            // Tornando allo stesso componente, Inertia riusa l'istanza e il form
            // resterebbe pieno dei valori appena registrati: un click distratto
            // produrrebbe un doppione identico. Il modulo riparte pulito.
            onSuccess: () => {
                if (creaAltro) form.reset();
            },
        });
};

const linkNuovaFattura = computed(() =>
    route(generateRoute('gestionale.fatture.create'), { condominio: props.condominio.id })
);

// La regolazione vive accanto alle fatture: uscendo si torna lì, non ai movimenti rate.
const urlFatture = computed(() =>
    generatePath('gestionale/:condominio/fatture', { condominio: props.condominio.id })
);

const annulla = () => {
    if (form.isDirty && !confirm('Uscire senza registrare? I dati inseriti andranno persi.')) return;
    router.visit(urlFatture.value);
};

// Inserimento da tastiera: la registrazione di un bollo o di una commissione deve
// costare pochi secondi. Il fuoco parte dall'importo (data, esercizio, gestione e
// cassa sono già compilati), TAB scorre i campi in ordine, Esc esce.
onMounted(() => {
    document.getElementById('importo')?.focus();
    window.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        e.preventDefault();
        annulla();
    }
}
</script>

<template>
    <Head title="Registrazione a regolazione immediata" />

    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Registrazione a regolazione immediata"
                page-subtitle="Prima nota diretta: costo → banca in un'unica scrittura, senza aprire una partita fornitore."
                :guides="pageGuides"
                :breadcrumbs="breadcrumbs"
                :video-url="null"
                :condominio="props.condominio"
                :condomini="props.condomini"
                :back-url="generatePath('gestionale/:condominio/fatture', { condominio: props.condominio.id })"
                back-text="Indietro"
            >
                <template #actions>
                    <Button variant="outline" size="sm" class="bg-white gap-2 text-indigo-700 hover:bg-indigo-50 hover:text-indigo-800 border-indigo-200" @click="showGuideCompleta = true">
                        <Zap class="w-4 h-4" />
                        Guida completa
                    </Button>
                </template>
            </PageHeaderGuide>

            <div v-if="flashMessage" class="mb-6">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <div v-if="form.errors.regolazione_non_ammessa"
                 class="flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-rose-900 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-100">
                <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" />
                <div class="space-y-2">
                    <p class="font-semibold">Operazione non ammessa</p>
                    <p class="text-sm">{{ form.errors.regolazione_non_ammessa }}</p>
                    <Button as-child size="sm" variant="outline">
                        <a :href="linkNuovaFattura">Registra una fattura passiva <ArrowRight class="ml-1 h-4 w-4" /></a>
                    </Button>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <!-- ── Form ─────────────────────────────────────────────── -->
                <div class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                    <form class="space-y-5" @submit.prevent="submit(false)">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <!-- Con un solo esercizio aperto (il caso normale) la scelta non esiste:
                                 il campo diventa una constatazione, non un menu. I chiusi non arrivano
                                 mai qui: il controller lista solo gli aperti e la validazione li esige. -->
                            <div class="space-y-1.5">
                                <Label for="esercizio">Esercizio *</Label>
                                <div v-if="props.esercizi.length === 1"
                                     class="flex h-10 items-center rounded-md border border-slate-200 bg-slate-50 px-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">
                                    {{ props.esercizi[0].nome }}
                                </div>
                                <vSelect
                                    v-else
                                    id="esercizio"
                                    :clearable="false"
                                    v-model="form.esercizio_id"
                                    :options="props.esercizi"
                                    :reduce="(e: Esercizio) => e.id"
                                    label="nome"
                                    placeholder="Seleziona l'esercizio"
                                />
                                <InputError :message="form.errors.esercizio_id" />
                            </div>

                            <div class="space-y-1.5">
                                <Label for="gestione">Gestione *</Label>
                                <vSelect
                                    id="gestione"
                                    :clearable="false"
                                    v-model="form.gestione_id"
                                    :options="gestioniDisponibili"
                                    :reduce="(g: Gestione) => g.id"
                                    label="nome"
                                    :disabled="!form.esercizio_id"
                                    placeholder="Seleziona la gestione"
                                />
                                <InputError :message="form.errors.gestione_id" />
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label for="data_operazione">Data operazione *</Label>
                                <Input id="data_operazione" v-model="form.data_operazione" type="date" :max="oggi" />
                                <InputError :message="form.errors.data_operazione" />
                            </div>

                            <div class="space-y-1.5">
                                <Label for="importo">Importo *</Label>
                                <MoneyInput id="importo" v-model="form.importo" />
                                <InputError :message="form.errors.importo" />
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="causale">Causale *</Label>
                            <Input id="causale" v-model="form.causale" placeholder="Es. Imposta di bollo su estratto conto" maxlength="255" />
                            <p class="text-xs text-slate-500">È ciò che rende leggibile il libro giornale: scrivila come la leggeresti in assemblea.</p>
                            <InputError :message="form.errors.causale" />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="capitolo">Capitolo di spesa (DARE) *</Label>
                            <vSelect
                                id="capitolo"
                                :clearable="false"
                                v-model="form.conto_id"
                                :options="props.capitoli"
                                :reduce="(c: Capitolo) => c.id"
                                label="label"
                                placeholder="Su quale voce di spesa va imputato il costo?"
                            />
                            <InputError :message="form.errors.conto_id" />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="cassa">Cassa / banca (AVERE) *</Label>
                            <vSelect
                                id="cassa"
                                :clearable="false"
                                v-model="form.cassa_id"
                                :options="props.casse"
                                :reduce="(c: Cassa) => c.id"
                                label="nome"
                                placeholder="Da dove escono i soldi?"
                            />
                            <InputError :message="form.errors.cassa_id" />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="fornitore">Fornitore <span class="font-normal text-slate-500">(facoltativo)</span></Label>
                            <vSelect
                                id="fornitore"
                                v-model="form.fornitore_id"
                                :options="props.fornitori"
                                :reduce="(f: FornitoreOpt) => f.id"
                                label="ragione_sociale"
                                placeholder="Solo come etichetta per la reportistica"
                            />
                            <p class="text-xs text-slate-500">
                                Non apre alcuna partita: non movimenta i debiti verso fornitori e non genera scadenze.
                            </p>
                            <InputError :message="form.errors.fornitore_id" />
                        </div>

                        <div v-if="bloccoRitenuta"
                             class="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" />
                            <div class="space-y-2">
                                <p class="text-sm font-semibold">
                                    «{{ fornitoreSelezionato?.ragione_sociale }}» è soggetto a ritenuta d'acconto
                                </p>
                                <p class="text-sm">
                                    Il corrispettivo va spezzato tra il netto dovuto al fornitore e la ritenuta da versare
                                    all'Erario: serve una fattura, non una regolazione immediata.
                                </p>
                                <Button as-child size="sm" variant="outline">
                                    <a :href="linkNuovaFattura">Registra una fattura passiva <ArrowRight class="ml-1 h-4 w-4" /></a>
                                </Button>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-3 pt-2">
                            <Button type="submit"
                                :disabled="!puoRegistrare || form.processing"
                                class="bg-slate-900 hover:bg-slate-800 text-white dark:bg-slate-100 dark:hover:bg-slate-200 dark:text-slate-900">
                                {{ form.processing ? 'Registrazione…' : 'Registra movimento' }}
                            </Button>
                            <Button type="button" variant="outline"
                                :disabled="!puoRegistrare || form.processing"
                                @click="submit(true)">
                                Registra e nuova
                            </Button>
                            <Button type="button" variant="outline" @click="annulla">Annulla</Button>
                            <span class="ml-auto text-xs text-slate-400">
                                <kbd class="rounded border px-1">Tab</kbd> campo successivo ·
                                <kbd class="rounded border px-1">Invio</kbd> registra ·
                                <kbd class="rounded border px-1">Esc</kbd> esci
                            </span>
                        </div>
                    </form>
                </div>

                <!-- ── Anteprima della scrittura: la pagina di giornale ───── -->
                <div class="relative overflow-hidden rounded-2xl border border-slate-800 bg-gradient-to-b from-slate-900 to-slate-950 p-6 text-slate-100 shadow-lg">

                    <div class="mb-5 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold uppercase tracking-wide">Anteprima scrittura</span>
                        </div>
                        <span class="rounded-full border border-slate-700 bg-slate-800/80 px-2.5 py-0.5 font-mono text-[10px] tracking-wider text-slate-300">
                            RIM · {{ form.data_operazione ? form.data_operazione.split('-').reverse().join('/') : '—' }}
                        </span>
                    </div>

                    <div class="space-y-2.5">
                        <div class="rounded-lg border-l-2 border-emerald-400/80 bg-white/[0.04] px-4 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-[10px] font-black uppercase tracking-widest text-emerald-300">Dare</span>
                                <span class="shrink-0 font-mono text-base tabular-nums" :class="importoValido ? 'text-white' : 'text-slate-500'">
                                    {{ importoValido ? euro(form.importo) : '—' }}
                                </span>
                            </div>
                            <p class="mt-1 truncate text-sm" :class="capitoloSelezionato ? 'text-slate-200' : 'text-slate-500 italic'">
                                {{ capitoloSelezionato?.label ?? 'Capitolo di spesa da selezionare' }}
                            </p>
                        </div>

                        <div class="rounded-lg border-l-2 border-rose-400/80 bg-white/[0.04] px-4 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-[10px] font-black uppercase tracking-widest text-rose-300">Avere</span>
                                <span class="shrink-0 font-mono text-base tabular-nums" :class="importoValido ? 'text-white' : 'text-slate-500'">
                                    {{ importoValido ? euro(form.importo) : '—' }}
                                </span>
                            </div>
                            <p class="mt-1 truncate text-sm" :class="cassaSelezionata ? 'text-slate-200' : 'text-slate-500 italic'">
                                {{ cassaSelezionata?.nome ?? 'Cassa da selezionare' }}
                            </p>
                        </div>
                    </div>

                    <!-- Il sigillo della partita doppia: si accende quando la scrittura è completa. -->
                    <div class="mt-4 flex items-center justify-between border-t border-dashed border-slate-700 pt-3">
                        <span class="text-[10px] font-bold uppercase tracking-widest text-slate-500">Quadratura</span>
                        <span v-if="puoRegistrare" class="flex items-center gap-1.5 text-xs font-bold text-emerald-300">
                            <span class="flex h-4 w-4 items-center justify-center rounded-full bg-emerald-500/20 text-[10px]">✓</span>
                            Dare = Avere
                        </span>
                        <span v-else class="text-xs text-slate-500">in attesa dei dati…</span>
                    </div>

                    <p class="mt-3 min-h-[2rem] text-xs leading-relaxed text-slate-400">
                        <template v-if="form.causale">«&nbsp;{{ form.causale }}&nbsp;»</template>
                        <template v-else>La causale comparirà qui e nel libro giornale.</template>
                    </p>

                    <div class="mt-4 flex items-start gap-2 rounded-lg bg-slate-800/60 p-3 text-xs text-slate-300">
                        <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        <span>
                            Nessuna fattura, nessuno stato di pagamento, nessuna scadenza.
                            Un solo movimento a protocollo <span class="font-mono">RIM</span>.
                        </span>
                    </div>
                </div>
            </div>

        </div>

        <RegolazioneImmediataGuide v-model:open="showGuideCompleta" />
    </GestionaleLayout>
</template>
