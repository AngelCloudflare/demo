<?php
/**
 * 基础代理测试类
 * 包含网络诊断和基础连通性测试
 */

class EnhancedProxyTester {
    protected $proxies = [
        'https://129888.xyz',
        'https://naizi.lolkda.top', 
        'http://ark.yanyuwangluo.cn:1200',
        'http://ark.jdddns.tk',
        'https://cap.lolkda.cf',
        'https://newpro.03vps.cn'
    ];
    
    protected $timeout = 5;
    protected $testEndpoint = '';
    
    public function __construct() {
        set_time_limit(90);
    }
    
    /**
     * 网络连接诊断
     */
    public function networkDiagnosis() {
        echo "=== 网络连接诊断 ===\n\n";
        
        $diagnosisResults = [];
        
        // 测试目标列表
        $testTargets = [
            ['url' => 'https://www.baidu.com', 'desc' => '百度网站（基础网络测试）'],
            ['url' => 'https://httpbin.org/ip', 'desc' => 'HTTPBin（IP查询服务）'],
            ['url' => 'https://qyapi.weixin.qq.com', 'desc' => '企业微信API域名'],
            ['url' => 'https://qyapi.weixin.qq.com/cgi-bin/gettoken', 'desc' => '企业微信令牌接口'],
        ];
        
        foreach ($testTargets as $target) {
            echo "测试: {$target['desc']}\n";
            echo "URL: {$target['url']}\n";
            
            $result = $this->testSingleConnection($target['url']);
            $diagnosisResults[] = [
                'description' => $target['desc'],
                'url' => $target['url'],
                'success' => $result['success'],
                'http_code' => $result['http_code'],
                'response_time' => $result['response_time'],
                'error' => $result['error']
            ];
            
            if ($result['success']) {
                echo "✅ 成功: HTTP {$result['http_code']} (耗时: {$result['response_time']}s)\n";
            } else {
                echo "❌ 失败: {$result['error']} (HTTP: {$result['http_code']})\n";
            }
            echo str_repeat("-", 60) . "\n";
        }
        
        // DNS解析测试
        echo "DNS解析测试:\n";
        $domains = ['qyapi.weixin.qq.com', 'api.weixin.qq.com', 'www.qq.com'];
        $dnsResults = [];
        
        foreach ($domains as $domain) {
            $startTime = microtime(true);
            $ip = gethostbyname($domain);
            $dnsTime = round(microtime(true) - $startTime, 3);
            
            if ($ip === $domain) {
                echo "❌ $domain 解析失败\n";
                $dnsResults[] = ['domain' => $domain, 'ip' => '解析失败', 'time' => $dnsTime, 'success' => false];
            } else {
                echo "✅ $domain -> $ip ({$dnsTime}s)\n";
                $dnsResults[] = ['domain' => $domain, 'ip' => $ip, 'time' => $dnsTime, 'success' => true];
            }
        }
        
        return [
            'connection_tests' => $diagnosisResults,
            'dns_tests' => $dnsResults,
            'summary' => $this->generateDiagnosisSummary($diagnosisResults, $dnsResults)
        ];
    }
    
    protected function testSingleConnection($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);
        
        $startTime = microtime(true);
        $response = curl_exec($ch);
        $responseTime = round(microtime(true) - $startTime, 3);
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        
        curl_close($ch);
        
        return [
            'success' => ($response !== false && $httpCode >= 200 && $httpCode < 400),
            'http_code' => $httpCode,
            'response_time' => $responseTime,
            'error' => $error ?: ($httpCode >= 400 ? "HTTP {$httpCode}" : '')
        ];
    }
    
    protected function generateDiagnosisSummary($connectionResults, $dnsResults) {
        $connectionSuccess = 0;
        $connectionTotal = count($connectionResults);
        
        foreach ($connectionResults as $result) {
            if ($result['success']) $connectionSuccess++;
        }
        
        $dnsSuccess = 0;
        $dnsTotal = count($dnsResults);
        
        foreach ($dnsResults as $result) {
            if ($result['success']) $dnsSuccess++;
        }
        
        $overallStatus = ($connectionSuccess == $connectionTotal && $dnsSuccess == $dnsTotal) ? '正常' : '异常';
        
        return [
            'overall_status' => $overallStatus,
            'connection_success' => $connectionSuccess,
            'connection_total' => $connectionTotal,
            'dns_success' => $dnsSuccess,
            'dns_total' => $dnsTotal
        ];
    }
    
    /**
     * 基础代理测速
     */
    public function testProxies() {
        $results = [];
        
        echo "\n=== 反代地址测速 ===\n";
        echo "超时时间: {$this->timeout}秒\n";
        echo "测试地址数量: " . count($this->proxies) . "\n\n";
        
        foreach ($this->proxies as $index => $proxy) {
            echo "测试 [".($index+1)."/".count($this->proxies)."]: {$proxy} ... ";
            
            $startTime = microtime(true);
            $response = $this->testSingleProxy($proxy);
            $endTime = microtime(true);
            
            if ($response !== false) {
                $responseTime = round(($endTime - $startTime) * 1000, 2);
                $results[] = [
                    'url' => $proxy,
                    'time' => $responseTime,
                    'status' => 'success',
                    'http_code' => $response['http_code']
                ];
                echo "✅ 成功 - {$responseTime}ms (HTTP: {$response['http_code']})\n";
            } else {
                $results[] = [
                    'url' => $proxy,
                    'time' => null,
                    'status' => 'failed',
                    'http_code' => 0,
                    'error' => '连接失败或响应无效'
                ];
                echo "❌ 失败\n";
            }
            
            if ($index < count($this->proxies) - 1) {
                usleep(200000);
            }
        }
        
        return $results;
    }
    
    protected function testSingleProxy($proxyUrl) {
        $ch = curl_init();
        $testUrl = $proxyUrl . $this->testEndpoint;
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $testUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HEADER => false,
            CURLOPT_NOBODY => false,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return false;
        }
        
        if ($httpCode !== 200) {
            return false;
        }
        
        if ($response && stripos($response, 'pong') !== false) {
            return [
                'http_code' => $httpCode,
                'total_time' => $totalTime,
                'content' => $response
            ];
        }
        
        return false;
    }
    
    public function findFastestProxy($results) {
        $successfulProxies = array_filter($results, function($result) {
            return $result['status'] === 'success';
        });
        
        if (empty($successfulProxies)) {
            return null;
        }
        
        usort($successfulProxies, function($a, $b) {
            return $a['time'] <=> $b['time'];
        });
        
        return $successfulProxies[0];
    }
    
    // 配置方法
    public function setProxies($proxies) {
        $this->proxies = $proxies;
    }
    
    public function setTimeout($timeout) {
        $this->timeout = $timeout;
    }
    
    public function setTestEndpoint($endpoint) {
        $this->testEndpoint = $endpoint;
    }
}
?>