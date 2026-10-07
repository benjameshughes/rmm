@use('App\Enums\InstallTarget')

<div>
    <flux:modal wire:model="showModal" class="md:w-[32rem]" data-install-software>
        @if($showModal)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Install software</flux:heading>
                    <flux:text class="mt-2">Installs one winget package machine-wide. Only approved Windows devices that take commands are included.</flux:text>
                </div>

                @if($isPackageFixed)
                    <flux:field>
                        <flux:label>Package</flux:label>
                        <flux:text class="font-mono" data-install-package>{{ $packageId }}</flux:text>
                    </flux:field>
                @else
                    <flux:autocomplete wire:model.live.debounce.300ms="packageId" label="Package" description="Pick one the fleet already has, or type a winget package ID such as Mozilla.Firefox." placeholder="Publisher.App" icon="magnifying-glass">
                        @foreach($this->suggestions as $suggestion)
                            <flux:autocomplete.item wire:key="suggestion-{{ $suggestion }}">{{ $suggestion }}</flux:autocomplete.item>
                        @endforeach
                    </flux:autocomplete>
                @endif

                <flux:radio.group wire:model.live="target" label="Install on" variant="segmented" size="sm">
                    @foreach($targets as $installTarget)
                        <flux:radio value="{{ $installTarget->value }}" :label="$installTarget->label()" wire:key="target-{{ $installTarget->value }}" />
                    @endforeach
                </flux:radio.group>

                @if($target === InstallTarget::Group->value)
                    <flux:select wire:model.live="targetGroupId" label="Group" placeholder="Choose a group...">
                        @foreach($groups as $group)
                            <flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @elseif($target === InstallTarget::Tag->value)
                    <flux:select wire:model.live="targetTagId" label="Tag" placeholder="Choose a tag...">
                        @foreach($tags as $tag)
                            <flux:select.option value="{{ $tag->id }}">{{ $tag->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                @if(trim($packageId) !== '')
                    <flux:callout icon="information-circle" color="zinc" data-install-plan>
                        <flux:callout.heading>{{ $this->plan->summary() }}</flux:callout.heading>
                        <flux:callout.text>
                            @if($this->plan->skippedNote())
                                <span class="block" data-install-skipped>{{ $this->plan->skippedNote() }}</span>
                            @endif
                            Offline devices install it when they next check in.
                        </flux:callout.text>
                    </flux:callout>
                @endif

                <flux:error name="target" />
                <flux:error name="script" />
                <flux:error name="packageId.PackageId" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" icon="arrow-down-tray" wire:click="install" data-confirm-install>Install</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
