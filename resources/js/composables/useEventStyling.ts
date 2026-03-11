import { 
    ClockAlert, ClockArrowUp, CheckCircle2, AlertCircle, 
    ArrowUpFromLine, ArrowDownToLine, AlertTriangle, XCircle, 
    CalendarDays, Info, Coins, PieChart, LucideIcon, History
} from 'lucide-vue-next';

// Interfaccia per il valore di ritorno (utile per intellisense)
interface EventStyle {
    color: string;
    bgColor: string;
    borderColor: string;
    icon: LucideIcon;
    label: string;
}

export function useEventStyling() {

    const getDaysRemaining = (dateInput: string | Date | null | undefined): number => {
        if (!dateInput) return 0;
        
        const now = new Date();
        const target = new Date(dateInput);
        
        // Normalizziamo a mezzanotte
        now.setHours(0, 0, 0, 0);
        target.setHours(0, 0, 0, 0);
        
        if (isNaN(target.getTime())) return 0;
        
        const msPerDay = 1000 * 60 * 60 * 24;
        return Math.floor((target.getTime() - now.getTime()) / msPerDay);
    };

    const getEventStyle = (evento: any): EventStyle => {
        const meta = evento.meta || {};
        const type = meta.type || 'default';
        const status = meta.status || 'pending';
        const requiresAction = meta.requires_action || false;
        
        // Parsing valori numerici
        const importoTotale = Math.abs(Number(meta.totale_rata || meta.importo_originale || 0));
        const residuo = Number(meta.importo_restante || 0);
        const pagatoCash = Number(meta.importo_pagato || 0);

        // Calcolo logiche booleane
        const isCreditSource = residuo < -0.01; 
        const isFullyCovered = !!meta.is_covered_by_credit; 
        const isPartiallyCoveredByCredit = !isCreditSource && 
                                           !isFullyCovered && 
                                           residuo > 0.01 && 
                                           residuo < (importoTotale - 0.01) && 
                                           pagatoCash === 0;

        const dataRiferimento = evento.start_time || evento.occurs || evento.occurs_at;
        const days = getDaysRemaining(dataRiferimento);

        // ---------------------------------------------------------
        // 1. LOGICHE AMMINISTRATIVE (Admin)
        // ---------------------------------------------------------
        if (type === 'emissione_rata') {
            if (days <= 0) {
                return { 
                    color: 'text-red-700 dark:text-red-500 font-bold', 
                    bgColor: 'bg-red-50 dark:bg-red-900/20', 
                    borderColor: 'border-red-200 dark:border-red-800', 
                    icon: AlertTriangle, 
                    label: 'Scaduto e da emettere' 
                };
            }
            return { 
                color: 'text-blue-600 dark:text-blue-400', 
                bgColor: 'bg-blue-50 dark:bg-blue-900/20', 
                borderColor: 'border-blue-200 dark:border-blue-800', 
                icon: ArrowUpFromLine, 
                label: 'Da emettere' 
            };
        }

        if (type === 'controllo_incassi') {
            if (days <= 0) {
                return { 
                    color: 'text-red-700 dark:text-red-500 font-bold', 
                    bgColor: 'bg-red-50 dark:bg-red-900/20', 
                    borderColor: 'border-red-200 dark:border-red-800', 
                    icon: AlertCircle, 
                    label: 'Verifica urgente' 
                };
            }
            return { 
                color: 'text-purple-600 dark:text-purple-400', 
                bgColor: 'bg-purple-50 dark:bg-purple-900/20', 
                borderColor: 'border-purple-200 dark:border-purple-800', 
                icon: ArrowDownToLine, 
                label: 'Verifica incassi' 
            };
        }

        // ---------------------------------------------------------
        // 2. STATI STANDARD (Pagato/Rifiutato)
        // ---------------------------------------------------------
        if (status === 'rejected') {
            return { 
                color: 'text-red-600 dark:text-red-400 font-bold', 
                bgColor: 'bg-red-50 dark:bg-red-900/20', 
                borderColor: 'border-red-200 dark:border-red-800', 
                icon: XCircle, 
                label: 'Rifiutato' 
            };
        }
        
        if (status === 'paid') {
            return { 
                color: 'text-emerald-600 dark:text-emerald-400', 
                bgColor: 'bg-emerald-50 dark:bg-emerald-900/20', 
                borderColor: 'border-emerald-200 dark:border-emerald-800', 
                icon: CheckCircle2, 
                label: 'Pagato' 
            };
        }

        // ---------------------------------------------------------
        // 3. LOGICHE CREDITO E COPERTURA (Condomino)
        // ---------------------------------------------------------
        if (isFullyCovered) {
            return { 
                color: 'text-emerald-700 dark:text-emerald-400 font-medium', 
                bgColor: 'bg-emerald-50 dark:bg-emerald-900/20', 
                borderColor: 'border-emerald-200 dark:border-emerald-800', 
                icon: Coins, 
                label: 'Coperta' 
            };
        }

        if (isPartiallyCoveredByCredit) {
            return { 
                color: 'text-indigo-700 dark:text-indigo-400 font-medium', 
                bgColor: 'bg-indigo-50 dark:bg-indigo-900/20', 
                borderColor: 'border-indigo-200 dark:border-indigo-800', 
                icon: PieChart, 
                label: 'Parz. Coperta' 
            };
        }

        if (isCreditSource) {
            return { 
                color: 'text-blue-600 dark:text-blue-400 font-bold', 
                bgColor: 'bg-blue-50 dark:bg-blue-900/20', 
                borderColor: 'border-blue-200 dark:border-blue-800', 
                icon: Info, 
                label: 'A credito' 
            };
        }

        // ---------------------------------------------------------
        // 4. STATI INTERMEDI E ACTION REQUIRED
        // ---------------------------------------------------------
        if (status === 'partial') {
            return { 
                color: 'text-orange-600 dark:text-orange-400', 
                bgColor: 'bg-orange-50 dark:bg-orange-900/20', 
                borderColor: 'border-orange-200 dark:border-orange-800', 
                icon: ClockArrowUp, 
                label: 'Pagato parz.' 
            };
        }

        if (status === 'reported' || requiresAction) {
            return { 
                color: 'text-amber-600 dark:text-amber-400', 
                bgColor: 'bg-amber-50 dark:bg-amber-900/20', 
                borderColor: 'border-amber-200 dark:border-amber-800', 
                icon: AlertCircle, 
                label: 'In verifica' 
            };
        }

        // INIZIO MODIFICHE UX CONDÒMINO -------------------------

        // 4.1 LA DOPAMINA (Rata Pagata)
        // Ora che il backend ci manda le rate pagate negli ultimi 30gg,
        // dobbiamo assicurarci di intercettarle PRIMA che scatti l'allarme "Scaduto" in basso.
        if (type === 'scadenza_rata_condomino' && status === 'paid') {
            return { 
                color: 'text-emerald-600 dark:text-emerald-400 font-bold', 
                bgColor: 'bg-emerald-50 dark:bg-emerald-900/20', 
                borderColor: 'border-emerald-200 dark:border-emerald-800', 
                icon: CheckCircle2, 
                label: 'Pagamento Ricevuto' 
            };
        }

        // 4.2 L'ANTI-TRAUMA (Rata 0 Scaduta)
        // Se è la Rata 0, è tecnicamente scaduta nel passato (es. 1 Gennaio),
        // ma non vogliamo mostrare l'allarme rosso "Scaduto".
        if (type === 'scadenza_rata_condomino' && meta.numero_rata === 0 && status !== 'paid') {
            return { 
                color: 'text-amber-600 dark:text-amber-500 font-bold', 
                bgColor: 'bg-amber-50 dark:bg-amber-900/20', 
                borderColor: 'border-amber-200 dark:border-amber-800', 
                icon: History, 
                label: 'Debito Pregresso' 
            };
        }
        
        // 🟢 FINE MODIFICHE UX CONDÒMINO ---------------------------

        // ---------------------------------------------------------
        // 5. SCADENZE TEMPORALI DEFAULT
        // ---------------------------------------------------------
        if (days < 0) {
            return { 
                color: 'text-red-700 dark:text-red-500 font-bold', 
                bgColor: 'bg-red-100 dark:bg-red-900/30', 
                borderColor: 'border-red-300 dark:border-red-700', 
                icon: ClockAlert, 
                label: 'Scaduto' 
            };
        } else if (days <= 7) {
            return { 
                color: 'text-red-500 dark:text-red-400', 
                bgColor: 'bg-red-50 dark:bg-red-900/20', 
                borderColor: 'border-red-200 dark:border-red-800', 
                icon: ClockAlert, 
                label: `Scade tra ${days} gg` 
            };
        } else if (days <= 14) {
            return { 
                color: 'text-yellow-500 dark:text-yellow-400', 
                bgColor: 'bg-yellow-50 dark:bg-yellow-900/20', 
                borderColor: 'border-yellow-200 dark:border-yellow-800', 
                icon: ClockArrowUp, 
                label: `Scade tra ${days} gg` 
            };
        } else {
            return { 
                color: 'text-slate-600 dark:text-slate-400', 
                bgColor: 'bg-slate-50 dark:bg-slate-900/20', 
                borderColor: 'border-slate-200 dark:border-slate-800', 
                icon: CalendarDays, 
                label: `Tra ${days} giorni` 
            };
        }
    };

    return { getEventStyle };
}