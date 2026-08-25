<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { CloudDownload, CheckCircle2, Loader2, AlertTriangle } from 'lucide-vue-next';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

const props = defineProps({
    currentVersion: String,
    availableRelease: Object, // Oggetto o null
    inProgress: Boolean,
    requisiti: Object,        // null quando il server regge la versione offerta
    errors: Object
});

const form = useForm({});

// Se manca qualcosa, l'aggiornamento non si può fare: non è una raccomandazione.
// Con PHP sotto la soglia il programma non si avvia; senza le estensioni si avvia e poi
// fallisce dove servono — nel caso di `gd`, su ogni stampa.
const puoAggiornare = computed(() => !props.requisiti);

const startLaunch = () => {
    if(confirm('Confermi di voler scaricare e installare l\'aggiornamento? Il sito andrà momentaneamente offline.')) {
        form.post(route('system.upgrade.launch'));
    }
};
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-50/50 p-4">
        <Card class="w-full max-w-lg shadow-xl">
            <CardHeader class="pb-4 border-b">
                <CardTitle class="flex items-center gap-2">
                    <CloudDownload class="w-6 h-6 text-blue-600" />
                    Gestione aggiornamenti
                </CardTitle>
                <CardDescription>Versione installata: <Badge variant="secondary">{{ currentVersion }}</Badge></CardDescription>
            </CardHeader>

            <CardContent class="pt-6 space-y-6">
                <Alert variant="destructive" v-if="errors.msg">
                    <AlertTriangle class="h-4 w-4" />
                    <AlertTitle>Errore</AlertTitle>
                    <AlertDescription>{{ errors.msg }}</AlertDescription>
                </Alert>

                <!-- ⚠️ Prima del pulsante, non dopo il clic: ciò che questo server non ha. -->
                <div v-if="availableRelease && requisiti" class="rounded-lg border border-amber-300 bg-amber-50 p-4 space-y-3">
                    <div class="flex items-start gap-2">
                        <AlertTriangle class="h-5 w-5 text-amber-600 shrink-0 mt-0.5" />
                        <div>
                            <h3 class="font-bold text-amber-900">Il tuo server non è ancora pronto</h3>
                            <p class="text-sm text-amber-800 mt-1">
                                L'aggiornamento è disponibile, ma non può essere installato finché non
                                sistemi quanto segue. Nessuna modifica è stata fatta.
                            </p>
                        </div>
                    </div>

                    <div v-if="requisiti.php" class="text-sm text-amber-900 bg-amber-100/60 rounded p-3">
                        <strong>Versione di PHP.</strong> Questa versione richiede
                        <strong>PHP {{ requisiti.php.richiesto }}</strong>, il tuo server ha la
                        <strong>{{ requisiti.php.attuale }}</strong>. Si cambia dal pannello del tuo
                        hosting, di solito alla voce «Versione PHP» o «PHP Selector».
                    </div>

                    <div v-if="requisiti.estensioni && requisiti.estensioni.length" class="text-sm text-amber-900 bg-amber-100/60 rounded p-3">
                        <strong>Estensioni PHP mancanti:</strong>&nbsp;<span class="font-semibold">{{ requisiti.estensioni.join(', ') }}</span>.
                        Si attivano dallo stesso pannello, di solito accanto alla versione di PHP. Se non
                        le trovi, le può attivare l'assistenza del tuo hosting.
                    </div>
                </div>

                <div v-if="availableRelease" class="space-y-4">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <div class="flex items-start justify-between">
                            <div>
                                <h3 class="font-bold text-blue-900 text-lg">Nuova versione {{ availableRelease.version }}</h3>
                                <p class="text-sm text-blue-700 mt-1">È disponibile un aggiornamento ufficiale.</p>
                            </div>
                            <Badge class="bg-blue-600">Consigliato</Badge>
                        </div>
                    </div>
                    
                    <div class="text-sm text-gray-500">
                        <p>Questo processo scaricherà il pacchetto in sicurezza, eseguirà un backup automatico dei file critici e aggiornerà il sistema.</p>
                    </div>
                </div>

                <div v-else class="text-center py-8">
                    <div class="mx-auto bg-green-100 p-4 rounded-full w-fit mb-4">
                        <CheckCircle2 class="w-12 h-12 text-green-600" />
                    </div>
                    <h3 class="text-lg font-medium text-gray-900">Il sistema è aggiornato</h3>
                    <p class="text-gray-500 mt-1">Stai utilizzando l'ultima versione disponibile.</p>
                </div>
            </CardContent>

            <CardFooter class="bg-gray-50/50 border-t pt-6 flex justify-between">
                <Button variant="outline" as-child>
                    <Link :href="route('admin.dashboard')">Torna alla Dashboard</Link>
                </Button>

                <Button 
                    v-if="availableRelease" 
                    @click="startLaunch" 
                    :disabled="form.processing || inProgress || !puoAggiornare"
                    class="bg-blue-600 hover:bg-blue-700"
                >
                    <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                    {{ form.processing ? 'Preparazione...' : 'Scarica e Installa' }}
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>