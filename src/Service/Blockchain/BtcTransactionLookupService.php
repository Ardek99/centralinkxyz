<?php

namespace App\Service\Blockchain;

use App\Enum\CryptoType;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class BtcTransactionLookupService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CoinGeckoHistoricalPriceProvider $historicalPriceProvider
    ){}

    public function lookup(string $txHash): array
    {
        $response = $this->httpClient->request(
            'GET',
            sprintf('https://blockchain.info/rawtx/%s?format=json', $txHash)
        );
        $tx = $response->toArray();

        $totalTx = 0;
        $senders = [];

        foreach ($tx['inputs'] as $inputs) {
            $senders[] = $inputs['prev_out']['addr'] ?? '';
        }

        $time = isset($tx['time']) ? new \DateTimeImmutable('@' . $tx['time']) : null;
        $price = $time ? $this->historicalPriceProvider->getHistoricalPrice($time, CryptoType::BTC) : null;

        foreach ($tx['out'] as $output) {
            if (!isset($output['addr'])) {
                continue;
            }
            if (!in_array($output['addr'], $senders)) {
                $totalTx += $output['value'] ?? 0;
            }
        }

        return [
            'date' => $time,
            'price' => $price,
            'amount' => $totalTx / 100000000,
        ];
    }
}