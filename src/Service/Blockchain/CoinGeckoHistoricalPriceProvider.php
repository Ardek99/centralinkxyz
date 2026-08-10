<?php

namespace App\Service\Blockchain;

use App\Enum\CryptoType;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CoinGeckoHistoricalPriceProvider
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {}

    public function getHistoricalPrice(\DateTimeImmutable $date, CryptoType $cryptoType): ?float
    {
        $formattedDate = $date->format('d-m-Y');
        $url = sprintf('https://api.coingecko.com/api/v3/coins/%s/history?date=%s', $cryptoType->cryptoId(), $formattedDate);

        $response = $this->httpClient->request('GET', $url);
        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();
        if (!isset($data['market_data']['current_price']['usd'])) {
            return null;
        }

        return (float) $data['market_data']['current_price']['usd'];
    }
}