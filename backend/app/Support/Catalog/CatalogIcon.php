<?php

namespace App\Support\Catalog;

use App\Support\Admin\AdminModule;

/**
 * Icons a category or service can use: a fixed list of the icons the admin
 * interface already uses (taken from AdminModule), never free text.
 */
enum CatalogIcon: string
{
    case Layers = 'layers';
    case Transfer = 'transfer';
    case Server = 'server';
    case Card = 'card';
    case Wallet = 'wallet';
    case Cash = 'cash';
    case People = 'people';
    case Bell = 'bell';
    case Chat = 'chat';
    case Chart = 'chart';
    case Shield = 'shield';
    case Person = 'person';
    case Grid = 'grid';

    public function label(): string
    {
        return match ($this) {
            self::Layers => 'Layers',
            self::Transfer => 'Transfer arrows',
            self::Server => 'Server',
            self::Card => 'Card',
            self::Wallet => 'Wallet',
            self::Cash => 'Cash',
            self::People => 'People',
            self::Bell => 'Bell',
            self::Chat => 'Chat bubble',
            self::Chart => 'Chart',
            self::Shield => 'Shield',
            self::Person => 'Person',
            self::Grid => 'Grid',
        };
    }

    /** SVG path data, reused from the admin sidebar icons. */
    public function path(): string
    {
        return (match ($this) {
            self::Layers => AdminModule::Services,
            self::Transfer => AdminModule::Transactions,
            self::Server => AdminModule::Providers,
            self::Card => AdminModule::Payments,
            self::Wallet => AdminModule::Wallet,
            self::Cash => AdminModule::Withdrawals,
            self::People => AdminModule::Referrals,
            self::Bell => AdminModule::Notifications,
            self::Chat => AdminModule::Support,
            self::Chart => AdminModule::Reports,
            self::Shield => AdminModule::Roles,
            self::Person => AdminModule::SystemUsers,
            self::Grid => AdminModule::Dashboard,
        })->icon();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
