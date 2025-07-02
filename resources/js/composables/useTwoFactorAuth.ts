import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

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
 
  const page = usePage();
  const csrfToken = ref((page.props.csrf_token as string) || '');

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

  const getHeaders = () => ({
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-CSRF-TOKEN': csrfToken.value,
    'X-Requested-With': 'XMLHttpRequest',
  });

  // Automatically enable 2FA when modal opens and QR is not yet fetched
  watch([showModal, verifyStep, qrCodeSvg], ([modal, verify, qr]) => {
    if (modal && !verify && !qr) {
      enable();
    }
  });

  const enable = async () => {
    try {
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
      const response = await fetch(route('two-factor.confirm'), {
        method: 'POST',
        headers: getHeaders(),
        body: JSON.stringify({ code: formattedCode }),
      });

      if (response.ok) {
        const data: ConfirmResponse = await response.json();
        if (data.recovery_codes) {
          recoveryCodesList.value = data.recovery_codes;
        }

        confirmed.value = true;
        verifyStep.value = false;
        showModal.value = false;
        showingRecoveryCodes.value = true;
        passcode.value = '';
        error.value = '';
      } else {
        const errorData = await response.json();
        error.value = errorData.message || 'Invalid verification code';
        passcode.value = '';
        console.error('Verification error:', errorData.message);
      }
    } catch (err) {
      error.value = 'An error occurred while confirming 2FA';
      console.error('Error confirming 2FA:', err);
    }
  };

  const regenerateRecoveryCodes = async () => {
    try {
      const response = await fetch(route('two-factor.regenerate-recovery-codes'), {
        method: 'POST',
        headers: getHeaders(),
      });

      if (response.ok) {
        const data: RecoveryCodesResponse = await response.json();
        recoveryCodesList.value = data.recovery_codes || [];
      } else {
        console.error('Error regenerating codes:', response.statusText);
      }
    } catch (error) {
      console.error('Error regenerating codes:', error);
    }
  };

  const disable = async () => {
    try {
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
