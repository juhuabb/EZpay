<?php

namespace app\service;

use think\facade\Log;

class PushService
{
    private $uniPushUrl = '';
    
    public function __construct()
    {
        $this->uniPushUrl = $this->getEnvValue('UNIPUSH_URL');
        self::debug('UNIPUSH_URL=' . $this->uniPushUrl);
    }
    
    private function getEnvValue(string $key): string
    {
        $envFile = dirname(__DIR__, 2) . '/.env';
        if (!file_exists($envFile)) {
            return '';
        }
        $lines = file($envFile);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            $parts = explode('=', $line, 2);
            $k = trim($parts[0]);
            $v = isset($parts[1]) ? trim($parts[1]) : '';
            if ($k === $key) {
                return $v;
            }
        }
        return '';
    }
    
    public function sendToClientId(string $clientId, string $title, string $body, array $extra = []): array
    {
        self::debug('sendToClientId: cid=' . $clientId . ' title=' . $title);
        if (empty($clientId)) {
            throw new \Exception('clientId error');
        }
        if (empty($this->uniPushUrl)) {
            throw new \Exception('UNIPUSH_URL not set');
        }
        $result = $this->callUniPush($clientId, $title, $body, $extra);
        self::debug('sendToClientId result: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        return $result;
    }
    
    public function notifyAllAdmins(string $title, string $body, array $extra = []): array
    {
        try {
            $clientIds = \think\facade\Db::name('admin_push_device')->column('client_id');
            self::debug('notifyAllAdmins: ' . count($clientIds) . ' devices');
            if (empty($clientIds)) {
                return ['code' => 0, 'msg' => 'no_device'];
            }
            $results = [];
            foreach ($clientIds as $cid) {
                $result = $this->callUniPush($cid, $title, $body, $extra);
                $results[] = $result;
                self::debug('notifyAllAdmins push to ' . $cid . ': ' . json_encode($result, JSON_UNESCAPED_UNICODE));
            }
            return ['code' => 0, 'msg' => 'ok', 'data' => $results];
        } catch (\Throwable $e) {
            self::debug('notifyAllAdmins error: ' . $e->getMessage());
            Log::error('notifyAllAdmins error: ' . $e->getMessage());
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }
    
    private function callUniPush(string $clientId, string $title, string $body, array $extra = []): array
    {
        $postData = [
            'push_clientid' => $clientId,
            'title'         => $title,
            'content'       => $body,
            'payload'       => $extra,
            'force_notification' => true,
        ];
        
        $ch = curl_init($this->uniPushUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json;charset=utf-8']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        self::debug('callUniPush: httpCode=' . $httpCode . ' resp=' . $response);
        
        if ($error) {
            return ['code' => -1, 'msg' => 'curl error: ' . $error];
        }
        
        $result = json_decode($response, true);
        if ($result === null) {
            return ['code' => -1, 'msg' => 'invalid response: ' . $response];
        }
        
        return $result;
    }
    
    public function registerDevice(int $userId, string $clientId, string $deviceType = 'android'): array
    {
        if (empty($clientId)) {
            return ['code' => -1, 'msg' => 'clientId error'];
        }
        $now = date('Y-m-d H:i:s');
        $exists = \think\facade\Db::name('admin_push_device')
            ->where('user_id', $userId)
            ->where('client_id', $clientId)
            ->find();
        if ($exists) {
            \think\facade\Db::name('admin_push_device')
                ->where('id', $exists['id'])
                ->update(['last_active_at' => $now]);
        } else {
            \think\facade\Db::name('admin_push_device')->insert([
                'user_id'        => $userId,
                'client_id'      => $clientId,
                'device_type'    => $deviceType,
                'registered_at'  => $now,
                'last_active_at' => $now,
            ]);
        }
        return ['code' => 0, 'msg' => 'ok'];
    }
    
    public function removeDevice(int $userId, string $clientId): array
    {
        \think\facade\Db::name('admin_push_device')
            ->where('user_id', $userId)
            ->where('client_id', $clientId)
            ->delete();
        return ['code' => 0, 'msg' => 'ok'];
    }
    
    public static function debug(string $msg): void
    {
        $logFile = dirname(__DIR__, 2) . '/runtime/push_debug.log';
        $line = date('H:i:s') . ' ' . $msg . PHP_EOL;
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
