<div
    x-data="{
        heading: '',
        message: '',
        confirmLabel: '',
        danger: false,
        action: null,
        open(detail) {
            this.heading = detail.heading ?? 'Are you sure?';
            this.message = detail.message ?? '';
            this.confirmLabel = detail.confirm ?? 'Confirm';
            this.danger = detail.danger ?? false;
            this.action = detail.action;
            this.$flux.modal('confirm-action').show();
            setTimeout(() => this.$root.querySelector(this.danger ? '[data-confirm-danger]' : '[data-confirm-primary]')?.focus(), 50);
        },
        run() {
            this.$flux.modal('confirm-action').close();
            this.action?.();
            this.action = null;
        },
    }"
    x-on:confirm-action.window="open($event.detail)"
>
    <flux:modal name="confirm-action" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg" x-text="heading" />
                <flux:text class="mt-2" x-text="message" />
            </div>

            <div class="flex gap-2">
                <flux:spacer />

                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" x-show="danger" x-on:click="run()" data-confirm-danger><span x-text="confirmLabel"></span></flux:button>
                <flux:button variant="primary" x-show="! danger" x-on:click="run()" data-confirm-primary><span x-text="confirmLabel"></span></flux:button>
            </div>
        </div>
    </flux:modal>
</div>
