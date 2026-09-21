<?php

namespace App\Filament\Actions;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AdministratorAccountAction
{
    public static function make(): Action
    {
        return Action::make('administratorAccount')
            ->label('管理员账号')
            ->icon('heroicon-o-user-circle')
            ->sort(-1)
            ->modalHeading('管理员账号')
            ->modalDescription('更新管理员账号信息；修改邮箱或密码时需要验证当前密码。')
            ->modalSubmitActionLabel('保存')
            ->modalWidth('lg')
            ->rateLimit(5)
            ->fillForm(function (): array {
                $user = Filament::auth()->user();

                return [
                    'name' => $user?->getAttribute('name'),
                    'email' => $user?->getAttribute('email'),
                    'currentPassword' => null,
                    'password' => null,
                    'passwordConfirmation' => null,
                ];
            })
            ->schema([
                Section::make('账号信息')
                    ->schema([
                        TextInput::make('name')
                            ->label('管理员名称')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('邮箱')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                table: User::class,
                                column: 'email',
                                ignorable: fn (): ?User => Filament::auth()->user(),
                            )
                            ->live(debounce: 500),
                    ]),
                Section::make('修改密码')
                    ->description('不修改密码时留空。')
                    ->schema([
                        TextInput::make('password')
                            ->label('新密码')
                            ->password()
                            ->revealable()
                            ->rule(Password::default())
                            ->showAllValidationMessages()
                            ->autocomplete('new-password')
                            ->live(debounce: 500)
                            ->same('passwordConfirmation'),
                        TextInput::make('passwordConfirmation')
                            ->label('确认新密码')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required(fn (Get $get): bool => filled($get('password'))),
                        TextInput::make('currentPassword')
                            ->label('当前密码')
                            ->password()
                            ->revealable()
                            ->autocomplete('current-password')
                            ->currentPassword()
                            ->required(fn (Get $get): bool => filled($get('password')) || self::emailChanged($get('email')))
                            ->visible(fn (Get $get): bool => filled($get('password')) || self::emailChanged($get('email'))),
                    ]),
            ])
            ->action(function (array $data, Action $action): void {
                /** @var User $user */
                $user = Filament::auth()->user();
                $newPassword = $data['password'] ?? null;

                DB::transaction(function () use ($data, $newPassword, $user): void {
                    $attributes = [
                        'name' => $data['name'],
                        'email' => $data['email'],
                    ];

                    if (filled($newPassword)) {
                        $attributes['password'] = Hash::make($newPassword);
                    }

                    $user->update($attributes);
                });

                if (filled($newPassword)) {
                    $loginUrl = Filament::getLoginUrl() ?? Filament::getUrl();

                    Filament::auth()->logout();
                    session()->invalidate();
                    session()->regenerateToken();

                    $action->redirect($loginUrl);

                    return;
                }

                Notification::make()
                    ->title('管理员账号已更新')
                    ->success()
                    ->send();
            });
    }

    private static function emailChanged(mixed $email): bool
    {
        return $email !== Filament::auth()->user()?->getAttribute('email');
    }
}
