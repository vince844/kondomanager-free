<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
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
    { title: 'Movimenti', href: generatePath('gestionale/:condominio/movimenti', { condominio: props.condominio.id }) },
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

const submit = () => {
    if (!puoRegistrare.value) return;
    form.post(route(generateRoute('gestionale.regolazioni-immediate.store'), { condominio: props.condominio.id }), {
        preserveScroll: true,
    });
};

const linkNuovaFattura = computed(() =>
    route(generateRoute('gestionale.fatture.create'), { condominio: props.condominio.id })
);

const urlMovimenti = computed(() =>
    generatePath('gestionale/:condominio/movimenti', { condominio: props.condominio.id })
);

const annulla = () => {
    if (form.isDirty && !confirm('Uscire senza registrare? I dati inseriti andranno persi.')) return;
    router.visit(urlMovimenti.value);
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
            />

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
                    <form class="space-y-5" @submit.prevent="submit">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label for="esercizio">Esercizio *</Label>
                                <vSelect
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
                            <Button type="submit" :disabled="!puoRegistrare || form.processing">
                                {{ form.processing ? 'Registrazione…' : 'Registra movimento' }}
                            </Button>
                            <Button type="button" variant="ghost" @click="annulla">Annulla</Button>
                            <span v-if="!puoRegistrare && !bloccoRitenuta" class="text-xs text-slate-500">
                                Compila tutti i campi obbligatori per procedere.
                            </span>
                            <span class="ml-auto text-xs text-slate-400">
                                <kbd class="rounded border px-1">Tab</kbd> campo successivo ·
                                <kbd class="rounded border px-1">Invio</kbd> registra ·
                                <kbd class="rounded border px-1">Esc</kbd> esci
                            </span>
                        </div>
                    </form>
                </div>

                <!-- ── Anteprima della scrittura ─────────────────────────── -->
                <div class="rounded-2xl border border-slate-900 bg-slate-900 p-6 text-slate-100 shadow-sm dark:border-slate-700">
                    <div class="mb-4 flex items-center gap-2">
                        <Landmark class="h-4 w-4" />
                        <span class="text-xs font-semibold uppercase tracking-wide">Anteprima scrittura</span>
                    </div>

                    <div class="space-y-3 text-sm">
                        <div class="flex items-start justify-between gap-3 border-b border-slate-700 pb-3">
                            <div>
                                <Badge class="mb-1 bg-emerald-500/15 text-emerald-300 hover:bg-emerald-500/15">DARE</Badge>
                                <p class="text-slate-300">
                                    {{ capitoloSelezionato?.label ?? 'Capitolo di spesa da selezionare' }}
                                </p>
                            </div>
                            <span class="shrink-0 font-mono">{{ importoValido ? euro(form.importo) : '—' }}</span>
                        </div>

                        <div class="flex items-start justify-between gap-3 border-b border-slate-700 pb-3">
                            <div>
                                <Badge class="mb-1 bg-rose-500/15 text-rose-300 hover:bg-rose-500/15">AVERE</Badge>
                                <p class="text-slate-300">
                                    {{ cassaSelezionata?.nome ?? 'Cassa da selezionare' }}
                                </p>
                            </div>
                            <span class="shrink-0 font-mono">{{ importoValido ? euro(form.importo) : '—' }}</span>
                        </div>

                        <p class="pt-1 text-xs text-slate-400">
                            {{ form.causale || 'La causale comparirà qui e nel libro giornale.' }}
                        </p>
                    </div>

                    <div class="mt-5 flex items-start gap-2 rounded-lg bg-slate-800/60 p-3 text-xs text-slate-300">
                        <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        <span>
                            Nessuna fattura, nessuno stato di pagamento, nessuna scadenza.
                            Un solo movimento a protocollo <span class="font-mono">RIM</span>.
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </GestionaleLayout>
</template>
