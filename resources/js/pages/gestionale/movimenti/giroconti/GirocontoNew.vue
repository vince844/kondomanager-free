<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount, watch } from 'vue';
import GirocontiGuide from '@/components/guides/GirocontiGuide.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import Alert from '@/components/Alert.vue';
import type { Flash } from '@/types/flash';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import MoneyInput from '@/components/MoneyInput.vue';
import InputError from '@/components/InputError.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Repeat2, Landmark, AlertTriangle, PiggyBank, Info } from 'lucide-vue-next';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import type { Breadcrumb } from '@/components/PageHeaderGuide.vue';

interface Cassa {
    id: number;
    nome: string;
    tipo: 'banca' | 'contanti' | 'fondo' | 'virtuale';
    conto_contabile_id: number;
    saldo_cents: number;
    sottotipo_fondo: string | null;
    is_override_assemblea: boolean;
    is_utilizzabile_per_imprevisti: boolean;
}
interface Copertura {
    id: number;
    importo: number; // CENTESIMI
    fondo_id: number;
    fattura_id: number;
    fattura_numero: string;
    fornitore: string;
    label: string;
}
interface Gestione { id: number; nome: string; tipo: string; esercizio_ids: number[] }
interface Esercizio { id: number; nome: string }

const props = defineProps<{
    condominio: any;
    condomini: any[];
    esercizio: any;
    esercizi: Esercizio[];
    gestioni: Gestione[];
    casse: Cassa[];
    coperture_in_attesa: Copertura[];
    copertura_preselezionata_id: number | null;
}>();

const { generateRoute, generatePath } = usePermission();
// Due formattatori: il form lavora in euro (MoneyInput), i saldi arrivano in centesimi.
const { euro } = useCurrencyFormatter({ fromCents: false });
const { euro: euroCents } = useCurrencyFormatter({ fromCents: true });

const breadcrumbs = computed<Breadcrumb[]>(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti', href: generatePath('gestionale/:condominio/movimenti', { condominio: props.condominio.id }) },
    { title: 'Giroconti', href: generatePath('gestionale/:condominio/giroconti', { condominio: props.condominio.id }) },
    { title: 'Nuovo giroconto' },
]);

const pageGuides = [
    {
        title: 'A cosa serve',
        description: 'Sposta liquidità fra le casse dello stesso condominio: banca → fondo accantona, fondo → banca libera liquidità (e conferma una copertura sforo), fondo → fondo ridestina.',
        icon: Repeat2,
        colorVariant: 'amber' as const,
    },
    {
        title: 'Nessun denaro reale si muove',
        description: 'I fondi sono partizioni contabili dell\'unico conto corrente reale: il giroconto non genera bonifici né prelievi, cambia solo la destinazione contabile della liquidità.',
        icon: PiggyBank,
        colorVariant: 'blue' as const,
    },
    {
        title: 'Effetto contabile',
        description: 'Genera una scrittura di prima nota a due righe: DARE sulla cassa di destinazione, AVERE su quella di origine. Sempre stornabile con scrittura inversa, mai cancellabile.',
        icon: Landmark,
        colorVariant: 'emerald' as const,
    },
];

const oggi = new Date().toISOString().slice(0, 10);

// NB: il campo NON può chiamarsi `data` — useForm di Inertia espone già un metodo
// form.data(), la collisione impedisce al v-model di legarsi e il campo resta vuoto.
const form = useForm({
    gestione_id: null as number | null,
    esercizio_id: props.esercizi.length === 1 ? props.esercizi[0].id : null as number | null,
    cassa_origine_id: null as number | null,
    cassa_destinazione_id: null as number | null,
    data_operazione: oggi,
    causale: '',
    importo: 0,
    fattura_copertura_id: null as number | null,
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
    if (!form.gestione_id) {
        const ordinaria = gestioniDisponibili.value.find(g => g.tipo === 'ordinaria');
        if (ordinaria) {
            form.gestione_id = ordinaria.id;
        } else if (gestioniDisponibili.value.length === 1) {
            form.gestione_id = gestioniDisponibili.value[0].id;
        }
    }
}, { immediate: true });

const cassaOrigine = computed(() => props.casse.find(c => c.id === form.cassa_origine_id) ?? null);
const cassaDestinazione = computed(() => props.casse.find(c => c.id === form.cassa_destinazione_id) ?? null);

const coperturaSelezionata = computed(() =>
    props.coperture_in_attesa.find(c => c.id === form.fattura_copertura_id) ?? null
);

// ── Filtri client sulle due gambe ─────────────────────────────────────────
// La liquidità dei fondi vive sul conto corrente: se una gamba è un fondo,
// l'altra può essere solo banca o fondo (mai contanti/virtuale).
const opzioniOrigine = computed(() => {
    let lista = props.casse.filter(c => c.id !== form.cassa_destinazione_id);
    if (cassaDestinazione.value?.tipo === 'fondo') {
        lista = lista.filter(c => c.tipo === 'banca' || c.tipo === 'fondo');
    }
    return lista;
});

const opzioniDestinazione = computed(() => {
    let lista = props.casse.filter(c => c.id !== form.cassa_origine_id);
    // Conferma copertura: la liquidità torna sempre sul conto corrente.
    if (coperturaSelezionata.value) {
        return lista.filter(c => c.tipo === 'banca');
    }
    if (cassaOrigine.value?.tipo === 'fondo') {
        lista = lista.filter(c => c.tipo === 'banca' || c.tipo === 'fondo');
    }
    return lista;
});

// Fondo vincolato: in ORIGINE non è selezionabile senza deroga assembleare.
// In destinazione resta selezionabile (accantonare è sempre lecito).
const origineSelezionabile = (o: Cassa) =>
    !(o.tipo === 'fondo' && !o.is_utilizzabile_per_imprevisti);

// Se il cambio di una gamba rende invalida l'altra, la ripuliamo subito:
// meglio un campo vuoto che un submit destinato all'errore di dominio.
watch(opzioniDestinazione, (opzioni) => {
    if (form.cassa_destinazione_id && !opzioni.some(c => c.id === form.cassa_destinazione_id)) {
        form.cassa_destinazione_id = null;
    }
});
watch(opzioniOrigine, (opzioni) => {
    if (coperturaSelezionata.value) return; // origine bloccata dalla copertura
    if (form.cassa_origine_id && !opzioni.some(c => c.id === form.cassa_origine_id)) {
        form.cassa_origine_id = null;
    }
});

// ── Copertura in attesa: selezione blocca origine e importo ───────────────
watch(() => form.fattura_copertura_id, (id) => {
    const copertura = props.coperture_in_attesa.find(c => c.id === id) ?? null;
    if (!copertura) return; // clear: i campi restano com'erano, solo sbloccati

    const origine = props.casse.find(c => c.conto_contabile_id === copertura.fondo_id) ?? null;
    form.cassa_origine_id = origine?.id ?? null;
    form.importo = copertura.importo / 100;
    form.causale = `Conferma copertura sforo Ft. ${copertura.fattura_numero} — utilizzo ${origine?.nome ?? 'fondo'}`;
});

const etichettaTipo: Record<Cassa['tipo'], string> = {
    banca: 'Banca',
    contanti: 'Contanti',
    fondo: 'Fondo',
    virtuale: 'Virtuale',
};

const importoValido = computed(() => Number(form.importo) > 0);

// Preavviso di capienza: il blocco vero è server-side (regola di dominio),
// ma scoprirlo dopo il submit sarebbe una perdita di tempo per l'amministratore.
const capienzaInsufficiente = computed(() =>
    !!cassaOrigine.value &&
    importoValido.value &&
    Number(form.importo) > cassaOrigine.value.saldo_cents / 100
);

const puoRegistrare = computed(() =>
    importoValido.value &&
    !!form.esercizio_id && !!form.gestione_id &&
    !!form.cassa_origine_id && !!form.cassa_destinazione_id &&
    form.cassa_origine_id !== form.cassa_destinazione_id &&
    form.causale.trim().length >= 3
);

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

/**
 * creaAltro: dopo il salvataggio si torna qui, al modulo vuoto con saldi e
 * coperture aggiornati, invece che al dettaglio della scrittura — per chi ha
 * più operazioni in fila.
 */
const submit = (creaAltro = false) => {
    if (!puoRegistrare.value) return;
    form.transform((data) => ({ ...data, crea_altro: creaAltro }))
        .post(route(generateRoute('gestionale.giroconti.store'), { condominio: props.condominio.id }), {
            preserveScroll: !creaAltro,
            // Tornando allo stesso componente, Inertia riusa l'istanza e il form
            // resterebbe pieno dei valori appena registrati: un click distratto
            // produrrebbe un doppione identico. Il modulo riparte pulito.
            onSuccess: () => {
                if (creaAltro) form.reset();
            },
        });
};

const urlMovimenti = computed(() =>
    generatePath('gestionale/:condominio/movimenti', { condominio: props.condominio.id })
);

const showGuideCompleta = ref(false);

const annulla = () => {
    if (form.isDirty && !confirm('Uscire senza registrare? I dati inseriti andranno persi.')) return;
    router.visit(urlMovimenti.value);
};

// Deep-link dalla fattura (?copertura_id=…): la copertura arriva già selezionata.
onMounted(() => {
    if (props.copertura_preselezionata_id
        && props.coperture_in_attesa.some(c => c.id === props.copertura_preselezionata_id)) {
        form.fattura_copertura_id = props.copertura_preselezionata_id;
    } else {
        document.getElementById('importo')?.focus();
    }
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
    <Head title="Nuovo giroconto" />

    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Nuovo giroconto"
                page-subtitle="Sposta liquidità fra casse e fondi — nessun denaro reale si muove, cambia la destinazione contabile."
                :guides="pageGuides"
                :breadcrumbs="breadcrumbs"
                :video-url="null"
                :condominio="props.condominio"
                :condomini="props.condomini"
                :back-url="route(generateRoute('gestionale.giroconti.index'), { condominio: props.condominio.id })"
                back-text="Indietro"
            >
                <template #actions>
                    <Button variant="outline" size="sm" class="bg-white gap-2 text-violet-700 hover:bg-violet-50 hover:text-violet-800 border-violet-200" @click="showGuideCompleta = true">
                        <Repeat2 class="w-4 h-4" />
                        Guida completa
                    </Button>
                </template>
            </PageHeaderGuide>

            <div v-if="flashMessage" class="mb-6">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <div v-if="form.errors.giroconto_non_ammesso"
                 class="mb-6 flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-rose-900 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-100">
                <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" />
                <div class="space-y-2">
                    <p class="font-semibold">Operazione non ammessa</p>
                    <p class="text-sm">{{ form.errors.giroconto_non_ammesso }}</p>
                </div>
            </div>

            <!-- ── Coperture in attesa di conferma ───────────────────────── -->
            <div v-if="props.coperture_in_attesa.length > 0"
                 class="mb-6 rounded-2xl border border-slate-900 bg-slate-900 p-6 text-slate-100 shadow-sm dark:border-slate-700">
                <div class="mb-2 flex items-center gap-2">
                    <PiggyBank class="h-4 w-4 text-violet-300" />
                    <span class="text-xs font-semibold uppercase tracking-wide">Coperture in attesa di conferma</span>
                </div>
                <p class="mb-4 text-xs leading-relaxed text-slate-400">
                    Qui compaiono le fatture registrate in sforamento con strategia «Fondo di riserva»:
                    la copertura è solo <span class="text-slate-200">pianificata</span> finché il fondo non viene
                    davvero utilizzato. Selezionane una e il modulo si compila da solo — il giroconto verso la
                    banca è l'atto che la conferma e decurta il fondo. I dettagli sono nella
                    <span class="text-slate-200">Guida completa</span> in alto, scheda Coperture.
                </p>
                <div class="copertura-select">
                    <vSelect
                        id="copertura"
                        v-model="form.fattura_copertura_id"
                        :options="props.coperture_in_attesa"
                        :reduce="(c: Copertura) => c.id"
                        label="label"
                        placeholder="Seleziona la copertura da confermare (facoltativo)"
                    />
                </div>
                <InputError :message="form.errors.fattura_copertura_id" />
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
                                <MoneyInput id="importo" v-model="form.importo" :disabled="!!coperturaSelezionata" />
                                <p v-if="capienzaInsufficiente" class="text-xs font-medium text-rose-600 dark:text-rose-400">
                                    Capienza insufficiente: disponibili {{ euroCents(cassaOrigine?.saldo_cents) }}
                                </p>
                                <InputError :message="form.errors.importo" />
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="causale">Causale *</Label>
                            <Input id="causale" v-model="form.causale" placeholder="Es. Accantonamento al fondo riserva — delibera del 12/05" maxlength="255" />
                            <p class="text-xs text-slate-500">È ciò che rende leggibile il libro giornale: scrivila come la leggeresti in assemblea.</p>
                            <InputError :message="form.errors.causale" />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="cassa_origine">Cassa di origine (AVERE) *</Label>
                            <vSelect
                                id="cassa_origine"
                                :clearable="false"
                                v-model="form.cassa_origine_id"
                                :options="opzioniOrigine"
                                :reduce="(c: Cassa) => c.id"
                                label="nome"
                                :selectable="origineSelezionabile"
                                :disabled="!!coperturaSelezionata"
                                placeholder="Da quale cassa esce la liquidità?"
                            >
                                <template #option="{ nome, tipo, saldo_cents, is_utilizzabile_per_imprevisti }">
                                    <div class="flex items-center justify-between gap-3 py-0.5">
                                        <div class="flex items-center gap-2 overflow-hidden">
                                            <span class="truncate text-sm font-semibold">{{ nome }}</span>
                                            <span class="shrink-0 rounded border border-slate-200 bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                                {{ etichettaTipo[tipo as Cassa['tipo']] }}
                                            </span>
                                            <span v-if="tipo === 'fondo' && !is_utilizzabile_per_imprevisti"
                                                  class="shrink-0 text-[10px] font-medium text-amber-600 dark:text-amber-400">
                                                vincolato — serve deroga assembleare
                                            </span>
                                        </div>
                                        <span class="shrink-0 font-mono text-xs">{{ euroCents(saldo_cents) }}</span>
                                    </div>
                                </template>
                                <template #selected-option="{ nome, saldo_cents }">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold">{{ nome }}</span>
                                        <span class="text-[10px] text-slate-400">{{ euroCents(saldo_cents) }}</span>
                                    </div>
                                </template>
                            </vSelect>
                            <p v-if="coperturaSelezionata && form.cassa_origine_id" class="text-xs text-slate-500">
                                Origine bloccata: è il fondo che ha pianificato la copertura selezionata.
                            </p>
                            <p v-else-if="coperturaSelezionata && !form.cassa_origine_id" class="text-xs font-medium text-rose-600">
                                Il fondo di questa copertura non ha una cassa attiva: riattiva la cassa
                                del fondo dalla sezione Casse prima di confermare.
                            </p>
                            <InputError :message="form.errors.cassa_origine_id" />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="cassa_destinazione">Cassa di destinazione (DARE) *</Label>
                            <vSelect
                                id="cassa_destinazione"
                                :clearable="false"
                                v-model="form.cassa_destinazione_id"
                                :options="opzioniDestinazione"
                                :reduce="(c: Cassa) => c.id"
                                label="nome"
                                placeholder="Dove va la liquidità?"
                            >
                                <template #option="{ nome, tipo, saldo_cents }">
                                    <div class="flex items-center justify-between gap-3 py-0.5">
                                        <div class="flex items-center gap-2 overflow-hidden">
                                            <span class="truncate text-sm font-semibold">{{ nome }}</span>
                                            <span class="shrink-0 rounded border border-slate-200 bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                                {{ etichettaTipo[tipo as Cassa['tipo']] }}
                                            </span>
                                        </div>
                                        <span class="shrink-0 font-mono text-xs">{{ euroCents(saldo_cents) }}</span>
                                    </div>
                                </template>
                                <template #selected-option="{ nome, saldo_cents }">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold">{{ nome }}</span>
                                        <span class="text-[10px] text-slate-400">{{ euroCents(saldo_cents) }}</span>
                                    </div>
                                </template>
                            </vSelect>
                            <InputError :message="form.errors.cassa_destinazione_id" />
                        </div>

                        <div class="flex flex-wrap items-center gap-3 pt-2">
                            <Button type="submit"
                                :disabled="!puoRegistrare || form.processing"
                                class="bg-slate-900 hover:bg-slate-800 text-white dark:bg-slate-100 dark:hover:bg-slate-200 dark:text-slate-900">
                                {{ form.processing ? 'Registrazione…' : 'Registra giroconto' }}
                            </Button>
                            <Button type="button" variant="outline"
                                :disabled="!puoRegistrare || form.processing"
                                @click="submit(true)">
                                Registra e nuovo
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
                    <Landmark class="pointer-events-none absolute -right-5 -top-5 h-28 w-28 rotate-12 text-white/[0.04]" />

                    <div class="mb-5 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <Landmark class="h-4 w-4 text-slate-400" />
                            <span class="text-xs font-semibold uppercase tracking-wide">Anteprima scrittura</span>
                        </div>
                        <span class="rounded-full border border-slate-700 bg-slate-800/80 px-2.5 py-0.5 font-mono text-[10px] tracking-wider text-slate-300">
                            GIR · {{ form.data_operazione ? form.data_operazione.split('-').reverse().join('/') : '—' }}
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
                            <p class="mt-1 truncate text-sm" :class="cassaDestinazione ? 'text-slate-200' : 'text-slate-500 italic'">
                                {{ cassaDestinazione?.nome ?? 'Cassa di destinazione da selezionare' }}
                            </p>
                        </div>

                        <div class="rounded-lg border-l-2 border-rose-400/80 bg-white/[0.04] px-4 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-[10px] font-black uppercase tracking-widest text-rose-300">Avere</span>
                                <span class="shrink-0 font-mono text-base tabular-nums" :class="importoValido ? 'text-white' : 'text-slate-500'">
                                    {{ importoValido ? euro(form.importo) : '—' }}
                                </span>
                            </div>
                            <p class="mt-1 truncate text-sm" :class="cassaOrigine ? 'text-slate-200' : 'text-slate-500 italic'">
                                {{ cassaOrigine?.nome ?? 'Cassa di origine da selezionare' }}
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
                            La liquidità complessiva non cambia: il giroconto sposta la destinazione contabile
                            all'interno dell'unico conto corrente. Un solo movimento a protocollo <span class="font-mono">GIR</span>.
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <GirocontiGuide v-model:open="showGuideCompleta" />
    </GestionaleLayout>
</template>

<style scoped>
/* Il menu delle coperture vive dentro la card scura: il campo veste lo stesso
   tono del box informativo (slate-800), il menu a tendina resta chiaro perché
   le opzioni con fattura e importo devono restare leggibilissime. */
.copertura-select :deep(.vs__dropdown-toggle) {
    background: rgb(30 41 59 / 0.6);
    border-radius: 0.5rem;
    border-color: rgb(51 65 85);
    min-height: 2.5rem;
}
.copertura-select :deep(.vs__search::placeholder) {
    color: rgb(148 163 184);
}
.copertura-select :deep(.vs__selected),
.copertura-select :deep(.vs__search) {
    color: rgb(241 245 249);
}
.copertura-select :deep(.vs__open-indicator),
.copertura-select :deep(.vs__clear) {
    fill: rgb(148 163 184);
}
.copertura-select :deep(.vs__dropdown-menu) {
    color: rgb(15 23 42);
}
</style>
