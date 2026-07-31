<script setup>
import { ref, computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { Checkbox } from '@/components/ui/checkbox';
import { Rocket, AlertTriangle, Loader2, CheckCircle2, ArrowRight, DatabaseBackup, ShieldCheck } from 'lucide-vue-next';

const props = defineProps({
    currentVersion: String,
    newVersion: String,
    needsUpgrade: Boolean,
    canBackup: Boolean,
    errors: Object,
});

const form = useForm({});

// Backup di sicurezza pre-aggiornamento: attivo di default (se possibile).
const doBackup = ref(props.canBackup);
const acknowledgeNoBackup = ref(false);

// Fasi locali: idle → backing_up (backup a step) → migrating (form.post run)
const phase = ref('idle');
const backupPercent = ref(0);
const backupError = ref(null);

const BACKUP_RUNNING = ['pending', 'dumping_database', 'archiving_files', 'finalizing'];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Si può procedere se: il backup automatico non è disponibile (non c'è nulla a
// cui rinunciare — la pagina chiede una copia dal pannello hosting), oppure lo
// si sta facendo, oppure si è confermato esplicitamente di procedere senza
// (attrito voluto per l'amministratore distratto).
//
// Il primo ramo NON è ridondante: la conferma esplicita vive dentro il blocco
// v-if="canBackup", quindi quando il backup non è disponibile non è nemmeno
// renderizzata e non potrebbe mai diventare true. Senza quel ramo il pulsante
// resterebbe disabilitato per sempre, murando ogni aggiornamento che parte da
// una versione priva dell'infrastruttura backup.
const canProceed = computed(() =>
    phase.value === 'idle' && (!props.canBackup || doBackup.value || acknowledgeNoBackup.value)
);

const buttonLabel = computed(() => {
    if (phase.value === 'backing_up') return `Backup di sicurezza… ${backupPercent.value}%`;
    if (phase.value === 'migrating') return 'Aggiornamento in corso…';
    return 'Avvia aggiornamento';
});

async function runSafetyBackup() {
    backupError.value = null;
    backupPercent.value = 0;

    const { data } = await axios.post(route('system.upgrade.backup'));
    let backup = data.backup;

    while (BACKUP_RUNNING.includes(backup.status)) {
        const res = await axios.post(route('system.upgrade.backup.step', backup.uuid));
        backup = res.data.backup;
        backupPercent.value = backup.percent ?? 0;
        if (backup.status === 'failed') {
            throw new Error(backup.error || 'Backup di sicurezza non riuscito.');
        }
        if (BACKUP_RUNNING.includes(backup.status)) await sleep(600);
    }
}

async function startUpgrade() {
    // Ramo senza backup (confermato): vai diritto alle migrazioni.
    if (!doBackup.value || !props.canBackup) {
        phase.value = 'migrating';
        form.post(route('system.upgrade.run'), { onError: () => (phase.value = 'idle') });
        return;
    }

    // Ramo con backup: prima il backup di sicurezza, poi le migrazioni.
    phase.value = 'backing_up';
    try {
        await runSafetyBackup();
    } catch (e) {
        phase.value = 'idle';
        backupError.value = e?.response?.data?.message || e?.message || 'Backup di sicurezza non riuscito.';
        return;
    }

    phase.value = 'migrating';
    form.post(route('system.upgrade.run'), { onError: () => (phase.value = 'idle') });
}
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-50/50 p-4">
        <Card class="w-full max-w-md shadow-lg border-t-4"
              :class="needsUpgrade ? 'border-t-blue-600' : 'border-t-green-500'">

            <CardHeader class="text-center pb-2">
                <div v-if="needsUpgrade" class="mx-auto bg-blue-100 p-3 rounded-full w-fit mb-4">
                    <Rocket class="w-8 h-8 text-blue-600" />
                </div>
                <div v-else class="mx-auto bg-green-100 p-3 rounded-full w-fit mb-4">
                    <CheckCircle2 class="w-8 h-8 text-green-600" />
                </div>

                <CardTitle class="text-2xl">
                    {{ needsUpgrade ? 'Aggiornamento disponibile' : 'Sistema aggiornato' }}
                </CardTitle>

                <CardDescription>
                    <div class="flex items-center justify-center gap-2 mt-2">
                        <span class="text-xs font-medium text-muted-foreground uppercase">Attuale:</span>
                        <Badge variant="secondary">{{ currentVersion }}</Badge>
                        <ArrowRight v-if="needsUpgrade" class="w-3 h-3 text-muted-foreground" />
                        <Badge v-if="needsUpgrade" variant="default" class="bg-blue-600">{{ newVersion }}</Badge>
                    </div>
                </CardDescription>
            </CardHeader>

            <CardContent class="space-y-4 pt-4">
                <template v-if="needsUpgrade">
                    <Alert variant="destructive" v-if="errors.msg">
                        <AlertTriangle class="h-4 w-4" />
                        <AlertTitle>Errore</AlertTitle>
                        <AlertDescription>
                            {{ errors.msg }}
                            <span v-if="canBackup" class="block mt-1.5 text-xs opacity-90">
                                Se hai creato un backup di sicurezza, puoi ripristinarlo dalla pagina Gestione backups.
                            </span>
                        </AlertDescription>
                    </Alert>

                    <Alert class="bg-amber-50 border-amber-200 text-amber-800">
                        <AlertTriangle class="h-4 w-4 text-amber-600" />
                        <AlertTitle class="text-amber-800 font-semibold text-sm">Pronto per l'aggiornamento?</AlertTitle>
                        <AlertDescription class="text-amber-700 text-xs mt-1">
                            L'operazione eseguirà le migrazioni del database e rigenererà la cache di sistema.
                            Se la pagina si blocca o va in errore, ricaricala e premi di nuovo: l'aggiornamento
                            riprende da dove si era interrotto.
                        </AlertDescription>
                    </Alert>

                    <!-- Backup di sicurezza pre-aggiornamento -->
                    <div v-if="canBackup" class="rounded-lg border p-3">
                        <label class="flex items-start gap-3" :class="phase === 'idle' ? 'cursor-pointer' : 'opacity-60'">
                            <Switch v-model="doBackup" :disabled="phase !== 'idle'" class="mt-0.5" />
                            <span>
                                <span class="flex items-center gap-1.5 text-sm font-medium">
                                    <DatabaseBackup class="w-3.5 h-3.5 text-muted-foreground shrink-0" />
                                    Backup di sicurezza
                                </span>
                                <span class="block text-[11px] text-muted-foreground mt-0.5">
                                    Una copia del database prima delle migrazioni, per poter tornare indietro se qualcosa va storto.
                                </span>
                            </span>
                        </label>

                        <!-- Avanzamento del backup -->
                        <div v-if="phase === 'backing_up'" class="mt-3">
                            <div class="h-1.5 w-full rounded-full bg-muted overflow-hidden">
                                <div class="h-full rounded-full bg-primary transition-all duration-500" :style="{ width: `${backupPercent}%` }" />
                            </div>
                        </div>

                        <p v-if="backupError" class="mt-2 text-[12px] text-red-600">{{ backupError }}</p>

                        <!-- Attrito sull'opt-out: conferma esplicita -->
                        <div v-if="!doBackup && phase === 'idle'" class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-2.5 dark:border-amber-800/50 dark:bg-amber-900/20">
                            <label class="flex items-start gap-2 cursor-pointer">
                                <Checkbox :model-value="acknowledgeNoBackup" @update:model-value="acknowledgeNoBackup = $event" class="mt-0.5" />
                                <span class="text-[12px] leading-relaxed text-amber-800 dark:text-amber-200/90">
                                    Confermo di voler aggiornare <strong>senza</strong> un backup di sicurezza.
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Se il backup automatico non è disponibile (versione troppo vecchia) -->
                    <Alert v-else class="bg-slate-50 border-slate-200">
                        <ShieldCheck class="h-4 w-4 text-slate-500" />
                        <AlertTitle class="text-slate-700 font-semibold text-sm">Prima di procedere, fai una copia del database</AlertTitle>
                        <AlertDescription class="text-slate-600 text-xs mt-1">
                            Il backup automatico non è ancora disponibile per la versione da cui stai aggiornando:
                            esegui una copia del database dal pannello del tuo hosting. Dagli aggiornamenti successivi
                            a questo sarà Kondomanager a farla per te.
                        </AlertDescription>
                    </Alert>
                </template>

                <div v-else class="text-center py-4 text-sm text-gray-600">
                    <p>Non ci sono aggiornamenti del database pendenti.</p>
                    <p class="mt-1 font-medium text-green-600 italic">Kondomanager è in esecuzione correttamente.</p>
                </div>
            </CardContent>

            <CardFooter>
                <Button
                    v-if="needsUpgrade"
                    class="w-full"
                    size="lg"
                    :disabled="!canProceed"
                    @click="startUpgrade"
                >
                    <Loader2 v-if="phase !== 'idle'" class="mr-2 h-4 w-4 animate-spin" />
                    {{ buttonLabel }}
                </Button>

                <Button v-else as-child variant="outline" class="w-full" size="lg">
                    <Link :href="route('admin.dashboard')">
                        Torna alla dashboard
                    </Link>
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
