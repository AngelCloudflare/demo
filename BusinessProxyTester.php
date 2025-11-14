<?php
/**
 * 业务代理测试类
 * 继承基础测试类，增加业务可用性验证
 */

require_once 'EnhancedProxyTester.php';

class BusinessProxyTester extends EnhancedProxyTester {
    private $configFile = '/www/wwwroot/dmmmmd/pro/Config/Config.json';
    private $currentProxy = '';
    
    public function __construct() {
        parent::__construct();
        $this->loadCurrentProxy();
    }
    
    /**
     * 加载当前配置中的代理地址
     */
    private function loadCurrentProxy() {
        if (file_exists($this->configFile)) {
            $configContent = file_get_contents($this->configFile);
            $config = json_decode($configContent, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->currentProxy = $config['ServerProxy'] ?? '';
            }
        }
    }
    
    /**
     * 增强代理测试 - 模拟实际业务请求
     */
    public function enhancedTestProxies() {
        $results = [];
        
        echo "\n=== 增强代理测试 - 模拟业务请求 ===\n";
        
        foreach ($this->proxies as $index => $proxy) {
            echo "测试 [".($index+1)."/".count($this->proxies)."]: {$proxy}\n";
            
            // 1. 基础连通性测试
            echo "  基础连通性 ... ";
            $basicTest = $this->testSingleProxy($proxy);
            $basicSuccess = ($basicTest !== false);
            
            if ($basicSuccess) {
                echo "✅ 成功\n";
            } else {
                echo "❌ 失败\n";
                $results[] = [
                    'url' => $proxy,
                    'status' => 'basic_failed',
                    'basic_success' => false,
                    'business_success' => false
                ];
                continue;
            }
            
            // 2. 业务请求模拟测试
            echo "  业务请求模拟 ... ";
            $businessTest = $this->testBusinessRequest($proxy);
            
            if ($businessTest['success']) {
                echo "✅ 成功 - {$businessTest['response_time']}s\n";
                $results[] = [
                    'url' => $proxy,
                    'status' => 'business_ready',
                    'basic_success' => true,
                    'business_success' => true,
                    'response_time' => $businessTest['response_time'],
                    'http_code' => $businessTest['http_code']
                ];
            } else {
                echo "❌ 失败 - {$businessTest['error']}\n";
                $results[] = [
                    'url' => $proxy,
                    'status' => 'basic_only',
                    'basic_success' => true,
                    'business_success' => false,
                    'error' => $businessTest['error']
                ];
            }
            
            echo str_repeat("-", 50) . "\n";
            
            if ($index < count($this->proxies) - 1) {
                sleep(1);
            }
        }
        
        return $results;
    }
    
    /**
     * 模拟业务请求测试
     */
    private function testBusinessRequest($proxyUrl) {
        // 尝试多个可能的业务端点
        $endpoints = [
            '/api/license/verify',
            '/license/verify', 
            '/api/status',
            '/status',
            '/health',
            '/'
        ];
        
        foreach ($endpoints as $endpoint) {
            $testUrl = $proxyUrl . $endpoint;
            $result = $this->makeBusinessRequest($testUrl);
            
            if ($result['success']) {
                return $result;
            }
        }
        
        // 如果所有端点都失败，返回最后一个结果
        return $result;
    }
    
    /**
     * 执行业务请求
     */
    private function makeBusinessRequest($url) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ]);
        
        $startTime = microtime(true);
        $response = curl_exec($ch);
        $responseTime = round(microtime(true) - $startTime, 3);
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // 业务逻辑判断
        $businessSuccess = false;
        if ($response !== false) {
            // 检查HTTP状态码
            if ($httpCode >= 200 && $httpCode < 400) {
                $businessSuccess = true;
            }
            
            // 检查响应内容特征
            if ($businessSuccess) {
                // 排除常见的错误页面特征
                $errorIndicators = [
                    'error',
                    'exception',
                    'nullreference',
                    'object reference',
                    'failed',
                    'invalid'
                ];
                
                $responseLower = strtolower($response);
                foreach ($errorIndicators as $indicator) {
                    if (strpos($responseLower, $indicator) !== false) {
                        $businessSuccess = false;
                        break;
                    }
                }
            }
        }
        
        return [
            'success' => $businessSuccess,
            'http_code' => $httpCode,
            'response_time' => $responseTime,
            'error' => $error ?: ($httpCode >= 400 ? "HTTP {$httpCode}" : ''),
            'response_preview' => $response ? substr($response, 0, 200) : ''
        ];
    }
    
    /**
     * 找出业务可用的代理
     */
    public function findBusinessReadyProxy($results) {
        $businessReady = array_filter($results, function($result) {
            return $result['status'] === 'business_ready';
        });
        
        if (empty($businessReady)) {
            // 如果没有业务可用的，回退到基础可用的
            $basicAvailable = array_filter($results, function($result) {
                return $result['basic_success'] === true;
            });
            
            if (empty($basicAvailable)) {
                return null;
            }
            
            // 按响应时间排序基础可用的代理
            usort($basicAvailable, function($a, $b) {
                $timeA = $a['response_time'] ?? PHP_FLOAT_MAX;
                $timeB = $b['response_time'] ?? PHP_FLOAT_MAX;
                return $timeA <=> $timeB;
            });
            
            return $basicAvailable[0];
        }
        
        // 按响应时间排序业务可用的代理
        usort($businessReady, function($a, $b) {
            return $a['response_time'] <=> $b['response_time'];
        });
        
        return $businessReady[0];
    }
    
    public function getCurrentProxy() {
        return $this->currentProxy;
    }
    
    public function setConfigFile($configFile) {
        $this->configFile = $configFile;
        $this->loadCurrentProxy();
    }
    
    private function getProxyStatusText($status) {
        $statusMap = [
            'business_ready' => '✅ 业务可用',
            'basic_only' => '⚠️ 仅基础连通', 
            'basic_failed' => '❌ 完全失败'
        ];
        
        return $statusMap[$status] ?? $status;
    }
}
?>