// composables/useDateConverter.ts
import { parse, format, isValid } from 'date-fns';
import { it } from 'date-fns/locale';

export function useDateConverter() {
  const toBackend = (input: string | Date | null | undefined): string => {
    if (!input) return '';

    let date: Date;

    try {
      if (input instanceof Date) {
        date = input;
      } else if (typeof input === 'string') {
        // Try parsing Italian format first
        date = parse(input, 'dd/MM/yyyy', new Date(), { locale: it });
        
        // If that fails, try parsing as ISO format (from backend)
        if (!isValid(date)) {
          date = new Date(input);
        }
      } else {
        throw new Error('Invalid date input type');
      }

      if (!isValid(date)) {
        console.warn('Invalid date value:', input);
        return '';
      }

      return format(date, 'yyyy-MM-dd');
    } catch (err) {
      console.error('Date conversion failed:', err);
      return '';
    }
  };

  const toItalian = (input: string | Date | null | undefined): string => {
    if (!input) return '';

    let date: Date;
    try {
      if (input instanceof Date) {
        date = input;
      } else if (typeof input === 'string') {
        // Try parsing as ISO format first (from backend)
        date = new Date(input);
        
        // If that fails, try Italian format
        if (!isValid(date)) {
          date = parse(input, 'dd/MM/yyyy', new Date(), { locale: it });
        }
      } else {
        throw new Error('Invalid date input type');
      }

      if (!isValid(date)) {
        console.warn('Invalid date value:', input);
        return '';
      }

      return format(date, 'dd/MM/yyyy', { locale: it });
    } catch (err) {
      console.error('Date conversion failed:', err);
      return '';
    }
  };

  // Helper to check if a value is empty
  const isEmptyDate = (value: any): boolean => {
    return !value || value === '' || (value instanceof Date && isNaN(value.getTime()));
  };

  return { toBackend, toItalian, isEmptyDate };
}