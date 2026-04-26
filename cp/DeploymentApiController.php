<?php

namespace App\Http\Controllers\Api;

use SelfPhp\SP;
use SelfPhp\Auth;
use SelfPhp\Request;
use SelfPhp\SPException;
use App\Models\SystemActivity;
use App\Http\Utils\AuthUtil;
use App\Http\Utils\BusinessUtil;
use ZipArchive;
use Exception;

/**
 * Class DeploymentApiController
 * Handles application deployment via API.
 */
class DeploymentApiController extends SP
{
    /**
     * @var AuthUtil
     */
    public $authUtil;

    /**
     * @var BusinessUtil
     */
    public $businessUtil;

    /**
     * DeploymentApiController constructor.
     *
     * @param AuthUtil $authUtil
     * @param BusinessUtil $businessUtil
     */
    public function __construct(AuthUtil $authUtil, BusinessUtil $businessUtil)
    {
        $this->authUtil = $authUtil;
        $this->businessUtil = $businessUtil;
    }

    /**
     * Deploy an app via this method
     * Extracts uploaded ZIP file and deploys application files
     * 
     * @param Request $request
     * @return array
     */
    public function cliDeploy(Request $request)
    {
        try { 
            $input = $request->captureAll();

            Logger($input, "public/storage/logs/deployment_debug.log");

            if (empty($input['deployPath'])) {
                throw new Exception('Deploy path is required');
            }
            
            $deployPath = rtrim($input['deployPath'], '/\\');
            $backupPath = $deployPath . DIRECTORY_SEPARATOR . 'backups';
            $deployZipPath = $deployPath . DIRECTORY_SEPARATOR . "deploy.zip"; 

            $this->logInfo("Deployment started for path: {$deployPath}");

            if (!file_exists($deployZipPath)) {
                throw new Exception("Deployment package not found at: {$deployZipPath}");
            }

            $this->validateZipFile($deployZipPath);
            
            if (!empty($input['hash'])) {
                $zipHash = md5_file($deployZipPath);
                if ($zipHash !== $input['hash']) {
                    throw new Exception('File integrity check failed. Package may be corrupted.');
                }
            }
            
            $this->createBackup($deployPath, $backupPath);

            $extractResult = $this->extractZip($deployZipPath, $deployPath);
            
            if (!$extractResult['success']) { 
                $this->restoreBackup($backupPath, $deployPath);
                throw new Exception('Failed to extract deployment package: ' . $extractResult['message']);
            }
            
            $this->runPostDeploymentTasks($deployPath);
            
            $this->logDeployment($extractResult['files_count']);
            
            $this->cleanup($deployZipPath, $backupPath);

            $this->logInfo("Deployment completed successfully");

            return [
                'success' => true,
                'message' => 'Deployment completed successfully',
                'data' => [
                    'files_deployed' => $extractResult['files_count'],
                    'deployment_time' => date('Y-m-d H:i:s'),
                    'version' => $this->getAppVersion($deployPath),
                    'deploy_path' => $deployPath
                ]
            ];

        } catch (Exception $e) { 
            $this->logError('Deployment failed: ' . $e->getMessage());

            return [
                'data' => null,
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate ZIP file structure and contents
     *
     * @param string $zipPath
     * @throws Exception
     */
    private function validateZipFile($zipPath)
    {
        $zip = new ZipArchive();
        
        if ($zip->open($zipPath) !== true) {
            throw new Exception('Invalid or corrupted ZIP file.');
        }
        
        $totalSize = 0;
        $forbiddenFiles = [];
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            
            if (substr($filename, -1) === '/') {
                continue;
            }
            
            $stat = $zip->statIndex($i);
            $totalSize += $stat['size'];
            
            if ($stat['size'] > 52428800) { // 50MB
                $forbiddenFiles[] = $filename . ' (exceeds size limit)';
            }
            
            // Check for directory traversal
            if (strpos($filename, '..') !== false) {
                $forbiddenFiles[] = $filename . ' (contains path traversal)';
            }
            
            // Check for system files
            if (preg_match('/\.(htaccess|htpasswd|env|git|svn)/i', $filename)) {
                $forbiddenFiles[] = $filename . ' (system file not allowed)';
            }
        }
        
        if ($totalSize > 524288000) { // 500MB total
            throw new Exception('Deployment package exceeds maximum total size (500MB)');
        }

        $zip->close();

        if (!empty($forbiddenFiles)) {
            throw new Exception(
                'Deployment package contains invalid files: ' . implode(', ', array_slice($forbiddenFiles, 0, 5))
            );
        }
    }

    /**
     * Extract ZIP file to target directory
     *
     * @param string $zipPath
     * @param string $extractPath
     * @return array
     */
    private function extractZip($zipPath, $extractPath)
    {
        $zip = new ZipArchive();
        
        if ($zip->open($zipPath) !== true) {
            return [
                'success' => false,
                'message' => 'Cannot open ZIP file',
                'files_count' => 0
            ];
        }

        if (!is_dir($extractPath)) {
            if (!mkdir($extractPath, 0755, true)) {
                $zip->close();
                return [
                    'success' => false,
                    'message' => 'Cannot create extraction directory',
                    'files_count' => 0
                ];
            }
        }

        // Extract files
        if ($zip->extractTo($extractPath)) {
            $filesCount = $zip->numFiles;
            $zip->close();
            
            return [
                'success' => true,
                'message' => 'Extraction successful',
                'files_count' => $filesCount
            ];
        } else {
            $zip->close();
            
            return [
                'success' => false,
                'message' => 'Extraction failed',
                'files_count' => 0
            ];
        }
    }

    /**
     * Create backup of current files before deployment
     *
     * @param string $sourcePath
     * @param string $backupPath
     * @throws Exception
     */
    private function createBackup($sourcePath, $backupPath)
    {
        if (!is_dir($sourcePath)) {
            return;
        }

        // Skip backup directory itself
        $backupDirName = basename($backupPath);

        // Create backup directory
        if (!is_dir($backupPath)) {
            if (!mkdir($backupPath, 0755, true)) {
                throw new Exception('Cannot create backup directory');
            }
        }

        // Create backup archive
        $zip = new ZipArchive();
        $backupFile = $backupPath . DIRECTORY_SEPARATOR . 'backup_' . date('Y-m-d_H-i-s') . '.zip';
        
        if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourcePath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($sourcePath) + 1);
                    
                    // Skip backup files
                    if (strpos($relativePath, $backupDirName) === 0) {
                        continue;
                    }
                    
                    // Skip deploy.zip
                    if ($relativePath === 'deploy.zip') {
                        continue;
                    }

                    $zip->addFile($filePath, $relativePath);
                }
            }

            $zip->close();

            // Keep only last 5 backups
            $this->cleanupOldBackups($backupPath);
        }
    }

    /**
     * Restore files from backup if deployment fails
     *
     * @param string $backupPath
     * @param string $targetPath
     */
    private function restoreBackup($backupPath, $targetPath)
    {
        // Find latest backup
        $backups = glob($backupPath . DIRECTORY_SEPARATOR . 'backup_*.zip');
        
        if (empty($backups)) {
            return;
        }

        rsort($backups);
        $latestBackup = $backups[0];

        // Clear target directory (except backups)
        $this->deleteDirectoryContents($targetPath, [basename($backupPath)]);

        // Extract backup
        $zip = new ZipArchive();
        if ($zip->open($latestBackup) === true) {
            $zip->extractTo($targetPath);
            $zip->close();
        }
    }

    /**
     * Run post-deployment tasks
     *
     * @param string $extractPath
     */
    private function runPostDeploymentTasks($extractPath)
    {
        // Clear cache directories if they exist
        $cacheDirs = ['storage/cache', 'storage/views', 'storage/compiled', 'cache', 'tmp'];
        
        foreach ($cacheDirs as $cacheDir) {
            $fullPath = $extractPath . DIRECTORY_SEPARATOR . $cacheDir;
            if (is_dir($fullPath)) {
                $this->deleteDirectoryContents($fullPath);
            }
        }

        // Set proper permissions
        $this->setDirectoryPermissions($extractPath);

        // Run migrations if exists
        $migrationScript = $extractPath . DIRECTORY_SEPARATOR . 'migrate.php';
        if (file_exists($migrationScript)) {
            try {
                include_once $migrationScript;
            } catch (Exception $e) {
                $this->logError('Migration failed: ' . $e->getMessage());
            }
        }
        
        // Run composer install if composer.json exists
        $composerJson = $extractPath . DIRECTORY_SEPARATOR . 'composer.json';
        $vendorDir = $extractPath . DIRECTORY_SEPARATOR . 'vendor';
        if (file_exists($composerJson) && !is_dir($vendorDir)) {
            $this->logInfo('Composer install may be required for dependencies');
        }
    }

    /**
     * Log deployment activity
     *
     * @param int $filesDeployed
     */
    private function logDeployment($filesDeployed)
    {
        try {
            $userId = Auth('user_id');
            
            SystemActivity::query()->insert([
                'user_id' => $userId ?? null,
                'activity_type' => 'deployment',
                'description' => "Application deployed: {$filesDeployed} files extracted",
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            $this->logError('Failed to log deployment: ' . $e->getMessage());
        }
    }

    /**
     * Clean up temporary files
     *
     * @param string $deployZipPath
     * @param string $backupPath
     */
    private function cleanup($deployZipPath, $backupPath)
    {
        if (file_exists($deployZipPath)) {
            unlink($deployZipPath);
        }
        
        $this->cleanupOldBackups($backupPath);
    }

    /**
     * Clean up old backups keeping only the latest ones
     *
     * @param string $backupPath
     */
    private function cleanupOldBackups($backupPath)
    {
        if (!is_dir($backupPath)) {
            return;
        }
        
        $backups = glob($backupPath . DIRECTORY_SEPARATOR . 'backup_*.zip');
        
        if (count($backups) > 5) {
            rsort($backups);
            $oldBackups = array_slice($backups, 5);
            
            foreach ($oldBackups as $oldBackup) {
                if (file_exists($oldBackup)) {
                    unlink($oldBackup);
                }
            }
        }
    }
    
    /**
     * Delete directory contents except specified folders
     *
     * @param string $dir
     * @param array $exclude
     * @return bool
     */
    private function deleteDirectoryContents($dir, $exclude = [])
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) { 
            if (in_array($file, $exclude)) {
                continue;
            }
            
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            
            if (is_dir($path)) {
                $this->deleteDirectoryContents($path);
                rmdir($path);
            } else {
                unlink($path);
            }
        }

        return true;
    }

    /**
     * Set proper directory permissions
     *
     * @param string $path
     */
    private function setDirectoryPermissions($path)
    {
        if (!is_dir($path)) {
            return;
        }
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    @chmod($item->getPathname(), 0755);
                } else {
                    @chmod($item->getPathname(), 0644);
                }
            }
        } catch (Exception $e) {
            $this->logError('Permission setting failed: ' . $e->getMessage());
        }
    }

    /**
     * Get application version if available
     *
     * @param string $extractPath
     * @return string
     */
    private function getAppVersion($extractPath)
    {
        $versionFile = $extractPath . DIRECTORY_SEPARATOR . 'version.txt';
        
        if (file_exists($versionFile)) {
            return trim(file_get_contents($versionFile));
        }

        $composerJson = $extractPath . DIRECTORY_SEPARATOR . 'composer.json';
        if (file_exists($composerJson)) {
            $composer = json_decode(file_get_contents($composerJson), true);
            return $composer['version'] ?? 'unknown';
        }

        return 'unknown';
    }

    /**
     * Log info message
     *
     * @param string $message
     */
    private function logInfo($message)
    {
        $this->writeLog('INFO', $message);
    }

    /**
     * Log error message
     *
     * @param string $message
     */
    private function logError($message)
    {
        $this->writeLog('ERROR', $message);
    }

    /**
     * Write to log file
     *
     * @param string $level
     * @param string $message
     */
    private function writeLog($level, $message)
    {
        $logDir = 'public' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . DIRECTORY_SEPARATOR . 'deployment.log';
        $timestamp = date('Y-m-d H:i:s');
        
        @file_put_contents(
            $logFile, 
            "[{$timestamp}] [{$level}] {$message}\n", 
            FILE_APPEND | LOCK_EX
        );
    }
}