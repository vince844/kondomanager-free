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
  
  return {
    formatStorage,
    formatFileSize,
    formatCount,
    formatDate,
    formatBytes, 
    formatNumber,
  };
}