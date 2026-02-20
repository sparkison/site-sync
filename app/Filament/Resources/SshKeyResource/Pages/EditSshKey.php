<?php

namespace App\Filament\Resources\SshKeyResource\Pages;

use App\Filament\Resources\SshKeyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSshKey extends EditRecord
{
    protected static string $resource = SshKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
