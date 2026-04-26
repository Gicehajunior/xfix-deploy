# XFIX Deploy

A lightweight, automated deployment tool for PHP applications using Node.js and FTP. It streamlines deployment workflows by packaging, uploading, and triggering remote extraction in a single command.

## Features

- Automated project packaging into optimized ZIP archives  
- FTP/FTPS upload support to remote servers  
- Remote server ZIP extraction trigger via HTTP endpoint  
- Branch restriction to prevent accidental deployments  
- Smart file filtering using `.updateignore` rules  
- Environment variable support for sensitive credentials  
- Verbose logging for deployment tracking  
- Retry mechanism for FTP reliability  
- Activity logging for deployments (file + database support)  

## Prerequisites

- Node.js >= 14.x  
- PHP >= 7.4 (SelfPhp Framework compatible)  
- FTP/FTPS server with write permissions  
- Git repository with proper branch setup  

## Installation

```bash
git clone https://github.com/Gicehajunior/xfix-deploy.git
cd xfix-deploy
npm install
```

## Configuration

Create a `.xfixrc.json` file:

```json
{
  "host": "ftp.yourdomain.com",
  "username": "your-ftp-username",
  "password": "your-ftp-password",
  "remotePath": "public_html/",
  "branch": "main",
  "cleanupLocal": true,
  "secure": false,
  "rejectUnauthorized": false,
  "maxRetries": 3,
  "retryDelay": 2000,
  "verbose": false,
  "deployUrl": "https://yourdomain.com/v1/api/deploy",
  "exclude": [
    "node_modules",
    ".git",
    ".env"
  ]
}
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| host | string | required | FTP server hostname |
| username | string | required | FTP username |
| password | string | required | FTP password |
| remotePath | string | required | Deployment directory |
| branch | string | main | Allowed deployment branch |
| cleanupLocal | boolean | true | Delete ZIP after upload |
| secure | boolean | false | Enable FTPS |
| rejectUnauthorized | boolean | false | SSL validation |
| maxRetries | number | 3 | FTP retry attempts |
| retryDelay | number | 2000 | Retry delay (ms) |
| verbose | boolean | false | Debug logs |
| deployUrl | string | required | Must be `/v1/api/deploy` |
| exclude | array | [] | Files/folders excluded |

## Deploy URL Requirement

The server must expose:

```
https://yourdomain.com/v1/api/deploy
```

It must:
- Accept POST requests  
- Validate API key (optional but recommended)  
- Extract uploaded ZIP file  

## .updateignore

```gitignore
node_modules/
vendor/
.git/
.env
.env.local
.env.production
.vscode/
.idea/
*.log
tmp/
cache/
tests/
deploy.zip
.updateignore
```

## Server Setup

Copy controller:

```bash
mv cp/DeploymentApiController.php /path/to/app/Http/Controllers/Api/
```

Add route:

```php
Route::post('/v1/api/deploy', 'Api\DeploymentApiController@deploy');
```

OR:

Proceed to Implement your own endpoint that handles the overall pipeline for your deployment need.

## Usage

### Basic deployment

```bash
npm run deploy
```

### Verbose mode

```bash
npm run deploy -- --verbose
```

### Using environment variables

```bash
export DEPLOY_PASSWORD="your-secure-password"
npm run deploy
```

## Deployment Flow

1. Validate Git branch  
2. Scan project files  
3. Apply `.updateignore` rules  
4. Create ZIP archive  
5. Upload via FTP  
6. Call `/v1/api/deploy` endpoint  
7. Extract files on server  
8. Cleanup and log results  

## Security

- Use environment variables for secrets  
- Do not commit `.xfixrc.json`  
- Enable FTPS in production  
- Use HTTPS deploy endpoint  
- Restrict API access  
- Validate API keys on server  

## Error Handling

- Branch mismatch protection  
- FTP retry mechanism  
- Extraction rollback support  
- Timeout handling  
- File integrity checks (optional)  

## Troubleshooting

### Package not found
- Check FTP upload success  
- Verify remote path permissions  

### Invalid branch
```bash
git checkout main
```

### FTP connection failed
- Validate credentials  
- Check firewall rules  

### Permission denied
- Ensure writable directory (755/775)  

## Project Structure

```
xfix-deploy/
├── .xfixrc.json
├── .updateignore
├── deploy.js
├── DeploymentApiController.php
├── package.json
└── src/
```

## Dependencies

### Node.js
- fs-extra  
- archiver  
- basic-ftp  
- ignore  
- node-fetch  
- execa  

### PHP
- SelfPhp Framework  
- ZipArchive  
- JSON extension  

## Contributing

1. Fork repository  
2. Create feature branch  
3. Commit changes  
4. Push branch  
5. Open pull request  

**Made with Love for simpler deployments**

For issues and feature requests, please [open an issue](https://github.com/Gicehajunior/xfix-deploy/issues).

## License

[MIT License](https://github.com/Gicehajunior/xfix-deploy/License)

