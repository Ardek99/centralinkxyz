<?php

namespace App\Enum;

enum CryptoType: string
{
    case BTC = 'btc';
    case ETH = 'eth';
    case USDC = 'usdc';
    case USDT = 'usdt';

    public function label(): string
    {
        return match ($this) {
            self::BTC => 'Bitcoin (BTC)',
            self::ETH => 'Ethereum (ETH)',
            self::USDC => 'USD Coin (USDC)',
            self::USDT => 'Tether (USDT)'
        };
    }

    public function symbol(): string
    {
        return strtoupper($this->value);
    }

    public function cryptoId(): string
    {
        return match ($this) {
            self::BTC => 'bitcoin',
            self::ETH => 'ethereum',
            self::USDC => 'usd-coin',
            self::USDT => 'tether'
        };
    }
}

