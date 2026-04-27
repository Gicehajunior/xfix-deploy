import 'dotenv/config';
import fs from 'fs-extra';
import path from 'path';
import ignore from 'ignore';
import archiver from 'archiver';
import ftp from 'basic-ftp';
import { execa } from 'execa';
import fetch from 'node-fetch';
import { fileURLToPath } from 'url'; 

// PATH HELPERS (ESM SAFE)
let total = null;
let included = null;
let excluded = null;
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const ROOT = process.cwd();

// CONFIGURATION LOADER
async function loadConfig() {
  const configPath = path.join(ROOT, '.xfixrc.json');
  
  if (!(await fs.pathExists(configPath))) {
    throw new Error('❌ Configuration file .xfixrc.json not found in project root');
  }

  const config = await fs.readJson(configPath);

  // Merge with environment variables for sensitive data
  return {
    host: config.host,
    username: config.username,
    password: process.env.DEPLOY_PASSWORD || config.password,
    remotePath: config.remotePath,
    deployPath: config.deployPath,
    branch: config.branch || 'develop',
    version: config.version || process.env.APP_VERSION || '',
    deployUrl: config.deployUrl + '/v1/app/deploy',
    secure: config.secure || false,
    rejectUnauthorized: config.rejectUnauthorized || false,
    maxRetries: config.maxRetries || 3,
    retryDelay: config.retryDelay || 2000,
    allowBackup: config.allowBackup || false,
    cleanupLocal: config.cleanupLocal || false,
    run_migrations: config.run_migrations || false,
    clear_cache: config.clear_cache || false,
    run_composer: config.run_composer || false,
    verbose: config.verbose || false,
    client_id: config.client_id || process.env.CLIENT_ID || process.env.XFIX_CLIENT_ID,
    api_key: config.api_key || process.env.API_KEY || process.env.XFIX_API_KEY,
  };
}

// CONFIGURATION VALIDATOR
function validateConfig(config) {
  const required = ['host', 'username', 'password', 'remotePath', 'deployPath'];
  const missing = required.filter(key => !config[key]);
  
  if (missing.length) {
    throw new Error(
      `❌ Missing required configuration fields: ${missing.join(', ')}`
    );
  }

  if (config.password === 'your-password-here') {
    throw new Error(
      '❌ Please update the default password in .xfixrc.json or set DEPLOY_PASSWORD environment variable'
    );
  }
}

// IGNORE LOADER (.updateignore)
function loadIgnore() {
  const ig = ignore();
  const ignoreFile = path.join(ROOT, '.updateignore');

  if (fs.existsSync(ignoreFile)) {
    const content = fs.readFileSync(ignoreFile, 'utf-8');
    ig.add(content.split('\n').filter(line => line.trim() && !line.startsWith('#')));
  }

  // Always ignore these files
  ig.add([
    '.git',
    '.updateignore',
    '.xfixrc.json',
    'node_modules',
    'deploy.zip',
    '.DS_Store',
    'Thumbs.db'
  ]);

  return ig;
}

// FILE SCANNER (ABSOLUTE PATHS)
async function getAllFiles(dir = ROOT, depth = 0, maxDepth = 50) {
  if (depth > maxDepth) {
    throw new Error(`❌ Maximum directory depth (${maxDepth}) exceeded at: ${dir}`);
  }

  const entries = await fs.readdir(dir, { withFileTypes: true });
  
  const files = await Promise.all(
    entries.map(async (entry) => {
      const fullPath = path.join(dir, entry.name);

      if (entry.isDirectory()) {
        return getAllFiles(fullPath, depth + 1, maxDepth);
      }

      return fullPath;
    })
  );

  return files.flat();
}

// FILTER FILES USING IGNORE
function filterFiles(files, ig, config) {
  const filtered = files.filter((file) => {
    const rel = path.relative(ROOT, file);
    const isIgnored = ig.ignores(rel);
    
    if (config.verbose && isIgnored) {
      console.log(`  ⏭️  Ignored: ${rel}`);
    }
    
    return !isIgnored;
  });

  const stats = {
    total: files.length,
    included: filtered.length,
    excluded: files.length - filtered.length
  };

  return { files: filtered, stats };
}

// ARCHIVE BUILDER
async function createArchive(zipPath, files, config) {
  return new Promise((resolve, reject) => {
    const output = fs.createWriteStream(zipPath);
    const archive = archiver('zip', { 
      zlib: { level: 9 }
    });

    let processedFiles = 0;
    const totalFiles = files.length;

    output.on('close', () => {
      const sizeInMB = (archive.pointer() / (1024 * 1024)).toFixed(2);
      console.log(`✅  Archive created (${sizeInMB} MB, ${processedFiles} files)`);
      resolve();
    });

    archive.on('error', reject);
    output.on('error', reject);

    archive.on('progress', (progress) => {
      if (progress.entries && progress.entries.processed > processedFiles) {
        processedFiles = progress.entries.processed;
        if (config.verbose) {
          console.log(`  📦 Adding: ${processedFiles}/${totalFiles} files`);
        }
      }
    });

    archive.pipe(output);

    for (const file of files) {
      const relative = path.relative(ROOT, file);
      archive.file(file, { name: relative });
    }

    archive.finalize();
  });
}

// BRANCH VALIDATOR
async function validateBranch(expectedBranch) {
  try {
    const { stdout: branch } = await execa('git', [
      'rev-parse',
      '--abbrev-ref',
      'HEAD'
    ]);

    const currentBranch = branch.trim();
    
    if (currentBranch !== expectedBranch) {
      throw new Error(
        `❌ Branch mismatch. Expected "${expectedBranch}", but currently on "${currentBranch}"`
      );
    }

    console.log(`✅  Branch verified: ${currentBranch}`);
    return currentBranch;
  } catch (error) {
    if (error.message.includes('Branch mismatch')) {
      throw error;
    }
    throw new Error('❌ Failed to validate git branch. Are you in a git repository?');
  }
}

// FTP UPLOADER WITH RETRY
async function uploadWithRetry(client, localPath, remotePath, config) {
  let lastError;
  
  for (let attempt = 1; attempt <= config.maxRetries; attempt++) {
    try {
      console.log(`📤  Upload attempt ${attempt}/${config.maxRetries}...`);
      
      await client.uploadFrom(localPath, remotePath);
      
      console.log('✅  Upload complete');
      return;
    } catch (error) {
      lastError = error;
      
      if (attempt < config.maxRetries) {
        console.log(`  🚫 Upload attempt ${attempt} failed, retrying in ${config.retryDelay/1000}s...`);
        await new Promise(resolve => setTimeout(resolve, config.retryDelay));
      }
    }
  }
  
  throw new Error(`❌ Upload failed after ${config.maxRetries} attempts: ${lastError.message}`);
}

// REMOTE EXTRACTION TRIGGER
async function triggerRemoteExtraction(deployUrl, config) {
  if (!deployUrl) {
    console.log('🚫  No deployUrl configured, skipping remote extraction');
    return;
  }

  console.log('🛠️  Triggering remote extraction...');
  
  try {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 300000); // 5 minutes timeout
    
    const formData = new URLSearchParams();
    Object.entries(config).forEach(([key, value]) => {
      if (value !== undefined && value !== null) {
        if (typeof value === 'object') {
          formData.append(key, JSON.stringify(value));
        } else {
          formData.append(key, value);
        }
      }
    });

    const res = await fetch(deployUrl, {
      method: 'POST',
      signal: controller.signal,
      body: formData,
      headers: {
        'User-Agent': 'XFIX-Deploy/1.0',
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-API-Key': config.api_key,
        'XFIX-CLIENT-ID': config.client_id
      }
    });
    
    clearTimeout(timeout);
    
    if (!res.ok) {
      const errorText = await res.text();
      throw new Error(`HTTP ${res.status}: ${res.statusText} - ${errorText}`);
    }
    
    const responseData = await res.json();
    
    if (responseData.success) {
      console.log('✅  Remote extraction triggered successfully');
      console.log(`    Files deployed: ${included || 'N/A'}`);
      console.log(`    Version: ${config?.version || 'N/A'}`);
    } else {
      throw new Error(responseData.message || 'Unknown deployment error');
    }
    
  } catch (error) {
    if (error.name === 'AbortError') {
      throw new Error('❌ Remote extraction request timed out after 5 minutes');
    }

    throw new Error(`❌ Remote extraction failed: ${error.message}`);
  }
}

// CLEANUP
async function cleanup(zipPath, config) {
  if (config.cleanupLocal && await fs.pathExists(zipPath)) {
    await fs.remove(zipPath);
    console.log('🧹 Cleanup complete');
  }
}

// MAIN DEPLOY FUNCTION
export default async function deploy(options = {}) {
  const startTime = Date.now();

  // Load and validate configuration
  const config = await loadConfig();

  try {
    console.log('\n🚀 Starting XFIX deployment...\n');
    
    // Validate configuration
    validateConfig(config);
    
    if (options.verbose) {
      config.verbose = true;
    }
    
    // Validate branch
    await validateBranch(config?.branch || 'main');
    
    // Scan and filter files
    console.log('\n📦 Scanning project files...');
    const ig = loadIgnore();
    const allFiles = await getAllFiles();
    const { files: allowedFiles, stats } = filterFiles(allFiles, ig, config);

    total = stats.total;
    included = stats.included;
    excluded = stats.excluded;
    
    console.log(`   Found ${total} files: ${included} included, ${excluded} excluded`);
    
    if (!allowedFiles.length) {
      throw new Error('❌ No files to deploy. Check your .updateignore configuration.');
    }
    
    // Create archive
    const zipPath = path.join(ROOT, 'deploy.zip');
    console.log('\n📦 Creating archive...');
    await createArchive(zipPath, allowedFiles, config);
    
    // Upload to server
    console.log('\n🔗  Connecting to server...');
    const client = new ftp.Client();
    client.ftp.verbose = config.verbose;
    
    try {
      await client.access({
        host: config.host,
        user: config.username,
        password: config.password,
        secure: config.secure,
        secureOptions: config.secure ? {
          rejectUnauthorized: config.rejectUnauthorized
        } : undefined
      });
      
      console.log('✅  Connected to server');
      
      // Track upload progress
      if (config.verbose) {
        client.trackProgress(info => {
          console.log(`  Uploaded: ${(info.bytes / 1024).toFixed(1)}KB`);
        });
      }
      
      const remoteFilePath = path.join(config.remotePath, 'deploy.zip');
      await uploadWithRetry(client, zipPath, remoteFilePath, config);
      
    } finally {
      client.close();
      console.log('✅  FTP connection closed');
    }
    
    // Trigger remote extraction
    console.log('');
    await triggerRemoteExtraction(config.deployUrl, config);
    
    // Cleanup
    await cleanup(zipPath, config);
    
    const duration = ((Date.now() - startTime) / 1000).toFixed(2);
    console.log(`\n✅ Deployment staged successfully in ${duration}s\n`);
    
  } catch (error) {
    console.error(`\n❌ Deployment failed: ${error.message}\n`);
    
    // Ensure cleanup even on failure
    const zipPath = path.join(ROOT, 'deploy.zip');
    
    await cleanup(zipPath, config);
    
    process.exit(1);
  }
}

// CLI SUPPORT
if (import.meta.url === `file://${process.argv[1]}`) {
  const verbose = process.argv.includes('--verbose') || process.argv.includes('-v');
  deploy({ verbose });
}