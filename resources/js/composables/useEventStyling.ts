import { 
    Clock, 
    ClockAlert, 
    ClockArrowUp,
    CheckCircle2, 
    AlertCircle, 
    Banknote, 
    AlertTriangle,
    CalendarClock
} from 'lucide-vue-next';

export function useEventStyling() {

    const getDaysRemaining = (dateInput: string | Date | null | undefined): number => {
        if (!dateInput) return 0;
        const now = new Date();
        const target = new Date(dateInput);
        
        // Resettiamo le ore per confrontare solo i giorni puri
        now.setHours(0, 0, 0, 0);
        target.setHours(0, 0, 0, 0);

        if (isNaN(target.getTime())) return 0;
        
        const msPerDay = 1000 * 60 * 60 * 24;
        return Math.floor((target.getTime() - now.getTime()) / msPerDay);
    };

    const getEventStyle = (evento: any) => {
        const meta = evento.meta || {};
        const type = meta.type || 'default';
        const status = meta.status || 'pending';
        const requiresAction = meta.requires_action || false;
        
        const dataRiferimento = evento.start_time || evento.occurs || evento.occurs_at;
        const days = getDaysRemaining(dataRiferimento);

        // --- 1. PRIORITÀ AL TIPO ADMIN (Emissione Rata) ---
        if (type === 'emissione_rata') {
            // MODIFICA: Se Scaduto O scade Oggi (days <= 0) -> ROSSO
            if (days <= 0) {
                return {
                    color: 'text-red-700 dark:text-red-500 font-bold',
                    bgColor: 'bg-red-50 dark:bg-red-900/20',
                    borderColor: 'border-red-200 dark:border-red-800',
                    icon: AlertTriangle,
                    label: 'Scaduto e da emettere'
                };
            }
            
            // Futuro -> BLU
            return {
                color: 'text-blue-600 dark:text-blue-400',
                bgColor: 'bg-blue-50 dark:bg-blue-900/20',
                borderColor: 'border-blue-200 dark:border-blue-800',
                icon: Banknote,
                label: 'Da emettere'
            };
        }

        // --- 2. PRIORITÀ ALLO STATO (User) ---
        if (status === 'paid') {
            return {
                color: 'text-green-600 dark:text-green-400',
                bgColor: 'bg-green-50 dark:bg-green-900/20',
                borderColor: 'border-green-200 dark:border-green-800',
                icon: CheckCircle2,
                label: 'Pagato'
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

        // --- 3. URGENZA GENERICA ---
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
                label: `Scade tra ${days} giorni`
            };
        } else if (days <= 14) {
            return {
                color: 'text-yellow-500 dark:text-yellow-400',
                bgColor: 'bg-yellow-50 dark:bg-yellow-900/20',
                borderColor: 'border-yellow-200 dark:border-yellow-800',
                icon: ClockArrowUp,
                label: `Scade tra ${days} giorni`
            };
        } else {
            return {
                color: 'text-emerald-500 dark:text-emerald-400',
                bgColor: 'bg-emerald-50 dark:bg-emerald-900/20',
                borderColor: 'border-emerald-200 dark:border-emerald-800',
                icon: Clock,
                label: `Tra ${days} giorni`
            };
        }
    };

    return { getEventStyle };
}