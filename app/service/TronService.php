<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Log;

class TronService
{
    private $apiBase = 'https://api.trongrid.io';
    private $apiKey;

    // USDT TRC20合约地址
    const USDT_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
    // USDT精度
    const USDT_DECIMALS = 6;
    // TRX精度(1 TRX = 1000000 SUN)
    const TRX_DECIMALS = 6;

    public function __construct()
    {
        $this->apiKey = env('TRON_API_KEY', '');
    }

    /**
     * 查询TRC20代币转入记录(主要查USDT)
     * @param string $address 接收地址
     * @param int $sinceTimestamp 起始时间戳(毫秒)
     * @param string|null $contractAddress 代币合约地址(默认USDT)
     * @return array 转入记录列表 [{tx_hash, from, to, amount, timestamp}]
     */
    public function getIncomingTrc20Transfers(string $address, int $sinceTimestamp, ?string $contractAddress = null): array
    {
        $contract = $contractAddress ?: self::USDT_CONTRACT;
        $url = $this->apiBase . "/v1/accounts/{$address}/transactions/trc20";
        $params = [
            'only_to' => 'true',
            'only_confirmed' => 'true',
            'limit' => 50,
            'contract_address' => $contract,
            'min_block_timestamp' => $sinceTimestamp,
        ];
        if ($this->apiKey) {
            $params['api_key'] = $this->apiKey;
        }

        $response = $this->httpGet($url, $params);
        if (!$response || !isset($response['data'])) {
            return [];
        }

        $result = [];
        foreach ($response['data'] as $tx) {
            $value = $tx['value'] ?? '0';
            $decimals = $tx['token_info']['decimals'] ?? self::USDT_DECIMALS;
            $amount = bcdiv($value, bcpow('10', (string)$decimals), $decimals);

            $result[] = [
                'tx_hash' => $tx['transaction_id'] ?? '',
                'from' => $tx['from'] ?? '',
                'to' => $tx['to'] ?? '',
                'amount' => $amount,
                'timestamp' => intval($tx['block_timestamp'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * 查询TRX原生转账转入记录
     * @param string $address 接收地址(base58)
     * @param int $sinceTimestamp 起始时间戳(毫秒)
     * @return array 转入记录列表 [{tx_hash, from, to, amount, timestamp}]
     */
    public function getIncomingTrxTransfers(string $address, int $sinceTimestamp): array
    {
        $url = $this->apiBase . "/v1/accounts/{$address}/transactions";
        $params = [
            'only_confirmed' => 'true',
            'limit' => 50,
            'min_timestamp' => $sinceTimestamp,
        ];
        if ($this->apiKey) {
            $params['api_key'] = $this->apiKey;
        }

        $response = $this->httpGet($url, $params);
        if (!$response || !isset($response['data'])) {
            return [];
        }

        // 将我们的base58地址转为hex用于比较
        $myAddressHex = $this->base58ToHex($address);

        $result = [];
        foreach ($response['data'] as $tx) {
            // 检查交易是否成功
            $ret = $tx['ret'][0]['contractRet'] ?? '';
            if ($ret !== 'SUCCESS') {
                continue;
            }

            $contracts = $tx['raw_data']['contract'] ?? [];
            foreach ($contracts as $contract) {
                // 只处理TRX转账(TransferContract)
                if (($contract['type'] ?? '') !== 'TransferContract') {
                    continue;
                }

                $value = $contract['parameter']['value'] ?? [];
                $toAddressHex = $value['to_address'] ?? '';
                $ownerAddressHex = $value['owner_address'] ?? '';
                $amountSun = $value['amount'] ?? 0;

                // 比较to地址(都是hex格式)
                if (strtolower($toAddressHex) !== strtolower($myAddressHex)) {
                    continue;
                }

                $amount = bcdiv((string)$amountSun, '1000000', self::TRX_DECIMALS);
                $blockTime = $tx['block_time'] ?? 0;

                $result[] = [
                    'tx_hash' => $tx['txID'] ?? '',
                    'from' => $this->hexToBase58($ownerAddressHex),
                    'to' => $address,
                    'amount' => $amount,
                    'timestamp' => intval($blockTime),
                ];
            }
        }

        return $result;
    }

    /**
     * 查询地址的TRC20余额(USDT)
     */
    public function getUsdtBalance(string $address): float
    {
        $url = $this->apiBase . "/v1/accounts/{$address}";
        $params = [];
        if ($this->apiKey) {
            $params['api_key'] = $this->apiKey;
        }

        $response = $this->httpGet($url, $params);
        if (!$response || !isset($response['data'][0]['trc20'])) {
            return 0.0;
        }

        foreach ($response['data'][0]['trc20'] as $token) {
            if (isset($token[self::USDT_CONTRACT])) {
                return (float) bcdiv($token[self::USDT_CONTRACT], '1000000', 6);
            }
        }
        return 0.0;
    }

    /**
     * 查询地址的TRX余额
     */
    public function getTrxBalance(string $address): float
    {
        $url = $this->apiBase . "/v1/accounts/{$address}";
        $params = [];
        if ($this->apiKey) {
            $params['api_key'] = $this->apiKey;
        }

        $response = $this->httpGet($url, $params);
        if (!$response || !isset($response['data'][0]['balance'])) {
            return 0.0;
        }
        return (float) bcdiv((string)$response['data'][0]['balance'], '1000000', 6);
    }

    /**
     * HTTP GET请求
     */
    private function httpGet(string $url, array $params = []): ?array
    {
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
        ]);
        if ($this->apiKey) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'TRON-PRO-API-KEY: ' . $this->apiKey,
            ]);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Log::error('TronGrid API请求失败: ' . $error);
            return null;
        }

        if ($httpCode !== 200) {
            Log::error('TronGrid API返回非200: ' . $httpCode . ' ' . $response);
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Base58转Hex地址
     */
    private function base58ToHex(string $base58): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $num = '0';
        for ($i = 0; $i < strlen($base58); $i++) {
            $num = bcmul($num, '58');
            $num = bcadd($num, (string)strpos($alphabet, $base58[$i]));
        }

        $hex = '';
        while (bccomp($num, '0') > 0) {
            $rem = bcmod($num, '16');
            $num = bcdiv($num, '16', 0);
            $hex = dechex((int)$rem) . $hex;
        }

        // 处理前导1(对应前导0字节)
        $leadingOnes = 0;
        for ($i = 0; $i < strlen($base58) && $base58[$i] === '1'; $i++) {
            $leadingOnes++;
        }
        // base58地址解码后含21字节地址+4字节校验和=25字节=50 hex字符
        // TronGrid API返回的地址只有21字节(42 hex字符)，需去掉校验和
        if (strlen($hex) > 42) {
            $hex = substr($hex, 0, 42);
        }
        $hex = str_pad($hex, 42, '0', STR_PAD_LEFT);
        return strtoupper($hex);
    }

    /**
     * Hex转Base58地址
     */
    private function hexToBase58(string $hex): string
    {
        $hex = strtoupper($hex);
        if (strpos($hex, '0X') === 0) {
            $hex = substr($hex, 2);
        }

        $binary = hex2bin($hex);
        $hash = hash('sha256', $binary, true);
        $hash = hash('sha256', $hash, true);
        $checksum = substr($hash, 0, 4);
        $binary .= $checksum;

        return $this->base58Encode($binary);
    }

    /**
     * Base58编码
     */
    private function base58Encode(string $data): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $hex = bin2hex($data);

        $num = '0';
        for ($i = 0; $i < strlen($hex); $i++) {
            $num = bcmul($num, '16');
            $num = bcadd($num, (string)hexdec($hex[$i]));
        }

        $result = '';
        while (bccomp($num, '0') > 0) {
            $rem = bcmod($num, '58');
            $num = bcdiv($num, '58', 0);
            $result = $alphabet[(int)$rem] . $result;
        }

        // 前导0字节对应'1'
        for ($i = 0; $i < strlen($data) && $data[$i] === "\x00"; $i++) {
            $result = '1' . $result;
        }

        return $result;
    }

    /**
     * 获取USDT和TRX的实时CNY价格
     * 优先CoinGecko，备用Binance data-api + 实时汇率，缓存5分钟
     * @return array|null ['usdt_cny'=>7.18, 'trx_cny'=>0.93, 'source'=>'CoinGecko', 'updated_at'=>'...']
     */
    public function getRealtimePrices(): ?array
    {
        $cacheFile = runtime_path() . 'crypto_prices.json';
        $cacheTTL = 300; // 5分钟

        // 检查缓存
        if (file_exists($cacheFile)) {
            $cache = json_decode(file_get_contents($cacheFile), true);
            if ($cache && isset($cache['expires_at']) && $cache['expires_at'] > time()) {
                return $cache['data'];
            }
        }

        // 方案1: CoinGecko API (直接返回CNY价格)
        $prices = $this->fetchFromCoingecko();
        if ($prices) {
            $this->savePriceCache($cacheFile, $prices, $cacheTTL);
            return $prices;
        }

        // 方案2: Binance data-api + 实时USD/CNY汇率
        $prices = $this->fetchFromBinanceDataApi();
        if ($prices) {
            $this->savePriceCache($cacheFile, $prices, $cacheTTL);
            return $prices;
        }

        // 方案3: 原Binance API (海外服务器备用)
        $prices = $this->fetchFromBinance();
        if ($prices) {
            $this->savePriceCache($cacheFile, $prices, $cacheTTL);
            return $prices;
        }

        return null;
    }

    /**
     * 从CoinGecko获取价格
     */
    private function fetchFromCoingecko(): ?array
    {
        $url = 'https://api.coingecko.com/api/v3/simple/price?ids=tether,tron&vs_currencies=cny';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['tether']['cny']) && isset($data['tron']['cny'])) {
                return [
                    'usdt_cny' => floatval($data['tether']['cny']),
                    'trx_cny' => floatval($data['tron']['cny']),
                    'source' => 'CoinGecko',
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }
        }
        return null;
    }

    /**
     * 从Binance data-api获取价格(国内可访问)
     * data-api.binance.vision 在国内可直连，用TRX/USDT + 实时USD/CNY汇率换算
     */
    private function fetchFromBinanceDataApi(): ?array
    {
        $trxUrl = 'https://data-api.binance.vision/api/v3/ticker/price?symbol=TRXUSDT';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $trxUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $trxResponse = curl_exec($ch);
        $trxHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($trxHttpCode !== 200 || !$trxResponse) {
            return null;
        }

        $trxData = json_decode($trxResponse, true);
        if (!isset($trxData['price'])) {
            return null;
        }

        $trxUsdt = floatval($trxData['price']);

        // 获取实时USD/CNY汇率
        $usdCny = $this->fetchUsdCnyRate();
        if ($usdCny <= 0) {
            $usdCny = 7.2; // 最终兜底
        }

        return [
            'usdt_cny' => round($usdCny, 4),
            'trx_cny' => round($trxUsdt * $usdCny, 4),
            'source' => 'BinanceData+ExchangeRate',
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 获取实时USD/CNY汇率
     * 优先open.er-api.com，失败则用固定值兜底
     */
    private function fetchUsdCnyRate(): float
    {
        $url = 'https://open.er-api.com/v6/latest/USD';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['rates']['CNY'])) {
                return floatval($data['rates']['CNY']);
            }
        }
        return 0.0;
    }

    /**
     * 从Binance获取价格(海外服务器备用)
     * api.binance.com 在国内可能无法访问，用TRX/USDT + 固定USD/CNY汇率换算
     */
    private function fetchFromBinance(): ?array
    {
        $url = 'https://api.binance.com/api/v3/ticker/price?symbol=TRXUSDT';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['price'])) {
                $trxUsdt = floatval($data['price']);
                $usdCny = $this->fetchUsdCnyRate();
                if ($usdCny <= 0) {
                    $usdCny = 7.2; // 备用固定汇率
                }
                return [
                    'usdt_cny' => round($usdCny, 4),
                    'trx_cny' => round($trxUsdt * $usdCny, 4),
                    'source' => 'Binance',
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }
        }
        return null;
    }

    /**
     * 保存价格缓存
     */
    private function savePriceCache(string $file, array $data, int $ttl): void
    {
        file_put_contents($file, json_encode([
            'data' => $data,
            'expires_at' => time() + $ttl,
        ]));
    }
}
