<script setup>

import { Link } from '@inertiajs/vue3';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { CheckCircle2, ArrowRight } from 'lucide-vue-next';

const props = defineProps({
    log: Object
});

</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-50/50 p-4">
        <Card class="w-full max-w-lg shadow-xl border-t-4 border-t-green-500">
            <CardHeader class="text-center border-b pb-6">
                <div class="mx-auto bg-green-100 p-3 rounded-full w-fit mb-4">
                    <CheckCircle2 class="w-10 h-10 text-green-600" />
                </div>
                <CardTitle class="text-3xl font-bold text-green-700">Aggiornamento riuscito!</CardTitle>
                <CardDescription class="text-base mt-2">
                    Il sistema è ora aggiornato alla versione <Badge variant="outline" class="font-bold border-green-500 text-green-700">{{ log.version }}</Badge>
                </CardDescription>
                <p class="text-xs text-muted-foreground mt-1">Data: {{ log.date }}</p>
            </CardHeader>

            <CardContent class="pt-6">
                <h3 class="font-semibold text-gray-900 mb-4 flex items-center">
                    <span class="w-1.5 h-1.5 bg-blue-500 rounded-full mr-2"></span>
                    Novità di questa versione
                </h3>
                
                <ul class="space-y-3">
                    <li v-for="(feature, index) in log.features" :key="index" class="flex items-start text-sm text-gray-600">
                        <CheckCircle2 class="w-4 h-4 text-green-500 mr-2 mt-0.5 shrink-0" />
                        {{ feature }}
                    </li>
                </ul>
            </CardContent>

            <CardFooter class="bg-gray-50/50 border-t pt-6">
                <Button as-child class="w-full" size="lg">
                    <Link :href="route('home')">
                        Torna alla dashboard
                        <ArrowRight class="ml-2 h-4 w-4" />
                    </Link>
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>