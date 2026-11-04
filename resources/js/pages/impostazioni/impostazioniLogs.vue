<script setup lang="ts">

import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { debounce } from 'lodash'; 
import { Mail, Search, CheckCircle2, FileText, AlertCircle } from 'lucide-vue-next';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

// Props
const props = defineProps<{
    mailLogs: any;
    filters: { search: string };
}>();

// Ricerca
const search = ref(props.filters.search || '');

// Gestione Ricerca
const handleSearch = debounce((val: string) => {
    router.get(route('logs.index'), { search: val }, { 
        preserveState: true, 
        preserveScroll: true, 
        replace: true 
    });
}, 300);

watch(search, (val) => {
    handleSearch(val);
});

// Helper Formattazione Data
const formatDate = (dateString: string) => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleString('it-IT', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
};
</script>

<template>
    <AppLayout>
        <Head title="Audit & Logs" />

        <div class="px-4 py-6">
            
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white flex items-center gap-2">
                        Audit & Logs
                    </h1>
                    <p class="text-muted-foreground text-sm mt-1">
                        Il registro delle email inviate dal programma, con il motivo di quelle non partite.
                    </p>
                </div>
                
                <div class="relative w-full md:w-64">
                    <Search class="absolute left-2 top-2.5 h-4 w-4 text-muted-foreground" />
                    <Input 
                        v-model="search" 
                        placeholder="Cerca..." 
                        class="pl-8 h-9 bg-white dark:bg-gray-950" 
                    />
                </div>
            </div>

            <nav class="inline-flex items-center space-x-2 shadow ring-1 ring-black/5 md:rounded-lg p-2 mb-4 bg-white dark:bg-card w-full sm:w-auto overflow-x-auto">
                
                <!-- Una sola scheda finché «Attività Sistema» non ha dati (Coda 465): è quella aperta. -->
                <Button
                    variant="ghost"
                    class="justify-start gap-2 bg-muted"
                >
                    <Mail class="h-4 w-4" />
                    Email Logs
                    <Badge variant="secondary" class="ml-1 h-5 px-1.5 text-[10px] pointer-events-none">
                        {{ props.mailLogs.total }}
                    </Badge>
                </Button>

            </nav>

            <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4 bg-white dark:bg-card">
                
                <section class="w-full">
                    <div class="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow class="bg-muted/50">
                                    <TableHead class="w-[50px] text-center">Stato</TableHead>
                                    <TableHead>Destinatario</TableHead>
                                    <TableHead class="hidden md:table-cell">Oggetto</TableHead>
                                    <TableHead class="hidden lg:table-cell">Driver</TableHead>
                                    <TableHead class="text-right">Data Invio</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="log in mailLogs.data" :key="log.id" class="hover:bg-muted/50">
                                    <TableCell class="text-center p-2">
                                        <div v-if="log.status === 'sent'" class="inline-flex items-center justify-center w-8 h-8 rounded-full text-green-600">
                                            <CheckCircle2 class="w-4 h-4" />
                                        </div>
                                        <div v-else-if="log.status === 'logged'" class="inline-flex items-center justify-center w-8 h-8 rounded-full text-blue-600">
                                            <FileText class="w-4 h-4" />
                                        </div>
                                        <div v-else class="inline-flex items-center justify-center w-8 h-8 rounded-full text-red-600">
                                            <AlertCircle class="w-4 h-4" />
                                        </div>
                                    </TableCell>
                                    <TableCell class="font-medium text-sm">
                                        {{ log.recipient }}
                                        <!-- Coda 121: il motivo di un invio fallito, sotto il destinatario (si legge anche con «Oggetto» e «Driver» nascosti). -->
                                        <div v-if="log.error_message" data-motivo-invio :title="log.error_message"
                                             class="mt-0.5 max-w-[420px] break-words text-xs font-normal text-red-600 dark:text-red-400">
                                            {{ log.error_message }}
                                        </div>
                                    </TableCell>
                                    <TableCell class="hidden md:table-cell text-sm max-w-[250px]">
                                        <div class="truncate" :title="log.subject">{{ log.subject }}</div>
                                    </TableCell>
                                    <TableCell class="hidden lg:table-cell">
                                        <Badge variant="outline" class="font-mono text-[10px] uppercase">{{ log.mailer }}</Badge>
                                    </TableCell>
                                    <TableCell class="text-right text-xs text-muted-foreground whitespace-nowrap">
                                        {{ formatDate(log.sent_at) }}
                                    </TableCell>
                                </TableRow>
                                
                                <TableRow v-if="mailLogs.data.length === 0">
                                    <TableCell colspan="5" class="h-32 text-center text-muted-foreground">
                                        <div class="flex flex-col items-center gap-2">
                                            <Mail class="w-8 h-8 opacity-20" />
                                            <span>Nessun log email trovato</span>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>

                    <div v-if="mailLogs.links.length > 3" class="flex items-center justify-between pt-4">
                        <div class="text-xs text-muted-foreground">
                            Pag. {{ mailLogs.current_page }} di {{ mailLogs.last_page }}
                        </div>
                        <div class="flex gap-1">
                            <!-- Coda 464: «precedente» e «successiva» si riconoscono dalla posizione, perché l'etichetta arriva nella lingua dell'applicazione
                                 (e con le entità HTML); senza un indirizzo (prima e ultima pagina) non sono un collegamento. -->
                            <Button 
                                v-for="(link, i) in mailLogs.links" :key="i"
                                :as="link.url ? Link : 'button'" 
                                :href="link.url ?? undefined" 
                                :variant="link.active ? 'default' : 'outline'"
                                size="icon"
                                class="h-8 w-8"
                                :disabled="!link.url"
                                v-html="i === 0 ? '<' : i === mailLogs.links.length - 1 ? '>' : link.label"
                            />
                        </div>
                    </div>
                </section>

            </div>
        </div>
    </AppLayout>
</template>