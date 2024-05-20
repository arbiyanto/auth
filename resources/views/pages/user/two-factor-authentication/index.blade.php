<?php

use function Laravel\Folio\{middleware, name};
use Livewire\Volt\Component;
use Livewire\Attributes\On;
use PragmaRX\Google2FA\Google2FA;
use Devdojo\Auth\Actions\TwoFactorAuth\DisableTwoFactorAuthentication;
use Devdojo\Auth\Actions\TwoFactorAuth\GenerateNewRecoveryCodes;
use Devdojo\Auth\Actions\TwoFactorAuth\GenerateQrCodeAndSecretKey;

name('user.two-factor-authentication');
middleware(['auth', 'verified', 'two-factor-enabled']);

new class extends Component
{
    public $enabled = false;

    // confirmed means that it has been enabled and the user has confirmed a code
    public $confirmed = false;

    public $showRecoveryCodes = true;

    public $secret = '';
    public $codes = '';
    public $qr = '';
    
    public function mount(){
        if(is_null(auth()->user()->two_factor_confirmed_at)) {
            app(DisableTwoFactorAuthentication::class)(auth()->user());
        } else {
            $this->confirmed = true;
        }
    }

    public function enable(){

        $QrCodeAndSecret = new GenerateQrCodeAndSecretKey();
        [$this->qr, $this->secret] = $QrCodeAndSecret(auth()->user());
        
        auth()->user()->forceFill([
            'two_factor_secret' => encrypt($this->secret),
            'two_factor_recovery_codes' => encrypt(json_encode($this->generateCodes()))
        ])->save();

        $this->enabled = true;
    }

    private function generateCodes(){
        $generateCodesFor = new GenerateNewRecoveryCodes();
        return $generateCodesFor(auth()->user());
    }

    public function cancelTwoFactor(){
        auth()->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null
        ])->save();
        
        $this->enabled = false;
    }

    #[On('submitCode')] 
    public function submitCode($code)
    {
        if(empty($code) || strlen($code) < 6){
            // TODO - If the code is empty or it's less than 6 characters we want to show the user a message
            dd('show validation error');
            return;
        }

        //dd($this->secret);

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($this->secret, $code);

        if($valid){
            auth()->user()->forceFill([
                'two_factor_confirmed_at' => now(),
            ])->save();

            $this->confirmed = true;
        } else {
            // TODO - implement an invalid message when the user enters an incorrect auth code
            dd('show invalide code message');
        }
    }

    public function disable(){
        $disable = new DisableTwoFactorAuthentication;
        $disable(auth()->user());

        $this->enabled = false;
        $this->confirmed = false;
        $this->showRecoveryCodes = true;
    }

}

?>

<x-auth::layouts.empty title="Two Factor Authentication">
    @volt('user.two-factor-authentication')
        <section class="flex @container justify-center items-center w-screen h-screen">

            <div x-data x-on:code-input-complete.window="$dispatch('submitCode', [event.detail.code])" class="flex flex-col mx-auto w-full max-w-sm text-sm">
                @if($confirmed)
                    <div class="flex flex-col space-y-5">
                        <h2 class="text-xl">You have enabled two factor authentication.</h2>
                        <p>When two factor authentication is enabled, you will be prompted for a secure, random token during authentication. You may retrieve this token from your phone's Google Authenticator application.</p>    
                        @if($showRecoveryCodes)
                            <div class="relative">
                                <p class="font-medium">Store these recovery codes in a secure password manager. They can be used to recover access to your account if your two factor authentication device is lost.</p>
                                <div class="grid gap-1 px-4 py-4 mt-4 max-w-xl font-mono text-sm bg-gray-100 rounded-lg dark:bg-gray-900 dark:text-gray-100">
                                    
                                    @foreach (json_decode(decrypt(auth()->user()->two_factor_recovery_codes), true) as $code)
                                        <div>{{ $code }}</div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        <div class="flex items-center space-x-5">
                            <x-auth::elements.button type="primary" wire:click="regenerateCodes" rounded="md" size="md">Regenerate Codes</x-auto::elements.button>
                            <x-auth::elements.button type="danger" wire:click="disable" size="md" rounded="md">Disable 2FA</x-auto::elements.button>
                        </div>
                    </div>
                    
                @else
                    @if(!$enabled)
                        <div class="flex relative flex-col justify-start items-start space-y-5">
                            <h2 class="text-lg font-semibold">Two factor authentication disabled.</h2>
                            <p class="-translate-y-1">When you enabled 2FA, you will be prompted for a secure code during authentication. This code can be retrieved from your phone's Google Authenticator application.</p>
                            <div class="relative w-auto">
                                <x-auth::elements.button type="primary" rounded="md" size="md" wire:click="enable" wire:target="enable">Enable</x-auth>
                            </div>
                        </div>
                    @else
                        <div  class="relative space-y-5 w-full">
                            <div class="space-y-5">
                                <h2 class="text-lg font-semibold">Finish enabling two factor authentication.</h2>
                                <p>Enable two-factor authentication to receive a secure token from your phone's Google Authenticator during login.</p>
                                <p class="font-bold">To enable two-factor authentication, scan the QR code or enter the setup key using your phone's authenticator app and provide the OTP code.</p>
                            </div>

                            <div class="overflow-hidden relative mx-auto max-w-full rounded-lg border border-zinc-200">
                                <img src="data:image/png;base64, {{ $qr }}" style="width:400px; height:auto" />
                            </div>

                            <p class="font-semibold text-center">
                                {{ __('Setup Key') }}: {{ $secret }}
                            </p>

                            <x-auth::elements.input-code id="auth-input-code" digits="6" eventCallback="code-input-complete" type="text" label="Code" />
                            
                            <div class="flex items-center space-x-5">
                                <x-auth::elements.button type="secondary" size="md" rounded="md" wire:click="cancelTwoFactor" wire:target="cancelTwoFactor">Cancel</x-auto::elements.button>
                                <x-auth::elements.button type="primary" size="md" wire:click="submitCode(document.getElementById('auth-input-code').value)" wire:target="submitCode" rounded="md">Confirm</x-auto::elements.button>
                            </div>

                        </div>
                    @endif
                @endif
            </div>
        </section>
    @endvolt

</x-auth::layouts.empty>
