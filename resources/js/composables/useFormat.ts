// composables/useFormat.ts
import { formatBytes, formatNumber } from '@/utils/formatBytes';

export function useFormat() {
  const formatStorage = (bytes: number, precision?: number) => 
    formatBytes(bytes, precision, false); // Usa unità binarie per storage
  
  const formatFileSize = (bytes: number, precision?: number) => 
    formatBytes(bytes, precision, true); // Usa unità SI per dimensioni file
  
  const formatCount = (num: number) => 
    formatNumber(num);
  
  const formatDate = (date: Date | string, locale = 'it-IT') => {
    const d = typeof date === 'string' ? new Date(date) : date;
    return d.toLocaleDateString(locale, {
      year: 'numeric',
      month: 'long',
      day: 'numeric'
    });
  };

  const formatCurrency = (amount: number) => {
    // Gestisce undefined/null
    if (amount === undefined || amount === null) return '-';
    
    // Usa le API native del browser per formattare in Euro
    return new Intl.NumberFormat('it-IT', {
      style: 'currency',
      currency: 'EUR',
      minimumFractionDigits: 2
    }).format(amount);
  };
  
  return {
    formatStorage,
    formatFileSize,
    formatCount,
    formatDate,
    formatCurrency,
    formatBytes, 
    formatNumber,
  };
}