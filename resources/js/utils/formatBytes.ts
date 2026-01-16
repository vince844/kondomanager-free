// utils/formatBytes.ts
export function formatBytes(
    bytes: number, 
    precision: number = 2, 
    useSiUnits: boolean = false
): string {
    if (bytes === 0) return '0 B';
    
    const base = useSiUnits ? 1000 : 1024;
    const units = useSiUnits 
        ? ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB']
        : ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB'];
    
    // Calcola l'unità appropriata con limite
    const exponent = Math.floor(Math.log(bytes) / Math.log(base));
    const maxExponent = units.length - 1;
    const i = Math.min(exponent, maxExponent);
    
    // Calcola e formatta il valore
    let value = bytes / Math.pow(base, i);
    
    // Rimuove decimali non necessari
    if (precision > 0) {
        value = parseFloat(value.toFixed(precision));
        // Rimuove .00 se presente
        if (value % 1 === 0) {
            return `${value} ${units[i]}`;
        }
    } else {
        value = Math.round(value);
    }
    
    return `${value} ${units[i]}`;
}

export function formatBytesFromString(
    bytesString: string, 
    precision: number = 2, 
    useSiUnits: boolean = false
): string {
    const bytes = parseInt(bytesString, 10) || 0;
    return formatBytes(bytes, precision, useSiUnits);
}

// Utility per formattare numeri (documenti, conteggi)
export function formatNumber(
    num: number,
    options?: Intl.NumberFormatOptions
): string {
    return new Intl.NumberFormat('it-IT', options).format(num);
}