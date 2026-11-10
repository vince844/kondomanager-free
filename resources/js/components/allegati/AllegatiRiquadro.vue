<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
import AllegatiCampoModuli from '@/components/allegati/AllegatiCampoModuli.vue';
import {
    Check,
    Download,
    ExternalLink,
    Files,
    FileText,
    Image as ImageIcon,
    Info,
    Pencil,
    Settings2,
    Trash2,
    X,
} from 'lucide-vue-next';
import { trans, wTrans } from 'laravel-vue-i18n';
import type { AllegatiConfig, AllegatoAccesso, AllegatoItem, AllegatoTipoElemento } from '@/types/allegati';

interface Props {
    tipo: AllegatoTipoElemento;
    elementoId: number;
    porta: 'admin' | 'user';
    allegati?: AllegatoItem[];
    config?: AllegatiConfig;
}

const props = withDefaults(defineProps<Props>(), {
    allegati: () => [],
    config: () => ({
        accesso: 'aperti',
        moderati: true,
        puo_caricare: false,
        puo_impostare: false,
        massimo: 5,
        rimasti: 5,
        limite_file: '10 MB',
        formati: ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
    }),
});

const uploadForm = useForm<{ allegati: File[] }>({
    allegati: [],
});

const erroreCaricamento = computed(() => {
    const errs = uploadForm.errors as Record<string, string>;
    for (const [k, v] of Object.entries(errs)) {
        if (k === 'allegati' || k.startsWith('allegati.')) {
            return v;
        }
    }
    return undefined;
});

const settingsForm = useForm<{
    allegati_accesso: AllegatoAccesso;
    allegati_moderati: boolean;
}>({
    allegati_accesso: props.config.accesso,
    allegati_moderati: props.config.moderati,
});

const etichettaAccessoConfig = computed(() => {
    if (props.config.accesso === 'aperti') return wTrans('allegati.settings.access_aperti').value;
    if (props.config.accesso === 'consultazione') return wTrans('allegati.settings.access_consultazione').value;
    if (props.config.accesso === 'riservati') return wTrans('allegati.settings.access_riservati').value;
    return '';
});

const etichettaAccessoSelezionato = computed(() => {
    if (settingsForm.allegati_accesso === 'aperti') return wTrans('allegati.settings.access_aperti').value;
    if (settingsForm.allegati_accesso === 'consultazione') return wTrans('allegati.settings.access_consultazione').value;
    if (settingsForm.allegati_accesso === 'riservati') return wTrans('allegati.settings.access_riservati').value;
    return '';
});

function ripristinaImpostazioni() {
    settingsForm.allegati_accesso = props.config.accesso;
    settingsForm.allegati_moderati = props.config.moderati;
}

watch(
    () => props.config,
    (nuova) => {
        if (nuova) {
            settingsForm.allegati_accesso = nuova.accesso;
            settingsForm.allegati_moderati = nuova.moderati;
        }
    },
    { deep: true }
);

const idInModifica = ref<number | null>(null);
const nomeInModifica = ref<string>('');
const erroreRinomina = ref<string | null>(null);
const allegatoDaEliminare = ref<AllegatoItem | null>(null);
const mostraConfermaElimina = ref(false);
const mostraModaleImpostazioni = ref(false);
const invioAzioneInCorso = ref(false);

function chiudiModaleImpostazioni() {
    ripristinaImpostazioni();
    mostraModaleImpostazioni.value = false;
}

watch(mostraModaleImpostazioni, (aperta) => {
    if (!aperta) {
        ripristinaImpostazioni();
    }
});

function etichettaMime(mime: string): string {
    if (mime === 'application/pdf') return 'PDF';
    if (mime === 'image/jpeg') return 'JPG';
    if (mime === 'image/png') return 'PNG';
    if (mime === 'image/webp') return 'WebP';
    return mime;
}

function formattaDimensione(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1).replace('.', ',')} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2).replace('.', ',')} MB`;
}

function formattaData(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleDateString('it-IT', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function inviaCaricamento() {
    if (uploadForm.allegati.length === 0) return;
    const url = route(`${props.porta}.${props.tipo}.allegati.store`, props.elementoId);
    uploadForm.post(url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.reset('allegati');
        },
    });
}

function salvaImpostazioni() {
    const url = route(`admin.${props.tipo}.allegati.impostazioni`, props.elementoId);
    settingsForm.patch(url, {
        preserveScroll: true,
        onSuccess: () => {
            mostraModaleImpostazioni.value = false;
        },
    });
}

function avviaRinomina(allegato: AllegatoItem) {
    idInModifica.value = allegato.id;
    nomeInModifica.value = allegato.nome;
    erroreRinomina.value = null;
}

function annullaRinomina() {
    idInModifica.value = null;
    nomeInModifica.value = '';
    erroreRinomina.value = null;
}

function salvaRinomina(allegato: AllegatoItem) {
    const pulito = nomeInModifica.value.trim();
    if (!pulito) return;
    erroreRinomina.value = null;
    invioAzioneInCorso.value = true;
    router.patch(
        route(`${props.porta}.allegati.update`, allegato.id),
        { nome: pulito },
        {
            preserveScroll: true,
            onSuccess: () => {
                idInModifica.value = null;
                nomeInModifica.value = '';
                erroreRinomina.value = null;
            },
            onError: (errors: Record<string, string>) => {
                erroreRinomina.value = errors.nome ?? errors.name ?? Object.values(errors)[0] ?? null;
            },
            onFinish: () => {
                invioAzioneInCorso.value = false;
            },
        }
    );
}

function approvaAllegato(allegato: AllegatoItem) {
    invioAzioneInCorso.value = true;
    router.post(
        route('admin.allegati.approva', allegato.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                invioAzioneInCorso.value = false;
            },
        }
    );
}

function chiediElimina(allegato: AllegatoItem) {
    allegatoDaEliminare.value = allegato;
    mostraConfermaElimina.value = true;
}

function confermaElimina() {
    if (!allegatoDaEliminare.value) return;
    const id = allegatoDaEliminare.value.id;
    invioAzioneInCorso.value = true;
    router.delete(route(`${props.porta}.allegati.destroy`, id), {
        preserveScroll: true,
        onFinish: () => {
            invioAzioneInCorso.value = false;
            mostraConfermaElimina.value = false;
            allegatoDaEliminare.value = null;
        },
    });
}
</script>

<template>
    <Card class="shadow-none">
        <CardHeader class="pb-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <CardTitle class="text-base font-semibold flex items-center gap-2">
                    <Files class="h-4 w-4 text-muted-foreground" />
                    <span>{{ trans('allegati.title') }}</span>
                    <Badge variant="secondary" class="tabular-nums">
                        {{ allegati.length }} / {{ config.massimo }}
                    </Badge>
                </CardTitle>

                <!-- Riepilogo impostazioni e pulsante modale (solo pannello se puo_impostare) -->
                <div v-if="porta === 'admin' && config.puo_impostare" class="flex flex-wrap items-center gap-1.5 sm:gap-2">
                    <!-- Badge accesso -->
                    <Badge variant="outline" class="text-xs font-normal">
                        {{ etichettaAccessoConfig }}
                    </Badge>

                    <!-- Badge moderazione -->
                    <Badge
                        v-if="config.moderati"
                        variant="secondary"
                        class="bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30 text-xs font-normal whitespace-nowrap"
                    >
                        {{ trans('allegati.settings.moderation_label') }}
                    </Badge>

                    <!-- Pulsante impostazioni -->
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-7 px-2 sm:px-2.5 text-xs gap-1.5 shrink-0"
                        :aria-label="trans('allegati.settings.title')"
                        :title="trans('allegati.settings.title')"
                        @click="mostraModaleImpostazioni = true"
                    >
                        <Settings2 class="h-3.5 w-3.5 text-muted-foreground" />
                        <span class="hidden sm:inline">{{ trans('allegati.settings.title') }}</span>
                    </Button>
                </div>
            </div>
        </CardHeader>

        <CardContent class="space-y-4">
            <!-- Elenco allegati (nessuna miniatura, nome sempre come testo) -->
            <Empty v-if="allegati.length === 0" class="border border-dashed py-8">
                <EmptyHeader>
                    <EmptyMedia variant="icon" class="bg-slate-50/50 dark:bg-slate-800/50 text-slate-500">
                        <Files class="w-6 h-6" />
                    </EmptyMedia>
                    <EmptyTitle>{{ trans('allegati.empty') }}</EmptyTitle>
                    <EmptyDescription>{{ trans('allegati.empty_element_description') }}</EmptyDescription>
                </EmptyHeader>
            </Empty>

            <ul v-else class="divide-y rounded-lg border max-h-96 overflow-y-auto">
                <li
                    v-for="allegato in allegati"
                    :key="allegato.id"
                    class="flex flex-col gap-2.5 p-3.5"
                >
                    <div class="min-w-0 w-full space-y-1">
                        <div v-if="idInModifica === allegato.id" class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <Input
                                    v-model="nomeInModifica"
                                    type="text"
                                    maxlength="255"
                                    class="h-8 text-sm flex-1 min-w-[200px]"
                                    @keyup.enter="salvaRinomina(allegato)"
                                />
                                <Button
                                    type="button"
                                    size="sm"
                                    class="h-8"
                                    :disabled="invioAzioneInCorso"
                                    @click="salvaRinomina(allegato)"
                                >
                                    <Check class="h-3.5 w-3.5 mr-1" />
                                    {{ trans('allegati.actions.save_name') }}
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    class="h-8"
                                    @click="annullaRinomina"
                                >
                                    <X class="h-3.5 w-3.5 mr-1" />
                                    {{ trans('allegati.actions.cancel') }}
                                </Button>
                            </div>
                            <p v-if="erroreRinomina" class="text-xs text-destructive">
                                {{ erroreRinomina }}
                            </p>
                        </div>

                        <div v-else class="flex flex-wrap items-center gap-2">
                            <FileText
                                v-if="allegato.mime_type === 'application/pdf'"
                                class="h-4 w-4 shrink-0 text-muted-foreground"
                            />
                            <ImageIcon
                                v-else
                                class="h-4 w-4 shrink-0 text-muted-foreground"
                            />
                            <span class="font-medium text-sm break-words">{{ allegato.nome }}</span>
                            <Badge variant="outline" class="text-xs">
                                {{ etichettaMime(allegato.mime_type) }}
                            </Badge>
                            <Badge
                                v-if="allegato.stato === 'in_attesa'"
                                variant="secondary"
                                class="bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30 text-xs"
                            >
                                {{ trans('allegati.pending_badge') }}
                            </Badge>
                        </div>

                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground tabular-nums">
                            <span>{{ formattaDimensione(allegato.dimensione) }}</span>
                            <span>•</span>
                            <span>{{ allegato.autore }}</span>
                            <template v-if="allegato.caricato_il">
                                <span>•</span>
                                <span>{{ formattaData(allegato.caricato_il) }}</span>
                            </template>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5 pt-0.5">
                        <Button as-child variant="outline" size="sm" class="h-7 px-2 text-xs">
                            <a :href="allegato.url_apri" target="_blank" rel="noopener">
                                <ExternalLink class="h-3 w-3 mr-1" />
                                {{ trans('allegati.actions.open') }}
                            </a>
                        </Button>

                        <Button as-child variant="outline" size="sm" class="h-7 px-2 text-xs">
                            <a :href="allegato.url_scarica">
                                <Download class="h-3 w-3 mr-1" />
                                {{ trans('allegati.actions.download') }}
                            </a>
                        </Button>

                        <Button
                            v-if="porta === 'admin' && allegato.puo_approvare && allegato.stato === 'in_attesa'"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="h-7 px-2 text-xs text-emerald-600 dark:text-emerald-400 hover:text-emerald-700 dark:hover:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-950/30"
                            :disabled="invioAzioneInCorso"
                            @click="approvaAllegato(allegato)"
                        >
                            <Check class="h-3 w-3 mr-1" />
                            {{ trans('allegati.actions.approve') }}
                        </Button>

                        <Button
                            v-if="allegato.puo_modificare && idInModifica !== allegato.id"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="h-7 px-2 text-xs"
                            @click="avviaRinomina(allegato)"
                        >
                            <Pencil class="h-3 w-3 mr-1" />
                            {{ trans('allegati.actions.rename') }}
                        </Button>

                        <Button
                            v-if="allegato.puo_eliminare"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="h-7 px-2 text-xs text-destructive hover:text-destructive hover:bg-destructive/10"
                            :disabled="invioAzioneInCorso"
                            @click="chiediElimina(allegato)"
                        >
                            <Trash2 class="h-3 w-3 mr-1" />
                            {{ trans('allegati.actions.delete') }}
                        </Button>
                    </div>
                </li>
            </ul>

            <!-- Modulo di caricamento (solo se puo_caricare) -->
            <div v-if="config.puo_caricare" class="border-t pt-4">
                <p v-if="config.rimasti <= 0" class="text-xs text-muted-foreground tabular-nums">
                    {{ trans('allegati.upload.no_slots', { massimo: String(config.massimo) }) }}
                </p>

                <form v-else class="space-y-3" @submit.prevent="inviaCaricamento">
                    <AllegatiCampoModuli
                        v-model="uploadForm.allegati"
                        :massimo="config.massimo"
                        :rimasti="config.rimasti"
                        :limite-file="config.limite_file"
                        :formati="config.formati"
                        :error="erroreCaricamento"
                    />

                    <div v-if="uploadForm.allegati.length > 0" class="flex justify-end">
                        <Button type="submit" size="sm" :disabled="uploadForm.processing">
                            {{
                                uploadForm.processing
                                    ? trans('allegati.upload.uploading')
                                    : trans('allegati.upload.button')
                            }}
                        </Button>
                    </div>
                </form>
            </div>
        </CardContent>
    </Card>

    <Dialog v-model:open="mostraConfermaElimina">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ trans('allegati.confirm_delete.title') }}</DialogTitle>
                <DialogDescription>
                    {{
                        trans('allegati.confirm_delete.description', {
                            nome: allegatoDaEliminare?.nome ?? '',
                        })
                    }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button
                    type="button"
                    variant="outline"
                    @click="mostraConfermaElimina = false"
                >
                    {{ trans('allegati.actions.cancel') }}
                </Button>
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="invioAzioneInCorso"
                    @click="confermaElimina"
                >
                    {{ trans('allegati.actions.delete') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Modale Impostazioni allegati per l'elemento -->
    <Dialog v-model:open="mostraModaleImpostazioni">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Settings2 class="h-4 w-4 text-muted-foreground" />
                    <span>{{ trans('allegati.settings.title') }}</span>
                </DialogTitle>
                <DialogDescription>
                    {{ trans('allegati.settings.dialog_description') }}
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4 py-2">
                <div class="space-y-1.5">
                    <div class="flex items-center gap-1.5">
                        <HoverCard :open-delay="150">
                            <HoverCardTrigger as-child>
                                <span class="inline-flex items-center gap-1.5 text-slate-700 dark:text-slate-300 hover:text-primary cursor-pointer select-none">
                                    <span class="text-xs font-medium">{{ trans('allegati.settings.access_label') }}</span>
                                    <Info class="w-3.5 h-3.5 text-slate-400 hover:text-primary transition-colors" />
                                </span>
                            </HoverCardTrigger>
                            <HoverCardContent
                                align="start"
                                :side-offset="6"
                                :collision-padding="16"
                                class="z-[60] w-72 sm:w-80 p-3 bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 shadow-xl text-xs space-y-2"
                            >
                                <h4 class="font-semibold text-slate-800 dark:text-slate-200">
                                    {{ trans('allegati.settings.access_label') }}
                                </h4>
                                <ul class="space-y-1.5 text-muted-foreground leading-relaxed">
                                    <li>
                                        <strong class="text-slate-700 dark:text-slate-300">{{ trans('allegati.settings.access_aperti') }}:</strong>
                                        {{ trans('allegati.settings.hint_aperti') }}
                                    </li>
                                    <li>
                                        <strong class="text-slate-700 dark:text-slate-300">{{ trans('allegati.settings.access_consultazione') }}:</strong>
                                        {{ trans('allegati.settings.hint_consultazione') }}
                                    </li>
                                    <li>
                                        <strong class="text-slate-700 dark:text-slate-300">{{ trans('allegati.settings.access_riservati') }}:</strong>
                                        {{ trans('allegati.settings.hint_riservati') }}
                                    </li>
                                </ul>
                            </HoverCardContent>
                        </HoverCard>
                    </div>
                    <Select v-model="settingsForm.allegati_accesso">
                        <SelectTrigger class="w-full h-9 text-xs bg-background">
                            <SelectValue>{{ etichettaAccessoSelezionato }}</SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="aperti">
                                {{ trans('allegati.settings.access_aperti') }}
                            </SelectItem>
                            <SelectItem value="consultazione">
                                {{ trans('allegati.settings.access_consultazione') }}
                            </SelectItem>
                            <SelectItem value="riservati">
                                {{ trans('allegati.settings.access_riservati') }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="rounded-lg border bg-muted/20 p-3 space-y-2">
                    <div class="flex items-center justify-between gap-3">
                        <label for="switch-moderazione" class="text-xs font-medium text-slate-700 dark:text-slate-300 cursor-pointer">
                            {{ trans('allegati.settings.moderation_label') }}
                        </label>
                        <Switch id="switch-moderazione" v-model="settingsForm.allegati_moderati" />
                    </div>
                    <p class="text-[11px] text-muted-foreground leading-relaxed">
                        {{ trans('allegati.settings.moderation_hint') }}
                    </p>
                </div>
            </div>

            <DialogFooter class="gap-2">
                <Button
                    type="button"
                    variant="outline"
                    @click="chiudiModaleImpostazioni"
                >
                    {{ trans('allegati.actions.cancel') }}
                </Button>
                <Button
                    type="button"
                    :disabled="settingsForm.processing"
                    @click="salvaImpostazioni"
                >
                    {{ trans('allegati.actions.save_settings') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

