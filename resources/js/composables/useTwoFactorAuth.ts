import { ref, watch } from 'vue';

interface EnableResponse {
  qrCode: string;
  secret: string;
}

interface ConfirmResponse {
  status: string;
  recovery_codes?: string[];
  message?: string;
}

interface RecoveryCodesResponse {
  recovery_codes: string[];
}

export function useTwoFactorAuth(initialConfirmed: boolean, initialRecoveryCodes: string[]) {
  const csrfToken = ref(document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');

  const confirmed = ref(initialConfirmed);
  const qrCodeSvg = ref('');
  const secretKey = ref('');
  const recoveryCodesList = ref(initialRecoveryCodes);
  const copied = ref(false);
  const passcode = ref('');
  const error = ref('');
  const verifyStep = ref(false);
  const showingRecoveryCodes = ref(false);
  const showModal = ref(false);

  // Helper to fetch fresh CSRF token from server and update meta tag & ref
  const fetchCsrfToken = async () => {
    const response = await fetch('/csrf-token', { credentials: 'same-origin' });
    if (response.ok) {
      const data = await response.json();
      const meta = document.querySelector('meta[name="csrf-token"]');
      if (meta) {
        meta.setAttribute('content', data.csrf_token);
      }
      csrfToken.value = data.csrf_token;
      return data.csrf_token;
    }
    throw new Error('Failed to fetch CSRF token');
  };

  // Function to generate fresh headers with current CSRF token
  const getHeaders = () => ({
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-CSRF-TOKEN': csrfToken.value,
    'X-Requested-With': 'XMLHttpRequest',
  });

  // Automatically enable 2FA when modal opens and QR is not yet fetched
  watch([showModal, verifyStep, qrCodeSvg], ([newShowModal, newVerifyStep, newQrCodeSvg]) => {
    if (newShowModal && !newVerifyStep && !newQrCodeSvg) {
      enable();
    }
  });

  const enable = async () => {
    try {
      await fetchCsrfToken(); // Refresh token before request

      const response = await fetch(route('two-factor.enable'), {
        method: 'POST',
        headers: getHeaders(),
      });

      if (response.ok) {
        const data: EnableResponse = await response.json();
        qrCodeSvg.value = data.qrCode;
        secretKey.value = data.secret;
      } else {
        console.error('Error enabling 2FA:', response.statusText);
      }
    } catch (error) {
      console.error('Error enabling 2FA:', error);
    }
  };

  const confirm = async () => {
    if (!passcode.value || passcode.value.length !== 6) return;

    const formattedCode = passcode.value.replace(/\s+/g, '').trim();

    try {
      await fetchCsrfToken(); // Refresh token before request

      const response = await fetch(route('two-factor.confirm'), {
        method: 'POST',
        headers: getHeaders(),
        body: JSON.stringify({ code: formattedCode }),
      });

      if (response.ok) {
        const responseData: ConfirmResponse = await response.json();
        if (responseData.recovery_codes) {
          recoveryCodesList.value = responseData.recovery_codes;
        }

        confirmed.value = true;
        verifyStep.value = false;
        showModal.value = false;
        showingRecoveryCodes.value = true;
        passcode.value = '';
        error.value = '';
      } else {
        const errorData = await response.json();
        console.error('Verification error:', errorData.message);
        error.value = errorData.message || 'Invalid verification code';
        passcode.value = '';
      }
    } catch (err) {
      console.error('Error confirming 2FA:', err);
      error.value = 'An error occurred while confirming 2FA';
    }
  };

  const regenerateRecoveryCodes = async () => {
    try {
      await fetchCsrfToken(); // Refresh token before request

      const response = await fetch(route('two-factor.regenerate-recovery-codes'), {
        method: 'POST',
        headers: getHeaders(),
      });

      if (response.ok) {
        const data: RecoveryCodesResponse = await response.json();
        if (data.recovery_codes) {
          recoveryCodesList.value = data.recovery_codes;
        }
      } else {
        console.error('Error regenerating codes:', response.statusText);
      }
    } catch (error) {
      console.error('Error regenerating codes:', error);
    }
  };

  const disable = async () => {
    try {
      await fetchCsrfToken(); // Refresh token before request

      const response = await fetch(route('two-factor.disable'), {
        method: 'DELETE',
        headers: getHeaders(),
      });

      if (response.ok) {
        confirmed.value = false;
        showingRecoveryCodes.value = false;
        recoveryCodesList.value = [];
        qrCodeSvg.value = '';
        secretKey.value = '';
      } else {
        console.error('Error disabling 2FA:', response.statusText);
      }
    } catch (error) {
      console.error('Error disabling 2FA:', error);
    }
  };

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
  };

  return {
    confirmed,
    qrCodeSvg,
    secretKey,
    recoveryCodesList,
    copied,
    passcode,
    error,
    verifyStep,
    showingRecoveryCodes,
    showModal,
    enable,
    confirm,
    regenerateRecoveryCodes,
    disable,
    copyToClipboard,
  };
}
