<script setup>

import { useForm } from '@inertiajs/vue3';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Rocket, AlertTriangle, Loader2 } from 'lucide-vue-next';

const props = defineProps({
    version: String,
    errors: Object
});

const form = useForm({});

const startUpgrade = () => {
    form.post(route('system.upgrade.run'));
};
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-50/50 p-4">
        <Card class="w-full max-w-md shadow-lg border-t-4 border-t-blue-600">
            <CardHeader class="text-center pb-2">
                <div class="mx-auto bg-blue-100 p-3 rounded-full w-fit mb-4">
                    <Rocket class="w-8 h-8 text-blue-600" />
                </div>
                <CardTitle class="text-2xl">Aggiornamento versione {{ version }}</CardTitle>
                <CardDescription>
                    I file sono pronti. È necessario aggiornare il database.
                </CardDescription>
            </CardHeader>

            <CardContent class="space-y-4">
                <Alert variant="destructive" v-if="errors.msg">
                    <AlertTriangle class="h-4 w-4" />
                    <AlertTitle>Errore</AlertTitle>
                    <AlertDescription>{{ errors.msg }}</AlertDescription>
                </Alert>

                <Alert class="bg-amber-50 border-amber-200 text-amber-800">
                    <AlertTriangle class="h-4 w-4 text-amber-600" />
                    <AlertTitle class="text-amber-800 font-semibold">Attenzione</AlertTitle>
                    <AlertDescription class="text-amber-700 text-xs mt-1">
                        Il sito potrebbe essere irraggiungibile per alcuni secondi durante l'esecuzione delle migrazioni.
                    </AlertDescription>
                </Alert>
            </CardContent>

            <CardFooter>
                <Button 
                    class="w-full" 
                    size="lg" 
                    :disabled="form.processing"
                    @click="startUpgrade"
                >
                    <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                    {{ form.processing ? 'Aggiornamento in corso...' : 'Avvia aggiornamento database' }}
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>