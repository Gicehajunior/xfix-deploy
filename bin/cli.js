#!/usr/bin/env node

import {
    Command
} from 'commander';
import deploy from '../app/deploy.js';

const program = new Command();

program
    .command('deploy')
    .description('Deploy project to cPanel')
    .action(async () => {
        try {
            await deploy();
        } catch (err) {
            console.error('❌', err.message);
            process.exit(1);
        }
    });

program.parse();