<?php
declare(strict_types=1);

namespace app\service;

use app\model\SystemSetting;
use think\facade\Log;

class EmailService
{
    /**
     * 发送邮箱验证码
     */
    public function sendVerifyCode(string $email, string $code): bool
    {
        $host = SystemSetting::getValue('smtp_host', '');
        $port = SystemSetting::getValue('smtp_port', '465');
        $user = SystemSetting::getValue('smtp_user', '');
        $pass = SystemSetting::getValue('smtp_pass', '');
        $fromName = SystemSetting::getValue('smtp_from_name', '积分交易平台');

        if (empty($host) || empty($user) || empty($pass)) {
            Log::error('邮件配置缺失: smtp_host/smtp_user/smtp_pass');
            return false;
        }

        $subject = "【{$fromName}】邮箱验证码";
        $body = "<div style='font-family:Arial,sans-serif;padding:20px;'>
            <h2>邮箱验证码</h2>
            <p>您的验证码是：<strong style='font-size:24px;color:#2979FF;'>{$code}</strong></p>
            <p>验证码10分钟内有效，请勿泄露给他人。</p>
            <p style='color:#999;font-size:12px;'>如非本人操作，请忽略此邮件。</p>
        </div>";

        return $this->sendMail($email, $subject, $body, $host, (int)$port, $user, $pass, $fromName);
    }

    /**
     * 使用PHP原生mail或socket发送（无需PHPMailer依赖）
     */
    private function sendMail(string $to, string $subject, string $body, string $host, int $port, string $user, string $pass, string $fromName): bool
    {
        // 使用fsockopen实现SMTP发送，无需第三方依赖
        $from = "{$fromName} <{$user}>";
        $headers = "From: {$from}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "To: {$to}\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "\r\n" . $body;

        try {
            // 尝试使用PHPMailer风格（如果已安装）
            if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
                return $this->sendViaPHPMailer($to, $subject, $body, $host, $port, $user, $pass, $fromName);
            }

            // 回退：使用socket直接发送
            return $this->sendViaSocket($to, $subject, $body, $host, $port, $user, $pass, $fromName);
        } catch (\Exception $e) {
            Log::error("邮件发送异常: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 通过PHPMailer发送
     */
    private function sendViaPHPMailer(string $to, string $subject, string $body, string $host, int $port, string $user, string $pass, string $fromName): bool
    {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $pass;
        $mail->SMTPSecure = $port === 465 ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $port;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($user, $fromName);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;

        $result = $mail->send();
        Log::info("邮件发送" . ($result ? '成功' : '失败') . ": {$to}");
        return $result;
    }

    /**
     * 通过Socket直接发送SMTP
     */
    private function sendViaSocket(string $to, string $subject, string $body, string $host, int $port, string $user, string $pass, string $fromName): bool
    {
        $ssl = ($port === 465) ? 'ssl://' : '';
        $fp = @fsockopen($ssl . $host, $port, $errno, $errstr, 10);
        if (!$fp) {
            Log::error("SMTP连接失败: {$errno} {$errstr}");
            return false;
        }

        $readResponse = function() use ($fp) {
            $response = '';
            while ($line = fgets($fp, 515)) {
                $response .= $line;
                if (substr($line, 3, 1) == ' ') break;
            }
            return $response;
        };

        $sendCmd = function($cmd) use ($fp, $readResponse) {
            fputs($fp, $cmd . "\r\n");
            return $readResponse();
        };

        $readResponse(); // 220 greeting
        $sendCmd("EHLO localhost");
        $sendCmd("AUTH LOGIN");
        $sendCmd(base64_encode($user));
        $resp = $sendCmd(base64_encode($pass));
        if (strpos($resp, '235') === false) {
            fclose($fp);
            Log::error("SMTP认证失败");
            return false;
        }

        $sendCmd("MAIL FROM:<{$user}>");
        $sendCmd("RCPT TO:<{$to}>");
        $sendCmd("DATA");

        $msg = "From: {$fromName} <{$user}>\r\n";
        $msg .= "To: <{$to}>\r\n";
        $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "\r\n{$body}\r\n.";
        $sendCmd($msg);
        $sendCmd("QUIT");
        fclose($fp);

        Log::info("邮件发送成功(Socket): {$to}");
        return true;
    }

    /**
     * 生成随机验证码
     */
    public function generateCode(int $length = 6): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= random_int(0, 9);
        }
        return $code;
    }
}
