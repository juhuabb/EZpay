<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Log;

/**
 * BSC (BNB Smart Chain) Service
 * 
 * 通过 JSON-RPC 直接查询 BSC 链上数据（免费，无需付费 API Key）
 * 
 * 数据源优先级：
 *   1. BSC 官方公共 RPC（bsc-dataseed.bnbchain.org）
 *   2. 用户自定义 RPC（.env BSC_RPC_URL）
 *   3. 备用第三方 RPC（OnFinality / Ankr / NodeReal / NOWNodes）
 * 
 * 注意：BSC 官方公共 RPC 禁用了 eth_getLogs，
 *       查 BEP-20 转账会自动 fallback 到支持 eth_getLogs 的第三方 RPC。
 *       查 BNB 原生转账只需 eth_getBlockByNumber，官方 RPC 即可。
 */
class BscService
{
    const BNB_SYMBOL = 'BNB';
    const USDT_BEP20_CONTRACT = '0x55d398326f99059ff775485246999027b3197955';
    const BNB_DECIMALS = 18;
    const USDT_BEP20_DECIMALS = 18;

    // ERC-20 Transfer event 签名: Transfer(address indexed from, address indexed to, uint256 value)
    const TRANSFER_EVENT_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    // BSC 公共 RPC 列表（按优先级排列）
    // 注意：官方 RPC 禁用了 eth_getLogs，但支持 eth_getBlockByNumber
    private const DEFAULT_RPC_URLS = [
        'https://bsc-dataseed.bnbchain.org',
        'https://bsc-dataseed.nariox.org',
        'https://bsc-dataseed.defibit.io',
        'https://bsc-dataseed.ninicoin.io',
        'https://bsc.nodereal.io',
    ];

    // 支持 eth_getLogs 的第三方 RPC（查 BEP-20 转账用）
    private const LOGS_RPC_URLS = [
        'https://bnb.api.onfinality.io/public',
        'https://rpc.ankr.com/bsc',
        'https://bsc-mainnet.nodereal.io/v1/64a9df0874fb4a93b9d0a3849de012d3',
        'https://public-bsc.nownodes.io',
    ];

    private $rpcUrls = [];
    private $logsRpcUrls = [];

    public function __construct()
    {
        // 用户自定义 RPC（优先级最高）
        $customRpc = env('BSC_RPC_URL', '');
        $this->rpcUrls = $customRpc
            ? array_merge([$customRpc], self::DEFAULT_RPC_URLS)
            : self::DEFAULT_RPC_URLS;

        $this->logsRpcUrls = $customRpc
            ? array_merge([$customRpc], self::LOGS_RPC_URLS)
            : self::LOGS_RPC_URLS;
    }

    // ========================================================================
    // BEP-20 Token Transfer 查询（通过 eth_getLogs）
    // ========================================================================

    /**
     * 查询 BEP-20 代币转入记录
     * 
     * 原理：通过 eth_getLogs 查询指定合约的 Transfer 事件，
     *       筛选 to = 收款地址 的日志，解析出转账金额。
     * 
     * @param string $address 收款地址 (0x...)
     * @param int $sinceTimestamp 起始时间戳（秒）
     * @param string|null $contractAddress 代币合约地址（默认 USDT-BEP20）
     * @return array [{tx_hash, from, to, amount, timestamp}]
     */
    public function getIncomingBep20Transfers(string $address, int $sinceTimestamp, ?string $contractAddress = null): array
    {
        $contract = strtolower($contractAddress ?: self::USDT_BEP20_CONTRACT);
        $addressLower = strtolower($address);

        // 将起始时间戳转换为区块号
        $fromBlock = $this->timestampToBlockNumber($sinceTimestamp);
        if (!$fromBlock) {
            Log::error('BSC: Failed to convert timestamp to block number, ts=' . $sinceTimestamp);
            return [];
        }

        // Transfer(address indexed from, address indexed to, uint256 value)
        // topic[0] = Transfer 事件签名
        // topic[2] = to 地址（左填充 0 到 32 字节）
        $toTopic = '0x000000000000000000000000' . substr($addressLower, 2);

        $params = [
            'fromBlock' => '0x' . dechex($fromBlock),
            'toBlock'   => 'latest',
            'address'   => $contract,
            'topics'    => [self::TRANSFER_EVENT_TOPIC, null, $toTopic],
        ];

        // 使用支持 eth_getLogs 的 RPC 端点
        $logs = $this->rpcCallWithFallback('eth_getLogs', [$params], $this->logsRpcUrls);

        if ($logs === null || !is_array($logs)) {
            Log::error('BSC: eth_getLogs failed or returned non-array for BEP-20 query');
            return [];
        }

        $result = [];
        foreach ($logs as $log) {
            // 每个 log 的 data 字段 = transfer amount（uint256, hex）
            $rawAmount = $log['data'] ?? '0x0';

            // topics[2] = to address（从 log 解析确认）
            $toRaw = $log['topics'][2] ?? '';
            $to = '0x' . substr($toRaw, -40);

            if (strtolower($to) !== $addressLower) {
                continue;
            }

            // 解析金额
            $hexAmount = str_replace('0x', '', $rawAmount);
            if (empty($hexAmount)) {
                $hexAmount = '0';
            }
            $weiAmount = $this->hexToDecimal($hexAmount);
            $amount = bcdiv($weiAmount, bcpow('10', (string)self::USDT_BEP20_DECIMALS), self::USDT_BEP20_DECIMALS);

            if (bccomp($amount, '0', self::USDT_BEP20_DECIMALS) <= 0) {
                continue;
            }

            // 获取交易详情（时间戳、from 地址）
            $txHash = $log['transactionHash'] ?? '';
            $tx = $this->getTransactionByHash($txHash);
            $timestamp = intval($tx['timestamp'] ?? 0);
            $from = $tx['from'] ?? '';

            if ($sinceTimestamp > 0 && $timestamp > 0 && $timestamp < $sinceTimestamp) {
                continue;
            }

            $result[] = [
                'tx_hash'   => $txHash,
                'from'      => $from,
                'to'        => $to,
                'amount'    => $amount,
                'timestamp' => $timestamp,
            ];
        }

        return $result;
    }

    // ========================================================================
    // BNB 原生转账查询（通过扫描区块）
    // ========================================================================

    /**
     * 查询 BNB 原生币转入记录
     * 
     * 原理：扫描最近区块的每笔交易，筛选 to = 收款地址 且 value > 0 的。
     * 
     * 性能优化：
     *   - 缓存最后扫描到的区块号，下次只扫描增量区块
     *   - 限制最大扫描 600 个区块（约 30 分钟）
     *   - BSC 出块约 3 秒，600 块 ≈ 18 秒扫描完成
     *   - 官方 RPC 限制 10000次/5分钟，充值检测频率远低于此
     * 
     * @param string $address 收款地址 (0x...)
     * @param int $sinceTimestamp 起始时间戳（秒）
     * @return array [{tx_hash, from, to, amount, timestamp}]
     */
    public function getIncomingBnbTransfers(string $address, int $sinceTimestamp): array
    {
        $addressLower = strtolower($address);

        // 获取当前最新区块号
        $latestBlockHex = $this->rpcCallWithFallback('eth_blockNumber', []);
        if (!$latestBlockHex) {
            Log::error('BSC: Failed to get latest block number');
            return [];
        }
        $latestBlock = intval($this->hexToDecimal($latestBlockHex));

        // 检查缓存：是否已有上次扫描位置，可跳过已扫描的区块
        $cacheKey = 'bsc_bnb_scan_' . $addressLower;
        $cache = $this->getCache($cacheKey);
        $lastScannedBlock = intval($cache['last_block'] ?? 0);
        $lastScanTime = intval($cache['time'] ?? 0);

        // 计算起始区块：取 sinceTimestamp 对应的区块
        $sinceBlock = $this->timestampToBlockNumber($sinceTimestamp);
        if (!$sinceBlock) {
            $sinceBlock = $latestBlock - 600; // fallback: ~30 minutes
        }

        // 如果缓存中有上次扫描位置且比 sinceBlock 更新，从缓存位置开始
        if ($lastScannedBlock > $sinceBlock && $lastScanTime > $sinceTimestamp) {
            $sinceBlock = $lastScannedBlock + 1;
        }

        // 限制扫描范围，避免过多 RPC 调用
        $maxScanBlocks = 600; // ~30 min at 3s/block
        if ($latestBlock - $sinceBlock > $maxScanBlocks) {
            $sinceBlock = $latestBlock - $maxScanBlocks;
        }

        if ($sinceBlock >= $latestBlock) {
            return []; // 无新区块可扫
        }

        $result = [];
        $batchSize = 20;

        // 分批扫描区块（每批 20 个）
        for ($blockNum = $sinceBlock; $blockNum <= $latestBlock; $blockNum += $batchSize) {
            $endBlock = min($blockNum + $batchSize - 1, $latestBlock);

            // 并行获取这批区块的数据
            $blocks = [];
            for ($bn = $blockNum; $bn <= $endBlock; $bn++) {
                $block = $this->getBlockByNumber($bn, true);
                if ($block) {
                    $blocks[] = $block;
                }
            }

            // 检查每个区块中的交易
            foreach ($blocks as $block) {
                $blockTs = intval($block['timestamp'] ?? 0);

                // 跳过时间范围外的区块
                if ($blockTs > 0 && $sinceTimestamp > 0 && $blockTs < $sinceTimestamp) {
                    continue;
                }

                $txs = $block['transactions'] ?? [];
                foreach ($txs as $tx) {
                    $to = strtolower($tx['to'] ?? '');
                    $value = $tx['value'] ?? '0x0';

                    // 只处理发往目标地址且有 BNB 价值的交易
                    if ($to !== $addressLower) {
                        continue;
                    }
                    if ($value === '0x0' || $value === '0x' || $value === '0') {
                        continue;
                    }

                    // 解析金额
                    $hexValue = str_replace('0x', '', $value);
                    $weiAmount = $this->hexToDecimal($hexValue);
                    $amount = bcdiv($weiAmount, bcpow('10', (string)self::BNB_DECIMALS), self::BNB_DECIMALS);

                    if (bccomp($amount, '0', self::BNB_DECIMALS) <= 0) {
                        continue;
                    }

                    $txHash = $tx['hash'] ?? '';
                    $from = $tx['from'] ?? '';

                    $result[] = [
                        'tx_hash'   => $txHash,
                        'from'      => $from,
                        'to'        => $to,
                        'amount'    => $amount,
                        'timestamp' => $blockTs,
                    ];
                }
            }
        }

        // 更新扫描位置缓存（30 分钟过期）
        $this->setCache($cacheKey, [
            'last_block' => $latestBlock,
            'time'       => time(),
        ], 1800);

        return $result;
    }

    // ========================================================================
    // 实时价格查询（与 RPC 无关，走 CoinGecko / Binance）
    // ========================================================================

    /**
     * Get BNB/USDT realtime price in CNY
     * @return array|null ['bnb_cny' => float, 'usdt_cny' => float]
     */
    public function getRealtimePrices(): ?array
    {
        $cacheFile = runtime_path() . 'bsc_prices.json';
        $cacheTTL = 300;

        if (file_exists($cacheFile)) {
            $cache = json_decode(file_get_contents($cacheFile), true);
            if ($cache && isset($cache['expires_at']) && $cache['expires_at'] > time()) {
                return $cache['data'];
            }
        }

        // CoinGecko
        $prices = $this->fetchFromCoingecko();
        if ($prices) {
            file_put_contents($cacheFile, json_encode([
                'data' => $prices,
                'expires_at' => time() + $cacheTTL,
            ]));
            return $prices;
        }

        // Binance data-api fallback
        $prices = $this->fetchFromBinanceDataApi();
        if ($prices) {
            file_put_contents($cacheFile, json_encode([
                'data' => $prices,
                'expires_at' => time() + $cacheTTL,
            ]));
            return $prices;
        }

        return null;
    }

    // ========================================================================
    // 内部方法：RPC 调用
    // ========================================================================

    /**
     * RPC 调用 + 自动 fallback
     * 第一个端点失败，自动尝试列表中下一个
     */
    private function rpcCallWithFallback(string $method, array $params, ?array $rpcUrls = null): mixed
    {
        $urls = $rpcUrls ?: $this->rpcUrls;

        foreach ($urls as $url) {
            $result = $this->jsonRpcCall($url, $method, $params);
            if ($result !== null) {
                return $result;
            }
        }

        Log::error("BSC: All RPC endpoints failed for method: {$method}");
        return null;
    }

    /**
     * 发送单个 JSON-RPC 请求
     */
    private function jsonRpcCall(string $url, string $method, array $params = []): mixed
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => 1,
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200 || !$response) {
            return null;
        }

        $data = json_decode($response, true);

        // 检查 JSON-RPC 错误
        if (isset($data['error'])) {
            return null;
        }

        return $data['result'] ?? null;
    }

    /**
     * 获取区块信息（含完整交易）
     */
    private function getBlockByNumber(int $blockNumber, bool $fullTx = true): ?array
    {
        $hexBlock = '0x' . dechex($blockNumber);
        return $this->rpcCallWithFallback('eth_getBlockByNumber', [$hexBlock, $fullTx]);
    }

    /**
     * 获取交易详情
     */
    private function getTransactionByHash(string $txHash): ?array
    {
        if (empty($txHash)) {
            return null;
        }
        return $this->rpcCallWithFallback('eth_getTransactionByHash', [$txHash]);
    }

    // ========================================================================
    // 内部方法：区块号 <-> 时间戳 转换
    // ========================================================================

    /**
     * 将时间戳转换为大致区块号
     * 
     * 策略：获取最新区块 + 附近区块时间戳，通过线性插值估算目标区块号。
     * BSC 出块约 3 秒，误差容忍几个区块不影响充值匹配。
     */
    private function timestampToBlockNumber(int $timestamp): int
    {
        if ($timestamp <= 0) {
            return 0;
        }

        // 获取最新区块号
        $latestBlockHex = $this->rpcCallWithFallback('eth_blockNumber', []);
        if (!$latestBlockHex) {
            // 粗略估算：BSC 出块 3 秒
            return max(1, intval($timestamp / 3));
        }
        $latestBlock = intval($this->hexToDecimal($latestBlockHex));

        // 获取最新区块的时间戳
        $latestBlockData = $this->getBlockByNumber($latestBlock, false);
        if (!$latestBlockData || !isset($latestBlockData['timestamp'])) {
            return max(1, $latestBlock - intval(($latestBlock * 3 - $timestamp) / 3));
        }
        $latestTs = intval($this->hexToDecimal($latestBlockData['timestamp']));

        // 如果目标时间就是现在或更晚
        if ($timestamp >= $latestTs) {
            return $latestBlock;
        }

        // 获取一个较远的参考区块（往回约 100 块 ≈ 5 分钟）
        $refBlockNum = max(1, $latestBlock - 100);
        $refBlockData = $this->getBlockByNumber($refBlockNum, false);
        if (!$refBlockData || !isset($refBlockData['timestamp'])) {
            // fallback: 假设 3 秒/块
            $blocksBack = intval(($latestTs - $timestamp) / 3);
            return max(1, $latestBlock - $blocksBack);
        }
        $refTs = intval($this->hexToDecimal($refBlockData['timestamp']));

        // 计算实际出块速度（秒/块）
        $blockDiff = $latestBlock - $refBlockNum;
        $timeDiff = $latestTs - $refTs;
        if ($timeDiff <= 0 || $blockDiff <= 0) {
            return max(1, $latestBlock - intval(($latestTs - $timestamp) / 3));
        }
        $secondsPerBlock = $timeDiff / $blockDiff;

        // 估算目标区块号
        $timeDiffFromLatest = $latestTs - $timestamp;
        $blocksBack = intval($timeDiffFromLatest / $secondsPerBlock);

        return max(1, $latestBlock - $blocksBack);
    }

    // ========================================================================
    // 内部方法：工具函数
    // ========================================================================

    /**
     * 十六进制字符串转十进制（纯 PHP，不依赖 bcmath 的 hex 支持）
     */
    private function hexToDecimal(string $hex): string
    {
        $hex = strtolower(str_replace('0x', '', $hex));
        if (empty($hex)) {
            return '0';
        }

        // 对于小数值（< 2^53），直接用 PHP 整数转换
        if (strlen($hex) <= 15) {
            return (string)hexdec($hex);
        }

        // 大数值：用 bcmath 逐位计算
        $decimal = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $decimal = bcmul($decimal, '16');
            $char = $hex[$i];
            $val = ctype_digit($char) ? intval($char) : (ord($char) - ord('a') + 10);
            $decimal = bcadd($decimal, (string)$val);
        }

        return $decimal;
    }

    /**
     * 文件缓存读取
     */
    private function getCache(string $key): array
    {
        $cacheDir = runtime_path() . 'bsc_cache' . DIRECTORY_SEPARATOR;
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $file = $cacheDir . md5($key) . '.json';
        if (!file_exists($file)) {
            return [];
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data) {
            return [];
        }

        // 检查过期
        if (isset($data['_expires']) && $data['_expires'] < time()) {
            @unlink($file);
            return [];
        }

        return $data;
    }

    /**
     * 文件缓存写入
     */
    private function setCache(string $key, array $data, int $ttl = 300): void
    {
        $cacheDir = runtime_path() . 'bsc_cache' . DIRECTORY_SEPARATOR;
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $data['_expires'] = time() + $ttl;
        $file = $cacheDir . md5($key) . '.json';
        @file_put_contents($file, json_encode($data));
    }

    // ========================================================================
    // 价格获取（CoinGecko / Binance，与链上查询无关）
    // ========================================================================

    private function fetchFromCoingecko(): ?array
    {
        $url = 'https://api.coingecko.com/api/v3/simple/price?ids=binancecoin,tether&vs_currencies=cny';
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
            if (isset($data['binancecoin']['cny']) && isset($data['tether']['cny'])) {
                return [
                    'bnb_cny' => floatval($data['binancecoin']['cny']),
                    'usdt_cny' => floatval($data['tether']['cny']),
                ];
            }
        }
        return null;
    }

    private function fetchFromBinanceDataApi(): ?array
    {
        $bnbUrl = 'https://data-api.binance.vision/api/v3/ticker/price?symbol=BNBUSDT';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $bnbUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['price'])) {
            return null;
        }

        $bnbUsdt = floatval($data['price']);

        // USD/CNY 汇率
        $url2 = 'https://open.er-api.com/v6/latest/USD';
        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $url2);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $response2 = curl_exec($ch2);
        $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        $usdCny = 7.2;
        if ($httpCode2 === 200 && $response2) {
            $data2 = json_decode($response2, true);
            if (isset($data2['rates']['CNY'])) {
                $usdCny = floatval($data2['rates']['CNY']);
            }
        }

        return [
            'bnb_cny' => round($bnbUsdt * $usdCny, 4),
            'usdt_cny' => round($usdCny, 4),
        ];
    }
}
