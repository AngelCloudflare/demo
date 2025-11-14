<?php
/**
 * 主执行文件
 * 协调各个组件完成代理测试和优化
 */

require_once 'EnhancedProxyTester.php';
require_once 'BusinessProxyTester.php';
require_once 'ConfigManager.php';
require_once 'WechatNotifier.php';

class ProxyTesterRunner {
    private $businessTester;
    private $configManager;
    private $wechatNotifier;
    private $configFile = '/www/wwwroot/dmmmmd/pro/Config/Config.json';
    
    public function __construct() {
        $this->businessTester = new BusinessProxyTester();
        $this->configManager = new ConfigManager($this->configFile);
        $this->wechatNotifier = new WechatNotifier();
        
        // 设置企业微信配置
        $this->wechatNotifier->setWechatConfig(
            '', 
            '', 
            '',
            '@all'
        );
    }
    
    public function run() {
        try {
            echo "=== 网络诊断与代理优化工具 ===\n";
            echo "配置文件: {$this->configFile}\n";
            echo "当前代理: " . $this->businessTester->getCurrentProxy() . "\n";
            echo "==============================\n\n";
            
            if (!function_exists('curl_init')) {
                throw new Exception("需要启用curl扩展！");
            }
            
            // 1. 网络连接诊断
            $diagnosisResults = $this->businessTester->networkDiagnosis();
            
            // 2. 增强代理测试
            $proxyResults = $this->businessTester->enhancedTestProxies();
            
            // 3. 找出业务可用的代理
            $bestProxy = $this->businessTester->findBusinessReadyProxy($proxyResults);
            
            // 4. 处理所有代理都不可用的情况
            $allProxiesFailed = ($bestProxy === null);
            
            if ($allProxiesFailed) {
                echo "\n❌ 所有反代地址都不可用！\n";
                $configUpdateResult = [
                    'updated' => false,
                    'message' => '所有代理均不可用，配置未更新'
                ];
            } else {
                // 显示结果汇总
                echo "\n=== 测试结果汇总 ===\n";
                foreach ($proxyResults as $result) {
                    $statusText = $this->getProxyStatusText($result['status']);
                    $currentMark = ($result['url'] === $this->businessTester->getCurrentProxy()) ? ' [当前]' : '';
                    $bestMark = ($result['url'] === $bestProxy['url']) ? ' [推荐]' : '';
                    
                    echo "{$result['url']} - {$statusText}{$currentMark}{$bestMark}\n";
                    
                    if (isset($result['response_time'])) {
                        echo "   响应时间: {$result['response_time']}s | HTTP: {$result['http_code']}\n";
                    }
                    if (isset($result['error'])) {
                        echo "   错误: {$result['error']}\n";
                    }
                }
                
                $businessReadyCount = count(array_filter($proxyResults, function($r) {
                    return $r['status'] === 'business_ready';
                }));
                $basicOnlyCount = count(array_filter($proxyResults, function($r) {
                    return $r['status'] === 'basic_only';
                }));
                
                echo "\n📊 统计: {$businessReadyCount}个业务可用, {$basicOnlyCount}个仅基础连通\n";
                echo "🏆 推荐代理: {$bestProxy['url']}";
                if (isset($bestProxy['response_time'])) {
                    echo " (响应时间: {$bestProxy['response_time']}s)";
                }
                echo "\n";
                
                // 5. 更新配置
                $configUpdateResult = $this->configManager->updateProxy($bestProxy['url'], $this->businessTester->getCurrentProxy());
            }
            
            // 6. 发送企业微信通知
            $messageSent = $this->wechatNotifier->sendMessage(
                $diagnosisResults, 
                $proxyResults, 
                $configUpdateResult, 
                $this->businessTester->getCurrentProxy(),
                $allProxiesFailed
            );
            
            if ($allProxiesFailed) {
                echo "\n🚨 警告！所有代理均不可用，已发送紧急通知。" . ($messageSent ? " 消息已发送。" : "") . "\n";
                return false;
            } elseif ($configUpdateResult['updated']) {
                echo "\n🎉 完成！已自动选择最优反代地址。" . ($messageSent ? " 消息已发送。" : "") . "\n";
            } else {
                echo "\nℹ️  完成！" . $configUpdateResult['message'] . ($messageSent ? " 消息已发送。" : "") . "\n";
            }
            
            return !$allProxiesFailed;
            
        } catch (Exception $e) {
            echo "\n❌ 错误: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    private function getProxyStatusText($status) {
        $statusMap = [
            'business_ready' => '✅ 业务可用',
            'basic_only' => '⚠️ 仅基础连通', 
            'basic_failed' => '❌ 完全失败'
        ];
        
        return $statusMap[$status] ?? $status;
    }
    
    // 配置方法
    public function setConfigFile($configFile) {
        $this->configFile = $configFile;
        $this->businessTester->setConfigFile($configFile);
        $this->configManager = new ConfigManager($configFile);
    }
    
    public function setWechatConfig($corpId, $agentId, $secret, $toUser = '@all') {
        $this->wechatNotifier->setWechatConfig($corpId, $agentId, $secret, $toUser);
    }
}

// 执行主程序
try {
    $runner = new ProxyTesterRunner();
    
    // 可选自定义配置
    // $runner->setConfigFile('/path/to/your/config.json');
    // $runner->setWechatConfig('corp_id', 'agent_id', 'secret', '@all');
    
    $success = $runner->run();
    
    if (!$success) {
        exit(1);
    }
    
} catch (Exception $e) {
    echo "初始化错误: " . $e->getMessage() . "\n";
    exit(1);
}
?>