@props(['plan' => null])

<flux:modal wire:model="showPackageCommandModal" class="md:w-[28rem]" data-package-command-modal>
    @if($plan)
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $plan->action->label() }} selected apps</flux:heading>
                <flux:text class="mt-2" data-package-command-summary>{{ $plan->summary() }}</flux:text>
                @if($plan->skippedNote())
                    <flux:text class="mt-2 text-amber-700 dark:text-amber-400" data-package-command-skipped>{{ $plan->skippedNote() }}</flux:text>
                @endif
                @unless($plan->isEmpty())
                    <flux:text size="sm" class="mt-2">Offline devices run it when they next check in.</flux:text>
                @endunless
            </div>

            @if($plan->action->canCloseApp() && ! $plan->isEmpty())
                <flux:checkbox wire:model="closeAppFirst" label="Close the app first" description="For uninstalls and upgrades that hang while the app is open" data-close-app-first />
            @endif

            <flux:error name="script" />
            <flux:error name="packageId.PackageId" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                @unless($plan->isEmpty())
                    <flux:button wire:click="queuePlannedPackageCommands" :variant="$plan->action->buttonVariant()" data-confirm-package-commands>
                        {{ $plan->action->label() }} ({{ $plan->commandCount() }})
                    </flux:button>
                @endunless
            </div>
        </div>
    @endif
</flux:modal>
