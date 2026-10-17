<?php

namespace App\Providers\Filament;

use App\Filament\Actions\AdministratorAccountAction;
use App\Filament\Pages\Dashboard;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class XPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('x')
            ->path('x')
            ->viteTheme('resources/css/filament/x/theme.css')
            ->login()
            ->userMenuItems([
                'profile' => fn () => AdministratorAccountAction::make(),
            ])
            ->brandName((string) config('app.name'))
            // 沿用 Filament 主题偏好机制，中文字体使用本机回退，不请求远程字体。
            ->font('"PingFang SC", "Microsoft YaHei", "Noto Sans CJK SC", system-ui, sans-serif', provider: LocalFontProvider::class)
            ->darkMode()
            ->defaultThemeMode(ThemeMode::Dark)
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('15rem')
            ->colors([
                'primary' => Color::hex('#4F46E5'),
                'success' => Color::hex('#35A66F'),
                'info' => Color::hex('#4E83D1'),
                'warning' => Color::hex('#C58A2B'),
                'danger' => Color::hex('#D45A64'),
                'gray' => Color::hex('#7B818D'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->maxContentWidth(Width::Full)
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
