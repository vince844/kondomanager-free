<script setup lang="ts">
/**
 * «Modelli delle lettere», da «Impostazioni → Stampe»: per ogni tipo prima il testo del programma (non si modifica, si duplica), poi i modelli
 * dello studio, con il nome, «vale per», chi l'ha cambiato e il giorno; i filtri per tipo e per condominio (quelli del condominio e quelli per
 * tutti). Il modulo chiede il tipo (solo per un modello nuovo: un modello non cambia tipo), il nome, «vale per» (nessuno già scelto) e il testo,
 * con i campi e l'anteprima con i valori d'esempio del server. Il condominio, in «vale per» e nel filtro, si sceglie con la ricerca del programma
 * (`vue-select`): i condomini possono essere cento. Eliminare chiede la conferma (le lettere già scritte non cambiano) e manda una richiesta sola.
 * Chi non gestisce le impostazioni di stampa vede i modelli e basta (ci arriva dalla finestra delle lettere): per lui niente briciole né ritorno
 * verso le impostazioni, che gli risponderebbero di no. Dalla 1.12.0-beta.9 anche l'email dei fogli del conguaglio ha i suoi modelli, con i suoi campi
 * (`campi_per_tipo`: [nome], [condominio], [esercizio]).
 */
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { FileText } from 'lucide-vue-next';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import type { Flash } from '@/types/flash';
import {
    FRASE_ELIMINA, FRASE_PAGINA, FRASE_PAGINA_SENZA_PERMESSO, FRASE_PROGRAMMA, nomeDellaCopia, riempiEsempio, testoCambiato, testoValePer,
    type Esempio, type ModelloDellaPagina, type TipoModello,
} from '@/lib/gestionale/lettere/modelli';
import { fraseFissa } from '@/lib/gestionale/rendiconto/invioDeiFogli';

const props = defineProps<{
    tipi: Array<{ tipo: TipoModello; nome: string }>;
    testi_del_programma: Record<TipoModello, string>;
    modelli: ModelloDellaPagina[];
    condomini: Array<{ id: number; nome: string }>;
    campi: Record<string, string>;
    /** I tipi con campi diversi da quelli delle lettere (l'email dei fogli del conguaglio). */
    campi_per_tipo?: Record<string, Record<string, string>>;
    /** Quello che un tipo dice sempre fuori dal modello: la frase della proposta dell'email dei fogli. */
    frase_proposta_per_tipo?: Record<string, string>;
    esempio: Esempio;
    puo_modificare: boolean;
}>();

const page = usePage<{ flash?: { message?: Flash } }>();
const flashMessage = computed(() => page.props?.flash?.message);

// Le impostazioni e le stampe sono di chi gestisce le impostazioni di stampa: per gli altri niente briciole né ritorno verso di loro.
const breadcrumbs = computed(() => (props.puo_modificare ? [
    { title: 'Impostazioni', href: '/impostazioni' },
    { title: 'Stampe', href: '/impostazioni/stampe' },
    { title: 'Modelli delle lettere', href: '/impostazioni/stampe/modelli-lettere' },
] : []));
const guide = [{ title: 'Modelli delle lettere', description: FRASE_PAGINA, icon: FileText, colorVariant: 'blue' as const }];

// ── I filtri ─────────────────────────────────────────────────────────
const filtroTipo = ref('');
/** Vuoto: tutti i modelli; 'tutti': solo quelli per tutti i condomini; l'id di un condominio: quelli validi lì (suoi e per tutti). */
const filtroCondominio = ref<string | null>(null);
interface Scelta { valore: string; nome: string }
const scelteDelFiltro = computed<Scelta[]>(() => [{ valore: 'tutti', nome: 'Solo quelli per tutti i condomini' },
    ...props.condomini.map((c) => ({ valore: String(c.id), nome: `Validi per ${c.nome}` }))]);
const scelteValePer = computed<Scelta[]>(() => [{ valore: 'tutti', nome: 'Tutti i condomini' }, ...props.condomini.map((c) => ({ valore: String(c.id), nome: c.nome }))]);
const valoreDi = (s: Scelta) => s.valore;
const tipiVisibili = computed(() => props.tipi.filter((t) => filtroTipo.value === '' || t.tipo === filtroTipo.value));
const passaIlFiltro = (m: ModelloDellaPagina) => (!filtroCondominio.value ? true
    : filtroCondominio.value === 'tutti' ? m.condominio === null
        : m.condominio === null || m.condominio.id === Number(filtroCondominio.value));
const modelliDi = (tipo: TipoModello) => props.modelli.filter((m) => m.tipo === tipo && passaIlFiltro(m));

// ── Il modulo ────────────────────────────────────────────────────────
/** `valePer`: null finché non si sceglie; 'tutti', o l'id di un condominio. */
interface Modulo { aperto: boolean; id: number | null; tipo: TipoModello | ''; nome: string; valePer: string | null; testo: string }
const chiuso = (): Modulo => ({ aperto: false, id: null, tipo: '', nome: '', valePer: null, testo: '' });
const modulo = ref<Modulo>(chiuso());
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);

function apri(m: Omit<Modulo, 'aperto'>) {
    errori.value = {};
    modulo.value = { aperto: true, ...m };
}
const nuovo = () => apri({ id: null, tipo: '', nome: '', valePer: null, testo: '' });
const modifica = (m: ModelloDellaPagina) => apri({ id: m.id, tipo: m.tipo, nome: m.nome, valePer: m.condominio ? String(m.condominio.id) : 'tutti', testo: m.testo });
/** «Duplica»: un modello nuovo con lo stesso testo; dal testo del programma «vale per» non è scelto. */
const duplicaDalProgramma = (tipo: TipoModello) => apri({ id: null, tipo, nome: nomeDellaCopia(null, tipo), valePer: null, testo: props.testi_del_programma[tipo] ?? '' });
const duplica = (m: ModelloDellaPagina) => apri({ id: null, tipo: m.tipo, nome: nomeDellaCopia(m.nome, m.tipo), valePer: m.condominio ? String(m.condominio.id) : 'tutti', testo: m.testo });

const salvabile = computed(() => modulo.value.tipo !== '' && modulo.value.nome.trim() !== '' && !!modulo.value.valePer && modulo.value.testo.trim() !== '');
/** I campi del tipo scelto nel modulo: quelli delle lettere, o quelli del tipo che ne ha altri. */
const campiDelModulo = computed(() => (modulo.value.tipo !== '' ? props.campi_per_tipo?.[modulo.value.tipo] : undefined) ?? props.campi);
const anteprima = computed(() => riempiEsempio(modulo.value.testo, props.esempio, campiDelModulo.value));
/** Quello che un tipo dice sempre fuori dal modello (l'email dei fogli: la frase della proposta e l'ora del calcolo); null per le lettere. */
const fraseFissaDi = (tipo: TipoModello | '') => (tipo !== '' && props.frase_proposta_per_tipo?.[tipo] ? fraseFissa(props.frase_proposta_per_tipo[tipo]) : null);

function salva() {
    if (!salvabile.value || inCorso.value) {
        return;
    }
    const m = modulo.value;
    const corpo = { nome: m.nome, testo: m.testo, condominio_id: m.valePer === 'tutti' ? null : Number(m.valePer) };
    const opzioni = {
        preserveScroll: true,
        onStart: () => { inCorso.value = true; },
        onFinish: () => { inCorso.value = false; },
        onSuccess: () => { modulo.value = chiuso(); },
        onError: (e: Record<string, string>) => { errori.value = e; },
    };
    if (m.id === null) {
        router.post(route('impostazioni.stampe.modelli-lettere.store'), { tipo: m.tipo, ...corpo }, opzioni);
    } else {
        router.put(route('impostazioni.stampe.modelli-lettere.update', { modello: m.id }), corpo, opzioni);
    }
}

// ── Eliminare ────────────────────────────────────────────────────────
const daEliminare = ref<ModelloDellaPagina | null>(null);
/** Una richiesta sola: un doppio clic ne manderebbe una seconda, che troverebbe il modello già eliminato. */
const eliminando = ref(false);
function elimina() {
    const m = daEliminare.value;
    if (!m || eliminando.value) {
        return;
    }
    eliminando.value = true;
    router.delete(route('impostazioni.stampe.modelli-lettere.destroy', { modello: m.id }), {
        preserveScroll: true,
        onFinish: () => { eliminando.value = false; daEliminare.value = null; },
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="[]">
        <Head title="Modelli delle lettere" />

        <div class="px-4 py-6 space-y-6">
            <PageHeaderGuide page-title="Modelli delle lettere" :guides="guide" :breadcrumbs="breadcrumbs" :back-url="puo_modificare ? '/impostazioni/stampe' : null"
                back-text="Stampe" :video-url="null" />

            <div v-if="flashMessage" class="py-2"><Alert :message="flashMessage.message" :type="flashMessage.type" /></div>

            <p v-if="!puo_modificare" class="text-sm text-slate-600 dark:text-slate-300" data-testid="senza-permesso">{{ FRASE_PAGINA_SENZA_PERMESSO }}</p>

            <div class="flex flex-wrap items-end gap-3 text-sm">
                <label class="space-y-1">
                    <span class="block text-xs text-slate-500 dark:text-slate-400">Tipo</span>
                    <select v-model="filtroTipo" class="h-9 rounded-md border px-2 bg-transparent" data-testid="filtro-tipo">
                        <option value="">Tutti i tipi</option>
                        <option v-for="t in tipi" :key="t.tipo" :value="t.tipo">{{ t.nome }}</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="block text-xs text-slate-500 dark:text-slate-400">Condominio</span>
                    <v-select v-model="filtroCondominio" :options="scelteDelFiltro" label="nome" :reduce="valoreDi" placeholder="Tutti i modelli: cerca un condominio…"
                        class="w-72 max-w-full" data-testid="filtro-condominio" />
                </label>
                <Button v-if="puo_modificare" class="ml-auto" data-testid="nuovo-modello" @click="nuovo">Nuovo modello</Button>
            </div>

            <section v-for="t in tipiVisibili" :key="t.tipo" class="space-y-2" :data-testid="`tipo-${t.tipo}`">
                <h2 class="text-base font-semibold">{{ t.nome }}</h2>

                <div class="rounded-lg border border-dashed p-3 space-y-2 bg-slate-50/60 dark:bg-slate-900/30" :data-testid="`programma-${t.tipo}`">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-medium">Testo del programma</span>
                        <span class="rounded border px-1.5 text-[11px] text-slate-500 dark:text-slate-400">Non si modifica</span>
                        <Button v-if="puo_modificare" size="sm" variant="outline" class="ml-auto h-7 text-xs" @click="duplicaDalProgramma(t.tipo)">Duplica</Button>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ FRASE_PROGRAMMA }}</p>
                    <p class="whitespace-pre-line text-sm">{{ testi_del_programma[t.tipo] }}</p>
                    <p v-if="fraseFissaDi(t.tipo)" class="text-xs text-slate-600 dark:text-slate-300" :data-testid="`frase-fissa-${t.tipo}`">{{ fraseFissaDi(t.tipo) }}</p>
                </div>

                <div v-for="m in modelliDi(t.tipo)" :key="m.id" class="rounded-lg border p-3 space-y-2" :data-testid="`modello-${m.id}`">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-medium">{{ m.nome }}</span>
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ testoValePer(m.condominio) }}</span>
                        <span v-if="testoCambiato(m)" class="text-xs text-slate-500 dark:text-slate-400">· {{ testoCambiato(m) }}</span>
                        <div v-if="puo_modificare" class="ml-auto flex gap-1">
                            <Button size="sm" variant="outline" class="h-7 text-xs" @click="modifica(m)">Modifica</Button>
                            <Button size="sm" variant="outline" class="h-7 text-xs" @click="duplica(m)">Duplica</Button>
                            <Button size="sm" variant="ghost" class="h-7 text-xs text-red-700 dark:text-red-400" @click="daEliminare = m">Elimina</Button>
                        </div>
                    </div>
                    <p class="whitespace-pre-line text-sm">{{ m.testo }}</p>
                </div>
            </section>
        </div>

        <Dialog :open="modulo.aperto" @update:open="(v: boolean) => { if (!v) modulo = chiuso(); }">
            <DialogContent class="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ modulo.id === null ? 'Nuovo modello' : 'Modifica il modello' }}</DialogTitle>
                    <DialogDescription>Il testo si riempie per ogni persona con i campi; nella finestra resta modificabile.</DialogDescription>
                </DialogHeader>
                <div class="space-y-3 text-sm">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="space-y-1">
                            <span class="block text-xs text-slate-500 dark:text-slate-400">Tipo</span>
                            <select v-model="modulo.tipo" :disabled="modulo.id !== null" class="h-9 w-full rounded-md border px-2 bg-transparent" data-testid="form-tipo">
                                <option value="" disabled>Scegli il tipo</option>
                                <option v-for="t in tipi" :key="t.tipo" :value="t.tipo">{{ t.nome }}</option>
                            </select>
                            <span v-if="errori.tipo" class="block text-xs text-red-600">{{ errori.tipo }}</span>
                        </label>
                        <label class="space-y-1">
                            <span class="block text-xs text-slate-500 dark:text-slate-400">Vale per</span>
                            <v-select v-model="modulo.valePer" :options="scelteValePer" label="nome" :reduce="valoreDi" :clearable="false"
                                placeholder="Scegli: tutti i condomini, o cerca un condominio…" class="w-full" data-testid="form-vale-per" />
                            <span v-if="errori.condominio_id" class="block text-xs text-red-600">{{ errori.condominio_id }}</span>
                        </label>
                    </div>
                    <label class="block space-y-1">
                        <span class="block text-xs text-slate-500 dark:text-slate-400">Nome del modello</span>
                        <Input v-model="modulo.nome" maxlength="120" data-testid="form-nome" />
                        <span v-if="errori.nome" class="block text-xs text-red-600">{{ errori.nome }}</span>
                    </label>
                    <label class="block space-y-1">
                        <span class="block text-xs text-slate-500 dark:text-slate-400">Testo</span>
                        <Textarea v-model="modulo.testo" rows="8" data-testid="form-testo" />
                        <span v-if="errori.testo" class="block text-xs text-red-600">{{ errori.testo }}</span>
                    </label>
                    <div class="space-y-1">
                        <span class="block text-xs text-slate-500 dark:text-slate-400">I campi, che si riempiono per ogni persona</span>
                        <ul class="text-xs" data-testid="form-campi">
                            <li v-for="(descrizione, campo) in campiDelModulo" :key="campo">{{ campo }}: {{ descrizione }}</li>
                        </ul>
                    </div>
                    <p v-if="fraseFissaDi(modulo.tipo)" class="text-xs text-slate-600 dark:text-slate-300" data-testid="form-frase-fissa">{{ fraseFissaDi(modulo.tipo) }}</p>
                    <div v-if="modulo.testo.trim()" class="space-y-1">
                        <span class="block text-xs text-slate-500 dark:text-slate-400">Anteprima, con valori d'esempio</span>
                        <p class="whitespace-pre-line rounded-md border bg-slate-50 p-2 text-sm dark:bg-slate-900/40" data-testid="form-anteprima">{{ anteprima }}</p>
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="ghost" @click="modulo = chiuso()">Annulla</Button>
                    <Button :disabled="!salvabile || inCorso" data-testid="form-salva" @click="salva">Salva il modello</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="daEliminare !== null" @update:open="(v: boolean) => { if (!v) daEliminare = null; }">
            <DialogContent class="sm:max-w-md" data-testid="conferma-elimina">
                <DialogHeader>
                    <DialogTitle>Eliminare «{{ daEliminare?.nome }}»?</DialogTitle>
                    <DialogDescription>{{ FRASE_ELIMINA }}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="daEliminare = null">Annulla</Button>
                    <Button variant="destructive" data-testid="elimina-conferma" :disabled="eliminando" @click="elimina">Elimina il modello</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
