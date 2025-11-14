<?php
/**
 * 企业微信通知类
 * 负责发送测试结果通知
 */

class WechatNotifier {
    private $wechatConfig = [
        'corp_id' => '',
        'agent_id' => '',
        'secret' => '',
        'to_user' => '@all'
    ];
    
    public function __construct($corpId = '', $agentId = '', $secret = '', $toUser = '@all') {
        $this->wechatConfig = [
            'corp_id' => $corpId,
            'agent_id' => $agentId,
            'secret' => $secret,
            'to_user' => $toUser
        ];
    }
    
    /**
     * 发送企业微信消息
     */
    public function sendMessage($diagnosisResults, $proxyResults, $configUpdateResult, $currentProxy = '', $allProxiesFailed = false) {
        if (empty($this->wechatConfig['corp_id']) || empty($this->wechatConfig['secret']) || empty($this->wechatConfig['agent_id'])) {
            echo "\n⚠️  企业微信配置不完整，跳过消息发送。\n";
            return false;
        }
        
        try {
            $accessToken = $this->getWechatAccessToken();
            if (!$accessToken) {
                throw new Exception("获取企业微信访问令牌失败");
            }
            
            if ($allProxiesFailed) {
                $message = $this->buildAllFailedMessage($diagnosisResults, $proxyResults, $currentProxy);
            } else {
                $message = $this->buildNewsMessage($diagnosisResults, $proxyResults, $configUpdateResult, $currentProxy);
            }
            
            $result = $this->sendWechatRequest($accessToken, $message);
            
            echo "\n✅ 企业微信消息发送成功！\n";
            return true;
            
        } catch (Exception $e) {
            echo "\n❌ 企业微信消息发送失败: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    private function getWechatAccessToken() {
        $url = "https://qyapi.weixin.qq.com/cgi-bin/gettoken?" . 
               "corpid={$this->wechatConfig['corp_id']}&corpsecret={$this->wechatConfig['secret']}";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            return false;
        }
        
        $data = json_decode($response, true);
        return $data['access_token'] ?? false;
    }
    
    private function buildNewsMessage($diagnosisResults, $proxyResults, $configUpdateResult, $currentProxy) {
        $summary = $diagnosisResults['summary'];
        $timestamp = date('Y-m-d H:i:s');
        
        // 统计代理测试结果
        $businessReadyCount = 0;
        $basicOnlyCount = 0;
        $failedCount = 0;
        
        foreach ($proxyResults as $result) {
            switch ($result['status']) {
                case 'business_ready': $businessReadyCount++; break;
                case 'basic_only': $basicOnlyCount++; break;
                case 'basic_failed': $failedCount++; break;
            }
        }
        
        $title = $configUpdateResult['updated'] ? "🔄 代理配置已优化" : "✅ 网络状态正常";
        
        $description = "⏰ 检测时间: {$timestamp}\n\n";
        
        // 网络诊断摘要
        $statusEmoji = $summary['overall_status'] === '正常' ? '✅' : '⚠️';
        $description .= "{$statusEmoji} 网络诊断: {$summary['overall_status']}\n";
        $description .= "📡 连接测试: {$summary['connection_success']}/{$summary['connection_total']} 成功\n";
        $description .= "🔍 DNS解析: {$summary['dns_success']}/{$summary['dns_total']} 成功\n\n";
        
        // 代理测试摘要
        $description .= "🚀 代理测试: {$businessReadyCount}业务可用, {$basicOnlyCount}基础连通, {$failedCount}失败\n";
        
        // 配置更新状态
        if ($configUpdateResult['updated']) {
            $description .= "\n🔄 配置已更新\n";
            $description .= "📝 原地址: " . $this->shortenUrl($configUpdateResult['old_proxy']) . "\n";
            $description .= "🆕 新地址: " . $this->shortenUrl($configUpdateResult['new_proxy']) . "\n";
        } else {
            $description .= "\nℹ️ " . $configUpdateResult['message'] . "\n";
            $description .= "📍 当前地址: " . $this->shortenUrl($currentProxy) . "\n";
        }
        
        // 添加详细测试结果
        $description .= "\n📊 详细结果:\n";
        foreach ($proxyResults as $proxy) {
            $status = $this->getStatusEmoji($proxy['status']);
            $time = isset($proxy['response_time']) ? "{$proxy['response_time']}s" : '失败';
            $currentMark = ($proxy['url'] === $currentProxy) ? ' [当前]' : '';
            $description .= "{$status} " . $this->shortenUrl($proxy['url']) . " - {$time}{$currentMark}\n";
        }
        
        return [
            'touser' => $this->wechatConfig['to_user'],
            'msgtype' => 'news',
            'agentid' => $this->wechatConfig['agent_id'],
            'news' => [
                'articles' => [
                    [
                        'title' => $title,
                        'description' => $description,
                        'url' => '',
                        'picurl' => ''
                    ]
                ]
            ]
        ];
    }
    
    private function buildAllFailedMessage($diagnosisResults, $proxyResults, $currentProxy) {
        $summary = $diagnosisResults['summary'];
        $timestamp = date('Y-m-d H:i:s');
        
        $title = "❌ 紧急：所有代理均不可用";
        
        $description = "⏰ 检测时间: {$timestamp}\n\n";
        $description .= "🚨 所有反代地址测试失败，请立即检查！\n\n";
        
        // 网络诊断摘要
        $statusEmoji = $summary['overall_status'] === '正常' ? '✅' : '⚠️';
        $description .= "{$statusEmoji} 网络诊断: {$summary['overall_status']}\n";
        $description .= "📡 连接测试: {$summary['connection_success']}/{$summary['connection_total']} 成功\n";
        $description .= "🔍 DNS解析: {$summary['dns_success']}/{$summary['dns_total']} 成功\n\n";
        
        // 详细代理测试结果
        $description .= "📋 代理测试详情:\n";
        foreach ($proxyResults as $index => $proxy) {
            $status = $this->getStatusEmoji($proxy['status']);
            $time = isset($proxy['response_time']) ? "{$proxy['response_time']}s" : '连接失败';
            $currentMark = ($proxy['url'] === $currentProxy) ? ' [当前配置]' : '';
            $description .= ($index + 1) . ". {$status} " . $this->shortenUrl($proxy['url']) . " - {$time}{$currentMark}\n";
            
            if (isset($proxy['error']) && $proxy['error']) {
                $description .= "   错误: {$proxy['error']}\n";
            }
        }
        
        $description .= "\n⚠️ 建议：请检查网络连接或联系管理员处理";
        
        return [
            'touser' => $this->wechatConfig['to_user'],
            'msgtype' => 'news',
            'agentid' => $this->wechatConfig['agent_id'],
            'news' => [
                'articles' => [
                    [
                        'title' => $title,
                        'description' => $description,
                        'url' => '',
                        'picurl' => ''
                    ]
                ]
            ]
        ];
    }
    
    private function getStatusEmoji($status) {
        $emojiMap = [
            'business_ready' => '✅',
            'basic_only' => '⚠️',
            'basic_failed' => '❌'
        ];
        
        return $emojiMap[$status] ?? '❓';
    }
    
    /**
     * 缩短URL显示
     */
    private function shortenUrl($url, $maxLength = 40) {
        if (strlen($url) <= $maxLength) {
            return $url;
        }
        
        $protocol = parse_url($url, PHP_URL_SCHEME) . '://';
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);
        
        if ($path && strlen($protocol . $host . $path) > $maxLength) {
            $path = substr($path, 0, $maxLength - strlen($protocol . $host) - 3) . '...';
        }
        
        $shortUrl = $protocol . $host . $path;
        
        if (strlen($shortUrl) > $maxLength) {
            $shortUrl = substr($shortUrl, 0, $maxLength - 3) . '...';
        }
        
        return $shortUrl;
    }
    
    private function sendWechatRequest($accessToken, $message) {
        $url = "https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token={$accessToken}";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($message, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8'
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            throw new Exception("HTTP请求失败: {$httpCode}");
        }
        
        $result = json_decode($response, true);
        if ($result['errcode'] !== 0) {
            throw new Exception("企业微信API错误: {$result['errmsg']} (代码: {$result['errcode']})");
        }
        
        return $result;
    }
    
    // 配置方法
    public function setWechatConfig($corpId, $agentId, $secret, $toUser = '@all') {
        $this->wechatConfig = [
            'corp_id' => $corpId,
            'agent_id' => $agentId,
            'secret' => $secret,
            'to_user' => $toUser
        ];
    }
}
?>