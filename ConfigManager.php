<?php
/**
 * 配置管理类
 * 负责配置文件的读写和备份
 */

class ConfigManager {
    private $configFile;
    
    public function __construct($configFile) {
        $this->configFile = $configFile;
    }
    
    /**
     * 更新配置文件中的代理地址
     */
    public function updateProxy($newProxy, $currentProxy = '') {
        if (!file_exists($this->configFile)) {
            throw new Exception("配置文件 {$this->configFile} 不存在！");
        }
        
        if (!is_writable($this->configFile)) {
            throw new Exception("配置文件没有写入权限！");
        }
        
        $configContent = file_get_contents($this->configFile);
        $config = json_decode($configContent, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("配置文件JSON格式错误: " . json_last_error_msg());
        }
        
        $oldProxy = $config['ServerProxy'] ?? '';
        
        // 如果最快的代理与当前相同，则不更新
        if ($oldProxy === $newProxy) {
            return [
                'updated' => false, 
                'old_proxy' => $oldProxy, 
                'new_proxy' => $newProxy,
                'message' => '一切正常，当前已是最优配置'
            ];
        }
        
        $config['ServerProxy'] = $newProxy;
        
        // 创建备份
        $backupFile = $this->configFile . '.backup.' . date('YmdHis');
        if (!file_put_contents($backupFile, $configContent)) {
            throw new Exception("创建备份文件失败: {$backupFile}");
        }
        
        $newConfigContent = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!file_put_contents($this->configFile, $newConfigContent)) {
            // 恢复原配置
            file_put_contents($this->configFile, $configContent);
            throw new Exception("更新配置文件失败，已恢复原配置！");
        }
        
        echo "\n配置文件已更新！\n";
        echo "原地址: {$oldProxy}\n";
        echo "新地址: {$newProxy}\n";
        echo "备份文件: {$backupFile}\n";
        
        return [
            'updated' => true, 
            'old_proxy' => $oldProxy, 
            'new_proxy' => $newProxy, 
            'backup_file' => $backupFile,
            'message' => '配置已更新为最优代理'
        ];
    }
    
    /**
     * 获取当前配置
     */
    public function getCurrentConfig() {
        if (!file_exists($this->configFile)) {
            return [];
        }
        
        $configContent = file_get_contents($this->configFile);
        return json_decode($configContent, true);
    }
}
?>